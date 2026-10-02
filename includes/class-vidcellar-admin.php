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
class VidCellar_Admin {
    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
        add_action( 'admin_post_vidcellar_export_videos_csv', [ __CLASS__, 'export_videos_csv' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( VIDCELLAR_PLUGIN_FILE ), [ __CLASS__, 'plugin_action_links' ] );
    }
    public static function enqueue_scripts( string $hook ): void {
        if ( 'toplevel_page_vidcellar' === $hook ) {
            wp_enqueue_style(
                'vidcellar-admin-pricing',
                VIDCELLAR_PLUGIN_URL . 'assets/css/admin-pricing.css',
                [],
                VIDCELLAR_VERSION
            );
        }

        $video_screens = [ 'vidcellar_page_vidcellar-videos' ];
        if ( ! in_array( $hook, $video_screens, true ) ) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script(
            'vidcellar-video-upload',
            VIDCELLAR_PLUGIN_URL . 'assets/js/admin-upload.js',
            [],
            VIDCELLAR_VERSION,
            true
        );
        wp_localize_script(
            'vidcellar-video-upload',
            'vidcellarUpload',
            [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( VidCellar_Chunk_Upload::NONCE_ACTION ),
                'chunkSize'   => VidCellar_Chunk_Upload::chunk_size_bytes(),
                'maxFileSize' => VidCellar_Chunk_Upload::max_file_size_bytes(),
            ]
        );
        wp_enqueue_script(
            'vidcellar-admin-videos',
            VIDCELLAR_PLUGIN_URL . 'assets/js/admin-videos.js',
            [ 'jquery' ],
            VIDCELLAR_VERSION,
            true
        );
    }
    public static function register_menu(): void {
        add_menu_page( 'VidCellar', 'VidCellar', 'manage_options', 'vidcellar', [ __CLASS__, 'render_dashboard' ], 'dashicons-video-alt3', 26 );
        add_submenu_page( 'vidcellar', 'Dashboard', 'Dashboard', 'manage_options', 'vidcellar', [ __CLASS__, 'render_dashboard' ] );
        add_submenu_page( 'vidcellar', 'Videos', 'Videos', 'manage_options', 'vidcellar-videos', [ __CLASS__, 'render_videos' ] );
        add_submenu_page( 'vidcellar', 'Categories', 'Categories', 'manage_options', 'vidcellar-categories', [ __CLASS__, 'render_categories' ] );
        if ( ! defined( 'VIDCELLAR_PRO_ACTIVE' ) || ! VIDCELLAR_PRO_ACTIVE ) {
            add_submenu_page( 'vidcellar', 'Settings', 'Settings', 'manage_options', 'vidcellar-settings', [ __CLASS__, 'render_settings' ] );
        }
        add_submenu_page( 'vidcellar', 'Need Help?', 'Need Help?', 'manage_options', 'vidcellar-help', [ __CLASS__, 'render_help' ] );
    }
    /**
     * Add familiar WordPress plugin action links: Settings | Need Help? | Upgrade PRO | Deactivate.
     * WordPress supplies the secure Deactivate link; we preserve it and place it last.
     */
    public static function plugin_action_links( array $links ): array {
        $deactivate = '';
        foreach ( $links as $key => $link ) {
            if ( strpos( $link, 'action=deactivate' ) !== false ) {
                $deactivate = $link;
                unset( $links[ $key ] );
                break;
            }
        }

        $custom = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=vidcellar-settings' ) ) . '">Settings</a>',
            '<a href="' . esc_url( admin_url( 'admin.php?page=vidcellar-help' ) ) . '">Need Help?</a>',
            '<a href="' . esc_url( 'https://license.bdc-tv.com/' ) . '" target="_blank" rel="noopener noreferrer">Upgrade PRO</a>',
        ];

        if ( $deactivate !== '' ) {
            $custom[] = $deactivate;
        }

        return array_merge( $custom, $links );
    }

    /**
     * Optional Pro plans shown in wp-admin only. Purchase happens on the
     * separate license site; Lite remains fully usable without a purchase.
     */
    public static function pro_plans(): array {
        return [
            'pro_personal'  => [
                'name'          => 'Pro Personal',
                'price_usd'     => 59,
                'sites_allowed' => 1,
            ],
            'pro_business'  => [
                'name'          => 'Pro Business',
                'price_usd'     => 79,
                'sites_allowed' => 5,
            ],
            'pro_agency'    => [
                'name'          => 'Pro Agency',
                'price_usd'     => 129,
                'sites_allowed' => 25,
            ],
            'pro_unlimited' => [
                'name'          => 'Pro Unlimited',
                'price_usd'     => 199,
                'sites_allowed' => 100,
            ],
        ];
    }

    public static function render_pricing_table(): void {
        $plans = self::pro_plans();
        ?>
        <div class="card" style="max-width:1100px;padding:20px;margin:20px 0;">
            <div class="vidcellar-pricing-table">
                <h2 style="margin-top:0;">Licensing</h2>
                <p>Choose a VidCellar Pro annual plan. Checkout and license-key delivery are handled on the VidCellar license site. VidCellar Lite remains fully usable without a Pro license.</p>
                <div class="vidcellar-pricing-grid">
                    <?php foreach ( $plans as $code => $plan ) : ?>
                        <?php
                        $sites = (int) $plan['sites_allowed'];
                        $site_label = 1 === $sites ? '1 site included' : sprintf( '%d sites included', $sites );
                        $purchase_url = add_query_arg( 'plan', $code, 'https://license.bdc-tv.com/' );
                        ?>
                        <div class="vidcellar-pricing-card">
                            <h3><?php echo esc_html( (string) $plan['name'] ); ?></h3>
                            <p class="vidcellar-pricing-price">
                                $<?php echo esc_html( number_format_i18n( (float) $plan['price_usd'], 0 ) ); ?>
                                <span>/ year</span>
                            </p>
                            <p class="vidcellar-pricing-meta"><?php echo esc_html( $site_label ); ?></p>
                            <p>
                                <a class="button button-primary" href="<?php echo esc_url( $purchase_url ); ?>" target="_blank" rel="noopener noreferrer">
                                    Purchase <?php echo esc_html( (string) $plan['name'] ); ?>
                                </a>
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php
    }

    public static function lite_vs_pro_rows(): array {
        return [
            [ 'Video Upload & Management', true, true ],
            [ 'Video Library & Categories', true, true ],
            [ 'Video Streaming / Playback', true, true ],
            [ 'Public Video Access', true, true ],
            [ 'Basic Content Protection', true, true ],
            [ 'Local Server Storage', true, true ],
            [ 'Cloud Storage', false, true ],
            [ 'Guest Checkout & Paid Videos', false, true ],
            [ 'Payment Integrations', false, true ],
            [ 'Stripe & PayPal', false, true ],
            [ 'Paid Content Access Control', false, true ],
            [ 'Complimentary Coupons', false, true ],
            [ 'Sales Reports', false, true ],
            [ 'Advertisement Insertions', false, true ],
            [ 'Advanced Analytics', false, true ],
            [ 'Priority Support', false, true ],
        ];
    }

    public static function render_lite_vs_pro_table(): void {
        ?>
        <div class="card" style="max-width:1100px;padding:20px;margin:20px 0;">
            <h2 style="margin-top:0;">VidCellar Lite vs Pro</h2>
            <p>VidCellar Lite is fully usable on its own. Pro adds monetization, cloud storage, advertising, and related premium features.</p>
            <table class="widefat striped" style="max-width:760px;">
                <thead>
                    <tr>
                        <th>Feature</th>
                        <th>VidCellar Lite</th>
                        <th>VidCellar Pro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( self::lite_vs_pro_rows() as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row[0] ); ?></td>
                            <td><?php echo $row[1] ? '<span style="color:#16833b;font-weight:700;">Yes</span>' : '<span style="color:#8c8f94;">—</span>'; ?></td>
                            <td><?php echo $row[2] ? '<span style="color:#16833b;font-weight:700;">Yes</span>' : '<span style="color:#8c8f94;">—</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:16px;">
                <a class="button button-primary" href="https://license.bdc-tv.com/" target="_blank" rel="noopener noreferrer">Upgrade to PRO</a>
            </p>
        </div>
        <?php
    }

    public static function render_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
        $settings_notice = '';
        if ( isset( $_POST['vidcellar_save_settings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately below.
            check_admin_referer( 'vidcellar_save_settings' );
            $delete_data = isset( $_POST['vidcellar_delete_data_on_uninstall'] ) ? '1' : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Presence-only checkbox after nonce verification.
            update_option( 'vidcellar_delete_data_on_uninstall', $delete_data );
            $settings_notice = 'Settings saved.';
        }
        ?>
        <div class="wrap">
            <h1>VidCellar Lite Settings</h1>
            <p>Configure and manage your VidCellar Lite video library.</p>
            <?php if ( ! empty( $settings_notice ) ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $settings_notice ); ?></p></div>
            <?php endif; ?>
            <div class="card" style="max-width:900px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:20px;">
                <h2>Video Library</h2>
                <p>Manage videos and upload new content from <strong>VidCellar → Videos</strong>. Manage your public video categories from <strong>VidCellar → Categories</strong>.</p>
                <p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vidcellar-videos' ) ); ?>">Manage Videos</a>
                <a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=vidcellar-categories' ) ); ?>">Manage Categories</a></p>
            </div>
            <div class="card" style="max-width:900px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:20px;">
                <h2>Data on uninstall</h2>
                <form method="post">
                    <?php wp_nonce_field( 'vidcellar_save_settings' ); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="vidcellar_delete_data_on_uninstall" value="1" <?php checked( get_option( 'vidcellar_delete_data_on_uninstall' ), '1' ); ?>>
                            Permanently delete plugin database tables and uploaded videos when this plugin is uninstalled.
                        </label>
                    </p>
                    <p class="description">Leave this unchecked to keep your video library if you later reinstall the plugin.</p>
                    <?php submit_button( 'Save Settings', 'secondary', 'vidcellar_save_settings' ); ?>
                </form>
            </div>
            <div class="card" style="max-width:900px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:20px;">
                <h2>Pro add-on</h2>
                <p>An optional Pro add-on is available separately to add paid video access, Stripe/PayPal monetization, coupons, advertising controls, and other premium features.</p>
                <p><a class="button button-primary" href="https://license.bdc-tv.com/" target="_blank" rel="noopener noreferrer">Upgrade to PRO</a></p>
            </div>
        </div>
        <?php
    }

    public static function render_help(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden' ); }
        ?>
        <div class="wrap">
            <h1>Need Help?</h1>
            <div class="card" style="max-width:900px;background:#fff;border:1px solid #dcdcde;padding:20px;margin-top:20px;">
                <h2>VidCellar Lite</h2>
                <p>Use the VidCellar menu to manage videos and categories. Large video uploads support resumable chunked uploading when JavaScript is enabled.</p>
                <h3>Need the Pro add-on?</h3>
                <p>Visit the licensing site for Pro information and purchasing.</p>
                <p><a class="button button-primary" href="https://license.bdc-tv.com/" target="_blank" rel="noopener noreferrer">Upgrade PRO</a></p>
            </div>
            <?php self::render_lite_vs_pro_table(); ?>
        </div>
        <?php
    }

    public static function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Forbidden' );
        }
        ?>
        <div class="wrap">
            <h1>VidCellar Dashboard</h1>
            <?php self::render_pricing_table(); ?>
            <?php self::render_lite_vs_pro_table(); ?>
            <?php do_action( 'vidcellar_lite_dashboard' ); ?>
        </div>
        <?php
    }

    public static function render_videos(): void
        {
            if (!current_user_can('manage_options')) wp_die('Forbidden');
    
            $notice = self::handle_video_form();
    
            $videos = vidcellar_list_videos('', '', 1, 200)['videos'];
            ?>
            <div class="wrap">
                <h1>Videos</h1>
                <?php if ($notice): ?>
                    <div class="notice notice-<?php echo  esc_attr($notice['type']) ?>">
                        <p>
                            <?php echo  esc_html($notice['message']) ?>
                            <?php if (!empty($notice['video_id'])): ?>
                                <a href="<?php echo  esc_url(vidcellar_video_url((int) $notice['video_id'])) ?>" target="_blank">Watch it now</a> (ID <?php echo  (int) $notice['video_id'] ?>)
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>
    
                <h2>Add a video</h2>
                <form method="post" enctype="multipart/form-data" id="vc-add-video-form">
                    <?php wp_nonce_field('vidcellar_add_video'); ?>
                    <input type="hidden" name="vc_form_action" value="add_video">
                    <input type="hidden" id="vc_chunked_video_key" name="chunked_video_key" value="">
                    <input type="hidden" id="vc_chunked_video_name" name="chunked_video_name" value="">
                    <table class="form-table">
                        <tr><th><label for="vc_title">Title</label></th><td><input type="text" id="vc_title" name="title" class="regular-text" required></td></tr>
                        <tr><th><label for="vc_description">Description</label></th><td><textarea id="vc_description" name="description" rows="4" class="large-text"></textarea></td></tr>
                        <tr><th>Access</th><td><strong>Free</strong><p class="description">VidCellar Lite provides free video playback. Paid access is provided by the optional Pro add-on.</p></td></tr>
                        <tr><th><label for="vc_category">Category</label></th><td>
                            <select id="vc_category" name="category" required>
                                <option value="">Select a category</option>
                                <?php foreach (vidcellar_video_categories() as $videoCategory): ?>
                                    <option value="<?php echo  esc_attr($videoCategory) ?>"><?php echo  esc_html($videoCategory) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty(vidcellar_video_categories())): ?>
                                <p class="description"><strong>There are no categories yet.</strong> <a href="<?php echo  esc_url(admin_url('admin.php?page=vidcellar-categories')) ?>">Add a category</a> before adding a video.</p>
                            <?php endif; ?>
                            <p class="description">Categories are managed under <a href="<?php echo  esc_url(admin_url('admin.php?page=vidcellar-categories')) ?>">VidCellar → Categories</a>.</p>
                        </td></tr>
                        <tr><th><label for="vc_trailer_attachment_id">Trailer</label></th><td>
                            <input type="hidden" id="vc_trailer_attachment_id" name="trailer_attachment_id" value="">
                            <button type="button" class="button" id="vc-select-trailer">Select Trailer Video</button>
                            <button type="button" class="button" id="vc-remove-trailer" style="display:none;margin-left:6px;">Remove</button>
                            <span id="vc-trailer-selected" style="margin-left:10px;font-weight:600;"></span>
                            <p class="description">Select a promotional trailer from the WordPress Media Library. It will appear publicly above the protected Full Video on the Watch page.</p>
                        </td></tr>
                        <tr><th><label for="vc_video_file">Video file</label></th>
                            <td>
                                <input type="file" id="vc_video_file" name="video_file" accept="video/*" required>
                                <p class="description">
                                    Videos up to 30 GB are uploaded in small resumable chunks automatically sized below this server's PHP request limit. Uploads automatically retry transient failures and can resume after a page refresh when you select the same file again. The final video is assembled server-side in private storage.
                                    If JavaScript is unavailable, this falls back to a single upload capped at <?php echo  esc_html(size_format(wp_max_upload_size())) ?> by this server's <code>upload_max_filesize</code> / <code>post_max_size</code>.
                                </p>
                                <div id="vc-video-upload-progress" style="display:none;max-width:520px;margin-top:8px;">
                                    <div style="background:#dcdcde;border-radius:4px;height:10px;overflow:hidden;">
                                        <div id="vc-video-upload-progress-bar" style="background:#2271b1;height:100%;width:0;transition:width .2s;"></div>
                                    </div>
                                    <p id="vc-video-upload-progress-label" style="margin:4px 0 0;font-size:12px;"></p>
                                    <p id="vc-video-upload-progress-detail" style="margin:2px 0 0;font-size:12px;color:#646970;"></p>
                                    <button type="button" id="vc-video-upload-cancel" class="button" style="display:none;margin-top:6px;">Cancel upload</button>
                                </div>
                            </td></tr>
                        <tr><th><label for="vc_thumbnail_file">Thumbnail</label></th><td><input type="file" id="vc_thumbnail_file" name="thumbnail_file" accept="image/*"></td></tr>
                    </table>
                    <?php submit_button('Add Video', 'primary', 'vidcellar_add_video'); ?>
                </form>
    
                <h2>Existing videos</h2>
                <p>Download a CSV report containing the current video catalog, including titles, categories, views, ratings, streaming status, and creation dates.</p>
                <p>
                    <a class="button button-primary" href="<?php echo  esc_url(wp_nonce_url(admin_url('admin-post.php?action=vidcellar_export_videos_csv'), 'vidcellar_export_videos_csv')) ?>">Download Existing Videos (CSV)</a>
                </p>
                <table class="widefat striped">
                    <thead><tr><th>ID</th><th>Title</th><th>Category</th><th>Views</th><th>Rating</th><th>Streaming</th><th></th></tr></thead>
                    <tbody>
                    <?php if (empty($videos)): ?>
                        <tr><td colspan="9">No videos yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($videos as $v): ?>
                        <tr>
                            <td><?php echo  (int) $v['id'] ?></td>
                            <td>
                                <a href="<?php echo  esc_url(vidcellar_video_url((int) $v['id'])) ?>" target="_blank"><?php echo  esc_html($v['title']) ?></a>
                                <button type="button" class="button button-small vc-toggle-edit" style="margin-left:8px;" aria-expanded="false">Edit</button>
                                <div class="vc-edit-panel" style="display:none;margin-top:12px;padding:12px;border:1px solid #dcdcde;background:#fff;max-width:760px;">
                                    <form method="post" enctype="multipart/form-data">
                                        <?php wp_nonce_field('vidcellar_edit_video_' . $v['id']); ?>
                                        <input type="hidden" name="video_id" value="<?php echo  (int) $v['id'] ?>">
                                        <table class="form-table" style="margin:0;">
                                            <tr><th style="padding-left:0;"><label>Title</label></th><td><input type="text" name="title" class="regular-text" maxlength="200" value="<?php echo  esc_attr($v['title']) ?>" required></td></tr>
                                            <tr><th style="padding-left:0;"><label>Description</label></th><td><textarea name="description" rows="5" class="large-text"><?php echo  esc_textarea($v['description']) ?></textarea></td></tr>
                                            <tr><th style="padding-left:0;">Access</th><td><strong>Free</strong></td></tr>
                                            <tr><th style="padding-left:0;"><label>Category</label></th><td><select name="category" required>
                                                <?php foreach (vidcellar_video_categories() as $videoCategory): ?>
                                                    <option value="<?php echo  esc_attr($videoCategory) ?>" <?php echo  vidcellar_normalize_video_category((string) $v['category']) === vidcellar_normalize_video_category($videoCategory) ? 'selected' : '' ?>><?php echo  esc_html($videoCategory) ?></option>
                                                <?php endforeach; ?>
                                            </select></td></tr>
                                            <?php $editTrailerId = absint($v['trailer_attachment_id'] ?? 0); ?>
                                            <tr><th style="padding-left:0;"><label>Trailer</label></th><td>
                                                <input type="hidden" class="vc-edit-trailer-id" name="trailer_attachment_id" value="<?php echo esc_attr( (string) $editTrailerId ); ?>">
                                                <button type="button" class="button vc-select-edit-trailer">Change Trailer</button>
                                                <button type="button" class="button vc-clear-edit-trailer" <?php echo esc_attr( $editTrailerId ? '' : 'style="display:none;"' ); ?>>Clear</button>
                                                <span class="vc-edit-trailer-label" style="margin-left:8px;"><?php echo esc_html( $editTrailerId ? ( get_the_title( $editTrailerId ) ?: ( 'Video #' . $editTrailerId ) ) : 'No trailer selected' ); ?></span>
                                            </td></tr>
                                            <tr><th style="padding-left:0;"><label>Thumbnail</label></th><td>
                                                <?php if (!empty($v['thumbnail'])): ?><img src="<?php echo  esc_url(vidcellar_thumbnail_url($v['thumbnail'])) ?>" alt="" style="display:block;max-width:180px;max-height:100px;object-fit:contain;margin-bottom:8px;"><?php endif; ?>
                                                <input type="file" name="thumbnail_file" accept="image/*">
                                                <p class="description">Leave empty to keep the current thumbnail.</p>
                                            </td></tr>
                                        </table>
                                        <p style="margin:12px 0 0;"><button type="submit" name="vidcellar_edit_video" value="1" class="button button-primary">Save Changes</button> <button type="button" class="button vc-cancel-edit">Cancel</button></p>
                                    </form>
                                </div>
                                <?php if (empty($v['video_filename'])): ?>
                                    <br><small style="color:#b32d2e;">No video file attached</small>
                                <?php endif; ?>
                                <?php $trailerId = absint($v['trailer_attachment_id'] ?? 0); $trailerUrl = vidcellar_trailer_url($v); ?>
                                <div style="margin-top:8px;">
                                    <?php if ($trailerUrl): ?><a href="<?php echo  esc_url($trailerUrl) ?>" target="_blank" rel="noopener">View trailer</a> · <?php endif; ?>
                                    <form method="post" class="vc-trailer-form" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
                                        <?php wp_nonce_field('vidcellar_update_video_trailer_' . $v['id']); ?>
                                        <input type="hidden" name="video_id" value="<?php echo  (int) $v['id'] ?>">
                                        <input type="hidden" class="vc-trailer-id" name="trailer_attachment_id" value="<?php echo esc_attr( (string) $trailerId ); ?>">
                                        <button type="button" class="button button-small vc-select-existing-trailer" data-label="vc-trailer-label-<?php echo  (int) $v['id'] ?>"><?php echo  $trailerId ? 'Change Trailer' : 'Add Trailer' ?></button>
                                        <span id="vc-trailer-label-<?php echo  (int) $v['id'] ?>" style="font-size:11px;"><?php echo esc_html( $trailerId ? ( get_the_title( $trailerId ) ?: ( 'Video #' . $trailerId ) ) : '' ); ?></span>
                                        <button type="submit" name="vidcellar_update_video_trailer" class="button button-small" value="1">Save</button>
                                        <?php if ($trailerId): ?><button type="button" class="button button-small vc-clear-existing-trailer">Clear</button><?php endif; ?>
                                    </form>
                                </div>
                            </td>
                            <td>
                                <form method="post" style="display:flex;align-items:center;gap:6px;">
                                    <?php wp_nonce_field('vidcellar_update_video_category_' . $v['id']); ?>
                                    <input type="hidden" name="video_id" value="<?php echo  (int) $v['id'] ?>">
                                    <select name="category" aria-label="Category for <?php echo  esc_attr($v['title']) ?>" required>
                                        <option value="" <?php echo  vidcellar_normalize_video_category((string) $v['category']) === '' ? 'selected' : '' ?>>Select category</option>
                                        <?php foreach (vidcellar_video_categories() as $videoCategory): ?>
                                            <option value="<?php echo  esc_attr($videoCategory) ?>" <?php echo  vidcellar_normalize_video_category((string) $v['category']) === vidcellar_normalize_video_category($videoCategory) ? 'selected' : '' ?>><?php echo  esc_html($videoCategory) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php submit_button('Save', 'secondary small', 'vidcellar_update_video_category', false); ?>
                                </form>
                            </td>
                            <td><?php echo  (int) $v['views'] ?></td>
                            <td><?php echo  esc_html($v['rating']) ?> (<?php echo  (int) $v['num_reviews'] ?>)</td>
                            <td>
                                <?php if (!empty($v['streaming_filename']) && ($v['streaming_status'] ?? '') === 'optimized'): ?>
                                    <span style="color:#157347;font-weight:600;">✓ Optimized</span>
                                <?php elseif (($v['streaming_status'] ?? '') === 'processing'): ?>
                                    <span style="color:#996800;">Processing…</span>
                                <?php elseif (($v['streaming_status'] ?? '') === 'queued'): ?>
                                    <span style="color:#996800;">Queued</span>
                                <?php elseif (($v['streaming_status'] ?? '') === 'unavailable'): ?>
                                    <span style="color:#b32d2e;">FFmpeg unavailable</span>
                                <?php elseif (($v['streaming_status'] ?? '') === 'error'): ?>
                                    <span style="color:#b32d2e;">Error</span>
                                <?php else: ?>
                                    <span style="color:#666;">Not optimized</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($v['streaming_filename']) && ($v['streaming_status'] ?? '') === 'optimized'): ?>
                                <?php else: ?>
                                    <form method="post" style="display:inline;margin-right:6px;">
                                        <?php wp_nonce_field('vidcellar_optimize_video_' . $v['id']); ?>
                                        <input type="hidden" name="video_id" value="<?php echo  (int) $v['id'] ?>">
                                        <?php submit_button(($v['streaming_status'] ?? '') === 'processing' ? 'Processing…' : 'Optimize for streaming', 'secondary small', 'vidcellar_optimize_video', false); ?>
                                    </form>
                                <?php endif; ?>
                                <form method="post" class="vc-delete-video-form" style="display:inline;">
                                    <?php wp_nonce_field('vidcellar_delete_video_' . $v['id']); ?>
                                    <input type="hidden" name="video_id" value="<?php echo  (int) $v['id'] ?>">
                                    <?php submit_button('Delete', 'delete small', 'vidcellar_delete_video', false); ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

    public static function render_categories(): void
        {
            if (!current_user_can('manage_options')) wp_die('Forbidden');
    
            $notice = null;
    
            if (self::has_post_flag('vidcellar_add_category')) {
                check_admin_referer('vidcellar_add_category');
    
                $result = vidcellar_add_video_category((string) (self::post_value('category_name')));
                $notice = [
                    'type' => $result['success'] ? 'success' : 'error',
                    'message' => $result['success']
                        ? 'Category added successfully. It is now available on the Watch page and in the video category selector.'
                        : (string) ($result['error'] ?? 'Could not add the category.'),
                ];
            }
    
            if (self::has_post_flag('vidcellar_rename_category')) {
                check_admin_referer('vidcellar_rename_category');

                $result = vidcellar_rename_video_category((string) self::post_value('category_slug'), (string) self::post_value('category_name'));
                $notice = [
                    'type' => $result['success'] ? 'success' : 'error',
                    'message' => $result['success']
                        ? ($result['old'] === $result['category']
                            ? 'No changes to save.'
                            : sprintf('Category "%s" renamed to "%s".', $result['old'], $result['category']))
                        : (string) ($result['error'] ?? 'Could not rename the category.'),
                ];
            }

            if (self::has_post_flag('vidcellar_delete_category')) {
                check_admin_referer('vidcellar_delete_category');

                $result = vidcellar_delete_video_category((string) (self::post_value('category_slug')));
                if ($result['success']) {
                    $message = sprintf('Category "%s" deleted.', $result['category']);
                    if ($result['moved'] > 0) {
                        $message .= '' === $result['target']
                            ? sprintf(' %d %s now uncategorized. Add a category to assign %s again.', $result['moved'], 1 === $result['moved'] ? 'video is' : 'videos are', 1 === $result['moved'] ? 'it' : 'them')
                            : sprintf(' %d %s moved to "%s".', $result['moved'], 1 === $result['moved'] ? 'video was' : 'videos were', $result['target']);
                    }
                }
                $notice = [
                    'type' => $result['success'] ? 'success' : 'error',
                    'message' => $result['success'] ? $message : (string) ($result['error'] ?? 'Could not delete the category.'),
                ];
            }

            $categories = vidcellar_video_categories();
            $counts = vidcellar_video_category_counts();
            ?>
            <div class="wrap">
                <h1>Video Categories</h1>
    
                <?php if ($notice): ?>
                    <div class="notice notice-<?php echo  esc_attr($notice['type']) ?> is-dismissible">
                        <p><?php echo  esc_html($notice['message']) ?></p>
                    </div>
                <?php endif; ?>
    
                <p>Add categories for your video library. Every category you add here is automatically added as a button to the <strong>Watch</strong> page and becomes available when adding or changing a video's category.</p>
                <p>Deleting a category moves its videos to <strong>General</strong>, or to the first remaining category if General is gone. Deleting the last category leaves its videos uncategorized. Renaming a category keeps its videos in it.</p>
    
                <h2>Add a category</h2>
                <form method="post">
                    <?php wp_nonce_field('vidcellar_add_category'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="vidcellar-category-name">Category name</label></th>
                            <td>
                                <input
                                    type="text"
                                    id="vidcellar-category-name"
                                    name="category_name"
                                    class="regular-text"
                                    maxlength="50"
                                    required
                                    placeholder="e.g. Action, Comedy, Interviews"
                                >
                                <p class="description">Maximum 50 characters. Category names are shown exactly as entered on the Watch page.</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Add Category', 'primary', 'vidcellar_add_category'); ?>
                </form>
    
                <h2>Current categories</h2>
                <table class="widefat striped" style="max-width:760px;">
                    <thead>
                        <tr>
                            <th style="width:80px;">#</th>
                            <th>Category</th>
                            <th style="width:100px;">Videos</th>
                            <th style="width:160px;"><span class="screen-reader-text">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="4">No categories yet. Add one above before adding videos.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categories as $index => $category): ?>
                            <?php
                            $slug = sanitize_title($category);
                            $count = (int) ($counts[$slug] ?? 0);
                            if ($count === 0) {
                                $confirm = sprintf('Delete the "%s" category?', $category);
                            } elseif (count($categories) > 1) {
                                $confirm = sprintf('Delete the "%s" category? Its %d %s will move to another category.', $category, $count, 1 === $count ? 'video' : 'videos');
                            } else {
                                $confirm = sprintf('Delete the "%s" category? It is the last category, so its %d %s will be uncategorized.', $category, $count, 1 === $count ? 'video' : 'videos');
                            }
                            ?>
                            <tr>
                                <td><?php echo  (int) ($index + 1) ?></td>
                                <td>
                                    <span class="vc-category-name"><?php echo  esc_html($category) ?></span>
                                    <form method="post" class="vc-category-edit" style="margin:0;" hidden>
                                        <?php wp_nonce_field('vidcellar_rename_category'); ?>
                                        <input type="hidden" name="category_slug" value="<?php echo  esc_attr($slug) ?>">
                                        <input type="text" name="category_name" value="<?php echo  esc_attr($category) ?>" maxlength="50" required aria-label="<?php echo  esc_attr(sprintf('New name for category %s', $category)) ?>">
                                        <button type="submit" name="vidcellar_rename_category" value="1" class="button button-primary button-small">Save</button>
                                        <button type="button" class="button button-small" onclick="var r=this.closest('tr');r.querySelector('.vc-category-edit').hidden=true;r.querySelector('.vc-category-name').hidden=false;r.querySelector('.vc-category-edit-toggle').style.display='';">Cancel</button>
                                    </form>
                                </td>
                                <td><?php echo  (int) $count ?></td>
                                <td style="white-space:nowrap;">
                                        <button type="button" class="button vc-category-edit-toggle" aria-label="<?php echo  esc_attr(sprintf('Edit category %s', $category)) ?>" onclick="var r=this.closest('tr');r.querySelector('.vc-category-edit').hidden=false;r.querySelector('.vc-category-name').hidden=true;this.style.display='none';r.querySelector('.vc-category-edit input[type=text]').focus();">Edit</button>
                                        <form method="post" style="margin:0;display:inline;" onsubmit="return window.confirm(this.dataset.confirm);" data-confirm="<?php echo  esc_attr($confirm) ?>">
                                            <?php wp_nonce_field('vidcellar_delete_category'); ?>
                                            <input type="hidden" name="category_slug" value="<?php echo  esc_attr($slug) ?>">
                                            <button type="submit" name="vidcellar_delete_category" value="1" class="button button-link-delete" aria-label="<?php echo  esc_attr(sprintf('Delete category %s', $category)) ?>">Delete</button>
                                        </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php
        }

    private static function handle_video_form(): ?array
        {
            // If the whole request exceeded post_max_size, PHP drops $_POST and
            // $_FILES entirely before this code ever runs — there's no error
            // code to read in that case, just an empty superglobal where a
            // multipart upload should be. This is the classic signature of that
            // failure mode, and without it a too-large video silently looks
            // identical to "no file selected".
            // A request with a body larger than post_max_size can arrive with
            // empty POST/FILES arrays. Capture only the two server values needed
            // for this diagnostic and normalize them before use.
            $request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            $content_length = isset( $_SERVER['CONTENT_LENGTH'] ) ? absint( wp_unslash( $_SERVER['CONTENT_LENGTH'] ) ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
            if (
                'POST' === $request_method
                && empty( $_POST )
                && empty( $_FILES )
                && $content_length > 0
            ) {
                return ['type' => 'error', 'message' => sprintf(
                    'That upload was larger than this server currently allows (max %s total). Ask your host to raise post_max_size (and upload_max_filesize to match), then try again.',
                    size_format(wp_max_upload_size())
                )];
            }
    
            if (self::post_value('vc_form_action') === 'add_video') {
                check_admin_referer('vidcellar_add_video');
    
                $storageDir = vidcellar_storage_dir();
                if (!is_dir($storageDir) || !wp_is_writable( $storageDir )) {
                    return ['type' => 'error', 'message' => "The video storage folder doesn't exist or isn't writable ($storageDir). Try deactivating and reactivating the plugin, or create the folder manually."];
                }
    
                $category = vidcellar_normalize_video_category((string) self::post_value('category'));
                if ($category === '') {
                    return ['type' => 'error', 'message' => 'Please select Feature Film, Documentary, or Short Film.'];
                }
    
                // The video file arrives one of two ways: assembled server-side
                // from small AJAX chunks (admin-upload.js — the normal
                // path when JS is available), or the classic single-shot $_FILES
                // upload (JS disabled/failed). Either way we end up with a source
                // path + an original filename, then the rest of this block is
                // identical regardless of which one supplied them.
                if ( ! empty( self::post_value( 'chunked_video_key' ) ) ) {
                    $assembled = VidCellar_Chunk_Upload::finalize(
                        (string) self::post_value('chunked_video_key'),
                        (string) self::post_value('chunked_video_name')
                    );
    
                    if (is_wp_error($assembled)) {
                        return ['type' => 'error', 'message' => $assembled->get_error_message()];
                    }
    
                    $sourcePath = $assembled['path'];
                    $originalName = $assembled['name'];
                    $moveSource = static function (string $source, string $dest): bool {
                        // The temp and final dirs are both under wp-content/uploads,
                        // so this is a same-filesystem rename in the common case —
                        // fall back to copy+unlink for the rare cross-filesystem setup.
                        return self::move_file( $source, $dest );
                    };
                } else {
                    $videoFile = self::uploaded_file( 'video_file' );
                    $videoError = isset( $videoFile['error'] ) ? (int) $videoFile['error'] : UPLOAD_ERR_NO_FILE;
    
                    if ($videoError !== UPLOAD_ERR_OK || empty( $videoFile['tmp_name'] ) || ! is_uploaded_file( $videoFile['tmp_name'] )) {
                        return ['type' => 'error', 'message' => self::upload_error_message($videoError)];
                    }
    
                    $originalName = sanitize_file_name((string) $videoFile['name']);
                    $videoCheck = VidCellar_Chunk_Upload::validate_video_file($videoFile['tmp_name'], $originalName);
                    if (is_wp_error($videoCheck)) {
                        return ['type' => 'error', 'message' => $videoCheck->get_error_message()];
                    }
    
                    $videoUpload = wp_handle_upload( $videoFile, [
                        'test_form' => false,
                        'mimes'     => [
                            'mp4'  => 'video/mp4',
                            'mov'  => 'video/quicktime',
                            'm4v'  => 'video/mp4',
                            'webm' => 'video/webm',
                            'ogv'  => 'video/ogg',
                            'avi'  => 'video/x-msvideo',
                            'wmv'  => 'video/x-ms-wmv',
                            'mkv'  => 'video/x-matroska',
                        ],
                    ]);
                    if (!empty($videoUpload['error']) || empty($videoUpload['file'])) {
                        return ['type' => 'error', 'message' => !empty($videoUpload['error']) ? $videoUpload['error'] : 'Could not save the video file.'];
                    }
                    $sourcePath = $videoUpload['file'];
                    $originalName = sanitize_file_name((string) ($videoUpload['file'] ? basename($videoUpload['file']) : $originalName));
                    $moveSource = static function (string $source, string $dest): bool {
                        return self::move_file( $source, $dest );
                    };
                }
    
                $videoFilename = wp_unique_filename($storageDir, sanitize_file_name($originalName));
                $finalVideoPath = trailingslashit($storageDir) . $videoFilename;
                if (!$moveSource($sourcePath, $finalVideoPath)) {
                    return ['type' => 'error', 'message' => 'Could not save the video file.'];
                }
    
                $thumbnailFilename = '';
                $thumbnailFile = self::uploaded_file( 'thumbnail_file' );
                if ( ! empty( $thumbnailFile['tmp_name'] ) && is_uploaded_file( $thumbnailFile['tmp_name'] ) ) {
                    $thumbUpload = wp_handle_upload( $thumbnailFile, [
                        'test_form' => false,
                        'mimes'     => [
                            'jpg|jpeg|jpe' => 'image/jpeg',
                            'png'         => 'image/png',
                            'webp'        => 'image/webp',
                        ],
                    ]);
                    if (!empty($thumbUpload['error']) || empty($thumbUpload['file'])) {
                        return ['type' => 'error', 'message' => !empty($thumbUpload['error']) ? $thumbUpload['error'] : 'Could not save the thumbnail.'];
                    }
                    $thumbDir = vidcellar_thumbnail_dir();
                    $thumbnailFilename = wp_unique_filename($thumbDir, sanitize_file_name( (string) $thumbnailFile['name'] ));
                    $thumbnailPath = trailingslashit($thumbDir) . $thumbnailFilename;
                    if ( ! self::move_file( $thumbUpload['file'], $thumbnailPath ) ) {
                        wp_delete_file($thumbUpload['file']);
                        return ['type' => 'error', 'message' => 'Could not move the thumbnail into private video storage.'];
                    }
                }
    
                $final_type = wp_check_filetype_and_ext( $finalVideoPath, $videoFilename );
            if ( empty( $final_type['type'] ) || 0 !== strpos( (string) $final_type['type'], 'video/' ) ) {
                wp_delete_file( $finalVideoPath );
                if ( $thumbnailFilename !== '' ) { wp_delete_file( trailingslashit( vidcellar_thumbnail_dir() ) . $thumbnailFilename ); }
                return [ 'type' => 'error', 'message' => 'The completed file failed video MIME validation.' ];
            }
            $storage = [ 'provider' => 'local', 'key' => $videoFilename, 'url' => '', 'status' => 'stored' ];

            $newVideoId = vidcellar_create_video([
                    'creator_id'     => get_current_user_id(),
                    'title'          => sanitize_text_field( (string) self::post_value('title') ),
                    'description'    => sanitize_textarea_field( (string) self::post_value('description') ),
                    'price' => 0.0,
                    'category'       => $category,
                    'thumbnail'      => $thumbnailFilename,
                    'trailer_attachment_id' => absint( self::post_value('trailer_attachment_id') ),
                    'video_filename' => $videoFilename,
                    'storage_provider' => $storage['provider'],
                    'storage_key' => $storage['key'],
                    'storage_url' => $storage['url'],
                    'storage_status' => $storage['status'],
                ]);
    
                if ($newVideoId <= 0) {
                    global $wpdb;
                    // The file landed on disk but the database row was never
                    // created — don't leave an orphaned file with nothing
                    // pointing to it, and don't claim success.
                    wp_delete_file($finalVideoPath);
                    if ($thumbnailFilename !== '') {
                        wp_delete_file(trailingslashit(vidcellar_thumbnail_dir()) . $thumbnailFilename);
                    }
                    $dbMessage = $wpdb->last_error ? " ({$wpdb->last_error})" : '';
                    return ['type' => 'error', 'message' => "The video could not be saved to the database{$dbMessage}. Nothing was published."];
                }
    
                // Queue a browser-friendly H.264/AAC copy. The original file remains
                // untouched; playback switches to the optimized copy automatically
                // when the background job finishes.
                VidCellar_Transcoder::schedule($newVideoId);
    
                return [ 'type' => 'success', 'message' => 'Video added to local private storage. Streaming optimization has been queued.', 'video_id' => $newVideoId ];
            }
    
            if (self::has_post_flag('vidcellar_edit_video')) {
                $videoId = absint( self::post_value('video_id') );
                check_admin_referer('vidcellar_edit_video_' . $videoId);
                if ($videoId < 1) return ['type' => 'error', 'message' => 'Invalid video.'];
    
                $existing = vidcellar_get_video($videoId);
                if (!$existing) return ['type' => 'error', 'message' => 'Video not found.'];
    
                $title = trim( sanitize_text_field( (string) self::post_value('title') ) );
                $description = sanitize_textarea_field( (string) self::post_value('description') );
                $category = vidcellar_normalize_video_category((string) self::post_value('category'));
                $price = 0.0;
                $attachmentId = absint( self::post_value('trailer_attachment_id') );
    
                if ($title === '') return ['type' => 'error', 'message' => 'Title is required.'];
                if ($category === '') return ['type' => 'error', 'message' => 'Please select a valid film category.'];
                if ($attachmentId > 0 && strpos((string) get_post_mime_type($attachmentId), 'video/') !== 0) {
                    return ['type' => 'error', 'message' => 'Please select a valid trailer video.'];
                }
    
                $data = [
                    'title' => $title,
                    'description' => $description,
                    'price' => $price,
                    'category' => $category,
                    'trailer_attachment_id' => $attachmentId,
                ];
    
                $oldThumbnail = (string) ($existing['thumbnail'] ?? '');
                $thumbnailFile = self::uploaded_file( 'thumbnail_file' );
                if ( ! empty( $thumbnailFile['tmp_name'] ) && is_uploaded_file( $thumbnailFile['tmp_name'] ) ) {
                    $thumbDir = vidcellar_thumbnail_dir();
                    if (!is_dir($thumbDir) || !wp_is_writable( $thumbDir )) {
                        return ['type' => 'error', 'message' => 'The thumbnail folder is not writable.'];
                    }
                    $fileType = wp_check_filetype_and_ext( $thumbnailFile['tmp_name'], $thumbnailFile['name'] );
                    if (empty($fileType['type']) || strpos((string) $fileType['type'], 'image/') !== 0) {
                        return ['type' => 'error', 'message' => 'Please upload a valid image thumbnail.'];
                    }
                    $thumbUpload = wp_handle_upload( $thumbnailFile, [
                        'test_form' => false,
                        'mimes'     => [
                            'jpg|jpeg|jpe' => 'image/jpeg',
                            'png'         => 'image/png',
                            'webp'        => 'image/webp',
                        ],
                    ]);
                    if (!empty($thumbUpload['error']) || empty($thumbUpload['file'])) {
                        return ['type' => 'error', 'message' => !empty($thumbUpload['error']) ? $thumbUpload['error'] : 'Could not save the new thumbnail.'];
                    }
                    $thumbnailFilename = wp_unique_filename($thumbDir, sanitize_file_name( (string) $thumbnailFile['name'] ));
                    $thumbnailPath = trailingslashit($thumbDir) . $thumbnailFilename;
                    if ( ! self::move_file( $thumbUpload['file'], $thumbnailPath ) ) {
                        wp_delete_file($thumbUpload['file']);
                        return ['type' => 'error', 'message' => 'Could not move the new thumbnail into private video storage.'];
                    }
                    $data['thumbnail'] = $thumbnailFilename;
                }
    
                vidcellar_update_video($videoId, $data);
    
                if (isset($data['thumbnail']) && $oldThumbnail !== '' && $oldThumbnail !== $data['thumbnail']) {
                    wp_delete_file(trailingslashit(vidcellar_thumbnail_dir()) . basename($oldThumbnail));
                }
    
                return ['type' => 'success', 'message' => 'Video details updated successfully.', 'video_id' => $videoId];
            }
    
            if (self::has_post_flag('vidcellar_update_video_trailer')) {
                $videoId = absint( self::post_value('video_id') );
                check_admin_referer('vidcellar_update_video_trailer_' . $videoId);
                if ($videoId < 1) return ['type' => 'error', 'message' => 'Invalid video.'];
    
                $attachmentId = absint( self::post_value('trailer_attachment_id') );
                if ($attachmentId > 0 && strpos((string) get_post_mime_type($attachmentId), 'video/') !== 0) {
                    return ['type' => 'error', 'message' => 'Please select a valid video from the Media Library.'];
                }
    
                vidcellar_update_video($videoId, ['trailer_attachment_id' => $attachmentId]);
                return ['type' => 'success', 'message' => $attachmentId ? 'Trailer updated.' : 'Trailer removed.', 'video_id' => $videoId];
            }
    
            if (self::has_post_flag('vidcellar_update_video_category')) {
                $videoId = absint( self::post_value('video_id') );
                check_admin_referer('vidcellar_update_video_category_' . $videoId);
                if ($videoId < 1) return ['type' => 'error', 'message' => 'Invalid video.'];
    
                $category = vidcellar_normalize_video_category((string) self::post_value('category'));
                if ($category === '') return ['type' => 'error', 'message' => 'Please select a valid film category.'];
    
                vidcellar_update_video($videoId, ['category' => $category]);
                return ['type' => 'success', 'message' => 'Video category updated.', 'video_id' => $videoId];
            }
    
            if (self::has_post_flag('vidcellar_optimize_video')) {
                $videoId = absint( self::post_value('video_id') );
                check_admin_referer('vidcellar_optimize_video_' . $videoId);
                if ($videoId < 1) return ['type' => 'error', 'message' => 'Invalid video.'];
                VidCellar_Transcoder::run_now($videoId);
                $updated = vidcellar_get_video($videoId);
                if (!empty($updated['streaming_filename']) && ($updated['streaming_status'] ?? '') === 'optimized') {
                    return ['type' => 'success', 'message' => 'Video optimized for smooth web streaming. Future playback will use the optimized copy.', 'video_id' => $videoId];
                }
                return ['type' => 'error', 'message' => 'The video could not be optimized: ' . ($updated['streaming_error'] ?? 'FFmpeg may not be available on this server.')];
            }
    
            if (self::has_post_flag('vidcellar_delete_video')) {
                $videoId = absint( self::post_value('video_id') );
                check_admin_referer('vidcellar_delete_video_' . $videoId);
                vidcellar_delete_video($videoId);
                return ['type' => 'success', 'message' => 'Video deleted. (The underlying file was left on disk — remove it manually if needed.)'];
            }
    
            return null;
        }

    /**
     * Move an uploaded file using WordPress' filesystem abstraction.
     *
     * @param string $source      Existing file path.
     * @param string $destination Destination file path.
     * @return bool
     */
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

    /**
     * Read a scalar admin POST value after WordPress request unslashing.
     * The caller performs context-specific validation/sanitization.
     *
     * @param string $key     POST key.
     * @param mixed  $default Default value.
     * @return mixed
     */
    // These helpers are called only after the relevant admin form handler verifies its nonce.
    // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verification occurs in each state-changing form branch before helper use.
    private static function post_value( string $key, $default = '' ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- The value is unslashed here and sanitized/validated by each caller according to its context.
        return wp_unslash( $_POST[ $key ] );
    }

    /**
     * Test for a submit-button flag without treating its value as trusted data.
     *
     * @param string $key POST key.
     * @return bool
     */
    private static function has_post_flag( string $key ): bool {
        return isset( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Presence-only submit flag; nonce verification follows before processing.
    }

    /**
     * Return one uploaded-file entry. File metadata is validated by the caller
     * and never treated as trusted HTML or SQL.
     *
     * @param string $key File input name.
     * @return array
     */
    private static function uploaded_file( string $key ): array {
        if ( ! isset( $_FILES[ $key ] ) || ! is_array( $_FILES[ $key ] ) ) {
            return [];
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES contains PHP upload metadata; type/MIME/path validation is performed before use.
        return $_FILES[ $key ];
    }

    // phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
    private static function upload_error_message(int $errorCode): string
        {
            switch ($errorCode) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return sprintf(
                        'That file is larger than this server currently allows (max %s). Ask your host to raise upload_max_filesize and post_max_size, then try again.',
                        size_format(wp_max_upload_size())
                    );
                case UPLOAD_ERR_PARTIAL:
                    return 'The upload was interrupted partway through — please try again.';
                case UPLOAD_ERR_NO_TMP_DIR:
                    return 'The server has no temporary folder configured for uploads. Contact your host.';
                case UPLOAD_ERR_CANT_WRITE:
                    return 'The server could not write the uploaded file to disk. Check available disk space and folder permissions.';
                case UPLOAD_ERR_EXTENSION:
                    return 'A server extension stopped this upload.';
                case UPLOAD_ERR_NO_FILE:
                default:
                    return 'A video file is required.';
            }
        }

    public static function export_videos_csv(): void
        {
            if (!current_user_can('manage_options')) {
                wp_die('Forbidden', 'Forbidden', ['response' => 403]);
            }
    
            check_admin_referer('vidcellar_export_videos_csv');
    
            global $wpdb;
            $table = vidcellar_videos_table();
            $cache_key = 'vidcellar_cvc_export_' . md5( $table );
            $videos = wp_cache_get( $cache_key, 'vidcellar' );
            if ( false === $videos ) {
                $videos = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Administrative CSV export requires a read from the plugin's custom table.
                    $wpdb->prepare(
                        "SELECT id, creator_id, title, description, category, video_filename, storage_provider, storage_status, streaming_filename, streaming_status, duration_seconds, views, rating, num_reviews, created_at FROM %i ORDER BY created_at DESC, id DESC",
                        $table
                    ),
                    ARRAY_A
                );
                wp_cache_set( $cache_key, $videos, 'vidcellar', MINUTE_IN_SECONDS );
            }
    
            $filename = 'vidcellar-videos-' . gmdate('Y-m-d') . '.csv';
    
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
    
            nocache_headers();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
            header('X-Content-Type-Options: nosniff');
    
            $output = new SplTempFileObject();
            $output->fputcsv([
                'Video ID',
                'Created At (UTC)',
                'Title',
                'Description',
                'Category',
                'Views',
                'Rating',
                'Reviews',
                'Duration (Seconds)',
                'Storage Provider',
                'Storage Status',
                'Streaming Status',
                'Original Video Filename',
                'Streaming Video Filename',
                'Watch URL',
            ]);

            foreach ($videos as $video) {
                $videoId = (int) $video['id'];
                $output->fputcsv([
                    $videoId,
                    self::cvc_safe($video['created_at']),
                    self::cvc_safe($video['title']),
                    self::cvc_safe($video['description']),
                    self::cvc_safe($video['category']),
                    (int) $video['views'],
                    number_format((float) $video['rating'], 2, '.', ''),
                    (int) $video['num_reviews'],
                    (int) $video['duration_seconds'],
                    self::cvc_safe($video['storage_provider']),
                    self::cvc_safe($video['storage_status']),
                    self::cvc_safe($video['streaming_status']),
                    self::cvc_safe($video['video_filename']),
                    self::cvc_safe($video['streaming_filename']),
                    self::cvc_safe(vidcellar_video_url($videoId)),
                ]);
            }

            $output->rewind();
            while (!$output->eof()) {
                echo $output->fgets(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV response body, not HTML.
            }
            exit;
        }

    private static function cvc_safe($value): string
        {
            $value = (string) $value;
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                return "'" . $value;
            }
            return $value;
        }

}
