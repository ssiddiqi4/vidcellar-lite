<?php
/**
 * Copyright (C) 2026  Suhaib Siddiqi
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// Binary chunk assembly requires random-access streams; WP_Filesystem does not
// expose the byte-offset semantics needed to safely resume multi-GB uploads.
// These narrowly-scoped native stream calls are therefore intentional.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

/**
 * Robust resumable chunked uploads for the admin video form.
 *
 * Videos are uploaded as independent chunks and written at their byte offset
 * into one private temporary file. A small JSON sidecar records which chunks
 * have been received, so an interrupted upload can resume without restarting.
 * The browser keeps the upload key in localStorage; after a page refresh the
 * administrator simply selects the same local file again and the uploader
 * continues from the server's recorded state.
 */
class VidCellar_Chunk_Upload
{
    const NONCE_ACTION = 'vidcellar_chunk_upload';
    const MAX_FILE_SIZE = 32212254720; // 30 GiB (displayed as 30 GB in the UI)
    const STALE_AFTER_SECONDS = 24 * HOUR_IN_SECONDS;
    const DEFAULT_CHUNK_SIZE = 4 * 1024 * 1024; // 4 MiB; intentionally below common 8–16 MB PHP limits

    const ALLOWED_EXTENSIONS = ['mp4', 'mov', 'm4v', 'webm', 'ogv', 'avi', 'wmv', 'mkv'];

    public static function init(): void
    {
        add_action('wp_ajax_vidcellar_upload_chunk', [__CLASS__, 'handle_chunk']);
        add_action('wp_ajax_vidcellar_upload_status', [__CLASS__, 'handle_status']);
        add_action('wp_ajax_vidcellar_cancel_upload', [__CLASS__, 'handle_cancel']);
    }

    public static function chunk_size_bytes(): int
    {
        $configured = (int) apply_filters('vidcellar_chunk_size_bytes', self::DEFAULT_CHUNK_SIZE);

        // A multipart/form-data chunk has request overhead. Keep each chunk
        // comfortably below the smaller of upload_max_filesize/post_max_size
        // so a normal 8–16 MB PHP configuration cannot reject the chunk.
        $serverLimit = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;
        if ($serverLimit > 0) {
            $safeServerChunk = (int) floor($serverLimit * 0.50);
            $configured = min($configured, $safeServerChunk);
        }

        return max(256 * KB_IN_BYTES, min($configured, 4 * MB_IN_BYTES));
    }

    public static function max_file_size_bytes(): int
    {
        return (int) apply_filters('vidcellar_max_video_upload_bytes', self::MAX_FILE_SIZE);
    }

    public static function handle_chunk(): void
    {
        self::authorize();

        // v0.3.8 uses raw application/octet-stream requests for the video bytes.
        // This avoids PHP multipart/$_FILES limits and temporary-upload handling.
        // Multipart uploads remain supported as a compatibility fallback.
        $chunkFile = self::uploaded_file( 'chunk' );
        $rawChunk = empty( $chunkFile );
        $uploadKey = self::sanitize_upload_key(self::request_value('upload_key', ''));
        $fileName = sanitize_file_name((string) self::request_value('file_name', ''));
        $fileSize = self::positive_int(self::request_value('file_size', 0));
        $chunkIndex = self::non_negative_int(self::request_value('chunk_index', -1));
        $totalChunks = self::positive_int(self::request_value('total_chunks', 0));
        $lastModified = self::positive_int(self::request_value('last_modified', 0));

        if (!$rawChunk && isset( $chunkFile['error'] ) ? (int) $chunkFile['error'] : UPLOAD_ERR_NO_FILE !== UPLOAD_ERR_OK) {
            $errorCode = isset( $chunkFile['error'] ) ? (int) $chunkFile['error'] : UPLOAD_ERR_NO_FILE;
            $status = in_array($errorCode, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 413 : 400;
            wp_send_json_error([
                'message' => self::upload_error_message($errorCode),
                'code'    => $errorCode,
                'retryable' => $errorCode === UPLOAD_ERR_PARTIAL,
                'server_chunk_size' => self::chunk_size_bytes(),
                'server_upload_limit' => function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0,
            ], $status);
        }

        if ($uploadKey === '' || $fileName === '' || $fileSize < 1 || $chunkIndex < 0 || $totalChunks < 1) {
            wp_send_json_error(['message' => 'Malformed upload request.'], 400);
        }

        if (PHP_INT_SIZE < 8) {
            wp_send_json_error(['message' => 'This server needs a 64-bit PHP build to support videos this large.'], 500);
        }

        if ($fileSize > self::max_file_size_bytes()) {
            wp_send_json_error(['message' => 'This video is larger than the 30 GB upload limit.'], 413);
        }

        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            wp_send_json_error(['message' => 'That file type is not a supported video format.'], 400);
        }

        $chunkSize = self::chunk_size_bytes();
        $expectedChunks = max(1, (int) ceil($fileSize / $chunkSize));
        if ($totalChunks !== $expectedChunks || $chunkIndex >= $totalChunks) {
            wp_send_json_error(['message' => 'The upload chunk information does not match the selected file.'], 400);
        }

        $tmpDir = vidcellar_upload_tmp_dir();
        if (!is_dir($tmpDir) && !wp_mkdir_p($tmpDir)) {
            wp_send_json_error(['message' => 'The server could not create the temporary upload directory.'], 500);
        }

        self::cleanup_stale_parts($tmpDir);

        $meta = self::load_meta($uploadKey, $tmpDir);
        $userId = get_current_user_id();

        if ($meta) {
            if ((int) ($meta['user_id'] ?? 0) !== $userId) {
                wp_send_json_error(['message' => 'This upload belongs to another administrator.'], 403);
            }
            if ((string) ($meta['file_name'] ?? '') !== $fileName || (int) ($meta['file_size'] ?? 0) !== $fileSize || (int) ($meta['total_chunks'] ?? 0) !== $totalChunks) {
                wp_send_json_error(['message' => 'The selected file does not match the upload being resumed.'], 409);
            }
        } else {
            $free = @disk_free_space($tmpDir);
            if ($free !== false && $free < ($fileSize + 256 * MB_IN_BYTES)) {
                wp_send_json_error(['message' => 'There is not enough free server disk space to safely upload this video. Required: approximately ' . size_format($fileSize) . ' plus working space.'], 507);
            }

            $meta = [
                'user_id'       => $userId,
                'file_name'     => $fileName,
                'file_size'     => $fileSize,
                'total_chunks'  => $totalChunks,
                'chunk_size'    => $chunkSize,
                'last_modified' => $lastModified,
                'received'     => [],
                'created_at'    => time(),
                'updated_at'    => time(),
            ];
            if (!self::save_meta($uploadKey, $tmpDir, $meta)) {
                wp_send_json_error(['message' => 'The server could not create the upload session.'], 500);
            }
        }

        $received = array_map('intval', (array) ($meta['received'] ?? []));
        if (in_array($chunkIndex, $received, true)) {
            $bytesReceived = self::received_bytes($received, $fileSize, $chunkSize);
            wp_send_json_success([
                'complete'       => count($received) >= $totalChunks,
                'alreadyReceived'=> true,
                'receivedChunks' => count($received),
                'totalChunks'    => $totalChunks,
                'bytesReceived'  => $bytesReceived,
                'fileSize'       => $fileSize,
            ]);
        }

        $offset = $chunkIndex * $chunkSize;
        $expectedBytes = min($chunkSize, $fileSize - $offset);
        if ($offset < 0 || $expectedBytes < 1) {
            wp_send_json_error([
                'message' => 'The received chunk position is invalid.',
                'code' => 'invalid_chunk_position',
                'retryable' => false,
            ], 400);
        }

        // Raw binary requests must declare exactly the number of bytes this
        // chunk represents. This prevents oversized request bodies from being
        // accepted by the PHP input stream. Multipart compatibility uploads
        // are validated through $_FILES below.
        if ($rawChunk) {
            $contentLength = isset( $_SERVER['CONTENT_LENGTH'] ) ? filter_var( wp_unslash( $_SERVER['CONTENT_LENGTH'] ), FILTER_VALIDATE_INT ) : false;
            if ($contentLength === false || (int) $contentLength !== $expectedBytes) {
                wp_send_json_error([
                    'message' => 'The raw upload chunk Content-Length does not match the expected chunk size.',
                    'code'    => 'invalid_content_length',
                ], 413);
            }
        }

        $chunkTmp = '';
        if (!$rawChunk) {
            $chunkTmp = isset( $chunkFile['tmp_name'] ) ? (string) $chunkFile['tmp_name'] : '';
            if ($chunkTmp === '' || !is_uploaded_file($chunkTmp)) {
                wp_send_json_error(['message' => 'No valid multipart chunk data was received.'], 400);
            }
        } else {
            $chunkTmp = @tempnam($tmpDir, 'svchunk-');
            if ($chunkTmp === false) {
                wp_send_json_error(['message' => 'The server could not create a temporary chunk file. Check disk space and permissions.'], 507);
            }
            $in = @fopen('php://input', 'rb');
            $out = @fopen($chunkTmp, 'wb');
            if (!$in || !$out) {
                if (is_resource($in)) @fclose($in);
                if (is_resource($out)) @fclose($out);
                wp_delete_file($chunkTmp);
                wp_send_json_error(['message' => 'The server could not read the binary upload chunk.'], 400);
            }
            $chunkBytes = 0;
            $copyOk = true;
            while (!feof($in) && $chunkBytes < $expectedBytes) {
                $buffer = fread($in, min(1024 * 1024, $expectedBytes - $chunkBytes));
                if ($buffer === false || $buffer === '') {
                    break;
                }
                $length = strlen($buffer);
                if (fwrite($out, $buffer) !== $length) {
                    $copyOk = false;
                    break;
                }
                $chunkBytes += $length;
            }
            @fclose($in);
            @fflush($out);
            @fclose($out);
            if (!$copyOk) {
                wp_delete_file($chunkTmp);
                wp_send_json_error(['message' => 'The server could not save the received binary chunk. Check disk space and permissions.'], 507);
            }
        }

        clearstatcache(true, $chunkTmp);
        $chunkBytes = (int) @filesize($chunkTmp);
        if ($chunkBytes !== $expectedBytes) {
            wp_delete_file($chunkTmp);
            wp_send_json_error([
                'message' => 'The received chunk size is invalid. The server received ' . size_format(max(0, $chunkBytes)) . ' but expected ' . size_format($expectedBytes) . '. Please retry this chunk.',
                'code' => 'invalid_chunk_size',
                'retryable' => true,
            ], 400);
        }

        $partPath = self::part_path($uploadKey, $tmpDir);
        $written = self::write_chunk_at_offset($partPath, $chunkTmp, $offset, $expectedBytes);
        if (is_wp_error($written)) {
            wp_send_json_error(['message' => $written->get_error_message()], 507);
        }

        $received[] = $chunkIndex;
        $received = array_values(array_unique(array_map('intval', $received)));
        sort($received, SORT_NUMERIC);
        $meta['received'] = $received;
        $meta['updated_at'] = time();

        $complete = count($received) === $totalChunks;
        if ($complete) {
            clearstatcache(true, $partPath);
            if (!file_exists($partPath) || (int) filesize($partPath) !== $fileSize) {
                wp_send_json_error(['message' => 'The upload appears complete, but the assembled file size is incorrect. Please retry the affected chunk.'], 500);
            }
            $meta['complete'] = true;
        }

        if (!self::save_meta($uploadKey, $tmpDir, $meta)) {
            wp_send_json_error(['message' => 'The chunk was written, but the upload state could not be saved. Please retry the chunk.'], 500);
        }

        $bytesReceived = self::received_bytes($received, $fileSize, $chunkSize);
        wp_send_json_success([
            'complete'       => $complete,
            'receivedChunks' => count($received),
            'totalChunks'    => $totalChunks,
            'bytesReceived'  => $bytesReceived,
            'fileSize'       => $fileSize,
        ]);
    }

    public static function handle_status(): void
    {
        self::authorize();
        $uploadKey = self::sanitize_upload_key( self::request_value( 'upload_key', '' ) );
        if ($uploadKey === '') {
            wp_send_json_error(['message' => 'Invalid upload reference.'], 400);
        }

        $meta = self::load_meta($uploadKey, vidcellar_upload_tmp_dir());
        if (!$meta) {
            wp_send_json_success(['found' => false]);
        }
        if ((int) ($meta['user_id'] ?? 0) !== get_current_user_id()) {
            wp_send_json_error(['message' => 'This upload belongs to another administrator.'], 403);
        }

        $fileSize = (int) $meta['file_size'];
        $chunkSize = (int) $meta['chunk_size'];
        $received = array_values(array_map('intval', (array) ($meta['received'] ?? [])));
        $complete = count($received) === (int) $meta['total_chunks'] && !empty($meta['complete']);

        wp_send_json_success([
            'found'          => true,
            'fileName'       => (string) $meta['file_name'],
            'fileSize'       => $fileSize,
            'totalChunks'    => (int) $meta['total_chunks'],
            'chunkSize'      => $chunkSize,
            'lastModified'   => (int) ($meta['last_modified'] ?? 0),
            'received'       => $received,
            'receivedChunks' => count($received),
            'bytesReceived'  => self::received_bytes($received, $fileSize, $chunkSize),
            'complete'       => $complete,
        ]);
    }

    public static function handle_cancel(): void
    {
        self::authorize();
        $uploadKey = self::sanitize_upload_key( self::request_value( 'upload_key', '' ) );
        if ($uploadKey === '') {
            wp_send_json_error(['message' => 'Invalid upload reference.'], 400);
        }

        $tmpDir = vidcellar_upload_tmp_dir();
        $meta = self::load_meta($uploadKey, $tmpDir);
        if ($meta && (int) ($meta['user_id'] ?? 0) !== get_current_user_id()) {
            wp_send_json_error(['message' => 'This upload belongs to another administrator.'], 403);
        }

        wp_delete_file(self::part_path($uploadKey, $tmpDir));
        wp_delete_file(self::meta_path($uploadKey, $tmpDir));
        wp_send_json_success(['cancelled' => true]);
    }

    /** @return array{path:string,name:string}|WP_Error */
    public static function finalize(string $rawUploadKey, string $rawFileName)
    {
        $uploadKey = self::sanitize_upload_key($rawUploadKey);
        $fileName = sanitize_file_name($rawFileName);
        $tmpDir = vidcellar_upload_tmp_dir();
        $meta = self::load_meta($uploadKey, $tmpDir);

        if ($uploadKey === '' || $fileName === '' || !$meta) {
            return new WP_Error('vidcellar_chunk_missing', 'The uploaded video could not be found on the server. Please choose the file again.');
        }
        if ((int) ($meta['user_id'] ?? 0) !== get_current_user_id()) {
            return new WP_Error('vidcellar_chunk_owner', 'You are not allowed to finalize this upload.');
        }
        if ((string) $meta['file_name'] !== $fileName || empty($meta['complete'])) {
            return new WP_Error('vidcellar_chunk_incomplete', 'The video upload is not complete yet. Please wait for the upload to reach 100%.');
        }

        $partPath = self::part_path($uploadKey, $tmpDir);
        clearstatcache(true, $partPath);
        if (!file_exists($partPath) || (int) filesize($partPath) !== (int) $meta['file_size']) {
            return new WP_Error('vidcellar_chunk_size', 'The assembled video file is incomplete. Please retry the upload.');
        }

        $validation = self::validate_video_file($partPath, $fileName);
        if (is_wp_error($validation)) {
            wp_delete_file($partPath);
            wp_delete_file(self::meta_path($uploadKey, $tmpDir));
            return $validation;
        }

        return ['path' => $partPath, 'name' => $fileName];
    }

    /**
     * Validate a completed video using both its extension and detected MIME.
     *
     * @param string $filePath Absolute path to the completed video.
     * @param string $fileName Original sanitized filename.
     * @return true|WP_Error
     */
    public static function validate_video_file(string $filePath, string $fileName)
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return new WP_Error('vidcellar_video_unreadable', 'The completed video file could not be read.', ['status' => 400]);
        }

        $allowed = [
            'mp4'  => ['video/mp4', 'application/mp4'],
            'mov'  => ['video/quicktime'],
            'm4v'  => ['video/mp4', 'video/x-m4v'],
            'webm' => ['video/webm'],
            'ogv'  => ['video/ogg'],
            'avi'  => ['video/x-msvideo', 'video/avi', 'application/x-troff-msvideo'],
            'wmv'  => ['video/x-ms-wmv', 'video/x-ms-asf'],
            'mkv'  => ['video/x-matroska', 'application/x-matroska'],
        ];

        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        if (!isset($allowed[$extension])) {
            return new WP_Error('vidcellar_video_type', 'This video file type is not supported.', ['status' => 415]);
        }

        $fileType = wp_check_filetype_and_ext($filePath, $fileName, [
            'mp4' => 'video/mp4',
            'mov' => 'video/quicktime',
            'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv' => 'video/ogg',
            'avi' => 'video/x-msvideo',
            'wmv' => 'video/x-ms-wmv',
            'mkv' => 'video/x-matroska',
        ]);

        if (empty($fileType['ext']) || empty($fileType['type'])) {
            return new WP_Error('vidcellar_video_mime', 'The uploaded file does not match an allowed video type.', ['status' => 415]);
        }

        $detectedMime = '';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = (string) finfo_file($finfo, $filePath);
                finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $detectedMime = (string) mime_content_type($filePath);
        }

        if ($detectedMime !== '' && !in_array($detectedMime, $allowed[$extension], true)) {
            return new WP_Error('vidcellar_video_mime', 'The uploaded file content does not match its video extension.', ['status' => 415]);
        }

        return true;
    }

    private static function authorize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'You do not have permission to upload videos.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
    }

    private static function write_chunk_at_offset(string $partPath, string $chunkTmp, int $offset, int $expectedBytes)
    {
        $out = @fopen($partPath, 'c+b');
        if (!$out) {
            return new WP_Error('vidcellar_chunk_output', 'The server could not open the upload for writing. Check disk space and folder permissions.');
        }

        if (!@flock($out, LOCK_EX)) {
            @fclose($out);
            return new WP_Error('vidcellar_chunk_lock', 'The server could not lock the upload file. Please retry this chunk.');
        }

        if (fseek($out, $offset, SEEK_SET) !== 0) {
            @flock($out, LOCK_UN);
            @fclose($out);
            return new WP_Error('vidcellar_chunk_seek', 'The server could not seek to the correct upload position.');
        }

        $in = @fopen($chunkTmp, 'rb');
        if (!$in) {
            @flock($out, LOCK_UN);
            @fclose($out);
            return new WP_Error('vidcellar_chunk_input', 'The server could not read the uploaded chunk.');
        }

        $writtenTotal = 0;
        while (!feof($in) && $writtenTotal < $expectedBytes) {
            $buffer = fread($in, min(1024 * 1024, $expectedBytes - $writtenTotal));
            if ($buffer === false || $buffer === '') {
                break;
            }
            $length = strlen($buffer);
            $written = fwrite($out, $buffer);
            if ($written === false || $written !== $length) {
                @fclose($in);
                @flock($out, LOCK_UN);
                @fclose($out);
                return new WP_Error('vidcellar_chunk_write', 'The server could not write the complete chunk. Check available disk space.');
            }
            $writtenTotal += $written;
        }

        @fclose($in);
        @fflush($out);
        @flock($out, LOCK_UN);
        @fclose($out);
        wp_delete_file($chunkTmp);

        if ($writtenTotal !== $expectedBytes) {
            return new WP_Error('vidcellar_chunk_write', 'The server wrote an incomplete chunk. Please retry this chunk.');
        }

        return true;
    }

    private static function received_bytes(array $received, int $fileSize, int $chunkSize): int
    {
        $bytes = 0;
        foreach ($received as $index) {
            $offset = $index * $chunkSize;
            if ($offset >= $fileSize) continue;
            $bytes += min($chunkSize, $fileSize - $offset);
        }
        return $bytes;
    }

    private static function load_meta(string $uploadKey, string $tmpDir): ?array
    {
        $path = self::meta_path($uploadKey, $tmpDir);
        if (!file_exists($path)) return null;
        $json = @file_get_contents($path);
        $meta = $json ? json_decode($json, true) : null;
        return is_array($meta) ? $meta : null;
    }

    private static function save_meta(string $uploadKey, string $tmpDir, array $meta): bool
    {
        $path = self::meta_path($uploadKey, $tmpDir);
        $lockPath = $path . '.lock';
        $lock = @fopen($lockPath, 'c');
        if (!$lock || !@flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                @fclose($lock);
            }
            return false;
        }

        // Merge the received-chunk set while holding the lock. Two parallel
        // browser requests can therefore never overwrite each other's chunk
        // metadata. The final write uses a temporary file followed by rename
        // so readers never observe partially-written JSON.
        $current = self::load_meta($uploadKey, $tmpDir);
        if (is_array($current)) {
            $currentReceived = array_map('intval', (array) ($current['received'] ?? []));
            $newReceived = array_map('intval', (array) ($meta['received'] ?? []));
            $meta['received'] = array_values(array_unique(array_merge($currentReceived, $newReceived)));
            sort($meta['received'], SORT_NUMERIC);
            $meta['complete'] = count($meta['received']) >= (int) ($meta['total_chunks'] ?? 0);
        }

        $json = wp_json_encode($meta, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            @flock($lock, LOCK_UN);
            @fclose($lock);
            return false;
        }

        $tempPath = $path . '.' . wp_generate_password(8, false, false) . '.tmp';
        $written = @file_put_contents($tempPath, $json, LOCK_EX);
        $ok = $written !== false && self::move_file( $tempPath, $path );
        if (!$ok) {
            wp_delete_file($tempPath);
        }
        @flock($lock, LOCK_UN);
        @fclose($lock);
        return $ok;
    }

    private static function move_file( string $source, string $destination ): bool {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        $filesystem = new WP_Filesystem_Direct( false );
        if ( $filesystem->move( $source, $destination, true ) ) {
            return true;
        }
        if ( @copy( $source, $destination ) ) {
            wp_delete_file( $source );
            return true;
        }
        return false;
    }

    // This helper is used only by AJAX handlers that call authorize() first.
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce/capability verification is performed by authorize() at each public AJAX entry point before this helper is reached.
    private static function request_value( string $key, $default = '' ) {
        if ( isset( $_POST[ $key ] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Context-specific validation is performed by each caller.
            return wp_unslash( $_POST[ $key ] );
        }
        if ( isset( $_GET[ $key ] ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Context-specific validation is performed by each caller.
            return wp_unslash( $_GET[ $key ] );
        }
        return $default;
    }

    /**
     * Return one PHP uploaded-file entry for validation and processing.
     *
     * @param string $key File input name.
     * @return array
     */
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- AJAX entry points call authorize() before uploaded-file processing.
    private static function uploaded_file( string $key ): array {
        if ( ! isset( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) {
            return [];
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- PHP upload metadata is validated by validate_video_file() and is_uploaded_file() before use.
        return $_FILES[ $key ];
    }

    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
    private static function sanitize_upload_key($key): string
    {
        $key = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $key) ?? '';
        return substr($key, 0, 64);
    }

    private static function positive_int($value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? 0 : (int) $value;
    }

    private static function non_negative_int($value): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $value === false ? -1 : (int) $value;
    }

    private static function part_path(string $uploadKey, string $tmpDir): string
    {
        return trailingslashit($tmpDir) . get_current_blog_id() . '-' . $uploadKey . '.part';
    }

    private static function meta_path(string $uploadKey, string $tmpDir): string
    {
        return trailingslashit($tmpDir) . get_current_blog_id() . '-' . $uploadKey . '.json';
    }

    private static function cleanup_stale_parts(string $tmpDir): void
    {
        $cutoff = time() - self::STALE_AFTER_SECONDS;
        foreach (glob(trailingslashit($tmpDir) . get_current_blog_id() . '-*.json') ?: [] as $metaFile) {
            if (@filemtime($metaFile) < $cutoff) {
                $base = substr($metaFile, 0, -5);
                wp_delete_file($metaFile);
                wp_delete_file($base . '.part');
            }
        }
        foreach (glob(trailingslashit($tmpDir) . get_current_blog_id() . '-*.part') ?: [] as $partFile) {
            if (@filemtime($partFile) < $cutoff) wp_delete_file($partFile);
        }
    }

    private static function upload_error_message(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'The upload chunk is larger than the server permits. Reduce the plugin chunk size or raise the server request limit.';
            case UPLOAD_ERR_PARTIAL:
                return 'The server received only part of this chunk. It will be retried automatically.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'PHP has no temporary upload directory configured.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'The server could not write the uploaded chunk. Check disk space and permissions.';
            case UPLOAD_ERR_EXTENSION:
                return 'A server extension stopped this upload chunk.';
            default:
                return 'No valid upload chunk was received.';
        }
    }
}

// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
