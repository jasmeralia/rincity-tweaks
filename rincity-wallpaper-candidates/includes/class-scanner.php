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

    private const TIER_ORDER = [ '1080p' => 0, '1440p' => 1, '4k' => 2 ];

    public static function get_results( bool $force = false ): array {
        if ( ! $force ) {
            $cached = wp_cache_get( self::CACHE_KEY, self::CACHE_GROUP );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        return self::run_scan();
    }

    public static function get_timestamp(): ?string {
        $ts = wp_cache_get( self::STAMP_KEY, self::CACHE_GROUP );
        return $ts ?: null;
    }

    public static function clear_cache(): void {
        wp_cache_delete( self::CACHE_KEY, self::CACHE_GROUP );
        wp_cache_delete( self::STAMP_KEY, self::CACHE_GROUP );
    }

    private static function run_scan(): array {
        $tail_pct  = (int) get_option( 'rincwc_tail_exclude_pct', 33 );
        $min_tier  = (string) get_option( 'rincwc_min_tier', '1080p' );
        $min_order = self::TIER_ORDER[ $min_tier ] ?? 0;

        $galleries = get_posts( [
            'post_type'      => 'envira',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] );

        $results                    = [];
        $gallery_ids_with_candidates = [];

        foreach ( $galleries as $post ) {
            $gallery_data = get_post_meta( $post->ID, '_eg_gallery_data', true );
            if ( ! is_array( $gallery_data ) || empty( $gallery_data['gallery'] ) ) {
                continue;
            }

            $images    = $gallery_data['gallery'];
            $total     = count( $images );
            $cutoff    = (int) floor( $total * ( 1.0 - $tail_pct / 100.0 ) );
            $att_ids   = array_keys( $images );
            $candidates = [];

            foreach ( $att_ids as $idx => $att_id ) {
                if ( $idx >= $cutoff ) {
                    break;
                }

                $att_id = (int) $att_id;
                $meta   = wp_get_attachment_metadata( $att_id );
                if ( ! $meta || empty( $meta['width'] ) || empty( $meta['height'] ) ) {
                    continue;
                }

                // Quick landscape check on cached metadata — avoids disk I/O for portraits.
                if ( (int) $meta['width'] <= (int) $meta['height'] ) {
                    continue;
                }

                // Get true original dimensions via getimagesize() on the source file.
                $original_path = wp_get_original_image_path( $att_id );
                if ( ! $original_path || ! file_exists( $original_path ) ) {
                    $original_path = get_attached_file( $att_id );
                }
                if ( ! $original_path || ! file_exists( $original_path ) ) {
                    continue;
                }

                $size = @getimagesize( $original_path );
                if ( ! $size ) {
                    continue;
                }

                $orig_w = (int) $size[0];
                $orig_h = (int) $size[1];

                if ( $orig_w <= $orig_h ) {
                    continue;
                }

                $tiers     = self::cropping_info( $orig_w, $orig_h );
                $qualifies = false;
                foreach ( $tiers as $key => $tier_data ) {
                    if ( ( self::TIER_ORDER[ $key ] ?? 0 ) >= $min_order && $tier_data['qualifies'] ) {
                        $qualifies = true;
                        break;
                    }
                }

                if ( ! $qualifies ) {
                    continue;
                }

                $file_size = @filesize( $original_path );
                $thumb_url = $images[ $att_id ]['src'] ?? wp_get_attachment_url( $att_id );

                $candidates[] = [
                    'attachment_id' => $att_id,
                    'position'      => $idx + 1,
                    'set_total'     => $total,
                    'thumbnail_url' => (string) $thumb_url,
                    'width'         => $orig_w,
                    'height'        => $orig_h,
                    'filesize'      => $file_size !== false ? (int) $file_size : 0,
                    'aspect_ratio'  => round( $orig_w / $orig_h, 2 ),
                    'tiers'         => $tiers,
                ];
            }

            if ( empty( $candidates ) ) {
                continue;
            }

            $terms      = get_the_terms( $post->ID, 'envira-category' );
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

            $gallery_ids_with_candidates[] = $post->ID;
            $results[] = [
                'gallery_id'      => $post->ID,
                'title'           => $post->post_title,
                'url'             => get_permalink( $post->ID ),
                'published_at'    => $post->post_date,
                'categories'      => $categories,
                'favorites_count' => 0,
                'comment_count'   => (int) $post->comment_count,
                'candidates'      => $candidates,
            ];
        }

        // Batch-query favorites counts in one query.
        if ( ! empty( $gallery_ids_with_candidates ) ) {
            global $wpdb;
            $fav_table = $wpdb->prefix . 'rincity_gallery_favorites';
            $ids_in    = implode( ',', array_map( 'intval', $gallery_ids_with_candidates ) );
            $rows      = $wpdb->get_results(
                "SELECT gallery_id, COUNT(*) AS cnt FROM {$fav_table} WHERE gallery_id IN ({$ids_in}) GROUP BY gallery_id",
                ARRAY_A
            );
            $fav_map = [];
            foreach ( $rows as $row ) {
                $fav_map[ (int) $row['gallery_id'] ] = (int) $row['cnt'];
            }
            foreach ( $results as &$r ) {
                $r['favorites_count'] = $fav_map[ $r['gallery_id'] ] ?? 0;
            }
            unset( $r );
        }

        $timestamp = current_time( 'mysql' );
        wp_cache_set( self::CACHE_KEY, $results, self::CACHE_GROUP, 0 );
        wp_cache_set( self::STAMP_KEY, $timestamp, self::CACHE_GROUP, 0 );

        return $results;
    }

    private static function cropping_info( int $w, int $h ): array {
        $ratio_img    = $w / $h;
        $ratio_target = 16.0 / 9.0;
        $result       = [];

        foreach ( self::TIERS as $key => $tier ) {
            if ( $ratio_img > $ratio_target + 0.001 ) {
                // Wider than 16:9 — crop left and right sides.
                $effective_w  = $h * $ratio_target;
                $crop_each    = ( $w - $effective_w ) / 2.0;
                $pct_retained = round( $effective_w / $w * 100.0, 1 );
                $qualifies    = ( $h >= $tier['height'] );
                $direction    = 'sides';
            } elseif ( $ratio_img < $ratio_target - 0.001 ) {
                // Landscape but narrower than 16:9 (e.g. 4:3) — crop top and bottom.
                $effective_h  = $w / $ratio_target;
                $crop_each    = ( $h - $effective_h ) / 2.0;
                $pct_retained = round( $effective_h / $h * 100.0, 1 );
                $qualifies    = ( $w >= $tier['width'] );
                $direction    = 'top_bottom';
            } else {
                // Essentially 16:9 — no cropping needed.
                $crop_each    = 0.0;
                $pct_retained = 100.0;
                $qualifies    = ( $w >= $tier['width'] );
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
