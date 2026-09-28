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

// Raw range streaming requires native PHP streams because WP_Filesystem does
// not provide the seekable byte-stream interface needed by HTML5 video.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Serves video bytes only to a logged-in user who owns a purchase row for
 * that video — same model as the standalone app's public/stream.php:
 *
 *  1. Files live in vidcellar_storage_dir(), which is inside
 *     wp-content/uploads but has a .htaccess denying direct access (Apache;
 *     see README.md for nginx). Nothing in that folder is otherwise
 *     reachable by URL.
 *  2. This handler requires a logged-in WP session AND a matching row in
 *     the purchases table before it streams anything.
 *  3. Honest limitation, same as the standalone app: this is app-level
 *     access control, not DRM. It stops casual downloading/sharing of the
 *     URL, not screen recording.
 *
 * Hooked on template_redirect (not the REST API) because REST responses go
 * through WP's response/formatting envelope, which fights with raw
 * Range-request byte streaming that <video> scrubbing depends on.
 */
class VidCellar_Stream
{
    public static function init(): void
    {
        add_action('template_redirect', [__CLASS__, 'maybe_stream']);
    }

    public static function maybe_stream(): void
    {
        if ( ! isset( $_GET['vc_stream'] ) ) { return; } // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $videoId = absint( wp_unslash( $_GET['vc_stream'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        // Lite provides free playback. A companion Pro add-on can replace the
        // access layer with paid/guest purchase authorization.
        $pro_handles_stream = ( defined( 'VIDCELLAR_PRO_ACTIVE' ) && VIDCELLAR_PRO_ACTIVE );
        if ( apply_filters( 'vidcellar_pro_handles_stream', $pro_handles_stream, $videoId ) ) {
            return;
        }

        $video = vidcellar_get_video($videoId);
        if (!$video || empty($video['video_filename'])) {
            status_header(404);
            exit('Video not found');
        }

        $originalPath = trailingslashit(vidcellar_storage_dir()) . $video['video_filename'];
        $streamingPath = !empty($video['streaming_filename'])
            ? trailingslashit(vidcellar_storage_dir()) . $video['streaming_filename']
            : '';
        $path = (is_file($streamingPath) && filesize($streamingPath) > 0) ? $streamingPath : $originalPath;
        if (!is_file($path)) {
            status_header(404);
            exit('Video file missing on server');
        }

        // Count a real protected playback request once per viewer/browser
        // window, not once per HTTP Range request.
        vidcellar_record_view($videoId);
        self::stream_file($path);
        exit;
    }

    private static function stream_file(string $path): void
    {
        $size = filesize($path);
        if ($size === false || $size < 1) {
            status_header(404);
            exit('Video file is empty or unavailable');
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '';
        if (!$mime) {
            $mime = 'video/mp4';
        }

        // Raw byte streaming must not be compressed, buffered, or modified by
        // WordPress/PHP output handlers. HTML5 video players depend on clean
        // HTTP Range responses for startup, seeking, and progressive playback.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Do not alter server-wide PHP execution settings for a front-end request.
        // Stop this PHP worker when the browser abandons a range request.
        // Continuing a disconnected stream wastes a PHP-FPM worker and can
        // starve the next range requests, which shows up as repeated buffering.
        ignore_user_abort(false);

        $lastModified = filemtime($path) ?: time();
        $etag = '"' . md5($path . '|' . $size . '|' . $lastModified) . '"';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . basename($path) . '"');
        // Allow the browser to retain already-downloaded protected ranges.
        // This reduces repeated range requests while keeping the response
        // private to the visitor's browser.
        header('Cache-Control: private, max-age=86400');
        header('Vary: Range');
        header('X-Content-Type-Options: nosniff');
        header('Accept-Ranges: bytes');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModified) . ' GMT');
        header('ETag: ' . $etag);
        header('X-Accel-Buffering: no');
        header('Content-Encoding: identity');

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $rangeHeader = isset( $_SERVER['HTTP_RANGE'] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( '' !== $rangeHeader ) {

            // Browsers normally send one range. If multiple ranges are
            // requested, return the full resource rather than attempting an
            // invalid multipart response.
            if (preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
                if ($m[1] === '' && $m[2] === '') {
                    status_header(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }

                if ($m[1] === '') {
                    // Suffix range: bytes=-500 means the final 500 bytes.
                    $suffixLength = min((int) $m[2], $size);
                    $start = $size - $suffixLength;
                    $end = $size - 1;
                } else {
                    $start = (int) $m[1];
                    $end = ($m[2] !== '') ? (int) $m[2] : ($size - 1);
                }

                if ($start >= $size || $start < 0 || $end < $start) {
                    status_header(416);
                    header('Content-Range: bytes */' . $size);
                    exit;
                }

                $end = min($end, $size - 1);
                $status = 206;
            }
        }

        $length = $end - $start + 1;
        if ($length < 1) {
            status_header(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }

        if ($status === 206) {
            status_header(206);
            header("Content-Range: bytes {$start}-{$end}/{$size}");
        } else {
            status_header(200);
        }
        header('Content-Length: ' . $length);

        $fh = fopen($path, 'rb');
        if (!$fh) {
            status_header(500);
            exit('Unable to open video file');
        }

        if (fseek($fh, $start, SEEK_SET) !== 0) {
            fclose($fh);
            status_header(500);
            exit('Unable to seek video file');
        }

        // Let PHP's native stream copier move the requested range rather than
        // looping through fread()/echo()/flush() in userland. This materially
        // reduces per-block overhead for large video ranges and improves
        // sustained throughput on PHP-FPM/shared hosting.
        @stream_set_read_buffer($fh, 1024 * 1024);
        $output = fopen('php://output', 'wb');
        if (!$output) {
            fclose($fh);
            status_header(500);
            exit('Unable to open video output stream');
        }

        $copied = @stream_copy_to_stream($fh, $output, $length);
        fclose($output);
        fclose($fh);

        if ($copied === false) {
            return;
        }
    }
}

// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
