<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Data {

    public const STATUS_CANDIDATE = 'CANDIDATE';
    public const STATUS_SELECTED  = 'SELECTED';
    public const STATUS_APPROVED  = 'APPROVED';

    private const VALID_STATUSES = [
        self::STATUS_CANDIDATE,
        self::STATUS_SELECTED,
        self::STATUS_APPROVED,
    ];

    private const VALID_CROPS = [
        'top',
        'center-top',
        'center',
        'center-bottom',
        'bottom',
        'custom',
    ];

    private const VALID_WM_CORNERS = [
        '',
        'top-left',
        'top-right',
        'bottom-left',
        'bottom-right',
    ];

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
        update_option( 'rincwc_settings', array_replace_recursive( self::get_settings(), $settings ), false );
    }

    public static function approve_allowed(): bool {
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

    public static function can_current_user_approve(): bool {
        return self::approve_allowed();
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

    public static function scan_target_galleries(): array {
        $settings       = self::get_settings();
        $excluded_ids   = array_filter( array_map( 'intval', (array) ( $settings['publish_galleries'] ?? [] ) ) );
        return array_filter(
            self::envira_galleries(),
            static function ( $post ) use ( $excluded_ids ): bool {
                if ( in_array( (int) $post->ID, $excluded_ids, true ) ) {
                    return false;
                }
                if ( $post->post_name === 'envira-default-gallery' ) {
                    return false;
                }
                return true;
            }
        );
    }

    /**
     * Cutoff sentinel values: -1 = entire gallery excluded ("Exclude all"), 0 = no
     * cutoff (nothing excluded), >0 = exclude from that position onward.
     */
    public static function set_gallery_cutoff( int $gallery_id, int $position ): void {
        global $wpdb;
        $table    = RinCWC_DB::images_table();
        $settings = self::get_settings();

        if ( $position < 0 ) {
            // Exclude entire gallery — sentinel cutoff of -1.
            $wpdb->update( $table, [ 'excluded' => 1 ], [ 'gallery_id' => $gallery_id ] );
            $settings['excluded_after'][ $gallery_id ] = -1;
        } elseif ( $position === 0 ) {
            // No cutoff — nothing excluded. Stored explicitly (not unset) so "reviewed,
            // accepted everything" stays distinguishable from "never touched at all";
            // has_cutoff_decision() is what tells those two apart.
            $wpdb->update( $table, [ 'excluded' => 0 ], [ 'gallery_id' => $gallery_id ] );
            $settings['excluded_after'][ $gallery_id ] = 0;
        } else {
            // Exclude from $position onward (position >= $position → excluded). This
            // also correctly clears a prior full exclusion, since every row's excluded
            // flag is recomputed from the new threshold regardless of its previous value.
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$table} SET excluded = CASE WHEN position >= %d THEN 1 ELSE 0 END WHERE gallery_id = %d",
                    $position, $gallery_id
                )
            );
            // Store as last-included position for scanner compat.
            $settings['excluded_after'][ $gallery_id ] = $position - 1;
        }
        update_option( 'rincwc_settings', $settings, false );
    }

    public static function get_cutoff( int $gallery_id ): int {
        $settings = self::get_settings();
        return (int) ( $settings['excluded_after'][ $gallery_id ] ?? 0 );
    }

    /**
     * Whether this gallery has ever had a cutoff decision made — including "Accept all"
     * (stored as an explicit 0), which must stay distinguishable from a gallery that has
     * simply never been looked at (no key at all, get_cutoff() also reads as 0).
     */
    public static function has_cutoff_decision( int $gallery_id ): bool {
        $settings = self::get_settings();
        return array_key_exists( $gallery_id, $settings['excluded_after'] ?? [] );
    }

    /**
     * Galleries with at least one scanned row but zero visible (non-excluded) rows —
     * either "Exclude all" was used, or a cutoff landed at/before the first position.
     * These never appear in get_visible_images(), so callers that need to know or
     * count them must go through this method instead.
     */
    public static function get_fully_excluded_gallery_ids(): array {
        global $wpdb;
        $table = RinCWC_DB::images_table();
        $ids   = $wpdb->get_col(
            "SELECT gallery_id FROM {$table} GROUP BY gallery_id HAVING SUM( excluded = 0 ) = 0"
        );
        return array_map( 'intval', $ids );
    }

    /**
     * Every excluded row, from any gallery — partially cut off or fully excluded.
     * get_visible_images() never returns these, so the Review page's Exclusions
     * filter needs this to show what actually got cut, not just fully-excluded sets.
     */
    public static function get_excluded_images(): array {
        global $wpdb;
        $img_table = RinCWC_DB::images_table();
        $sel_table = RinCWC_DB::selections_table();
        return $wpdb->get_results(
            "SELECT i.*,
                    s.id AS selection_id,
                    s.crop_variant,
                    s.custom_crop_scale,
                    s.custom_crop_x,
                    s.custom_crop_y,
                    s.wm_corner,
                    s.wm_file_id,
                    s.wm_applied
             FROM {$img_table} i
             LEFT JOIN {$sel_table} s ON s.image_id = i.id
             WHERE i.excluded = 1
             ORDER BY i.gallery_id DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
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

    public static function max_crop_scale( int $orig_w, int $orig_h ): float {
        $orig_w = max( 1, $orig_w );
        $orig_h = max( 1, $orig_h );
        return ( $orig_w / $orig_h >= 16 / 9 ) ? $orig_h / 2160 : $orig_w / 3840;
    }

    public static function clamp_custom_crop( array $image, float $scale, int $x, int $y ): array {
        $orig_w = max( 1, (int) $image['orig_w'] );
        $orig_h = max( 1, (int) $image['orig_h'] );
        $max_scale = max( 1.0, self::max_crop_scale( $orig_w, $orig_h ) );
        $scale  = max( 1.0, min( $max_scale, $scale ) );
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

    public static function preset_crop_box( array $image, string $variant ): array {
        $factor_map = [
            'top'           => 0.0,
            'center-top'    => 0.25,
            'center'        => 0.5,
            'center-bottom' => 0.75,
            'bottom'        => 1.0,
        ];
        $factor = $factor_map[ $variant ] ?? 0.5;
        $orig_w = max( 1, (int) $image['orig_w'] );
        $orig_h = max( 1, (int) $image['orig_h'] );
        $scale  = self::max_crop_scale( $orig_w, $orig_h );
        $box_w  = (int) round( $scale * 3840 );
        $box_h  = (int) round( $scale * 2160 );
        $max_x  = max( 0, $orig_w - $box_w );
        $max_y  = max( 0, $orig_h - $box_h );

        return [
            'scale' => $scale,
            'x'     => (int) round( $max_x * $factor ),
            'y'     => (int) round( $max_y * $factor ),
            'box_w' => $box_w,
            'box_h' => $box_h,
        ];
    }

    public static function crop_geometry( array $image, ?array $selection = null, string $variant = 'center' ): array {
        $selection = $selection ?: [];
        if ( $variant === 'custom' ) {
            return self::clamp_custom_crop(
                $image,
                (float) ( $selection['custom_crop_scale'] ?? self::max_crop_scale( (int) $image['orig_w'], (int) $image['orig_h'] ) ),
                (int) ( $selection['custom_crop_x'] ?? 0 ),
                (int) ( $selection['custom_crop_y'] ?? 0 )
            );
        }
        return self::preset_crop_box( $image, $variant ?: 'center' );
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
            'position'      => (int) ( $data['position'] ?? 0 ),
            'total'         => (int) ( $data['total'] ?? 0 ),
            'original_path' => (string) ( $data['original_path'] ?? '' ),
            'scaled_path'   => (string) ( $data['scaled_path'] ?? '' ),
            'src_url'       => esc_url_raw( $data['src_url'] ?? '' ),
            'orig_w'        => (int) ( $data['orig_w'] ?? 0 ),
            'orig_h'        => (int) ( $data['orig_h'] ?? 0 ),
            'excluded'      => empty( $data['excluded'] ) ? 0 : 1,
        ];

        $existing = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( $existing ) {
            // Preserve the manually-managed excluded flag on rescan.
            unset( $row['excluded'] );
            $wpdb->update( RinCWC_DB::images_table(), $row, [ 'id' => (int) $existing['id'] ] );
            return (int) $existing['id'];
        }

        $row['status'] = in_array( $data['status'] ?? '', self::VALID_STATUSES, true )
            ? $data['status']
            : self::STATUS_CANDIDATE;
        $wpdb->insert( RinCWC_DB::images_table(), $row );
        return (int) $wpdb->insert_id;
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

    public static function get_review_images( bool $include_excluded = false ): array {
        global $wpdb;
        $where = $include_excluded ? '1=1' : 'i.excluded = 0';
        return $wpdb->get_results(
            "SELECT i.*,
                    s.id AS selection_id,
                    s.crop_variant,
                    s.custom_crop_scale,
                    s.custom_crop_x,
                    s.custom_crop_y,
                    s.wm_corner,
                    s.wm_file_id,
                    s.wm_applied
             FROM " . RinCWC_DB::images_table() . ' i
             LEFT JOIN ' . RinCWC_DB::selections_table() . " s ON s.image_id = i.id
             WHERE {$where}
             ORDER BY i.gallery_id DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function get_visible_images(): array {
        return self::get_review_images( false );
    }

    public static function get_candidate_rows_for_admin(): array {
        return self::get_review_images( true );
    }

    public static function get_gallery_images( int $gallery_id, bool $include_excluded = true ): array {
        global $wpdb;
        $where = $include_excluded ? '' : ' AND excluded = 0';
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . RinCWC_DB::images_table() . " WHERE gallery_id = %d{$where} ORDER BY position ASC",
                $gallery_id
            ),
            ARRAY_A
        ) ?: [];
    }

    public static function count_by_status(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT status, COUNT(*) AS cnt FROM ' . RinCWC_DB::images_table() . ' WHERE excluded = 0 GROUP BY status',
            ARRAY_A
        ) ?: [];
        $out = [
            self::STATUS_CANDIDATE => 0,
            self::STATUS_SELECTED  => 0,
            self::STATUS_APPROVED  => 0,
        ];
        foreach ( $rows as $row ) {
            $out[ $row['status'] ] = (int) $row['cnt'];
        }
        return $out;
    }

    public static function counts(): array {
        $statuses = self::count_by_status();
        $rows     = self::get_review_images( false );
        return [
            'candidates' => count( $rows ),
            'selected'   => $statuses[ self::STATUS_SELECTED ] + $statuses[ self::STATUS_APPROVED ],
            'approved'   => $statuses[ self::STATUS_APPROVED ],
        ];
    }

    public static function upsert_selection( int $image_id, array $data ): bool {
        global $wpdb;

        if ( ! $image_id ) {
            return false;
        }

        $existing = self::get_selection( $image_id );
        $variant  = sanitize_key( $data['crop_variant'] ?? $existing['crop_variant'] ?? '' );
        if ( $variant && ! in_array( $variant, self::VALID_CROPS, true ) ) {
            return false;
        }

        $row = [
            'image_id'      => $image_id,
            'crop_variant' => $variant ?: null,
            'wm_corner'    => sanitize_text_field( $data['wm_corner'] ?? $existing['wm_corner'] ?? '' ),
            'wm_file_id'   => isset( $data['wm_file_id'] ) ? (int) $data['wm_file_id'] : ( $existing['wm_file_id'] ?? null ),
        ];
        if ( ! in_array( (string) $row['wm_corner'], self::VALID_WM_CORNERS, true ) ) {
            return false;
        }

        foreach ( [ 'custom_crop_scale', 'custom_crop_x', 'custom_crop_y' ] as $field ) {
            if ( array_key_exists( $field, $data ) ) {
                $row[ $field ] = $data[ $field ];
            } elseif ( $existing ) {
                $row[ $field ] = $existing[ $field ];
            } else {
                $row[ $field ] = null;
            }
        }

        $wm_applied = array_key_exists( 'wm_applied', $data )
            ? ( empty( $data['wm_applied'] ) ? 0 : 1 )
            : (int) ( $existing['wm_applied'] ?? 0 );

        if ( $existing ) {
            $crop_changed = $variant !== (string) ( $existing['crop_variant'] ?? '' )
                || (string) $row['custom_crop_scale'] !== (string) ( $existing['custom_crop_scale'] ?? '' )
                || (string) $row['custom_crop_x'] !== (string) ( $existing['custom_crop_x'] ?? '' )
                || (string) $row['custom_crop_y'] !== (string) ( $existing['custom_crop_y'] ?? '' )
                || (string) $row['wm_corner'] !== (string) ( $existing['wm_corner'] ?? '' )
                || (string) $row['wm_file_id'] !== (string) ( $existing['wm_file_id'] ?? '' );
            if ( $crop_changed && ! array_key_exists( 'wm_applied', $data ) ) {
                $wm_applied = 0;
            }
        }
        $row['wm_applied'] = $wm_applied;

        $ok = $existing
            ? $wpdb->update( RinCWC_DB::selections_table(), $row, [ 'image_id' => $image_id ] ) !== false
            : (bool) $wpdb->insert( RinCWC_DB::selections_table(), $row );

        if ( $ok && $wm_applied === 0 ) {
            self::demote_if_approved_without_watermark( $image_id );
        }

        return $ok;
    }

    /**
     * An APPROVED image must always have an applied watermark. If a crop or watermark
     * change just invalidated it, drop the image back to SELECTED until it's reapplied
     * and re-approved — "approved with watermark pending" must never be a stored state.
     */
    private static function demote_if_approved_without_watermark( int $image_id ): void {
        $image = self::get_image( $image_id );
        if ( $image && $image['status'] === self::STATUS_APPROVED ) {
            self::set_status( $image_id, self::STATUS_SELECTED );
        }
    }

    public static function get_selection( int $image_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( 'SELECT * FROM ' . RinCWC_DB::selections_table() . ' WHERE image_id = %d', $image_id ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function delete_selection( int $image_id ): bool {
        global $wpdb;
        return $wpdb->delete( RinCWC_DB::selections_table(), [ 'image_id' => $image_id ] ) !== false;
    }

    public static function select_image( int $gallery_id, int $attach_id, string $variant, array $custom = [] ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return false;
        }

        $data = [ 'crop_variant' => sanitize_key( $variant ) ];
        if ( $variant === 'custom' ) {
            $crop = self::clamp_custom_crop(
                $image,
                (float) ( $custom['scale'] ?? self::max_crop_scale( (int) $image['orig_w'], (int) $image['orig_h'] ) ),
                (int) ( $custom['x'] ?? 0 ),
                (int) ( $custom['y'] ?? 0 )
            );
            $data['custom_crop_scale'] = $crop['scale'];
            $data['custom_crop_x']     = $crop['x'];
            $data['custom_crop_y']     = $crop['y'];
        }

        $ok = self::upsert_selection( (int) $image['id'], $data );
        if ( $ok ) {
            self::set_status( (int) $image['id'], self::STATUS_SELECTED );
        }
        return $ok;
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
        $selection = self::get_selection( (int) $image['id'] );
        if ( ! $selection ) {
            return false;
        }

        return self::upsert_selection( (int) $image['id'], [
            'wm_corner'  => $corner,
            'wm_applied' => 0,
        ] );
    }

    public static function mark_watermark_applied( int $image_id, int $wm_file_id ): bool {
        return self::upsert_selection( $image_id, [
            'wm_file_id' => $wm_file_id,
            'wm_applied' => 1,
        ] );
    }

    public static function set_status( int $image_id, string $status, int $user_id = 0 ): bool {
        global $wpdb;
        if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
            return false;
        }

        $data = [ 'status' => $status ];
        if ( $status === self::STATUS_APPROVED ) {
            $data['approved_by'] = $user_id ?: get_current_user_id();
            $data['approved_at'] = current_time( 'mysql' );
        } else {
            $data['approved_by'] = null;
            $data['approved_at'] = null;
        }

        return $wpdb->update( RinCWC_DB::images_table(), $data, [ 'id' => $image_id ] ) !== false;
    }

    public static function approve( int $gallery_id, int $attach_id ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image ) {
            return false;
        }
        $selection = self::get_selection( (int) $image['id'] );
        if ( ! $selection || empty( $selection['crop_variant'] ) || empty( $selection['wm_corner'] ) || empty( $selection['wm_applied'] ) ) {
            return false;
        }
        return self::set_status( (int) $image['id'], self::STATUS_APPROVED );
    }

    public static function unapprove( int $gallery_id, int $attach_id ): bool {
        $image = self::get_image_by_gallery_attach( $gallery_id, $attach_id );
        if ( ! $image || $image['status'] !== self::STATUS_APPROVED ) {
            return false;
        }
        return self::set_status( (int) $image['id'], self::STATUS_SELECTED );
    }

    public static function add_watermark( string $name, string $file_path, bool $default = false ): int {
        global $wpdb;
        if ( $default ) {
            $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        }
        $wpdb->insert( RinCWC_DB::watermarks_table(), [
            'name'       => sanitize_text_field( $name ),
            'file_path'  => $file_path,
            'is_default' => $default ? 1 : 0,
            'created_at' => current_time( 'mysql' ),
        ] );
        return (int) $wpdb->insert_id;
    }

    public static function get_watermarks(): array {
        global $wpdb;
        return $wpdb->get_results( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' ORDER BY is_default DESC, name ASC', ARRAY_A ) ?: [];
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
        $row = $wpdb->get_row( 'SELECT * FROM ' . RinCWC_DB::watermarks_table() . ' WHERE is_default = 1 ORDER BY id ASC LIMIT 1', ARRAY_A );
        return $row ?: null;
    }

    public static function set_default_watermark( int $id ): bool {
        global $wpdb;
        $wm = self::get_watermark( $id );
        if ( ! $wm ) {
            return false;
        }
        $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 0 ], [ 'is_default' => 1 ] );
        $ok = $wpdb->update( RinCWC_DB::watermarks_table(), [ 'is_default' => 1 ], [ 'id' => $id ] ) !== false;
        if ( $ok ) {
            self::mark_all_watermarks_pending();
        }
        return $ok;
    }

    public static function delete_watermark( int $id ): bool {
        global $wpdb;
        $wm = self::get_watermark( $id );
        if ( ! $wm || ! empty( $wm['is_default'] ) || self::watermark_in_use( $id ) ) {
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

    public static function set_gallery_watermark( int $gallery_id, int $wm_file_id ): bool {
        global $wpdb;
        if ( ! $gallery_id ) {
            return false;
        }
        if ( ! $wm_file_id ) {
            $ok = $wpdb->delete( RinCWC_DB::gallery_wm_table(), [ 'gallery_id' => $gallery_id ] ) !== false;
            if ( $ok ) {
                self::mark_gallery_watermarks_pending( $gallery_id );
            }
            return $ok;
        }
        if ( ! self::get_watermark( $wm_file_id ) ) {
            return false;
        }
        $ok = $wpdb->replace( RinCWC_DB::gallery_wm_table(), [
            'gallery_id' => $gallery_id,
            'wm_file_id' => $wm_file_id,
        ] ) !== false;
        if ( $ok ) {
            self::mark_gallery_watermarks_pending( $gallery_id );
        }
        return $ok;
    }

    public static function get_gallery_watermarks(): array {
        global $wpdb;
        $rows = $wpdb->get_results( 'SELECT * FROM ' . RinCWC_DB::gallery_wm_table(), ARRAY_A ) ?: [];
        $out  = [];
        foreach ( $rows as $row ) {
            $out[ (int) $row['gallery_id'] ] = (int) $row['wm_file_id'];
        }
        return $out;
    }

    public static function get_effective_watermark( int $gallery_id ): ?array {
        global $wpdb;
        $wm_id = (int) $wpdb->get_var(
            $wpdb->prepare( 'SELECT wm_file_id FROM ' . RinCWC_DB::gallery_wm_table() . ' WHERE gallery_id = %d', $gallery_id )
        );
        if ( $wm_id ) {
            $wm = self::get_watermark( $wm_id );
            if ( $wm ) {
                return $wm;
            }
        }
        return self::get_default_watermark();
    }

    public static function mark_all_watermarks_pending(): void {
        global $wpdb;
        $wpdb->update( RinCWC_DB::selections_table(), [ 'wm_applied' => 0 ], [ 'wm_applied' => 1 ] );
        self::demote_all_approved_without_watermark();
    }

    public static function mark_gallery_watermarks_pending( int $gallery_id ): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . RinCWC_DB::selections_table() . ' s
                 JOIN ' . RinCWC_DB::images_table() . ' i ON i.id = s.image_id
                 SET s.wm_applied = 0
                 WHERE i.gallery_id = %d',
                $gallery_id
            )
        );
        self::demote_all_approved_without_watermark( $gallery_id );
    }

    /**
     * Bulk counterpart to demote_if_approved_without_watermark() for the two
     * batch "mark pending" paths, which write wm_applied directly via SQL rather
     * than through upsert_selection().
     */
    private static function demote_all_approved_without_watermark( ?int $gallery_id = null ): void {
        global $wpdb;
        $sql = 'UPDATE ' . RinCWC_DB::images_table() . ' i
                JOIN ' . RinCWC_DB::selections_table() . " s ON s.image_id = i.id
                SET i.status = '" . self::STATUS_SELECTED . "', i.approved_by = NULL, i.approved_at = NULL
                WHERE i.status = '" . self::STATUS_APPROVED . "' AND s.wm_applied = 0";
        if ( $gallery_id !== null ) {
            $sql = $wpdb->prepare( $sql . ' AND i.gallery_id = %d', $gallery_id );
        }
        $wpdb->query( $sql );
    }

    public static function approved_for_sync(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT i.*,
                    s.crop_variant,
                    s.custom_crop_scale,
                    s.custom_crop_x,
                    s.custom_crop_y,
                    s.wm_corner,
                    s.wm_file_id,
                    s.wm_applied,
                    p.post_date AS gallery_post_date
             FROM " . RinCWC_DB::images_table() . ' i
             JOIN ' . RinCWC_DB::selections_table() . ' s ON s.image_id = i.id
             LEFT JOIN ' . $wpdb->posts . " p ON p.ID = i.gallery_id
             WHERE i.status = 'APPROVED' AND s.wm_applied = 1
             ORDER BY p.post_date DESC, i.position ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function source_galleries(): array {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT gallery_id, gallery_slug, gallery_title, COUNT(*) AS total_images
             FROM ' . RinCWC_DB::images_table() . '
             GROUP BY gallery_id, gallery_slug, gallery_title
             ORDER BY gallery_title ASC',
            ARRAY_A
        ) ?: [];
    }
}
