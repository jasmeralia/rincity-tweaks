<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Gallery_Sync {

    private const TIERS = [
        '4k'    => [ 'suffix' => '',       'label' => '4K' ],
        '1440p' => [ 'suffix' => '_1440p', 'label' => '1440p' ],
        '1080p' => [ 'suffix' => '_1080p', 'label' => '1080p' ],
    ];

    public static function sync(): array {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $settings = RinCWC_Data::get_settings();
        $rows     = self::sorted_approved_rows();
        $summary  = [];

        foreach ( self::TIERS as $tier => $tier_data ) {
            $gallery_id = (int) ( $settings['publish_galleries'][ $tier ] ?? 0 );
            if ( ! $gallery_id ) {
                $summary[ $tier ] = [ 'added' => 0, 'skipped' => 0, 'errors' => [ 'No target gallery configured.' ] ];
                continue;
            }

            self::clear_gallery( $gallery_id );
            $new_gallery = [];
            $added = 0;
            $skipped = 0;
            $errors = [];

            foreach ( $rows as $row ) {
                $crop = (string) $row['crop_variant'];
                $filename = self::wm_filename( $row, $crop, $tier_data['suffix'] );
                $abs_path = RINCWC_CROPS_DIR . $filename;
                if ( ! file_exists( $abs_path ) ) {
                    $skipped++;
                    continue;
                }

                $relative = RINCWC_CROPS_SUBDIR . '/' . $filename;
                $att_id   = self::find_attachment( $relative );
                $title    = $row['gallery_title'] . ' #' . (int) $row['position'];

                if ( ! $att_id ) {
                    $att_id = self::create_attachment( $filename, $title );
                }
                if ( ! $att_id ) {
                    $errors[] = 'Could not create attachment for ' . $filename;
                    continue;
                }

                self::ensure_thumbnails( $att_id, $abs_path, $filename );
                $thumb_filename = pathinfo( $filename, PATHINFO_FILENAME ) . '-128x72.jpg';
                $new_gallery[ $att_id ] = self::gallery_entry( $att_id, $filename, $title, $thumb_filename );
                $added++;
            }

            self::write_gallery( $gallery_id, $new_gallery );
            self::clear_envira_transients( $gallery_id );
            $summary[ $tier ] = [
                'gallery_id' => $gallery_id,
                'added'      => $added,
                'skipped'    => $skipped,
                'errors'     => $errors,
            ];
        }

        return $summary;
    }

    private static function sorted_approved_rows(): array {
        $rows = RinCWC_Data::approved_for_sync();
        $pub_dates = [];
        foreach ( $rows as $row ) {
            $gid = (int) $row['gallery_id'];
            if ( ! isset( $pub_dates[ $gid ] ) ) {
                $post = get_post( $gid );
                $pub_dates[ $gid ] = $post ? $post->post_date : '0000-00-00 00:00:00';
            }
        }
        usort( $rows, function ( $a, $b ) use ( $pub_dates ) {
            $cmp = strcmp( $pub_dates[ (int) $b['gallery_id'] ], $pub_dates[ (int) $a['gallery_id'] ] );
            if ( $cmp !== 0 ) {
                return $cmp;
            }
            return (int) $a['position'] <=> (int) $b['position'];
        } );
        return $rows;
    }

    private static function clear_gallery( int $gallery_id ): void {
        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        $gallery_data = is_array( $gallery_data ) ? $gallery_data : [];

        if ( ! empty( $gallery_data['gallery'] ) && is_array( $gallery_data['gallery'] ) ) {
            foreach ( array_keys( $gallery_data['gallery'] ) as $att_id ) {
                $relative = get_post_meta( (int) $att_id, '_wp_attached_file', true );
                if ( is_string( $relative ) && str_starts_with( $relative, RINCWC_CROPS_SUBDIR . '/' ) ) {
                    wp_delete_post( (int) $att_id, true );
                }
            }
        }

        $gallery_data['gallery'] = [];
        update_post_meta( $gallery_id, '_eg_gallery_data', $gallery_data );
    }

    private static function find_attachment( string $relative_path ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value = %s
             LIMIT 1",
            $relative_path
        ) );
    }

    private static function create_attachment( string $filename, string $title ): int {
        $upload = wp_upload_dir();
        $relative = RINCWC_CROPS_SUBDIR . '/' . $filename;
        $url = trailingslashit( $upload['baseurl'] ) . $relative;

        $att_id = wp_insert_post( [
            'post_title'     => $title,
            'post_status'    => 'inherit',
            'post_type'      => 'attachment',
            'post_mime_type' => 'image/jpeg',
            'guid'           => $url,
        ] );

        if ( is_wp_error( $att_id ) ) {
            return 0;
        }
        update_post_meta( (int) $att_id, '_wp_attached_file', $relative );
        return (int) $att_id;
    }

    private static function ensure_thumbnails( int $att_id, string $abs_path, string $filename ): void {
        $meta = wp_generate_attachment_metadata( $att_id, $abs_path );
        if ( ! is_array( $meta ) ) {
            $meta = [];
        }

        $base = pathinfo( $filename, PATHINFO_FILENAME );
        $thumb_320 = $base . '-320x180.jpg';
        $thumb_128 = $base . '-128x72.jpg';
        self::generate_thumb( $abs_path, RINCWC_CROPS_DIR . $thumb_320, 320, 180 );
        self::generate_thumb( $abs_path, RINCWC_CROPS_DIR . $thumb_128, 128, 72 );

        $meta['file'] = RINCWC_CROPS_SUBDIR . '/' . $filename;
        $meta['sizes']['envira-wallpaper-thumb'] = [
            'file'      => $thumb_320,
            'width'     => 320,
            'height'    => 180,
            'mime-type' => 'image/jpeg',
        ];
        wp_update_attachment_metadata( $att_id, $meta );
    }

    private static function generate_thumb( string $src, string $dst, int $w, int $h ): void {
        if ( file_exists( $dst ) ) {
            return;
        }
        $cmd = 'convert ' . escapeshellarg( $src )
            . ' -resize ' . escapeshellarg( $w . 'x' . $h )
            . ' -quality 88 ' . escapeshellarg( $dst ) . ' 2>&1';
        shell_exec( $cmd );
        if ( file_exists( $dst ) ) {
            /** Publish the new file to S3/CloudFront; no-op if rincity-media-sync is absent. */
            do_action( 'rincity_media_sync_file', $dst );
        }
    }

    private static function write_gallery( int $gallery_id, array $gallery ): void {
        $gallery_data = get_post_meta( $gallery_id, '_eg_gallery_data', true );
        $gallery_data = is_array( $gallery_data ) ? $gallery_data : [];
        $gallery_data['gallery'] = $gallery;

        $gallery_data['config']['image_size'] = 'envira-wallpaper-thumb';
        $gallery_data['config']['crop'] = 0;
        $gallery_data['config']['thumbnails_width'] = 128;
        $gallery_data['config']['thumbnails_height'] = 72;
        $gallery_data['config']['mobile_thumbnails_width'] = 128;
        $gallery_data['config']['mobile_thumbnails_height'] = 72;
        $gallery_data['config']['lightbox_image_size'] = 'default';

        update_post_meta( $gallery_id, '_eg_gallery_data', $gallery_data );
    }

    private static function gallery_entry( int $att_id, string $filename, string $title, string $thumb_filename ): array {
        $upload = wp_upload_dir();
        $url = trailingslashit( $upload['baseurl'] ) . RINCWC_CROPS_SUBDIR . '/' . $filename;
        $thumb_url = trailingslashit( $upload['baseurl'] ) . RINCWC_CROPS_SUBDIR . '/' . $thumb_filename;

        return [
            'status'                    => 'active',
            'src'                       => $url,
            'thumb'                     => $thumb_url,
            'title'                     => $title,
            'caption'                   => '',
            'alt'                       => '',
            'link'                      => $url,
            'id'                        => $att_id,
            'schedule_meta'             => '',
            'schedule_meta_start'       => '',
            'schedule_meta_end'         => '',
            'schedule_meta_ignore_date' => '',
            'schedule_meta_ignore_year' => '',
            'video_aspect_ratio'        => '',
            'video_width'               => '',
            'video_height'              => '',
            'video_in_gallery'          => '',
        ];
    }

    private static function clear_envira_transients( int $gallery_id ): void {
        $post = get_post( $gallery_id );
        $slug = $post ? $post->post_name : (string) $gallery_id;
        foreach ( [
            "_eg_cache_{$gallery_id}",
            "_eg_cache_{$slug}",
            "_eg_fragment_json_{$gallery_id}",
            "_eg_fragment_{$gallery_id}",
            "_eg_fragment_mobile_{$gallery_id}",
        ] as $key ) {
            delete_transient( $key );
        }
    }

    private static function wm_filename( array $row, string $crop, string $suffix ): string {
        return sanitize_title( (string) $row['gallery_slug'] ) . '_' . (int) $row['position'] . '_' . sanitize_key( $crop ) . $suffix . '_wm.jpg';
    }
}
