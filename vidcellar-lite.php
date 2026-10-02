<?php
/**
 * Plugin Name: VidCellar Lite
 * Description: Free WordPress video library and secure private video playback with categories, ratings, thumbnails, and resumable large-file uploads.
 * Version: 1.1.10
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Suhaib Siddiqi
 * Author URI: https://canvasly.pro/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: vidcellar-lite
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'VIDCELLAR_VERSION', '1.1.10' );
define( 'VIDCELLAR_PLUGIN_FILE', __FILE__ );
define( 'VIDCELLAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VIDCELLAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once VIDCELLAR_PLUGIN_DIR . 'includes/functions-storage.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/functions-video.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-activator.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-rest.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-stream.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-chunk-upload.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-transcoder.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-admin.php';
require_once VIDCELLAR_PLUGIN_DIR . 'includes/class-vidcellar-shortcodes.php';

register_activation_hook( __FILE__, [ 'VidCellar_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'VidCellar_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'VidCellar_Activator', 'maybe_upgrade' ], 5 );
add_action(
    'plugins_loaded',
    static function () {
        VidCellar_REST::init();
        VidCellar_Stream::init();
        VidCellar_Chunk_Upload::init();
        VidCellar_Transcoder::init();
        VidCellar_Admin::init();
        VidCellar_Shortcodes::init();
    },
    10
);

/**
 * Enqueue public CSS/JS only on pages that render the plugin shortcodes.
 */
function vidcellar_enqueue_frontend_assets(): void {
    static $enqueued = false;
    if ( $enqueued ) {
        return;
    }
    $enqueued = true;

    wp_enqueue_style(
        'vidcellar',
        VIDCELLAR_PLUGIN_URL . 'assets/css/style.css',
        [],
        VIDCELLAR_VERSION
    );
    wp_enqueue_script(
        'vidcellar',
        VIDCELLAR_PLUGIN_URL . 'assets/js/frontend.js',
        [],
        VIDCELLAR_VERSION,
        true
    );
    wp_localize_script(
        'vidcellar',
        'vidCellar',
        [
            'restUrl'          => esc_url_raw( rest_url( 'vidcellar/v1' ) ),
            'nonce'            => wp_create_nonce( 'wp_rest' ),
            'isLoggedIn'       => is_user_logged_in(),
            'currentUserEmail' => is_user_logged_in() ? sanitize_email( wp_get_current_user()->user_email ) : '',
            'loginUrl'         => wp_login_url( get_permalink() ),
        ]
    );
}

add_action(
    'wp_enqueue_scripts',
    static function () {
        if ( ! is_singular() ) {
            return;
        }
        $post = get_post();
        if ( ! ( $post instanceof WP_Post ) ) {
            return;
        }
        if (
            has_shortcode( $post->post_content, 'vidcellar_browse' )
            || has_shortcode( $post->post_content, 'vidcellar_video' )
        ) {
            vidcellar_enqueue_frontend_assets();
        }
    }
);
