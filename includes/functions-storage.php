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

if (!defined('ABSPATH')) exit;

/**
 * Same principle as the standalone app's VIDEO_STORAGE_DIR: video files must
 * never be reachable by a direct URL. wp-content/uploads IS web-servable by
 * default, so we carve out a subfolder and drop a `.htaccess` that denies
 * direct access to it (Apache). On nginx that .htaccess does nothing — see
 * README.md for the equivalent `location` block you must add by hand.
 *
 * The only way bytes ever leave this folder is VidCellar_Stream, which
 * checks the visitor actually purchased the video first.
 */
function vidcellar_storage_dir(): string
{
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'vidcellar-private/videos';
}

function vidcellar_thumbnail_dir(): string
{
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'vidcellar-public/thumbnails';
}

function vidcellar_thumbnail_url(string $filename): string
{
    if ($filename === '') return '';
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['baseurl']) . 'vidcellar-public/thumbnails/' . rawurlencode($filename);
}

/**
 * Scratch space chunked video uploads are assembled into before being moved
 * into vidcellar_storage_dir(). Deliberately a sibling of videos/ under the
 * same vidcellar-private/ parent, so it inherits that folder's .htaccess
 * "deny direct access" rule for free — see VidCellar_Chunk_Upload.
 */
function vidcellar_upload_tmp_dir(): string
{
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'vidcellar-private/tmp';
}
