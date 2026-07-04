<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Wallpaper_Scanner {

    private const CACHE_GROUP = 'rincwc';
    private const CACHE_KEY   = 'scan_results';
    private const STAMP_KEY   = 'scan_timestamp';

    private const TIERS = [
        '4k'    => [ 'width' => 3840, 'height' => 2160, 'label' => '4K' ],
        '1440p' => [ 'width' => 2560, 'height' => 1440, 'label' => '1440p' ],
        '1080p' => [ 'width' => 1920, 'height' => 1080, 'label' => '1080p' ],
    ];

    public static function get_results( bool $force = false ): array {
        if ( ! $force ) {
            $cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
            if ( is_array( $cached ) ) {
                return $cached;
            }
            $results = self::rows_to_admin_results( RinCWC_Data::get_candidate_rows_for_admin() );
            wp_cache_set( self::CACHE_KEY, $results, self::CACHE_GROUP, 0 );
            return $results;
        }

        $results = self::scan_all( true );
        wp_cache_set( self::CACHE_KEY, $results, self::CACHE_GROUP, 0 );
        wp_cache_set( self::STAMP_KEY, current_time( 'mysql' ), self::CACHE_GROUP, 0 );
        update_option( 'rincwc_last_scan', current_time( 'mysql' ), false );
        return $results;
    }

    public static function get_timestamp(): ?string {
        $ts = wp_cache_get( self::STAMP_KEY, self::CACHE_GROUP );
        if ( $ts ) {
            return $ts;
        }
        $stored = get_option( 'rincwc_last_scan', '' );
        return $stored ?: null;
    }

    public static function clear_cache(): void {
        wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
        wp_cache_delete( self::STAMP_KEY, self::CACHE_GROUP );
    }

    public static function scan_all( bool $commit = false ): array {
        $rows = [];
        foreach ( RinCWC_Data::envira_galleries() as $post ) {
            $rows = array_merge( $rows, self::scan_gallery( (int) $post->ID, $commit ) );
        }
        if ( $commit ) {
            update_option( 'rincwc_last_scan', current_time( 'mysql' ), false );
        }
        return self::rows_to_admin_results( $rows );
    }

    public static function scan_gallery( int $gallery_id, bool $commit = false ): array {
        $post = get_post( $gallery_id );
        if ( ! $post || $post->post_type !== 'envira' ) {
            return [];
        }

        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        if ( ! is_array( $gallery_data ) || empty( $gallery_data['gallery'] ) || ! is_array( $gallery_data['gallery'] ) ) {
            return [];
        }

        $images = $gallery_data['gallery'];
        $total  = count( $images );
        $cutoff = RinCWC_Data::get_cutoff( $gallery_id );
        $rows   = [];
        $idx    = 0;

        foreach ( $images as $att_id => $entry ) {
            $idx++;
            $att_id = (int) $att_id;
            if ( ! $att_id ) {
                continue;
            }

            $paths = self::attachment_paths( $att_id );
            if ( ! $paths['original_path'] ) {
                continue;
            }

            $dims = self::identify_dimensions( $paths['original_path'] );
            if ( ! $dims ) {
                continue;
            }

            $orig_w = (int) $dims['w'];
            $orig_h = (int) $dims['h'];
            if ( $orig_w <= $orig_h || $orig_w < 3840 ) {
                continue;
            }

            $src_url = '';
            if ( is_array( $entry ) && ! empty( $entry['src'] ) ) {
                $src_url = (string) $entry['src'];
            }
            if ( ! $src_url ) {
                $src_url = (string) wp_get_attachment_url( $att_id );
            }

            $row = [
                'gallery_id'    => $gallery_id,
                'gallery_slug'  => $post->post_name,
                'gallery_title' => $post->post_title,
                'attach_id'     => $att_id,
                'position'      => $idx,
                'total'         => $total,
                'original_path' => $paths['original_path'],
                'scaled_path'   => $paths['scaled_path'],
                'src_url'       => $src_url,
                'orig_w'        => $orig_w,
                'orig_h'        => $orig_h,
                'excluded'      => $cutoff > 0 && $idx > $cutoff ? 1 : 0,
                'status'        => RinCWC_Data::STATUS_CANDIDATE,
            ];

            if ( $commit ) {
                RinCWC_Data::upsert_image( $row );
            }

            $rows[] = $row;
        }

        if ( $commit ) {
            self::clear_cache();
        }

        return $rows;
    }

    private static function attachment_paths( int $att_id ): array {
        $original_path = wp_get_original_image_path( $att_id );
        if ( ! $original_path || ! file_exists( $original_path ) ) {
            $original_path = get_attached_file( $att_id );
        }
        if ( ! $original_path || ! file_exists( $original_path ) ) {
            $original_path = '';
        }

        $scaled_path = get_attached_file( $att_id );
        if ( ! $scaled_path || ! file_exists( $scaled_path ) ) {
            $scaled_path = $original_path;
        }

        return [
            'original_path' => $original_path,
            'scaled_path'   => $scaled_path,
        ];
    }

    private static function identify_dimensions( string $path ): ?array {
        $cmd = 'identify -format ' . escapeshellarg( '%w %h' ) . ' ' . escapeshellarg( $path ) . ' 2>/dev/null';
        $out = trim( (string) shell_exec( $cmd ) );
        if ( preg_match( '/^(\d+)\s+(\d+)$/', $out, $m ) ) {
            return [ 'w' => (int) $m[1], 'h' => (int) $m[2] ];
        }

        $size = @getimagesize( $path );
        if ( ! $size ) {
            return null;
        }
        return [ 'w' => (int) $size[0], 'h' => (int) $size[1] ];
    }

    private static function rows_to_admin_results( array $rows ): array {
        $by_gallery = [];
        foreach ( $rows as $row ) {
            $gid = (int) $row['gallery_id'];
            if ( ! isset( $by_gallery[ $gid ] ) ) {
                $post = get_post( $gid );
                $by_gallery[ $gid ] = [
                    'gallery_id'      => $gid,
                    'title'           => $post ? $post->post_title : (string) ( $row['gallery_title'] ?? '' ),
                    'url'             => $post ? get_permalink( $post ) : '',
                    'published_at'    => $post ? $post->post_date : '0000-00-00 00:00:00',
                    'categories'      => self::categories_for_gallery( $gid ),
                    'favorites_count' => 0,
                    'comment_count'   => $post ? (int) $post->comment_count : 0,
                    'candidates'      => [],
                ];
            }

            $width  = (int) ( $row['orig_w'] ?? $row['width'] ?? 0 );
            $height = (int) ( $row['orig_h'] ?? $row['height'] ?? 0 );
            $by_gallery[ $gid ]['candidates'][] = [
                'attachment_id' => (int) $row['attach_id'],
                'position'      => (int) $row['position'],
                'set_total'     => (int) $row['total'],
                'thumbnail_url' => (string) ( $row['src_url'] ?? wp_get_attachment_url( (int) $row['attach_id'] ) ),
                'width'         => $width,
                'height'        => $height,
                'filesize'      => ! empty( $row['original_path'] ) && file_exists( $row['original_path'] ) ? (int) filesize( $row['original_path'] ) : 0,
                'aspect_ratio'  => $height ? round( $width / $height, 2 ) : 0,
                'tiers'         => self::cropping_info( $width, $height ),
                'excluded'      => ! empty( $row['excluded'] ),
            ];
        }

        $results = array_values( $by_gallery );
        self::fill_favorites_counts( $results );
        return $results;
    }

    private static function categories_for_gallery( int $gallery_id ): array {
        $terms      = get_the_terms( $gallery_id, 'envira-category' );
        $categories = [];
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $link         = get_term_link( $term );
                $categories[] = [
                    'name' => $term->name,
                    'url'  => is_wp_error( $link ) ? null : (string) $link,
                ];
            }
        }
        return $categories;
    }

    private static function fill_favorites_counts( array &$results ): void {
        if ( empty( $results ) ) {
            return;
        }

        global $wpdb;
        $fav_table = $wpdb->prefix . 'rincity_gallery_favorites';
        $ids_in    = implode( ',', array_map( 'intval', wp_list_pluck( $results, 'gallery_id' ) ) );
        if ( ! $ids_in ) {
            return;
        }

        $rows = $wpdb->get_results(
            "SELECT gallery_id, COUNT(*) AS cnt FROM {$fav_table} WHERE gallery_id IN ({$ids_in}) GROUP BY gallery_id",
            ARRAY_A
        );
        $fav_map = [];
        foreach ( $rows ?: [] as $row ) {
            $fav_map[ (int) $row['gallery_id'] ] = (int) $row['cnt'];
        }
        foreach ( $results as &$result ) {
            $result['favorites_count'] = $fav_map[ (int) $result['gallery_id'] ] ?? 0;
        }
        unset( $result );
    }

    private static function cropping_info( int $w, int $h ): array {
        $ratio_target = 16.0 / 9.0;
        $result       = [];
        if ( $w <= 0 || $h <= 0 ) {
            return $result;
        }

        foreach ( self::TIERS as $key => $tier ) {
            if ( $w / $h > $ratio_target + 0.001 ) {
                $effective_w  = $h * $ratio_target;
                $crop_each    = ( $w - $effective_w ) / 2.0;
                $pct_retained = round( $effective_w / $w * 100.0, 1 );
                $qualifies    = $h >= $tier['height'];
                $direction    = 'sides';
            } elseif ( $w / $h < $ratio_target - 0.001 ) {
                $effective_h  = $w / $ratio_target;
                $crop_each    = ( $h - $effective_h ) / 2.0;
                $pct_retained = round( $effective_h / $h * 100.0, 1 );
                $qualifies    = $w >= $tier['width'];
                $direction    = 'top_bottom';
            } else {
                $crop_each    = 0.0;
                $pct_retained = 100.0;
                $qualifies    = $w >= $tier['width'];
                $direction    = 'none';
            }

            $result[ $key ] = [
                'label'        => $tier['label'],
                'qualifies'    => $qualifies,
                'direction'    => $direction,
                'crop_px'      => (int) round( $crop_each ),
                'pct_retained' => $pct_retained,
            ];
        }

        return $result;
    }
}
