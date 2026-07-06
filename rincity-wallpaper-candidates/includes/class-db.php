<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_DB {

    public const SCHEMA_VERSION = '3.0.0';

    private static bool $created = false;

    public static function images_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincwc_images';
    }

    public static function selections_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincwc_selections';
    }

    public static function watermarks_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincwc_watermarks';
    }

    public static function gallery_wm_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincwc_gallery_wm';
    }

    public static function comments_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincwc_comments';
    }

    public static function table(): string {
        return self::comments_table();
    }

    public static function create_table(): void {
        self::create_tables();
    }

    public static function create_tables(): void {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $images          = self::images_table();
        $selections      = self::selections_table();
        $watermarks      = self::watermarks_table();
        $gallery_wm      = self::gallery_wm_table();
        $comments        = self::comments_table();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$images} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            gallery_id int(10) unsigned NOT NULL,
            gallery_slug varchar(200) NOT NULL,
            gallery_title varchar(500) NOT NULL,
            attach_id int(10) unsigned NOT NULL,
            position smallint(5) unsigned DEFAULT NULL,
            total smallint(5) unsigned DEFAULT NULL,
            original_path text DEFAULT NULL,
            scaled_path text DEFAULT NULL,
            src_url text DEFAULT NULL,
            orig_w smallint(5) unsigned DEFAULT NULL,
            orig_h smallint(5) unsigned DEFAULT NULL,
            excluded tinyint(1) NOT NULL DEFAULT 0,
            status enum('CANDIDATE','SELECTED','APPROVED') NOT NULL DEFAULT 'CANDIDATE',
            approved_by bigint(20) unsigned DEFAULT NULL,
            approved_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_gallery_attach (gallery_id, attach_id),
            KEY status (status),
            KEY gallery_id (gallery_id),
            KEY excluded (excluded)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$selections} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            image_id int(10) unsigned NOT NULL,
            crop_variant varchar(50) DEFAULT NULL,
            custom_crop_scale float DEFAULT NULL,
            custom_crop_x int(11) DEFAULT NULL,
            custom_crop_y int(11) DEFAULT NULL,
            wm_corner varchar(20) DEFAULT NULL,
            wm_file_id int(10) unsigned DEFAULT NULL,
            wm_applied tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY uq_image (image_id),
            KEY wm_file_id (wm_file_id),
            KEY wm_applied (wm_applied)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$watermarks} (
            id int(10) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(200) NOT NULL,
            file_path text NOT NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY is_default (is_default)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$gallery_wm} (
            gallery_id int(10) unsigned NOT NULL,
            wm_file_id int(10) unsigned NOT NULL,
            PRIMARY KEY  (gallery_id),
            KEY wm_file_id (wm_file_id)
        ) {$charset_collate};" );

        dbDelta( "CREATE TABLE {$comments} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            image_key varchar(200) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            body text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY image_key (image_key),
            KEY user_id (user_id)
        ) {$charset_collate};" );

        self::$created = true;
        update_option( 'rincwc_schema_version', self::SCHEMA_VERSION, false );
        self::seed_default_watermark();
    }

    public static function maybe_create_table(): void {
        if ( self::$created ) {
            return;
        }

        global $wpdb;
        $version = (string) get_option( 'rincwc_schema_version', '' );
        $images  = self::images_table();

        if ( $version !== self::SCHEMA_VERSION || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $images ) ) !== $images ) {
            self::create_tables();
            return;
        }

        self::seed_default_watermark();
        self::$created = true;
    }

    private static function seed_default_watermark(): void {
        global $wpdb;
        $table = self::watermarks_table();
        $id    = (int) $wpdb->get_var( "SELECT id FROM {$table} WHERE is_default = 1 ORDER BY id ASC LIMIT 1" );
        if ( $id ) {
            return;
        }

        $existing = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM {$table} WHERE file_path = %s LIMIT 1", RINCWC_WM_FILE )
        );
        if ( $existing ) {
            $wpdb->update( $table, [ 'is_default' => 1 ], [ 'id' => $existing ] );
            return;
        }

        $wpdb->insert( $table, [
            'name'       => 'Default RinCity watermark',
            'file_path'  => RINCWC_WM_FILE,
            'is_default' => 1,
            'created_at' => current_time( 'mysql' ),
        ] );
    }

    public static function get_comments( string $image_key ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, u.user_login, u.display_name
             FROM " . self::comments_table() . " c
             JOIN {$wpdb->users} u ON u.ID = c.user_id
             WHERE c.image_key = %s
             ORDER BY c.created_at ASC",
            $image_key
        ), ARRAY_A ) ?: [];
    }

    /**
     * Every image_key ("gallery_id:attach_id") with at least one comment, as a lookup
     * set. Used by the Review page's Comments filter, which needs to check excluded
     * images too, not just visible candidates.
     */
    public static function get_commented_image_keys(): array {
        global $wpdb;
        $keys = $wpdb->get_col( 'SELECT DISTINCT image_key FROM ' . self::comments_table() );
        return array_flip( $keys );
    }

    public static function add( string $image_key, int $user_id, string $body ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( self::comments_table(), [
            'image_key'  => $image_key,
            'user_id'    => $user_id,
            'body'       => $body,
            'created_at' => $now,
            'updated_at' => $now,
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function update( int $id, int $user_id, string $body ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT user_id FROM ' . self::comments_table() . ' WHERE id = %d',
            $id
        ) );
        if ( ! $row || (int) $row->user_id !== $user_id ) {
            return false;
        }
        return (bool) $wpdb->update(
            self::comments_table(),
            [ 'body' => $body, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
    }

    public static function delete( int $id, int $user_id ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT user_id FROM ' . self::comments_table() . ' WHERE id = %d',
            $id
        ) );
        if ( ! $row || (int) $row->user_id !== $user_id ) {
            return false;
        }
        return (bool) $wpdb->delete( self::comments_table(), [ 'id' => $id ] );
    }
}
