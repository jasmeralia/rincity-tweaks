<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Activator {

    public static function activate(): void {
        global $wpdb;

        $table   = $wpdb->prefix . 'rincity_gallery_favorites';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            amember_user_id BIGINT UNSIGNED NOT NULL,
            gallery_id      BIGINT UNSIGNED NOT NULL,
            note            TEXT NULL,
            note_updated_at DATETIME NULL,
            created_at      DATETIME NOT NULL,
            updated_at      DATETIME NOT NULL,
            wp_user_id      BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_member_gallery (amember_user_id, gallery_id),
            KEY idx_member_created (amember_user_id, created_at),
            KEY idx_gallery (gallery_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
