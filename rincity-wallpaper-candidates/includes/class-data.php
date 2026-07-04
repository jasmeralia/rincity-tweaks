<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Data {

    public const STATUS_CANDIDATE = 'CANDIDATE';
    public const STATUS_SELECTED  = 'SELECTED';
    public const STATUS_APPROVED  = 'APPROVED';

    public static function default_settings(): array {
        return [
            'publish_galleries'  => [
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
        update_option( 'rincwc_settings', array_replace_recursive( self::get_settings(), $settings ), false );
    }

    public static function get_cutoff( int $gallery_id ): int {
        $settings = self::get_settings();
        return max( 0, (int) ( $settings['excluded_after'][ $gallery_id ] ?? 0 ) );
    }

    public static function set_cutoffs( array $cutoffs ): void {
        $clean = [];
        foreach ( $cutoffs as $gallery_id => $position ) {
            $gallery_id = (int) $gallery_id;
            $position   = (int) $position;
            if ( $gallery_id > 0 && $position > 0 ) {
                $clean[ $gallery_id ] = $position;
            }
        }
        self::update_settings( [ 'excluded_after' => $clean ] );
    }

    public static function can_current_user_approve(): bool {
        $user = get_userdata( get_current_user_id() );
        if ( $user && in_array( $user->user_login, [ 'rincity', 'rincity_member' ], true ) ) {
            return true;
        }

        $settings = self::get_settings();
        return ! empty( $settings['allow_test_approve'] ) && current_user_can( 'manage_options' );
    }

    public static function envira_galleries(): array {
        return get_posts( [
            'post_type'      => 'envira',
            'post_status'    => [ 'publish', 'future', 'draft', 'private' ],
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );
    }

    public static function upsert_image( array $data ): int {
        global $wpdb;
        $gallery_id = (int) ( $data['gallery_id'] ?? 0 );
        $attach_id  = (int) ( $data['attach_id'] ?? 0 );
        if ( ! $gallery_id || ! $attach_id ) {
            return 0;
        }

        $row = [
            'gallery_id'    => $gallery_id,
            'gallery_slug'  => sanitize_title( $data['gallery_slug'] ?? '' ),
            'gallery_title' => sanitize_text_field( $data['gallery_title'] ?? '' ),
            'attach_id'     => $attach_id,
            'position'      => isset( $data['position'] ) ? (int) $data['position'] : null,
            'total'         => isset( $data['total'] ) ? (int) $data['total'] : null,
            'original_path' => (string) ( $data['original_path'] ?? '' ),
            'scaled_path'   => (string) ( $data['scaled_path'] ?? '' ),
            'src_url'       => esc_url_raw( $data['src_url'] ?? '' ),
            'orig_w'        => isset( $data['orig_w'] ) ? (int) $data['orig_w'] : null,
            'orig_h'        => isset( $data['orig_h'] ) ? (int) $data['orig_h'] : null,
            'excluded'      => ! empty( $data['excluded'] ) ? 1 : 0,
            'updated_at'    => current_time( 'mysql' ),
        ];

        $existing = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( $existing ) {
            unset( $row['gallery_id'], $row['attach_id'] );
            if ( isset( $data['status'] ) && self::is_valid_status( $data['status'] ) ) {
                $row['status'] = $data['status'];
            }
            $wpdb->update( RinCWC_DB::images_table(), $row, [ 'id' => (int) $existing['id'] ] );
            return (int) $existing['id'];
        }

        $row['status']     = self::is_valid_status( $data['status'] ?? '' ) ? $data['status'] : self::STATUS_CANDIDATE;
        $row['created_at'] = current_time( 'mysql' );
        $wpdb->insert( RinCWC_DB::images_table(), $row );
        return (int) $wpdb->insert_id;
    }

    public static function get_image( int $image_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . RinCWC_DB::images_table() . ' WHERE id = %d',
            $image_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_image_by_gallery_attach( int $gallery_id, int $attach_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . RinCWC_DB::images_table() . ' WHERE gallery_id = %d AND attach_id = %d',
            $gallery_id,
            $attach_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_visible_images(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT i.*, s.crop_variant, s.custom_crop_scale, s.custom_crop_x, s.custom_crop_y,
                    s.wm_corner, s.wm_file_id, s.wm_applied
             FROM ' . RinCWC_DB::images_table() . ' i
             LEFT JOIN ' . RinCWC_DB::selections_table() . ' s ON s.image_id = i.id
             WHERE i.excluded = 0
             ORDER BY i.gallery_id DESC, i.position ASC',
            ARRAY_A
        ) ?: [];
    }

    public static function get_candidate_rows_for_admin(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT * FROM ' . RinCWC_DB::images_table() . ' ORDER BY gallery_id DESC, position ASC',
            ARRAY_A
        ) ?: [];
    }

    public static function get_selection( int $image_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . RinCWC_DB::selections_table() . ' WHERE image_id = %d',
            $image_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public static function upsert_selection( int $image_id, array $data ): bool {
        global $wpdb;
        if ( ! $image_id ) {
            return false;
        }

        $existing = self::get_selection( $image_id );
        $row      = [
            'crop_variant'      => sanitize_key( $data['crop_variant'] ?? '' ),
            'custom_crop_scale' => isset( $data['custom_crop_scale'] ) && $data['custom_crop_scale'] !== null ? (float) $data['custom_crop_scale'] : null,
            'custom_crop_x'     => isset( $data['custom_crop_x'] ) && $data['custom_crop_x'] !== null ? (int) $data['custom_crop_x'] : null,
            'custom_crop_y'     => isset( $data['custom_crop_y'] ) && $data['custom_crop_y'] !== null ? (int) $data['custom_crop_y'] : null,
            'wm_corner'         => sanitize_text_field( $data['wm_corner'] ?? '' ),
            'wm_file_id'        => isset( $data['wm_file_id'] ) && $data['wm_file_id'] ? (int) $data['wm_file_id'] : null,
            'wm_applied'        => ! empty( $data['wm_applied'] ) ? 1 : 0,
            'updated_at'        => current_time( 'mysql' ),
        ];

        if ( ! $row['crop_variant'] ) {
            return false;
        }

        if ( $existing ) {
            if ( array_key_exists( 'wm_corner', $data ) && (string) $existing['wm_corner'] !== (string) $row['wm_corner'] ) {
                $row['wm_applied'] = 0;
            }
            if ( array_key_exists( 'crop_variant', $data ) && (string) $existing['crop_variant'] !== (string) $row['crop_variant'] ) {
                $row['wm_applied'] = 0;
            }
            return (bool) $wpdb->update( RinCWC_DB::selections_table(), $row, [ 'image_id' => $image_id ] );
        }

        $row['image_id']    = $image_id;
        $row['created_at']  = current_time( 'mysql' );
        return (bool) $wpdb->insert( RinCWC_DB::selections_table(), $row );
    }

    public static function select_image( int $image_id, string $variant, array $custom = [] ): bool {
        $image = self::get_image( $image_id );
        if ( ! $image ) {
            return false;
        }

        $existing = self::get_selection( $image_id );
        $data     = [
            'crop_variant'      => $variant,
            'custom_crop_scale' => $custom['scale'] ?? null,
            'custom_crop_x'     => $custom['x'] ?? null,
            'custom_crop_y'     => $custom['y'] ?? null,
            'wm_corner'         => $existing['wm_corner'] ?? '',
            'wm_file_id'        => $existing['wm_file_id'] ?? null,
            'wm_applied'        => 0,
        ];

        $ok = self::upsert_selection( $image_id, $data );
        if ( $ok ) {
            self::set_status( $image_id, self::STATUS_SELECTED );
        }
        return $ok;
    }

    public static function deselect_image( int $image_id ): bool {
        global $wpdb;
        $wpdb->delete( RinCWC_DB::selections_table(), [ 'image_id' => $image_id ] );
        return self::set_status( $image_id, self::STATUS_CANDIDATE );
    }

    public static function set_watermark_corner( int $image_id, string $corner ): bool {
        $valid = [ '', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
        if ( ! in_array( $corner, $valid, true ) ) {
            return false;
        }

        $selection = self::get_selection( $image_id );
        if ( ! $selection ) {
            return false;
        }

        $old_corner             = (string) ( $selection['wm_corner'] ?? '' );
        $selection['wm_corner'] = $corner;
        if ( $old_corner !== $corner ) {
            $selection['wm_applied'] = 0;
        }
        return self::upsert_selection( $image_id, $selection );
    }

    public static function set_status( int $image_id, string $status, ?int $user_id = null ): bool {
        global $wpdb;
        if ( ! self::is_valid_status( $status ) ) {
            return false;
        }

        $row = [
            'status'     => $status,
            'updated_at' => current_time( 'mysql' ),
        ];

        if ( $status === self::STATUS_APPROVED ) {
            $row['approved_by'] = $user_id ?: get_current_user_id();
            $row['approved_at'] = current_time( 'mysql' );
        } elseif ( $status === self::STATUS_SELECTED ) {
            $row['approved_by'] = null;
            $row['approved_at'] = null;
        } elseif ( $status === self::STATUS_CANDIDATE ) {
            $row['approved_by'] = null;
            $row['approved_at'] = null;
        }

        return (bool) $wpdb->update( RinCWC_DB::images_table(), $row, [ 'id' => $image_id ] );
    }

    public static function approve( int $image_id ): bool {
        $selection = self::get_selection( $image_id );
        if ( ! $selection || empty( $selection['crop_variant'] ) || empty( $selection['wm_corner'] ) ) {
            return false;
        }
        return self::set_status( $image_id, self::STATUS_APPROVED, get_current_user_id() );
    }

    public static function unapprove( int $image_id ): bool {
        return self::set_status( $image_id, self::STATUS_SELECTED );
    }

    public static function selected_with_image( bool $only_pending_crops = false ): array {
        global $wpdb;
        $where = $only_pending_crops ? "WHERE s.crop_variant <> ''" : '';
        return $wpdb->get_results(
            "SELECT i.*, s.id AS selection_id, s.crop_variant, s.custom_crop_scale, s.custom_crop_x,
                    s.custom_crop_y, s.wm_corner, s.wm_file_id, s.wm_applied
             FROM " . RinCWC_DB::selections_table() . " s
             JOIN " . RinCWC_DB::images_table() . " i ON i.id = s.image_id
             {$where}
             ORDER BY i.gallery_id DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function approved_for_publish(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*, s.crop_variant, s.wm_corner, s.wm_file_id, s.wm_applied
             FROM " . RinCWC_DB::images_table() . " i
             JOIN " . RinCWC_DB::selections_table() . " s ON s.image_id = i.id
             WHERE i.status = 'APPROVED' AND s.wm_applied = 1
             ORDER BY i.gallery_id DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function counts(): array {
        global $wpdb;
        $images = RinCWC_DB::images_table();
        $sel    = RinCWC_DB::selections_table();
        return [
            'candidates' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$images} WHERE excluded = 0" ),
            'selected'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$images} WHERE status IN ('SELECTED','APPROVED') AND excluded = 0" ),
            'approved'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$images} WHERE status = 'APPROVED' AND excluded = 0" ),
            'wm_pending' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sel} s JOIN {$images} i ON i.id = s.image_id WHERE i.excluded = 0 AND i.status = 'APPROVED' AND s.wm_applied = 0" ),
        ];
    }

    public static function watermarks(): array {
        global $wpdb;
        return $wpdb->get_results( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' ORDER BY is_default DESC, name ASC', ARRAY_A ) ?: [];
    }

    public static function get_watermark( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' WHERE id = %d', $id ), ARRAY_A );
        return $row ?: null;
    }

    public static function default_watermark(): ?array {
        global $wpdb;
        $row = $wpdb->get_row( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1', ARRAY_A );
        return $row ?: null;
    }

    public static function add_watermark( string $name, string $path, bool $default = false ): int {
        global $wpdb;
        if ( $default ) {
            $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        }
        $wpdb->insert( RinCWC_DB::watermarks_table(), [
            'name'       => sanitize_text_field( $name ),
            'file_path'  => $path,
            'is_default' => $default ? 1 : 0,
            'created_at' => current_time( 'mysql' ),
        ] );
        if ( $default ) {
            self::mark_all_watermarks_pending();
        }
        return (int) $wpdb->insert_id;
    }

    public static function set_default_watermark( int $id ): bool {
        global $wpdb;
        if ( ! self::get_watermark( $id ) ) {
            return false;
        }
        $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        $ok = (bool) $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 1 ], [ 'id' => $id ] );
        if ( $ok ) {
            self::mark_all_watermarks_pending();
        }
        return $ok;
    }

    public static function delete_watermark( int $id ): bool {
        global $wpdb;
        if ( self::watermark_in_use( $id ) ) {
            return false;
        }
        $row = self::get_watermark( $id );
        if ( ! $row || ! empty( $row['is_default'] ) ) {
            return false;
        }
        $ok = (bool) $wpdb->delete( RinCWC_DB::watermarks_table(), [ 'id' => $id ] );
        if ( $ok && ! empty( $row['file_path'] ) && str_starts_with( (string) $row['file_path'], RINCWC_WATERMARKS_DIR ) && file_exists( $row['file_path'] ) ) {
            @unlink( $row['file_path'] );
        }
        return $ok;
    }

    public static function watermark_in_use( int $id ): bool {
        global $wpdb;
        $sel_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RinCWC_DB::selections_table() . ' WHERE wm_file_id = %d', $id ) );
        $gal_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RinCWC_DB::gallery_wm_table() . ' WHERE wm_file_id = %d', $id ) );
        return $sel_count > 0 || $gal_count > 0;
    }

    public static function gallery_watermark_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT gallery_id, wm_file_id FROM ' . RinCWC_DB::gallery_wm_table(), ARRAY_A ) ?: [];
        $map  = [];
        foreach ( $rows as $row ) {
            $map[ (int) $row['gallery_id'] ] = (int) $row['wm_file_id'];
        }
        return $map;
    }

    public static function set_gallery_watermark( int $gallery_id, int $wm_id ): bool {
        global $wpdb;
        if ( ! $gallery_id ) {
            return false;
        }
        if ( $wm_id <= 0 ) {
            $ok = $wpdb->delete( RinCWC_DB::gallery_wm_table(), [ 'gallery_id' => $gallery_id ] ) !== false;
            self::mark_gallery_watermarks_pending( $gallery_id );
            return $ok;
        }
        if ( ! self::get_watermark( $wm_id ) ) {
            return false;
        }
        $exists = isset( self::gallery_watermark_map()[ $gallery_id ] );
        $row    = [ 'gallery_id' => $gallery_id, 'wm_file_id' => $wm_id ];
        $ok     = $exists
            ? (bool) $wpdb->update( RinCWC_DB::gallery_wm_table(), [ 'wm_file_id' => $wm_id ], [ 'gallery_id' => $gallery_id ] )
            : (bool) $wpdb->insert( RinCWC_DB::gallery_wm_table(), $row );
        if ( $ok ) {
            self::mark_gallery_watermarks_pending( $gallery_id );
        }
        return $ok;
    }

    public static function effective_watermark_for_gallery( int $gallery_id ): ?array {
        $map = self::gallery_watermark_map();
        if ( ! empty( $map[ $gallery_id ] ) ) {
            $wm = self::get_watermark( (int) $map[ $gallery_id ] );
            if ( $wm ) {
                return $wm;
            }
        }
        return self::default_watermark();
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

    public static function crop_geometry( array $image, ?array $selection = null, ?string $variant = null ): array {
        $orig_w  = max( 1, (int) ( $image['orig_w'] ?? 0 ) );
        $orig_h  = max( 1, (int) ( $image['orig_h'] ?? 0 ) );
        $variant = $variant ?: (string) ( $selection['crop_variant'] ?? '' );

        if ( $variant === 'custom' && $selection && ! empty( $selection['custom_crop_scale'] ) ) {
            $scale = (float) $selection['custom_crop_scale'];
            $box_w = (int) round( $scale * 3840 );
            $box_h = (int) round( $scale * 2160 );
            $max_x = max( 0, $orig_w - $box_w );
            $max_y = max( 0, $orig_h - $box_h );
            return [
                'scale' => $scale,
                'x'     => min( $max_x, max( 0, (int) ( $selection['custom_crop_x'] ?? 0 ) ) ),
                'y'     => min( $max_y, max( 0, (int) ( $selection['custom_crop_y'] ?? 0 ) ) ),
                'w'     => min( $orig_w, $box_w ),
                'h'     => min( $orig_h, $box_h ),
                'max_x' => $max_x,
                'max_y' => $max_y,
            ];
        }

        $scale = self::max_crop_scale( $orig_w, $orig_h );
        $box_w = min( $orig_w, (int) round( $scale * 3840 ) );
        $box_h = min( $orig_h, (int) round( $scale * 2160 ) );
        $max_x = max( 0, $orig_w - $box_w );
        $max_y = max( 0, $orig_h - $box_h );
        $pct   = [
            'top'           => 0.0,
            'center-top'    => 0.25,
            'center'        => 0.5,
            'center-bottom' => 0.75,
            'bottom'        => 1.0,
        ][ $variant ] ?? 0.5;

        return [
            'scale' => $scale,
            'x'     => (int) round( $max_x * $pct ),
            'y'     => (int) round( $max_y * $pct ),
            'w'     => $box_w,
            'h'     => $box_h,
            'max_x' => $max_x,
            'max_y' => $max_y,
        ];
    }

    public static function max_crop_scale( int $orig_w, int $orig_h ): float {
        if ( $orig_w <= 0 || $orig_h <= 0 ) {
            return 1.0;
        }
        if ( $orig_w / $orig_h >= 16 / 9 ) {
            return max( 1.0, $orig_h / 2160 );
        }
        return max( 1.0, $orig_w / 3840 );
    }

    public static function image_id_from_request( WP_REST_Request $req ): int {
        $image_id = (int) ( $req->get_param( 'image_id' ) ?? 0 );
        if ( $image_id ) {
            return $image_id;
        }
        $image = self::get_image_by_gallery_attach(
            (int) ( $req->get_param( 'gallery_id' ) ?? 0 ),
            (int) ( $req->get_param( 'attach_id' ) ?? 0 )
        );
        return $image ? (int) $image['id'] : 0;
    }

    private static function is_valid_status( string $status ): bool {
        return in_array( $status, [ self::STATUS_CANDIDATE, self::STATUS_SELECTED, self::STATUS_APPROVED ], true );
    }
}
