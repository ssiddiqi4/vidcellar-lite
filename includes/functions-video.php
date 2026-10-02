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

// This plugin uses custom tables and must read/write current catalog and rating
// state directly; WordPress has no equivalent content API for these tables.
// Prepared statements are used for all dynamic values and table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

if ( ! function_exists( 'vidcellar_normalize_video_category' ) ) {
    /**
     * Normalize a video category for consistent storage and querying.
     *
     * @param string $category Video category.
     * @return string
     */
    function vidcellar_normalize_video_category( $category ) {
        $category = sanitize_text_field( (string) $category );
        return sanitize_title( $category );
    }
}




if ( ! function_exists( 'vidcellar_video_categories' ) ) {
    /**
     * Return the available video categories.
     *
     * Categories are stored as a simple WordPress option so an administrator
     * can add them without requiring a taxonomy migration. Existing categories
     * already present in the video table are preserved and merged in.
     *
     * @return array
     */
    function vidcellar_video_categories(): array {
        global $wpdb;

        $saved = get_option( 'vidcellar_video_categories', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        $table = vidcellar_videos_table();
        $rows  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT category FROM %i WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC", $table ) );

        $categories = [];
        foreach ( array_merge( $saved, (array) $rows ) as $category ) {
            $category = sanitize_text_field( (string) $category );
            if ( '' === $category ) {
                continue;
            }
            $slug = sanitize_title( $category );
            // Saved names come first; a video row only stores the slug, so it must not replace the name.
            if ( '' !== $slug && ! isset( $categories[ $slug ] ) ) {
                $categories[ $slug ] = $category;
            }
        }

        $categories = array_values( $categories );

        if ( empty( $categories ) ) {
            $categories = [ 'General' ];
        }

        natcasesort( $categories );
        $categories = array_values( $categories );
        update_option( 'vidcellar_video_categories', $categories, false );

        return $categories;
    }
}

if ( ! function_exists( 'vidcellar_video_category_label' ) ) {
    /**
     * Return the display name of a stored category slug.
     *
     * @param string $category Category slug or name.
     * @return string
     */
    function vidcellar_video_category_label( $category ): string {
        $slug = vidcellar_normalize_video_category( $category );
        foreach ( vidcellar_video_categories() as $name ) {
            if ( sanitize_title( $name ) === $slug ) {
                return $name;
            }
        }
        return (string) $category;
    }
}

if ( ! function_exists( 'vidcellar_add_video_category' ) ) {
    /**
     * Add a new administrator-defined video category.
     *
     * @param string $category Category name.
     * @return array
     */
    function vidcellar_add_video_category( string $category ): array {
        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'success' => false, 'error' => 'You do not have permission to add categories.' ];
        }

        $category = sanitize_text_field( $category );
        $category = trim( $category );

        if ( '' === $category ) {
            return [ 'success' => false, 'error' => 'Please enter a category name.' ];
        }

        if ( strlen( $category ) > 50 ) {
            return [ 'success' => false, 'error' => 'Category names must be 50 characters or fewer.' ];
        }

        $slug = sanitize_title( $category );
        if ( '' === $slug ) {
            return [ 'success' => false, 'error' => 'Please enter a valid category name.' ];
        }

        $categories = vidcellar_video_categories();

        foreach ( $categories as $existing ) {
            if ( sanitize_title( $existing ) === $slug ) {
                return [ 'success' => false, 'error' => 'That category already exists.' ];
            }
        }

        $categories[] = $category;
        $unique = [];
        foreach ( $categories as $item ) {
            $unique[ sanitize_title( $item ) ] = $item;
        }

        $categories = array_values( $unique );
        natcasesort( $categories );
        update_option( 'vidcellar_video_categories', array_values( $categories ), false );

        return [ 'success' => true, 'category' => $category ];
    }
}

if ( ! function_exists( 'vidcellar_video_category_counts' ) ) {
    /**
     * Count the videos in each category.
     *
     * @return array Video counts keyed by category slug.
     */
    function vidcellar_video_category_counts(): array {
        global $wpdb;

        $table  = vidcellar_videos_table();
        $rows   = $wpdb->get_results( $wpdb->prepare( "SELECT category, COUNT(*) AS total FROM %i GROUP BY category", $table ), ARRAY_A );
        $counts = [];
        foreach ( (array) $rows as $row ) {
            $slug = vidcellar_normalize_video_category( (string) $row['category'] );
            if ( '' !== $slug ) {
                $counts[ $slug ] = ( $counts[ $slug ] ?? 0 ) + (int) $row['total'];
            }
        }

        return $counts;
    }
}

if ( ! function_exists( 'vidcellar_delete_video_category' ) ) {
    /**
     * Delete an administrator-defined video category.
     *
     * Videos in the deleted category move to "General" when it remains,
     * otherwise to the first remaining category. The last category cannot be
     * deleted, because every video needs one.
     *
     * @param string $category Category name or slug.
     * @return array
     */
    function vidcellar_delete_video_category( string $category ): array {
        global $wpdb;

        if ( ! current_user_can( 'manage_options' ) ) {
            return [ 'success' => false, 'error' => 'You do not have permission to delete categories.' ];
        }

        $slug       = vidcellar_normalize_video_category( $category );
        $categories = vidcellar_video_categories();
        $name       = '';
        $remaining  = [];
        foreach ( $categories as $existing ) {
            if ( sanitize_title( $existing ) === $slug ) {
                $name = $existing;
            } else {
                $remaining[] = $existing;
            }
        }

        if ( '' === $slug || '' === $name ) {
            return [ 'success' => false, 'error' => 'That category no longer exists.' ];
        }

        if ( empty( $remaining ) ) {
            return [ 'success' => false, 'error' => 'You cannot delete the only category. Add another category first.' ];
        }

        $target = $remaining[0];
        foreach ( $remaining as $existing ) {
            if ( 'general' === sanitize_title( $existing ) ) {
                $target = $existing;
                break;
            }
        }

        // Video rows store the slug, but older rows may hold the name, so match both forms.
        $table  = vidcellar_videos_table();
        $stored = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT category FROM %i WHERE category <> ''", $table ) );
        $moved  = 0;
        foreach ( (array) $stored as $value ) {
            if ( vidcellar_normalize_video_category( (string) $value ) !== $slug ) {
                continue;
            }
            $updated = $wpdb->update( $table, [ 'category' => vidcellar_normalize_video_category( $target ) ], [ 'category' => $value ], [ '%s' ], [ '%s' ] );
            if ( false === $updated ) {
                return [ 'success' => false, 'error' => 'Could not move the videos out of this category. Nothing was deleted.' ];
            }
            $moved += (int) $updated;
        }

        update_option( 'vidcellar_video_categories', array_values( $remaining ), false );

        return [ 'success' => true, 'category' => $name, 'moved' => $moved, 'target' => $target ];
    }
}

if ( ! function_exists( 'vidcellar_video_url' ) ) {
    /**
     * Build the public Watch URL for a video.
     *
     * @param int $video_id Video ID.
     * @return string
     */
    function vidcellar_video_url( int $video_id ): string {
        $video_id = absint( $video_id );
        if ( ! $video_id ) {
            return '';
        }

        $page_id = absint( get_option( 'vidcellar_watch_page_id', 0 ) );
        $base    = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        if ( ! $base ) {
            $base = home_url( '/' );
        }

        return add_query_arg( 'vc_id', $video_id, $base );
    }
}

function vidcellar_trailer_url(array $video): string
{
    $attachmentId = absint($video['trailer_attachment_id'] ?? 0);
    if ($attachmentId <= 0) return '';

    $mime = get_post_mime_type($attachmentId);
    if (!$mime || strpos((string) $mime, 'video/') !== 0) return '';

    $url = wp_get_attachment_url($attachmentId);
    return $url ? esc_url_raw($url) : '';
}

function vidcellar_stream_url(int $videoId): string
{
    // Handled by VidCellar_Stream on template_redirect — see that class.
    // Deliberately a plain query arg on the site root rather than a REST
    // route: REST responses go through WP's envelope/formatting machinery,
    // which fights with raw ranged byte-streaming for video playback.
    return add_query_arg('vc_stream', $videoId, home_url('/'));
}

// ---------------------------------------------------------------------
// CRUD — same shape as the standalone app's includes/Video.php, ported to
// $wpdb. Returned rows are plain associative arrays either way.
// ---------------------------------------------------------------------

function vidcellar_list_videos(string $keyword, string $category, int $page, int $perPage = 24): array
{
    global $wpdb;
    $table = vidcellar_videos_table();

    $perPage = max(1, $perPage);
    $offset = max(0, ($page - 1) * $perPage);
    $keywordLike = '%' . $wpdb->esc_like($keyword) . '%';
    $category = vidcellar_normalize_video_category($category);

    if ($keyword !== '' && $category !== '') {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE title LIKE %s AND category = %s',
                $table,
                $keywordLike,
                $category
            )
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE title LIKE %s AND category = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $table,
                $keywordLike,
                $category,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    } elseif ($keyword !== '') {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE title LIKE %s',
                $table,
                $keywordLike
            )
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE title LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $table,
                $keywordLike,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    } elseif ($category !== '') {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE category = %s',
                $table,
                $category
            )
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE category = %s ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $table,
                $category,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    } else {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d',
                $table,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    }

    return [
        'videos' => $videos,
        'total'  => $total,
        'pages'  => max(1, (int) ceil($total / $perPage)),
    ];
}

function vidcellar_list_most_watched_videos(string $keyword, int $page, int $perPage = 24): array
{
    global $wpdb;
    $table = vidcellar_videos_table();

    $perPage = max(1, $perPage);
    $offset = max(0, ($page - 1) * $perPage);
    $keywordLike = '%' . $wpdb->esc_like($keyword) . '%';

    if ($keyword !== '') {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE title LIKE %s',
                $table,
                $keywordLike
            )
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i WHERE title LIKE %s ORDER BY views DESC, created_at DESC, id DESC LIMIT %d OFFSET %d',
                $table,
                $keywordLike,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    } else {
        $total = (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i', $table)
        );
        $videos = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM %i ORDER BY views DESC, created_at DESC, id DESC LIMIT %d OFFSET %d',
                $table,
                $perPage,
                $offset
            ),
            ARRAY_A
        );
    }

    return [
        'videos' => $videos,
        'total'  => $total,
        'pages'  => max(1, (int) ceil($total / $perPage)),
    ];
}

function vidcellar_get_video(int $id): ?array
{
    global $wpdb;
    $table = vidcellar_videos_table();
    $video = $wpdb->get_row($wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $table, $id ), ARRAY_A);
    if (!$video) return null;

    $reviewsTable = vidcellar_reviews_table();
    $video['reviews'] = $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM %i WHERE video_id = %d ORDER BY created_at DESC", $reviewsTable, $id ),
        ARRAY_A
    );

    return $video;
}

function vidcellar_create_video(array $data): int
{
    global $wpdb;
    $inserted = $wpdb->insert(vidcellar_videos_table(), $data);
    if ($inserted === false) {
        return 0; // caller must check for this — insert_id is unreliable/stale after a failed insert
    }
    return (int) $wpdb->insert_id;
}

function vidcellar_update_video(int $id, array $data): void
{
    global $wpdb;
    $wpdb->update(vidcellar_videos_table(), $data, ['id' => $id]);
}

function vidcellar_delete_video(int $id): void
{
    global $wpdb;
    $wpdb->delete(vidcellar_videos_table(), ['id' => $id]);
}

function vidcellar_creator_videos(int $creatorId): array
{
    global $wpdb;
    $table = vidcellar_videos_table();
    return $wpdb->get_results(
        $wpdb->prepare( "SELECT * FROM %i WHERE creator_id = %d ORDER BY created_at DESC", $table, $creatorId ),
        ARRAY_A
    );
}

function vidcellar_add_review(int $videoId, int $userId, int $rating, string $comment): ?string
{
    global $wpdb;
    $reviewsTable = vidcellar_reviews_table();

    $existing = $wpdb->get_var(
        $wpdb->prepare( "SELECT id FROM %i WHERE video_id = %d AND user_id = %d", $reviewsTable, $videoId, $userId )
    );
    if ($existing) return 'You already reviewed this video';

    $wpdb->insert($reviewsTable, [
        'video_id' => $videoId,
        'user_id'  => $userId,
        'rating'   => $rating,
        'comment'  => $comment,
    ]);

    $agg = $wpdb->get_row(
        $wpdb->prepare( "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM %i WHERE video_id = %d", $reviewsTable, $videoId )
    );

    $wpdb->update(vidcellar_videos_table(), [
        'rating'      => round((float) $agg->avg_rating, 2),
        'num_reviews' => (int) $agg->cnt,
    ], ['id' => $videoId]);

    return null;
}

/**
 * Stable, non-PII key used to store one rating per viewer/video. Logged-in
 * users are keyed by account ID; guests are keyed by the hash of their
 * existing protected guest-access token.
 */
function vidcellar_reviewer_key(int $userId = 0, string $guestToken = ''): string
{
    if ($userId > 0) {
        return hash('sha256', 'user:' . $userId);
    }
    if ($guestToken !== '') {
        return hash('sha256', 'guest:' . $guestToken);
    }
    return '';
}

function vidcellar_get_current_rating(int $videoId, int $userId = 0, string $guestToken = ''): int
{
    $key = vidcellar_reviewer_key($userId, $guestToken);
    if ($videoId <= 0 || $key === '') return 0;

    global $wpdb;
    $table = vidcellar_reviews_table();
    $rating = $wpdb->get_var($wpdb->prepare(
        "SELECT rating FROM %i WHERE video_id = %d AND reviewer_key = %s LIMIT 1",
        $table, $videoId, $key
    ));
    return $rating === null ? 0 : max(1, min(5, (int) $rating));
}

function vidcellar_save_rating(int $videoId, int $rating, int $userId = 0, string $guestToken = ''): array
{
    global $wpdb;

    $rating = max(1, min(5, $rating));
    $key = vidcellar_reviewer_key($userId, $guestToken);
    if ($videoId <= 0 || $key === '') {
        return ['success' => false, 'error' => 'A valid viewer session is required.'];
    }

    $reviewsTable = vidcellar_reviews_table();
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM %i WHERE video_id = %d AND reviewer_key = %s LIMIT 1",
        $reviewsTable, $videoId, $key
    ));

    $data = [
        'video_id'    => $videoId,
        'user_id'     => $userId > 0 ? $userId : null,
        'reviewer_key'=> $key,
        'rating'      => $rating,
        'comment'     => '',
    ];

    if ( $existing ) {
        $updated = $wpdb->update(
            $reviewsTable,
            [ 'rating' => $rating, 'comment' => '' ],
            [ 'id' => (int) $existing ],
            [ '%d', '%s' ],
            [ '%d' ]
        );
        if ( $updated === false ) {
            return [ 'success' => false, 'error' => 'Your rating could not be saved. Please try again.' ];
        }
    } else {
        $inserted = $wpdb->insert(
            $reviewsTable,
            $data,
            [
                '%d',
                $userId > 0 ? '%d' : '%s',
                '%s',
                '%d',
                '%s',
            ]
        );
        if ($inserted === false) {
            return ['success' => false, 'error' => 'Your rating could not be saved. Please try again.'];
        }
    }

    $agg = $wpdb->get_row($wpdb->prepare(
        "SELECT AVG(rating) AS avg_rating, COUNT(*) AS cnt FROM %i WHERE video_id = %d",
        $reviewsTable, $videoId
    ));

    $average = $agg ? round((float) $agg->avg_rating, 2) : 0.0;
    $count = $agg ? (int) $agg->cnt : 0;

    $wpdb->update(vidcellar_videos_table(), [
        'rating'      => $average,
        'num_reviews' => $count,
    ], ['id' => $videoId], ['%f', '%d'], ['%d']);

    return [
        'success'      => true,
        'rating'       => $rating,
        'average'      => $average,
        'count'        => $count,
    ];
}

function vidcellar_rating_stars(float $rating, bool $showNumber = false): string
{
    $rating = max(0, min(5, $rating));
    $html = '<span class="vc-stars" aria-label="Rating ' . esc_attr(number_format($rating, 1)) . ' out of 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= $i <= round($rating) ? '★' : '☆';
    }
    $html .= '</span>';
    if ($showNumber) {
        $html .= ' <span class="vc-rating-number">' . esc_html(number_format($rating, 1)) . '/5</span>';
    }
    return $html;
}

/**
 * Count a video view once per viewer/browser within a 30-minute window.
 * This avoids inflating views because HTML5 playback commonly makes many
 * HTTP Range requests for the same video. The Most Watched ordering remains
 * server-calculated from this stored counter.
 */
function vidcellar_record_view(int $videoId): bool
{
    if ($videoId <= 0) return false;

    $cookieName = 'vidcellar_viewed_' . $videoId;
    $now = time();
    $last = isset($_COOKIE[$cookieName]) ? (int) $_COOKIE[$cookieName] : 0;

    if ($last > 0 && ($now - $last) < (30 * MINUTE_IN_SECONDS)) {
        return false;
    }

    setcookie($cookieName, (string) $now, [
        'expires'  => $now + (30 * MINUTE_IN_SECONDS),
        'path'     => COOKIEPATH ?: '/',
        'domain'   => COOKIE_DOMAIN ?: '',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    global $wpdb;
    $table = vidcellar_videos_table();
    $updated = $wpdb->query($wpdb->prepare(
        "UPDATE %i SET views = views + 1 WHERE id = %d",
        $table, $videoId
    ));
    return $updated !== false;
}

function vidcellar_increment_views(int $videoId): void
{
    // Backward-compatible wrapper. New streaming code uses vidcellar_record_view().
    vidcellar_record_view($videoId);
}

if ( ! function_exists( 'vidcellar_videos_table' ) ) {
    function vidcellar_videos_table() {
        global $wpdb;
        return $wpdb->prefix . 'vidcellar_videos';
    }
}

if ( ! function_exists( 'vidcellar_reviews_table' ) ) {
    function vidcellar_reviews_table() {
        global $wpdb;
        return $wpdb->prefix . 'vidcellar_reviews';
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
