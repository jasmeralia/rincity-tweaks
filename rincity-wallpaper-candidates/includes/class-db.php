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

    public static function legacy_comments_table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincity_wallpaper_comments';
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
        self::migrate_legacy_comments();
        self::migrate_csv_once();
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
        self::migrate_legacy_comments();
        self::migrate_csv_once();
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

    private static function migrate_legacy_comments(): void {
        global $wpdb;
        $old = self::legacy_comments_table();
        $new = self::comments_table();

        $old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old ) ) === $old;
        $new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new ) ) === $new;

        if ( $old_exists && ! $new_exists ) {
            $wpdb->query( "RENAME TABLE {$old} TO {$new}" );
        } elseif ( $old_exists && $new_exists ) {
            $count_new = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$new}" );
            if ( $count_new === 0 ) {
                $wpdb->query( "INSERT INTO {$new} (id, image_key, user_id, body, created_at, updated_at)
                    SELECT id, image_key, user_id, body, created_at, updated_at FROM {$old}" );
            }
        }
    }

    private static function migrate_csv_once(): void {
        if ( get_option( 'rincwc_csv_migrated_v3' ) ) {
            return;
        }

        $db_rows  = self::read_csv_flat( RINCWC_DB_CSV );
        $sel_rows = self::read_csv_flat( RINCWC_SEL_CSV );

        if ( empty( $db_rows ) && empty( $sel_rows ) ) {
            update_option( 'rincwc_csv_migrated_v3', current_time( 'mysql' ), false );
            return;
        }

        foreach ( $db_rows as $row ) {
            self::migrate_db_row( $row );
        }

        foreach ( $sel_rows as $row ) {
            self::migrate_selection_row( $row );
        }

        update_option( 'rincwc_csv_migrated_v3', current_time( 'mysql' ), false );
    }

    private static function migrate_db_row( array $row ): int {
        if ( class_exists( 'RinCWC_Data' ) ) {
            return RinCWC_Data::upsert_image( [
                'gallery_id'    => (int) ( $row['gallery_id'] ?? 0 ),
                'gallery_slug'  => (string) ( $row['gallery_slug'] ?? '' ),
                'gallery_title' => (string) ( $row['gallery_title'] ?? '' ),
                'attach_id'     => (int) ( $row['attach_id'] ?? 0 ),
                'position'      => (int) ( $row['position'] ?? 0 ),
                'total'         => (int) ( $row['total'] ?? 0 ),
                'original_path' => (string) ( $row['original_path'] ?? '' ),
                'scaled_path'   => (string) ( $row['scaled_path'] ?? '' ),
                'src_url'       => (string) ( $row['src_url'] ?? '' ),
                'orig_w'        => (int) ( $row['orig_w'] ?? $row['width'] ?? 0 ),
                'orig_h'        => (int) ( $row['orig_h'] ?? $row['height'] ?? 0 ),
                'excluded'      => ! empty( $row['excluded'] ) && $row['excluded'] !== '0' ? 1 : 0,
                'status'        => 'CANDIDATE',
            ] );
        }

        return 0;
    }

    private static function migrate_selection_row( array $row ): void {
        if ( ! class_exists( 'RinCWC_Data' ) ) {
            return;
        }

        $image = RinCWC_Data::get_image_by_gallery_attach( (int) ( $row['gallery_id'] ?? 0 ), (int) ( $row['attach_id'] ?? 0 ) );
        if ( ! $image ) {
            return;
        }

        $variant = sanitize_key( $row['selected_crop'] ?? '' );
        if ( ! $variant ) {
            return;
        }

        $custom = [];
        if ( $variant === 'custom' ) {
            $offset = (int) ( $row['custom_crop_offset'] ?? 0 );
            $custom = self::legacy_offset_to_custom_crop( $image, $offset );
        }

        RinCWC_Data::upsert_selection( (int) $image['id'], [
            'crop_variant'      => $variant,
            'custom_crop_scale' => $custom['scale'] ?? null,
            'custom_crop_x'     => $custom['x'] ?? null,
            'custom_crop_y'     => $custom['y'] ?? null,
            'wm_corner'         => sanitize_text_field( $row['wm_corner'] ?? '' ),
            'wm_applied'        => ( ( $row['wm_applied'] ?? '' ) === 'true' || ( $row['wm_applied'] ?? '' ) === '1' ) ? 1 : 0,
        ] );

        // v2 CSV has no durable "published" marker, so migrated rows become SELECTED.
        RinCWC_Data::set_status( (int) $image['id'], 'SELECTED' );
    }

    private static function legacy_offset_to_custom_crop( array $image, int $offset ): array {
        $orig_w = max( 1, (int) $image['orig_w'] );
        $orig_h = max( 1, (int) $image['orig_h'] );
        $scale  = RinCWC_Data::max_crop_scale( $orig_w, $orig_h );
        $box_w  = (int) round( $scale * 3840 );
        $box_h  = (int) round( $scale * 2160 );
        $max_x  = max( 0, $orig_w - $box_w );
        $max_y  = max( 0, $orig_h - $box_h );
        $source_offset = (int) round( $offset * $scale );

        if ( $orig_w / $orig_h >= 3840 / 2160 ) {
            return [ 'scale' => $scale, 'x' => min( $max_x, max( 0, $source_offset ) ), 'y' => 0 ];
        }

        return [ 'scale' => $scale, 'x' => 0, 'y' => min( $max_y, max( 0, $source_offset ) ) ];
    }

    private static function read_csv_flat( string $path ): array {
        if ( ! file_exists( $path ) ) {
            return [];
        }
        $fh = fopen( $path, 'r' );
        if ( ! $fh ) {
            return [];
        }
        $headers = fgetcsv( $fh );
        if ( ! $headers ) {
            fclose( $fh );
            return [];
        }
        $rows = [];
        while ( ( $row = fgetcsv( $fh ) ) !== false ) {
            if ( count( $row ) < count( $headers ) ) {
                $row = array_pad( $row, count( $headers ), '' );
            }
            $rows[] = array_combine( $headers, array_slice( $row, 0, count( $headers ) ) );
        }
        fclose( $fh );
        return $rows;
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
