<?php
/** VidCellar Lite REST API. GPLv2 or later. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class VidCellar_REST {
    public static function init(): void { add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] ); }
    public static function register_routes(): void {
        register_rest_route( 'vidcellar/v1', '/rate-video', [
            'methods' => 'POST',
            'callback' => [ __CLASS__, 'handle_rate_video' ],
            'permission_callback' => [ __CLASS__, 'permission' ],
            'args' => [
                'video_id' => [ 'required' => true, 'type' => 'integer' ],
                'rating' => [ 'required' => true, 'type' => 'integer' ],
                'guest_token' => [ 'required' => false, 'type' => 'string' ],
            ],
        ] );
    }
    public static function permission( WP_REST_Request $request ): bool {
        if ( is_user_logged_in() ) { return current_user_can( 'read' ); }
        $nonce = $request->get_header( 'X-WP-Nonce' );
        return '' !== $nonce && (bool) wp_verify_nonce( $nonce, 'wp_rest' );
    }
    public static function handle_rate_video( WP_REST_Request $request ) {
        $video_id = absint( $request->get_param( 'video_id' ) );
        $rating = absint( $request->get_param( 'rating' ) );
        if ( $video_id < 1 || $rating < 1 || $rating > 5 || ! vidcellar_get_video( $video_id ) ) {
            return new WP_Error( 'vc_rating_invalid', 'Invalid video or rating.', [ 'status' => 400 ] );
        }
        $guest_token = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $request->get_param( 'guest_token' ) );
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $result = vidcellar_save_rating( $video_id, $rating, $user_id, $guest_token );
        if ( is_wp_error( $result ) ) { return $result; }
        return new WP_REST_Response( [ 'ok' => true, 'average' => (float) $result['average'], 'count' => (int) $result['count'] ], 200 );
    }
}
