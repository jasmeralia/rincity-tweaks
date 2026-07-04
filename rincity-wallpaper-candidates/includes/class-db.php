<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_DB {

    private static bool $created = false;

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'rincity_wallpaper_comments';
    }

    public static function create_table(): void {
        global $wpdb;
        $table           = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql             = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            image_key varchar(200) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            body text NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY image_key (image_key),
            KEY user_id (user_id)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
        self::$created = true;
    }

    public static function maybe_create_table(): void {
        if ( self::$created ) return;
        global $wpdb;
        // Only run dbDelta if table is missing (avoids cost on every page load).
        $table = self::table();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            self::create_table();
        }
    }

    public static function get_comments( string $image_key ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT c.*, u.user_login, u.display_name
             FROM " . self::table() . " c
             JOIN {$wpdb->users} u ON u.ID = c.user_id
             WHERE c.image_key = %s
             ORDER BY c.created_at ASC",
            $image_key
        ), ARRAY_A ) ?: [];
    }

    public static function add( string $image_key, int $user_id, string $body ): int {
        global $wpdb;
        $now = current_time( 'mysql' );
        $wpdb->insert( self::table(), [
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
            'SELECT user_id FROM ' . self::table() . ' WHERE id = %d', $id
        ) );
        if ( ! $row || (int) $row->user_id !== $user_id ) return false;
        return (bool) $wpdb->update(
            self::table(),
            [ 'body' => $body, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id'   => $id ]
        );
    }

    public static function delete( int $id, int $user_id ): bool {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT user_id FROM ' . self::table() . ' WHERE id = %d', $id
        ) );
        if ( ! $row || (int) $row->user_id !== $user_id ) return false;
        return (bool) $wpdb->delete( self::table(), [ 'id' => $id ] );
    }
}
