<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Wallpaper_Scanner {

    private const CACHE_GROUP = 'rincwc';
    private const STAMP_KEY   = 'scan_timestamp';

    public static function scan_gallery( int $gallery_id, bool $commit = false ): array {
        $post = get_post( $gallery_id );
        if ( ! $post || $post->post_type !== 'envira' ) {
            return self::error_result( 'Envira gallery not found.' );
        }

        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        if ( ! is_array( $gallery_data ) || empty( $gallery_data['gallery'] ) || ! is_array( $gallery_data['gallery'] ) ) {
            return self::error_result( 'Gallery has no Envira image data.' );
        }

        $cutoff = RinCWC_Data::get_cutoff( $gallery_id );
        $images = $gallery_data['gallery'];
        $total  = count( $images );
        $rows   = [];
        $counts = [
            'seen'       => 0,
            'candidates' => 0,
            'excluded'   => 0,
            'skipped'    => 0,
        ];
        $written  = 0;
        $position = 0;

        foreach ( $images as $att_id => $item ) {
            $position++;
            $counts['seen']++;
            $att_id = (int) $att_id;
            if ( ! $att_id ) {
                $counts['skipped']++;
                continue;
            }

            $source = wp_get_original_image_path( $att_id );
            if ( ! $source || ! file_exists( $source ) ) {
                $source = get_attached_file( $att_id );
            }
            if ( ! $source || ! file_exists( $source ) ) {
                $counts['skipped']++;
                continue;
            }

            $dims = self::identify_dimensions( $source );
            if ( ! $dims ) {
                $counts['skipped']++;
                continue;
            }

            [ $orig_w, $orig_h ] = $dims;
            if ( $orig_w <= $orig_h || $orig_w < 3840 || RinCWC_Data::max_crop_scale( $orig_w, $orig_h ) < 1.0 ) {
                $counts['skipped']++;
                continue;
            }

            $excluded    = $cutoff > 0 && $position > $cutoff;
            $scaled_path = get_attached_file( $att_id );
            $src_url     = '';
            if ( is_array( $item ) && ! empty( $item['src'] ) ) {
                $src_url = (string) $item['src'];
            }
            if ( ! $src_url ) {
                $src_url = (string) wp_get_attachment_url( $att_id );
            }

            $row = [
                'gallery_id'    => $gallery_id,
                'gallery_slug'  => $post->post_name,
                'gallery_title' => $post->post_title,
                'attach_id'     => $att_id,
                'position'      => $position,
                'total'         => $total,
                'original_path' => $source,
                'scaled_path'   => $scaled_path ?: '',
                'src_url'       => $src_url,
                'orig_w'        => $orig_w,
                'orig_h'        => $orig_h,
                'excluded'      => $excluded ? 1 : 0,
                'status'        => RinCWC_Data::STATUS_CANDIDATE,
            ];

            if ( $commit && RinCWC_Data::upsert_image( $row ) ) {
                $written++;
            }

            $rows[] = [
                'row'      => $row,
                'excluded' => $excluded,
                'status'   => $excluded ? 'excluded' : 'candidate',
                'reason'   => $excluded ? "Past cutoff position {$cutoff}." : 'Candidate.',
            ];
            $counts[ $excluded ? 'excluded' : 'candidates' ]++;
        }

        if ( $commit ) {
            self::mark_scan_timestamp();
        }

        return [
            'ok'            => true,
            'error'         => '',
            'message'       => $commit ? 'Scan committed.' : 'Dry run complete.',
            'gallery_id'    => $gallery_id,
            'gallery_title' => $post->post_title,
            'gallery_slug'  => $post->post_name,
            'total'         => $total,
            'cutoff'        => $cutoff,
            'rows'          => $rows,
            'counts'        => $counts,
            'written'       => $written,
        ];
    }

    public static function scan_all( bool $commit = false ): array {
        $results = [];
        foreach ( RinCWC_Data::scan_target_galleries() as $post ) {
            $results[] = self::scan_gallery( (int) $post->ID, $commit );
        }
        if ( $commit ) {
            self::mark_scan_timestamp();
        }
        return $results;
    }

    public static function mark_scan_timestamp(): void {
        $now = current_time( 'mysql' );
        wp_cache_set( self::STAMP_KEY, $now, self::CACHE_GROUP, 0 );
        update_option( 'rincwc_last_scan', $now, false );
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
        wp_cache_delete( self::STAMP_KEY, self::CACHE_GROUP );
    }

    public static function database_summary(): array {
        $rows = RinCWC_Data::get_candidate_rows_for_admin();
        $out  = [
            'images'    => count( $rows ),
            'visible'   => 0,
            'excluded'  => 0,
            'selected'  => 0,
            'approved'  => 0,
            'galleries' => [],
        ];

        foreach ( $rows as $row ) {
            $gid = (int) $row['gallery_id'];
            if ( ! isset( $out['galleries'][ $gid ] ) ) {
                $post     = get_post( $gid );
                $pub_date = $post ? $post->post_date : '';
                $out['galleries'][ $gid ] = [
                    'title'    => $row['gallery_title'],
                    'pub_date' => $pub_date ? gmdate( 'Y-m-d', strtotime( $pub_date ) ) : '',
                    'images'   => 0,
                    'excluded' => 0,
                    'selected' => 0,
                    'approved' => 0,
                ];
            }
            $out['galleries'][ $gid ]['images']++;

            if ( (int) $row['excluded'] ) {
                $out['excluded']++;
                $out['galleries'][ $gid ]['excluded']++;
            } else {
                $out['visible']++;
            }

            if ( $row['status'] === RinCWC_Data::STATUS_SELECTED ) {
                $out['selected']++;
                $out['galleries'][ $gid ]['selected']++;
            } elseif ( $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $out['approved']++;
                $out['galleries'][ $gid ]['approved']++;
            }
        }

        return $out;
    }

    private static function identify_dimensions( string $path ): ?array {
        $cmd = 'identify -format ' . escapeshellarg( '%w %h' ) . ' ' . escapeshellarg( $path ) . ' 2>/dev/null';
        $out = trim( (string) shell_exec( $cmd ) );
        if ( preg_match( '/^(\d+)\s+(\d+)$/', $out, $m ) ) {
            return [ (int) $m[1], (int) $m[2] ];
        }

        $size = @getimagesize( $path );
        if ( ! $size ) {
            return null;
        }
        return [ (int) $size[0], (int) $size[1] ];
    }

    private static function error_result( string $message ): array {
        return [
            'ok'            => false,
            'error'         => $message,
            'message'       => $message,
            'gallery_id'    => 0,
            'gallery_title' => '',
            'gallery_slug'  => '',
            'total'         => 0,
            'cutoff'        => 0,
            'rows'          => [],
            'counts'        => [
                'seen'       => 0,
                'candidates' => 0,
                'excluded'   => 0,
                'skipped'    => 0,
            ],
            'written'       => 0,
        ];
    }
}
