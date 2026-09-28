<?php
/** VidCellar Lite activation and schema migration. GPLv2 or later. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

// These direct queries are limited to plugin-owned schema creation/migration.
// All identifiers are derived from $wpdb->prefix and definitions are hard-coded.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class VidCellar_Activator {

    public static function activate(): void {
        self::create_tables();
        self::create_storage_dirs();
        self::create_pages();
        update_option( 'vidcellar_db_version', VIDCELLAR_VERSION );
        flush_rewrite_rules();
    }

    public static function maybe_upgrade(): void {
        $v = get_option( 'vidcellar_db_version', '0.0.0' );
        if ( version_compare( $v, VIDCELLAR_VERSION, '<' ) ) {
            self::create_tables();
            self::create_storage_dirs();
            self::create_pages();
            update_option( 'vidcellar_db_version', VIDCELLAR_VERSION );
        }
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    /**
     * Legacy-safe schema migration.
     *
     * Important: this intentionally does NOT use dbDelta(). dbDelta() can
     * generate malformed CHANGE COLUMN statements when it parses some legacy
     * schemas. Existing columns/data are never rewritten or dropped here.
     */
    private static function create_tables(): void {
        global $wpdb;

        $videos  = $wpdb->prefix . 'vidcellar_videos';
        $reviews = $wpdb->prefix . 'vidcellar_reviews';
        $charset = $wpdb->get_charset_collate();

        self::ensure_table(
            $videos,
            "CREATE TABLE `$videos` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `creator_id` BIGINT UNSIGNED NOT NULL,
                `title` VARCHAR(200) NOT NULL,
                `description` TEXT NOT NULL,
                `price` DECIMAL(10,2) NOT NULL DEFAULT 0,
                `category` VARCHAR(50) NOT NULL DEFAULT '',
                `thumbnail` VARCHAR(255) NOT NULL DEFAULT '',
                `trailer_attachment_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `video_filename` VARCHAR(255) NOT NULL DEFAULT '',
                `storage_provider` VARCHAR(30) NOT NULL DEFAULT 'local',
                `storage_key` VARCHAR(500) NOT NULL DEFAULT '',
                `storage_url` TEXT NOT NULL,
                `storage_status` VARCHAR(30) NOT NULL DEFAULT 'stored',
                `streaming_filename` VARCHAR(255) NOT NULL DEFAULT '',
                `streaming_status` VARCHAR(30) NOT NULL DEFAULT 'not_started',
                `streaming_error` TEXT NOT NULL,
                `duration_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
                `views` INT UNSIGNED NOT NULL DEFAULT 0,
                `purchase_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `rating` DECIMAL(3,2) NOT NULL DEFAULT 0,
                `num_reviews` INT UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `creator_id` (`creator_id`),
                KEY `category` (`category`)
            ) $charset;"
        );

        self::ensure_table(
            $reviews,
            "CREATE TABLE `$reviews` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `video_id` BIGINT UNSIGNED NOT NULL,
                `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `guest_key` VARCHAR(64) NOT NULL DEFAULT '',
                `reviewer_key` VARCHAR(64) NOT NULL DEFAULT '',
                `rating` TINYINT UNSIGNED NOT NULL,
                `comment` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `reviewer` (`video_id`,`user_id`,`guest_key`),
                KEY `video_id` (`video_id`),
                KEY `reviewer_key` (`video_id`,`reviewer_key`)
            ) $charset;"
        );

        // Add only missing columns. Never CHANGE an existing id column.
        $video_columns = [
            'creator_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'title' => "VARCHAR(200) NOT NULL DEFAULT ''",
            'description' => "TEXT NOT NULL",
            'price' => "DECIMAL(10,2) NOT NULL DEFAULT 0",
            'category' => "VARCHAR(50) NOT NULL DEFAULT ''",
            'thumbnail' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'trailer_attachment_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'video_filename' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'storage_provider' => "VARCHAR(30) NOT NULL DEFAULT 'local'",
            'storage_key' => "VARCHAR(500) NOT NULL DEFAULT ''",
            'storage_url' => "TEXT NOT NULL",
            'storage_status' => "VARCHAR(30) NOT NULL DEFAULT 'stored'",
            'streaming_filename' => "VARCHAR(255) NOT NULL DEFAULT ''",
            'streaming_status' => "VARCHAR(30) NOT NULL DEFAULT 'not_started'",
            'streaming_error' => "TEXT NOT NULL",
            'duration_seconds' => "INT UNSIGNED NOT NULL DEFAULT 0",
            'views' => "INT UNSIGNED NOT NULL DEFAULT 0",
            'purchase_count' => "INT UNSIGNED NOT NULL DEFAULT 0",
            'rating' => "DECIMAL(3,2) NOT NULL DEFAULT 0",
            'num_reviews' => "INT UNSIGNED NOT NULL DEFAULT 0",
            'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        ];
        foreach ( $video_columns as $column => $definition ) {
            self::ensure_column( $videos, $column, $definition );
        }

        $review_columns = [
            'video_id' => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'user_id' => "BIGINT UNSIGNED NULL DEFAULT NULL",
            'guest_key' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'reviewer_key' => "VARCHAR(64) NOT NULL DEFAULT ''",
            'rating' => "TINYINT UNSIGNED NOT NULL DEFAULT 0",
            'comment' => "TEXT NOT NULL",
            'created_at' => "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'updated_at' => "DATETIME NULL DEFAULT NULL",
        ];
        foreach ( $review_columns as $column => $definition ) {
            self::ensure_column( $reviews, $column, $definition );
        }

        self::ensure_index( $videos, 'creator_id', 'KEY `creator_id` (`creator_id`)' );
        self::ensure_index( $videos, 'category', 'KEY `category` (`category`)' );
        self::ensure_index( $reviews, 'video_id', 'KEY `video_id` (`video_id`)' );
        self::ensure_index( $reviews, 'reviewer_key', 'KEY `reviewer_key` (`video_id`,`reviewer_key`)' );
        self::backfill_reviewer_keys( $reviews );
        // Do not attempt to recreate the primary key or alter an existing id.
    }

    private static function ensure_table( string $table, string $create_sql ): void {
        global $wpdb;
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
        );
        if ( $exists !== $table ) {
            $wpdb->query( $create_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema SQL is assembled only from plugin-owned definitions and the WordPress charset string.
        }
    }

    private static function ensure_column( string $table, string $column, string $definition ): void {
        global $wpdb;
        if ( ! self::column_exists( $table, $column ) ) {
            // Controlled plugin-owned migration; schema changes are required only during activation/upgrade.
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN %i $definition", $table, $column ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Column definitions come only from the hard-coded migration map.
        }
    }

    private static function ensure_index( string $table, string $name, string $definition ): void {
        global $wpdb;
        if ( ! self::index_exists( $table, $name ) ) {
            // Controlled plugin-owned migration; schema changes are required only during activation/upgrade.
            $wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD $definition", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Index definitions come only from the hard-coded migration map.
        }
    }

    private static function column_exists( string $table, string $column ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW COLUMNS FROM %i LIKE %s", $table, $column )
        );
    }

    private static function index_exists( string $table, string $name ): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare( "SHOW INDEX FROM %i WHERE Key_name = %s", $table, $name )
        );
    }

    /**
     * Populate reviewer_key for rows created before the column existed.
     */
    private static function backfill_reviewer_keys( string $table ): void {
        global $wpdb;
        if ( ! self::column_exists( $table, 'reviewer_key' ) ) {
            return;
        }
        if ( self::column_exists( $table, 'user_id' ) ) {
            $wpdb->query( $wpdb->prepare( "UPDATE %i SET reviewer_key = SHA2(CONCAT('user:', user_id), 256) WHERE reviewer_key = '' AND user_id IS NOT NULL AND user_id > 0", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema backfill for plugin-owned reviews.
        }
        if ( self::column_exists( $table, 'guest_key' ) ) {
            $wpdb->query( $wpdb->prepare( "UPDATE %i SET reviewer_key = SHA2(CONCAT('guest:', guest_key), 256) WHERE reviewer_key = '' AND guest_key <> ''", $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time schema backfill for plugin-owned reviews.
        }
    }

    private static function create_storage_dirs(): void {
        foreach ( [ vidcellar_storage_dir(), vidcellar_thumbnail_dir(), vidcellar_upload_tmp_dir() ] as $dir ) {
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
        }
        $ht = trailingslashit( dirname( vidcellar_storage_dir() ) ) . '.htaccess';
        if ( ! file_exists( $ht ) ) {
            file_put_contents( $ht, "Require all denied\n" );
        }
        $idx = trailingslashit( dirname( vidcellar_storage_dir() ) ) . 'index.php';
        if ( ! file_exists( $idx ) ) {
            file_put_contents( $idx, "<?php\n// Silence is golden.\n" );
        }
    }

    private static function create_pages(): void {
        foreach ( [
            'vidcellar_browse_page_id' => [ 'title' => 'Videos', 'shortcode' => '[vidcellar_browse]' ],
            'vidcellar_watch_page_id'  => [ 'title' => 'Watch', 'shortcode' => '[vidcellar_video]' ],
        ] as $option => $def ) {
            $id = (int) get_option( $option, 0 );
            if ( $id && get_post( $id ) ) {
                continue;
            }
            $page = wp_insert_post( [
                'post_title'   => $def['title'],
                'post_content' => $def['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ] );
            if ( $page && ! is_wp_error( $page ) ) {
                update_option( $option, $page );
            }
        }
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
