<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Data {

    public const STATUS_CANDIDATE = 'CANDIDATE';
    public const STATUS_SELECTED  = 'SELECTED';
    public const STATUS_APPROVED  = 'APPROVED';

    private const VALID_STATUSES = [ self::STATUS_CANDIDATE, self::STATUS_SELECTED, self::STATUS_APPROVED ];
    private const VALID_CORNERS  = [ '', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
    private const VALID_CROPS    = [ 'top', 'center-top', 'center', 'center-bottom', 'bottom', 'custom' ];

    public static function default_settings(): array {
        return [
            'publish_galleries' => [
                '4k'    => 21261,
                '1440p' => 21306,
                '1080p' => 21330,
            ],
            'allow_test_approve' => false,
            'excluded_after'     => [],
        ];
    }

    public static function get_settings(): array {
        $settings = get_option( 'rincwc_settings', [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }
        return array_replace_recursive( self::default_settings(), $settings );
    }

    public static function update_settings( array $settings ): void {
        $current = self::get_settings();
        update_option( 'rincwc_settings', array_replace_recursive( $current, $settings ), false );
    }

    public static function user_can_approve(): bool {
        $settings = self::get_settings();
        if ( ! empty( $settings['allow_test_approve'] ) && current_user_can( 'manage_options' ) ) {
            return true;
        }

        $user = get_userdata( get_current_user_id() );
        if ( ! $user ) {
            return false;
        }

        return in_array( $user->user_login, [ 'rincity', 'rincity_member' ], true );
    }

    public static function all_images( bool $include_excluded = false ): array {
        global $wpdb;
        $where = $include_excluded ? '1=1' : 'i.excluded = 0';
        return $wpdb->get_results(
            "SELECT i.*, s.id AS selection_id, s.crop_variant, s.custom_crop_scale,
                    s.custom_crop_x, s.custom_crop_y, s.wm_corner, s.wm_file_id, s.wm_applied,
                    p.post_date AS gallery_pub_date
             FROM " . RinCWC_DB::images_table() . " i
             LEFT JOIN " . RinCWC_DB::selections_table() . " s ON s.image_id = i.id
             LEFT JOIN {$wpdb->posts} p ON p.ID = i.gallery_id
             WHERE {$where}
             ORDER BY p.post_date DESC, i.gallery_id DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function get_image( int $image_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . RinCWC_DB::images_table() . ' WHERE id = %d', $image_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_image_by_gallery_attach( int $gallery_id, int $attach_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . RinCWC_DB::images_table() . ' WHERE gallery_id = %d AND attach_id = %d',
                $gallery_id,
                $attach_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function upsert_image( array $data ): int {
        global $wpdb;

        $gallery_id = (int) ( $data['gallery_id'] ?? 0 );
        $attach_id  = (int) ( $data['attach_id'] ?? 0 );
        if ( ! $gallery_id || ! $attach_id ) {
            return 0;
        }

        $existing = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        $row      = [
            'gallery_id'    => $gallery_id,
            'gallery_slug'  => sanitize_title( (string) ( $data['gallery_slug'] ?? '' ) ),
            'gallery_title' => sanitize_text_field( (string) ( $data['gallery_title'] ?? '' ) ),
            'attach_id'     => $attach_id,
            'position'      => isset( $data['position'] ) ? (int) $data['position'] : null,
            'total'         => isset( $data['total'] ) ? (int) $data['total'] : null,
            'original_path' => (string) ( $data['original_path'] ?? '' ),
            'scaled_path'   => (string) ( $data['scaled_path'] ?? '' ),
            'src_url'       => esc_url_raw( (string) ( $data['src_url'] ?? '' ) ),
            'orig_w'        => isset( $data['orig_w'] ) ? (int) $data['orig_w'] : null,
            'orig_h'        => isset( $data['orig_h'] ) ? (int) $data['orig_h'] : null,
            'excluded'      => empty( $data['excluded'] ) ? 0 : 1,
            'updated_at'    => current_time( 'mysql' ),
        ];

        if ( isset( $data['status'] ) && in_array( $data['status'], self::VALID_STATUSES, true ) ) {
            $row['status'] = $data['status'];
        }

        if ( $existing ) {
            unset( $row['status'] );
            $wpdb->update( RinCWC_DB::images_table(), $row, [ 'id' => (int) $existing['id'] ] );
            return (int) $existing['id'];
        }

        if ( empty( $row['status'] ) ) {
            $row['status'] = self::STATUS_CANDIDATE;
        }
        $row['created_at'] = current_time( 'mysql' );

        $wpdb->insert( RinCWC_DB::images_table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function get_selection( int $image_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . RinCWC_DB::selections_table() . ' WHERE image_id = %d', $image_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function upsert_selection( int $image_id, array $data ): bool {
        global $wpdb;

        if ( $image_id <= 0 ) {
            return false;
        }

        $existing = self::get_selection( $image_id );
        $row      = [
            'image_id'    => $image_id,
            'updated_at'  => current_time( 'mysql' ),
        ];

        if ( array_key_exists( 'crop_variant', $data ) ) {
            $variant = sanitize_key( (string) $data['crop_variant'] );
            if ( ! in_array( $variant, self::VALID_CROPS, true ) ) {
                return false;
            }
            $row['crop_variant'] = $variant;
        }

        foreach ( [ 'custom_crop_scale', 'custom_crop_x', 'custom_crop_y', 'wm_file_id', 'wm_applied' ] as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $row[ $field ] = $data[ $field ] === null ? null : ( $field === 'custom_crop_scale' ? (float) $data[ $field ] : (int) $data[ $field ] );
            }
        }

        if ( array_key_exists( 'wm_corner', $data ) ) {
            $corner = sanitize_text_field( (string) $data['wm_corner'] );
            if ( ! in_array( $corner, self::VALID_CORNERS, true ) ) {
                return false;
            }
            $row['wm_corner'] = $corner;
        }

        if ( $existing ) {
            $changed_crop = isset( $row['crop_variant'] ) && (string) $existing['crop_variant'] !== (string) $row['crop_variant'];
            $changed_wm   = isset( $row['wm_corner'] ) && (string) $existing['wm_corner'] !== (string) $row['wm_corner'];
            if ( ( $changed_crop || $changed_wm ) && ! array_key_exists( 'wm_applied', $row ) ) {
                $row['wm_applied'] = 0;
            }
            $wpdb->update( RinCWC_DB::selections_table(), $row, [ 'image_id' => $image_id ] );
            return $wpdb->last_error === '';
        }

        $row['created_at'] = current_time( 'mysql' );
        if ( ! array_key_exists( 'wm_applied', $row ) ) {
            $row['wm_applied'] = 0;
        }
        $wpdb->insert( RinCWC_DB::selections_table(), $row );
        return $wpdb->insert_id > 0;
    }

    public static function delete_selection( int $image_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( RinCWC_DB::selections_table(), [ 'image_id' => $image_id ] );
    }

    public static function select_image( int $gallery_id, int $attach_id, string $variant ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return false;
        }

        $ok = self::upsert_selection( (int) $image['id'], [
            'crop_variant' => $variant,
            'wm_applied'   => 0,
        ] );
        if ( $ok ) {
            self::set_status( (int) $image['id'], self::STATUS_SELECTED );
        }
        return $ok;
    }

    public static function save_custom_crop( int $gallery_id, int $attach_id, float $scale, int $x, int $y ): ?array {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return null;
        }

        $crop = self::clamp_custom_crop( (int) $image['orig_w'], (int) $image['orig_h'], $scale, $x, $y );
        $ok   = self::upsert_selection( (int) $image['id'], [
            'crop_variant'      => 'custom',
            'custom_crop_scale' => $crop['scale'],
            'custom_crop_x'     => $crop['x'],
            'custom_crop_y'     => $crop['y'],
            'wm_applied'        => 0,
        ] );

        if ( ! $ok ) {
            return null;
        }
        self::set_status( (int) $image['id'], self::STATUS_SELECTED );
        return array_merge( $image, $crop );
    }

    public static function deselect_image( int $gallery_id, int $attach_id ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return false;
        }

        self::delete_selection( (int) $image['id'] );
        return self::set_status( (int) $image['id'], self::STATUS_CANDIDATE );
    }

    public static function set_watermark_corner( int $gallery_id, int $attach_id, string $corner ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return false;
        }
        if ( ! self::get_selection( (int) $image['id'] ) ) {
            return false;
        }
        return self::upsert_selection( (int) $image['id'], [
            'wm_corner'  => $corner,
            'wm_applied' => 0,
        ] );
    }

    public static function set_status( int $image_id, string $status, ?int $user_id = null ): bool {
        global $wpdb;
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            return false;
        }

        $row = [
            'status'     => $status,
            'updated_at' => current_time( 'mysql' ),
        ];
        if ( $status === self::STATUS_APPROVED ) {
            $row['approved_by'] = $user_id ?: get_current_user_id();
            $row['approved_at'] = current_time( 'mysql' );
        } else {
            $row['approved_by'] = null;
            $row['approved_at'] = null;
        }

        return (bool) $wpdb->update( RinCWC_DB::images_table(), $row, [ 'id' => $image_id ] );
    }

    public static function approve_image( int $gallery_id, int $attach_id ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image || $image['status'] === self::STATUS_CANDIDATE ) {
            return false;
        }
        return self::set_status( (int) $image['id'], self::STATUS_APPROVED, get_current_user_id() );
    }

    public static function unapprove_image( int $gallery_id, int $attach_id ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image || $image['status'] !== self::STATUS_APPROVED ) {
            return false;
        }
        return self::set_status( (int) $image['id'], self::STATUS_SELECTED );
    }

    public static function mark_watermark_applied( int $image_id, int $wm_file_id ): void {
        self::upsert_selection( $image_id, [
            'wm_file_id'  => $wm_file_id,
            'wm_applied' => 1,
        ] );
    }

    public static function mark_watermark_pending_for_file( int $wm_file_id ): void {
        global $wpdb;
        $wpdb->update( RinCWC_DB::selections_table(), [ 'wm_applied' => 0 ], [ 'wm_file_id' => $wm_file_id ] );
    }

    public static function get_review_stats(): array {
        $rows = self::all_images();
        $stats = [
            'candidates' => count( $rows ),
            'selected'   => 0,
            'approved'   => 0,
            'wm_pending' => 0,
        ];

        foreach ( $rows as $row ) {
            if ( $row['status'] === self::STATUS_SELECTED || $row['status'] === self::STATUS_APPROVED ) {
                $stats['selected']++;
            }
            if ( $row['status'] === self::STATUS_APPROVED ) {
                $stats['approved']++;
                if ( ! (int) $row['wm_applied'] && ! empty( $row['wm_corner'] ) ) {
                    $stats['wm_pending']++;
                }
            } elseif ( ! empty( $row['crop_variant'] ) && ! empty( $row['wm_corner'] ) && ! (int) $row['wm_applied'] ) {
                $stats['wm_pending']++;
            }
        }

        return $stats;
    }

    public static function list_watermarks(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' ORDER BY is_default DESC, name ASC',
            ARRAY_A
        ) ?: [];
    }

    public static function get_watermark( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' WHERE id = %d', $id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function get_default_watermark(): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1',
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function add_watermark( string $name, string $file_path, bool $is_default = false ): int {
        global $wpdb;
        if ( $is_default ) {
            $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        }
        $wpdb->insert( RinCWC_DB::watermarks_table(), [
            'name'       => sanitize_text_field( $name ),
            'file_path'  => $file_path,
            'is_default' => $is_default ? 1 : 0,
            'created_at' => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function set_default_watermark( int $id ): bool {
        global $wpdb;
        if ( ! self::get_watermark( $id ) ) {
            return false;
        }
        $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        $ok = (bool) $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 1 ], [ 'id' => $id ] );
        self::mark_all_watermarks_pending();
        return $ok;
    }

    public static function delete_watermark( int $id ): bool {
        global $wpdb;
        $wm = self::get_watermark( $id );
        if ( ! $wm || (int) $wm['is_default'] || self::watermark_in_use( $id ) ) {
            return false;
        }
        return (bool) $wpdb->delete( RinCWC_DB::watermarks_table(), [ 'id' => $id ] );
    }

    public static function watermark_in_use( int $id ): bool {
        global $wpdb;
        $sel = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RinCWC_DB::selections_table() . ' WHERE wm_file_id = %d', $id )
        );
        $gal = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RinCWC_DB::gallery_wm_table() . ' WHERE wm_file_id = %d', $id )
        );
        return ( $sel + $gal ) > 0;
    }

    public static function get_gallery_watermark_id( int $gallery_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT wm_file_id FROM ' . RinCWC_DB::gallery_wm_table() . ' WHERE gallery_id = %d', $gallery_id )
        );
    }

    public static function set_gallery_watermark( int $gallery_id, int $wm_file_id ): bool {
        global $wpdb;
        if ( $gallery_id <= 0 ) {
            return false;
        }
        if ( $wm_file_id <= 0 ) {
            $wpdb->delete( RinCWC_DB::gallery_wm_table(), [ 'gallery_id' => $gallery_id ] );
            self::mark_gallery_watermarks_pending( $gallery_id );
            return true;
        }
        if ( ! self::get_watermark( $wm_file_id ) ) {
            return false;
        }
        $ok = false !== $wpdb->replace( RinCWC_DB::gallery_wm_table(), [
            'gallery_id' => $gallery_id,
            'wm_file_id' => $wm_file_id,
        ] );
        self::mark_gallery_watermarks_pending( $gallery_id );
        return $ok;
    }

    public static function get_gallery_watermark_overrides(): array {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT gallery_id, wm_file_id FROM ' . RinCWC_DB::gallery_wm_table(), ARRAY_A ) ?: [];
        $out  = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['gallery_id'] ] = (int) $row['wm_file_id'];
        }
        return $out;
    }

    public static function get_effective_watermark( int $gallery_id ): ?array {
        $override = self::get_gallery_watermark_id( $gallery_id );
        if ( $override ) {
            $wm = self::get_watermark( $override );
            if ( $wm ) {
                return $wm;
            }
        }
        return self::get_default_watermark();
    }

    public static function mark_all_watermarks_pending(): void {
        global $wpdb;
        $wpdb->query( 'UPDATE ' . RinCWC_DB::selections_table() . ' SET wm_applied = 0' );
    }

    public static function mark_gallery_watermarks_pending( int $gallery_id ): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            'UPDATE ' . RinCWC_DB::selections_table() . ' s
             JOIN ' . RinCWC_DB::images_table() . ' i ON i.id = s.image_id
             SET s.wm_applied = 0
             WHERE i.gallery_id = %d',
            $gallery_id
        ) );
    }

    public static function approved_for_sync(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*, s.crop_variant, s.custom_crop_scale, s.custom_crop_x, s.custom_crop_y,
                    s.wm_corner, s.wm_file_id, s.wm_applied, p.post_date AS gallery_pub_date
             FROM " . RinCWC_DB::images_table() . " i
             JOIN " . RinCWC_DB::selections_table() . " s ON s.image_id = i.id
             LEFT JOIN {$wpdb->posts} p ON p.ID = i.gallery_id
             WHERE i.status = 'APPROVED' AND s.wm_applied = 1
             ORDER BY p.post_date DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function galleries_with_candidates(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT DISTINCT i.gallery_id, i.gallery_slug, i.gallery_title, p.post_date
             FROM " . RinCWC_DB::images_table() . " i
             LEFT JOIN {$wpdb->posts} p ON p.ID = i.gallery_id
             ORDER BY p.post_date DESC, i.gallery_title ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function max_crop_scale( int $orig_w, int $orig_h ): float {
        $orig_w = max( 1, $orig_w );
        $orig_h = max( 1, $orig_h );
        return ( $orig_w / $orig_h >= 3840 / 2160 ) ? $orig_h / 2160 : $orig_w / 3840;
    }

    public static function clamp_custom_crop( int $orig_w, int $orig_h, float $scale, int $x, int $y ): array {
        $orig_w = max( 1, $orig_w );
        $orig_h = max( 1, $orig_h );
        $scale  = max( 1.0, min( self::max_crop_scale( $orig_w, $orig_h ), $scale ) );
        $box_w  = (int) round( $scale * 3840 );
        $box_h  = (int) round( $scale * 2160 );
        $max_x  = max( 0, $orig_w - $box_w );
        $max_y  = max( 0, $orig_h - $box_h );

        return [
            'scale' => $scale,
            'x'     => max( 0, min( $max_x, $x ) ),
            'y'     => max( 0, min( $max_y, $y ) ),
            'box_w' => $box_w,
            'box_h' => $box_h,
        ];
    }
}
