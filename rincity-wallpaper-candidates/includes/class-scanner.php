<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Wallpaper_Scanner {

    public static function scan_gallery( int $gallery_id, bool $commit = false ): array {
        $post = get_post( $gallery_id );
        if ( ! $post || $post->post_type !== 'envira' ) {
            return [
                'ok'      => false,
                'message' => 'Envira gallery not found.',
                'rows'    => [],
                'counts'  => self::empty_counts(),
            ];
        }

        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        if ( ! is_array( $gallery_data ) || empty( $gallery_data['gallery'] ) || ! is_array( $gallery_data['gallery'] ) ) {
            return [
                'ok'      => false,
                'message' => 'Gallery has no Envira image data.',
                'rows'    => [],
                'counts'  => self::empty_counts(),
            ];
        }

        $settings = RinCWC_Data::get_settings();
        $cutoffs  = is_array( $settings['excluded_after'] ?? null ) ? $settings['excluded_after'] : [];
        $cutoff   = (int) ( $cutoffs[ $gallery_id ] ?? 0 );
        $images   = $gallery_data['gallery'];
        $total    = count( $images );
        $rows     = [];
        $counts   = self::empty_counts();
        $position = 0;

        foreach ( $images as $att_id => $item ) {
            $position++;
            $counts['seen']++;
            $att_id = (int) $att_id;
            if ( ! $att_id ) {
                $rows[] = self::row_result( 0, $position, $total, '', 0, 0, 'skipped', 'Missing attachment ID.' );
                $counts['skipped']++;
                continue;
            }

            $source = wp_get_original_image_path( $att_id );
            if ( ! $source || ! file_exists( $source ) ) {
                $source = get_attached_file( $att_id );
            }
            if ( ! $source || ! file_exists( $source ) ) {
                $rows[] = self::row_result( $att_id, $position, $total, '', 0, 0, 'skipped', 'Source file missing.' );
                $counts['skipped']++;
                continue;
            }

            $dims = self::identify_dimensions( $source );
            if ( ! $dims ) {
                $rows[] = self::row_result( $att_id, $position, $total, $source, 0, 0, 'skipped', 'Could not read dimensions.' );
                $counts['skipped']++;
                continue;
            }

            [ $orig_w, $orig_h ] = $dims;
            $eligible = $orig_w > $orig_h && $orig_w >= 3840 && RinCWC_Data::max_crop_scale( $orig_w, $orig_h ) >= 1.0;
            if ( ! $eligible ) {
                $rows[] = self::row_result( $att_id, $position, $total, $source, $orig_w, $orig_h, 'skipped', 'Not landscape 4K-capable.' );
                $counts['skipped']++;
                continue;
            }

            $excluded = $cutoff > 0 && $position > $cutoff;
            $status   = $excluded ? 'excluded' : 'candidate';
            $reason   = $excluded ? "Past cutoff position {$cutoff}." : 'Candidate.';

            if ( $commit ) {
                RinCWC_Data::upsert_image( [
                    'gallery_id'    => $gallery_id,
                    'gallery_slug'  => $post->post_name,
                    'gallery_title' => $post->post_title,
                    'attach_id'     => $att_id,
                    'position'      => $position,
                    'total'         => $total,
                    'original_path' => $source,
                    'scaled_path'   => get_attached_file( $att_id ) ?: '',
                    'src_url'       => (string) ( $item['src'] ?? wp_get_attachment_url( $att_id ) ),
                    'orig_w'        => $orig_w,
                    'orig_h'        => $orig_h,
                    'excluded'      => $excluded ? 1 : 0,
                ] );
            }

            $rows[] = self::row_result( $att_id, $position, $total, $source, $orig_w, $orig_h, $status, $reason );
            $counts[ $excluded ? 'excluded' : 'candidates' ]++;
        }

        return [
            'ok'      => true,
            'message' => $commit ? 'Scan committed.' : 'Dry run complete.',
            'rows'    => $rows,
            'counts'  => $counts,
            'gallery' => [
                'id'         => $gallery_id,
                'title'      => $post->post_title,
                'slug'       => $post->post_name,
                'post_date'  => $post->post_date,
                'total'      => $total,
                'cutoff'     => $cutoff,
            ],
        ];
    }

    public static function database_summary(): array {
        $rows = RinCWC_Data::all_images( true );
        $out  = [
            'images'     => count( $rows ),
            'visible'    => 0,
            'excluded'   => 0,
            'selected'   => 0,
            'approved'   => 0,
            'galleries'  => [],
        ];

        foreach ( $rows as $row ) {
            $gid = (int) $row['gallery_id'];
            if ( ! isset( $out['galleries'][ $gid ] ) ) {
                $out['galleries'][ $gid ] = [
                    'title'    => $row['gallery_title'],
                    'images'   => 0,
                    'excluded' => 0,
                ];
            }
            $out['images'] = count( $rows );
            $out['galleries'][ $gid ]['images']++;

            if ( (int) $row['excluded'] ) {
                $out['excluded']++;
                $out['galleries'][ $gid ]['excluded']++;
            } else {
                $out['visible']++;
            }

            if ( $row['status'] === RinCWC_Data::STATUS_SELECTED ) {
                $out['selected']++;
            } elseif ( $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $out['approved']++;
            }
        }

        return $out;
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

    private static function row_result( int $att_id, int $position, int $total, string $source, int $w, int $h, string $status, string $reason ): array {
        return [
            'attach_id' => $att_id,
            'position'  => $position,
            'total'     => $total,
            'filename'  => $source ? basename( $source ) : '',
            'width'     => $w,
            'height'    => $h,
            'status'    => $status,
            'reason'    => $reason,
        ];
    }

    private static function empty_counts(): array {
        return [
            'seen'       => 0,
            'candidates' => 0,
            'excluded'   => 0,
            'skipped'    => 0,
        ];
    }
}
