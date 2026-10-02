<?php
/** VidCellar Lite shortcodes. GPLv2 or later. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class VidCellar_Shortcodes {
    public static function init(): void {
        add_shortcode( 'vidcellar_browse', [ __CLASS__, 'render_browse' ] );
        add_shortcode( 'vidcellar_video', [ __CLASS__, 'render_video' ] );
    }

public static function render_browse($showCategoryTabs = false): string
    {
        // As a shortcode callback this receives the attributes array (or ''), never a bool.
        $showCategoryTabs = true === $showCategoryTabs;
        vidcellar_enqueue_frontend_assets();
        $keyword = isset( $_GET['vc_q'] ) ? sanitize_text_field( wp_unslash( $_GET['vc_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $category = isset( $_GET['vc_category'] ) ? sanitize_text_field( wp_unslash( $_GET['vc_category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = isset( $_GET['vc_pg'] ) ? max( 1, absint( wp_unslash( $_GET['vc_pg'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        // Public catalog filter; no state change. Read the query parameter through
        // PHP's input filter so Plugin Check can see that it is validated before use.
        $mostWatchedInput = filter_input( INPUT_GET, 'vc_most_watched', FILTER_VALIDATE_INT );
        $mostWatched = 1 === $mostWatchedInput;

        // Keep the catalog reasonably dense while still paginating large libraries.
        // Most Watched is a fixed server-side sort and cannot be viewer-reordered.
        $result = $mostWatched
            ? vidcellar_list_most_watched_videos($keyword, $page, 24)
            : vidcellar_list_videos($keyword, $category, $page, 24);

        // The Watch-page heading is the title of the WordPress Page that
        // actually hosts this shortcode. We resolve the page from several
        // WordPress contexts because the shortcode can be rendered outside
        // the normal Loop (Gutenberg, templates, page builders, etc.).
        $watch_heading = 'Cinema Showcase';
        $watch_heading_page_id = 0;
        $watch_heading_source = 'fallback';
        if ( $showCategoryTabs ) {
            $candidates = [];

            // 1) The page WordPress says is currently being requested.
            $queried_id = absint( get_queried_object_id() );
            if ( $queried_id ) {
                $candidates[] = [ $queried_id, 'queried-object' ];
            }

            // 2) Resolve the actual front-end request URL to a Page ID.
            // This is useful when a theme/page builder changes the Loop's
            // global $post while the shortcode is being rendered.
            $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            if ( $request_uri ) {
                $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
                if ( is_string( $request_path ) && '' !== $request_path ) {
                    $request_url = home_url( $request_path );
                    $resolved_id = absint( url_to_postid( $request_url ) );
                    if ( $resolved_id ) {
                        $candidates[] = [ $resolved_id, 'request-url' ];
                    }
                }
            }

            // 3) The current global post, when available.
            global $post;
            if ( $post instanceof WP_Post ) {
                $candidates[] = [ absint( $post->ID ), 'global-post' ];
            }

            // 4) The configured Watch page is the final page-based
            // fallback and is also the page used to build video URLs.
            $configured_id = absint( get_option( 'vidcellar_watch_page_id', 0 ) );
            if ( $configured_id ) {
                $candidates[] = [ $configured_id, 'configured-watch-page' ];
            }

            $seen = [];
            foreach ( $candidates as $candidate ) {
                $candidate_id = absint( $candidate[0] );
                $source = (string) $candidate[1];
                if ( ! $candidate_id || isset( $seen[ $candidate_id ] ) ) {
                    continue;
                }
                $seen[ $candidate_id ] = true;

                $candidate_post = get_post( $candidate_id );
                if ( ! ( $candidate_post instanceof WP_Post ) || 'page' !== $candidate_post->post_type || 'publish' !== $candidate_post->post_status ) {
                    continue;
                }

                $candidate_title = wp_strip_all_tags( (string) $candidate_post->post_title );
                if ( '' === trim( $candidate_title ) ) {
                    continue;
                }

                // Do not let a loop/template post title replace the Watch page
                // title unless it is the queried/request/configured page.
                if ( 'global-post' === $source && $candidate_id !== $queried_id && $candidate_id !== $configured_id ) {
                    continue;
                }

                $watch_heading = sanitize_text_field( $candidate_title );
                $watch_heading_page_id = $candidate_id;
                $watch_heading_source = $source;
                break;
            }
        }

        ob_start(); ?>
        <div class="vidcellar vidcellar-browse" data-vc-heading-page-id="<?php echo  (int) $watch_heading_page_id ?>" data-vc-heading-source="<?php echo  esc_attr( $watch_heading_source ) ?>">
            <h1 class="vc-browse-title"><?php echo  esc_html( $showCategoryTabs ? $watch_heading : 'Videos' ) ?></h1>
            <p class="vc-browse-intro"><?php echo  $showCategoryTabs ? 'Browse our video categories and discover something to watch.' : 'Choose a video to watch. All videos in VidCellar Lite are available to watch.' ?></p>
            <?php if ($showCategoryTabs): ?>
                <?php echo wp_kses_post( self::render_category_tabs( $category, $mostWatched ) ); ?>
            <?php endif; ?>
            <form method="get" class="vc-search">
                <?php if ($mostWatched): ?>
                    <input type="hidden" name="vc_most_watched" value="1">
                <?php elseif ($category !== ''): ?>
                    <input type="hidden" name="vc_category" value="<?php echo  esc_attr(vidcellar_normalize_video_category($category)) ?>">
                <?php endif; ?>
                <input type="text" name="vc_q" value="<?php echo  esc_attr($keyword) ?>" placeholder="Search videos…">
                <button type="submit">Search</button>
            </form>

            <div class="vc-grid">
                <?php if (empty($result['videos'])): ?>
                    <p>No videos found.</p>
                <?php endif; ?>
                <?php foreach ($result['videos'] as $v): ?>
                    <a class="vc-card" href="<?php echo  esc_url(vidcellar_video_url((int) $v['id'])) ?>">
                        <?php if ($v['thumbnail']): ?>
                            <img src="<?php echo  esc_url(vidcellar_thumbnail_url($v['thumbnail'])) ?>" alt="<?php echo  esc_attr($v['title']) ?>">
                        <?php endif; ?>
                        <div class="vc-card-title"><?php echo  esc_html($v['title']) ?></div>
                        <?php if (!empty($v['category'])): ?><div class="vc-card-category"><?php echo  esc_html(vidcellar_video_category_label($v['category'])) ?></div><?php endif; ?>
                        <div class="vc-card-stats">
                            <span>👁 <?php echo  esc_html(number_format((int) $v['views'])) ?></span>
                            <?php echo wp_kses_post( vidcellar_rating_stars( (float) $v['rating'] ) ); ?>
                            <span>(<?php echo  (int) $v['num_reviews'] ?>)</span>
                        </div>
                        <div class="vc-card-price">Free</div>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ($result['pages'] > 1): ?>
                <div class="vc-pagination">
                    <?php for ($i = 1; $i <= $result['pages']; $i++): ?>
                        <a class="<?php echo esc_attr( $i === $page ? 'active' : '' ); ?>" href="<?php echo  esc_url(add_query_arg('vc_pg', $i)) ?>"><?php echo esc_html( (string) $i ); ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function render_video(): string {
        vidcellar_enqueue_frontend_assets();
        $video_id = isset( $_GET['vc_id'] ) ? absint( wp_unslash( $_GET['vc_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! $video_id ) { return self::render_browse( true ); }
        $video = vidcellar_get_video( $video_id );
        if ( ! $video ) { return self::render_browse( true ); }
        $guest_token = wp_generate_password( 48, false, false );
        $current_rating = vidcellar_get_current_rating( $video_id, is_user_logged_in() ? get_current_user_id() : 0, is_user_logged_in() ? '' : $guest_token );
        $trailer_url = vidcellar_trailer_url( $video );
        ob_start(); ?>
        <div class="vidcellar vidcellar-video" data-price="0.00" data-video-id="<?php echo  (int) $video_id ?>" data-guest-token="<?php echo  esc_attr( $guest_token ) ?>">
            <?php echo wp_kses_post( self::render_category_tabs( (string) $video['category'] ) ); ?>
            <h1><?php echo  esc_html( $video['title'] ) ?></h1>
            <p><?php echo  nl2br( esc_html( $video['description'] ) ) ?></p>
            <?php if ( $trailer_url ) : ?>
                <p><strong>Trailer</strong></p>
                <video class="vc-video-element" preload="metadata" controls playsinline poster="<?php echo  esc_url( vidcellar_thumbnail_url( $video['thumbnail'] ) ) ?>"><source src="<?php echo  esc_url( $trailer_url ) ?>"></video>
            <?php endif; ?>
            <section class="vc-full-video-section">
                <h2>Full Video</h2>
                <div class="vc-owned">✓ Free video — watch now</div>
                <div class="vc-player" data-video-id="<?php echo  (int) $video_id ?>" data-advertisements="[]" data-ad-interval="0">
                    <video class="vc-video-element" preload="auto" playsinline controlsList="nodownload" poster="<?php echo  esc_url( vidcellar_thumbnail_url( $video['thumbnail'] ) ) ?>"><source src="<?php echo  esc_url( vidcellar_stream_url( $video_id ) ) ?>"></video>
                    <div class="vc-player-loading" aria-hidden="true"><span class="vc-spinner"></span></div>
                    <button type="button" class="vc-center-play" aria-label="Play video"><span class="vc-icon vc-icon-play" aria-hidden="true">▶</span></button>
                    <div class="vc-player-error" role="alert" hidden>Unable to play this video. Please refresh the page and try again.</div>
                    <div class="vc-controls" aria-label="Video controls">
                        <div class="vc-progress-wrap"><div class="vc-buffered" aria-hidden="true"></div><input class="vc-progress" type="range" min="0" max="1000" value="0" step="1" aria-label="Video progress"></div>
                        <div class="vc-controls-row"><button type="button" class="vc-control vc-play" aria-label="Play"><span class="vc-control-icon" aria-hidden="true">▶</span></button><div class="vc-volume-group"><button type="button" class="vc-control vc-volume" aria-label="Mute"><span class="vc-control-icon" aria-hidden="true">🔊</span></button><input class="vc-volume-slider" type="range" min="0" max="100" value="100" step="1" aria-label="Volume"></div><span class="vc-time"><span class="vc-current-time">0:00</span> / <span class="vc-duration">0:00</span></span><span class="vc-spacer"></span><button type="button" class="vc-control vc-settings" aria-label="Player settings" aria-expanded="false"><span class="vc-control-icon" aria-hidden="true">⚙</span></button><button type="button" class="vc-control vc-fullscreen" aria-label="Fullscreen"><span class="vc-control-icon" aria-hidden="true">⛶</span></button></div>
                    </div>
                </div>
                <div class="vc-video-stats-row">
                    <section class="vc-video-rating" data-video-id="<?php echo  (int) $video_id ?>" data-guest-token="<?php echo  esc_attr( $guest_token ) ?>">
                        <div class="vc-rating-summary"><?php echo wp_kses_post( vidcellar_rating_stars( (float) $video['rating'] ) ); ?><span class="vc-rating-number"><?php echo  esc_html( number_format( (float) $video['rating'], 1 ) ) ?>/5</span><span class="vc-rating-count"><?php echo esc_html( (string) (int) $video['num_reviews'] ); ?> rating<?php echo  1 === (int) $video['num_reviews'] ? '' : 's' ?></span></div>
                        <div class="vc-rating-prompt">Rate this video</div>
                        <div class="vc-rating-input" role="radiogroup" aria-label="Your rating"><?php for ( $star = 1; $star <= 5; $star++ ) : ?><button type="button" class="vc-rating-star <?php echo esc_attr( $star <= $current_rating ? 'selected' : '' ); ?>" data-rating="<?php echo esc_attr( (string) $star ); ?>" aria-label="<?php echo esc_attr( (string) $star ); ?> star<?php echo esc_attr( 1 === $star ? '' : 's' ); ?>" aria-pressed="<?php echo esc_attr( $star === $current_rating ? 'true' : 'false' ); ?>"><?php echo esc_html( $star <= $current_rating ? '★' : '☆' ); ?></button><?php endfor; ?></div>
                        <div class="vc-rating-message" role="status" aria-live="polite"></div>
                    </section>
                    <div class="vc-video-views" aria-label="Video views"><span class="vc-video-views-icon" aria-hidden="true">👁</span><span><?php echo  esc_html( number_format( (int) $video['views'] ) ) ?> Views</span></div>
                </div>
            </section>
        </div>
        <?php return (string) ob_get_clean();
    }


private static function render_category_tabs(string $activeCategory = '', bool $mostWatched = false): string
    {
        $categories = vidcellar_video_categories();
        $activeCategory = vidcellar_normalize_video_category($activeCategory);
        $baseUrl = remove_query_arg(['vc_pg', 'vc_id', 'vc_category', 'vc_most_watched'], get_permalink());

        ob_start(); ?>
        <nav class="vc-category-tabs" aria-label="Film categories">
            <a class="vc-category-tab vc-most-watched-tab <?php echo  $mostWatched ? 'active' : '' ?>" href="<?php echo  esc_url(add_query_arg('vc_most_watched', '1', $baseUrl)) ?>">Most Watched</a>
            <a class="vc-category-tab <?php echo  (!$mostWatched && $activeCategory === '') ? 'active' : '' ?>" href="<?php echo  esc_url($baseUrl) ?>">All Films</a>
            <?php foreach ($categories as $category): ?>
                <a class="vc-category-tab <?php echo  (!$mostWatched && $activeCategory === vidcellar_normalize_video_category($category)) ? 'active' : '' ?>" href="<?php echo  esc_url(add_query_arg('vc_category', $category, $baseUrl)) ?>">
                    <?php echo  esc_html($category) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <?php
        return (string) ob_get_clean();
    }
}
