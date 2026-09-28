<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { exit; }

// Uninstall intentionally performs schema deletion only when the administrator
// explicitly enabled data deletion. The table names are plugin-owned.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
if ( '1' !== get_option( 'vidcellar_delete_data_on_uninstall' ) ) { return; }
global $wpdb;
$vidcellar_reviews_table = $wpdb->prefix . 'vidcellar_reviews';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit administrator-requested uninstall cleanup of a plugin-owned table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $vidcellar_reviews_table ) );
$vidcellar_videos_table = $wpdb->prefix . 'vidcellar_videos';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Explicit administrator-requested uninstall cleanup of a plugin-owned table.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $vidcellar_videos_table ) );
foreach ( [ 'vidcellar_db_version', 'vidcellar_browse_page_id', 'vidcellar_watch_page_id', 'vidcellar_delete_data_on_uninstall', 'vidcellar_video_categories' ] as $vidcellar_option_name ) { delete_option( $vidcellar_option_name ); }

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
