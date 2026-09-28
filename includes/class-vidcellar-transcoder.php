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
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

// FFmpeg is an external process and its pipes are native PHP streams. The
// stream calls below are intentionally limited to that process boundary.
// phpcs:disable Generic.PHP.ForbiddenFunctions.Found, WordPress.WP.AlternativeFunctions.file_system_operations_fclose

/**
 * Creates a browser-friendly H.264/AAC MP4 copy. This is intentionally a
 * separate file so the original upload is never destroyed. The optimized
 * copy uses fast-start MP4 metadata and a sane streaming bitrate, allowing
 * the browser to buffer ahead instead of trying to deliver a very high-bitrate
 * source file at real-time speed.
 */
class VidCellar_Transcoder
{
    public static function init(): void
    {
        add_action('vidcellar_optimize_video', [__CLASS__, 'process'], 10, 1);
    }

    public static function schedule(int $videoId): void
    {
        if ($videoId < 1) return;

        vidcellar_update_video($videoId, ['streaming_status' => 'queued']);
        if (!wp_next_scheduled('vidcellar_optimize_video', [$videoId])) {
            wp_schedule_single_event(time() + 5, 'vidcellar_optimize_video', [$videoId]);
        }
    }

    public static function process(int $videoId): void
    {
        if ($videoId < 1) return;

        $video = vidcellar_get_video($videoId);
        if (!$video || empty($video['video_filename'])) return;

        $source = trailingslashit(vidcellar_storage_dir()) . $video['video_filename'];
        if (!is_file($source)) {
            vidcellar_update_video($videoId, [
                'streaming_status' => 'error',
                'streaming_error' => 'The original local video file could not be found.',
            ]);
            return;
        }

        if (!self::ffmpeg_available()) {
            vidcellar_update_video($videoId, [
                'streaming_status' => 'unavailable',
                'streaming_error' => 'FFmpeg is not available on this server.',
            ]);
            return;
        }

        $storageDir = vidcellar_storage_dir();
        $base = pathinfo($video['video_filename'], PATHINFO_FILENAME);
        $outputName = sanitize_file_name($base . '-stream.mp4');
        $output = trailingslashit($storageDir) . $outputName;
        $tmpOutput = $output . '.tmp.mp4';

        if (is_file($output) && filesize($output) > 0) {
            vidcellar_update_video($videoId, [
                'streaming_filename' => $outputName,
                'streaming_status' => 'optimized',
                'streaming_error' => '',
            ]);
            return;
        }

        wp_delete_file($tmpOutput);
        vidcellar_update_video($videoId, ['streaming_status' => 'processing', 'streaming_error' => '']);

        $ffmpeg = self::ffmpeg_path();
        $filter = 'scale=if(gt(iw\,1920)\,1920\,iw):-2';
        $command = implode(' ', [
            escapeshellarg($ffmpeg),
            '-hide_banner -loglevel error -y',
            '-i', escapeshellarg($source),
            '-map 0:v:0 -map 0:a?',
            '-vf', escapeshellarg($filter),
            '-c:v libx264 -preset veryfast -crf 23',
            '-maxrate 8000k -bufsize 16000k',
            '-pix_fmt yuv420p',
            '-c:a aac -b:a 128k',
            '-movflags +faststart',
            '-f mp4',
            escapeshellarg($tmpOutput),
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open( $command, $descriptors, $pipes ); // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- FFmpeg is an optional local binary invoked with fully shell-escaped arguments.
        if (!is_resource($process)) {
            vidcellar_update_video($videoId, [
                'streaming_status' => 'error',
                'streaming_error' => 'The server could not start FFmpeg.',
            ]);
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $lastError = '';
        while (true) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            if ($stderr !== false && $stderr !== '') $lastError .= $stderr;
            $status = proc_get_status($process);
            if (!$status['running']) break;
            usleep(250000);
        }
        $stderr = stream_get_contents($pipes[2]);
        if ($stderr !== false && $stderr !== '') $lastError .= $stderr;
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        clearstatcache(true, $tmpOutput);
        if ($exitCode !== 0 || !is_file($tmpOutput) || filesize($tmpOutput) < 1024) {
            wp_delete_file($tmpOutput);
            $message = trim(preg_replace('/\s+/', ' ', $lastError));
            if ($message === '') $message = 'FFmpeg could not create a streaming-optimized copy.';
            vidcellar_update_video($videoId, [
                'streaming_status' => 'error',
                'streaming_error' => substr($message, 0, 1000),
            ]);
            return;
        }

        if ( ! self::move_file( $tmpOutput, $output ) ) {
            wp_delete_file($tmpOutput);
            vidcellar_update_video($videoId, [
                'streaming_status' => 'error',
                'streaming_error' => 'The optimized video was created but could not be saved.',
            ]);
            return;
        }

        vidcellar_update_video($videoId, [
            'streaming_filename' => $outputName,
            'streaming_status' => 'optimized',
            'streaming_error' => '',
        ]);
    }

    public static function run_now(int $videoId): void
    {
        self::process($videoId);
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

    private static function ffmpeg_available(): bool
    {
        return self::ffmpeg_path() !== '';
    }

    private static function ffmpeg_path(): string
    {
        $candidates = ['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/opt/homebrew/bin/ffmpeg'];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) return $candidate;
        }
        return '';
    }
}

// phpcs:enable Generic.PHP.ForbiddenFunctions.Found, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
