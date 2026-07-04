<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Rest {

    const NS = 'rincity/v1';

    private const TIERS = [
        '4k'    => [ 'width' => 3840, 'height' => 2160, 'suffix' => '' ],
        '1440p' => [ 'width' => 2560, 'height' => 1440, 'suffix' => '_1440p' ],
        '1080p' => [ 'width' => 1920, 'height' => 1080, 'suffix' => '_1080p' ],
    ];

    public static function register(): void {
        $admin = [ __CLASS__, 'is_admin' ];

        register_rest_route( self::NS, '/wpc/comments', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_comments'  ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'post_comment'  ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/comments/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'put_comment'    ], 'permission_callback' => $admin ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_comment' ], 'permission_callback' => $admin ],
        ] );

        register_rest_route( self::NS, '/wpc/select',     [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'select'     ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/deselect',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'deselect'   ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/approve',    [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'approve'    ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/unapprove',  [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'unapprove'  ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/watermark',  [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'watermark'  ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/crop-custom', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_custom' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/crop-offset', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_offset' ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/generate-crops',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'generate_crops'  ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/apply-watermarks', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'apply_watermarks' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/sync-galleries',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_galleries'   ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/watermarks', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'watermarks_list' ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'watermarks_add'  ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/watermarks/(?P<id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'watermarks_delete' ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/gallery-wm', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'gallery_wm' ], 'permission_callback' => $admin ] );
    }

    public static function is_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    public static function get_comments( WP_REST_Request $req ): WP_REST_Response {
        $key = sanitize_text_field( $req->get_param( 'image_key' ) ?? '' );
        return new WP_REST_Response( $key ? RinCWC_DB::get_comments( $key ) : [], 200 );
    }

    public static function post_comment( WP_REST_Request $req ): WP_REST_Response {
        $key  = sanitize_text_field( $req->get_param( 'image_key' ) ?? '' );
        $body = sanitize_textarea_field( $req->get_param( 'body' ) ?? '' );
        if ( ! $key || ! $body ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $id   = RinCWC_DB::add( $key, get_current_user_id(), $body );
        $user = wp_get_current_user();
        return new WP_REST_Response( [
            'id'           => $id,
            'image_key'    => $key,
            'user_id'      => get_current_user_id(),
            'body'         => $body,
            'display_name' => $user->display_name ?: $user->user_login,
            'created_at'   => current_time( 'mysql' ),
        ], 201 );
    }

    public static function put_comment( WP_REST_Request $req ): WP_REST_Response {
        $id   = (int) $req['id'];
        $body = sanitize_textarea_field( $req->get_param( 'body' ) ?? '' );
        if ( ! $body ) {
            return new WP_REST_Response( [ 'error' => 'Empty body' ], 400 );
        }
        $ok = RinCWC_DB::update( $id, get_current_user_id(), $body );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 403 );
    }

    public static function delete_comment( WP_REST_Request $req ): WP_REST_Response {
        $ok = RinCWC_DB::delete( (int) $req['id'], get_current_user_id() );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 403 );
    }

    public static function select( WP_REST_Request $req ): WP_REST_Response {
        $gid  = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid  = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $crop = sanitize_key( $req->get_param( 'selected_crop' ) ?? '' );
        if ( ! $gid || ! $aid || ! $crop ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }

        $ok = RinCWC_Data::select_image( $gid, $aid, $crop );
        return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_SELECTED : null ], $ok ? 200 : 404 );
    }

    public static function deselect( WP_REST_Request $req ): WP_REST_Response {
        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::deselect_image( $gid, $aid );
        return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_CANDIDATE : null ], $ok ? 200 : 404 );
    }

    public static function approve( WP_REST_Request $req ): WP_REST_Response {
        if ( ! RinCWC_Data::approve_allowed() ) {
            return new WP_REST_Response( [ 'error' => 'Not allowed' ], 403 );
        }
        $image = self::image_from_request( $req );
        if ( $image ) {
            $ok = RinCWC_Data::approve( (int) $image['gallery_id'], (int) $image['attach_id'] );
            return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_APPROVED : null ], $ok ? 200 : 400 );
        }
        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::approve( $gid, $aid );
        return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_APPROVED : null ], $ok ? 200 : 400 );
    }

    public static function unapprove( WP_REST_Request $req ): WP_REST_Response {
        if ( ! RinCWC_Data::approve_allowed() ) {
            return new WP_REST_Response( [ 'error' => 'Not allowed' ], 403 );
        }
        $image = self::image_from_request( $req );
        if ( $image ) {
            $ok = RinCWC_Data::unapprove( (int) $image['gallery_id'], (int) $image['attach_id'] );
            return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_SELECTED : null ], $ok ? 200 : 400 );
        }
        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::unapprove( $gid, $aid );
        return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_SELECTED : null ], $ok ? 200 : 400 );
    }

    public static function watermark( WP_REST_Request $req ): WP_REST_Response {
        $gid    = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid    = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $corner = sanitize_text_field( $req->get_param( 'wm_corner' ) ?? '' );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::set_watermark_corner( $gid, $aid, $corner );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function crop_custom( WP_REST_Request $req ): WP_REST_Response {
        $gid   = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid   = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $scale = (float) ( $req->get_param( 'scale' ) ?? $req->get_param( 'crop_scale' ) ?? 1.0 );
        $x     = (int) ( $req->get_param( 'x' ) ?? $req->get_param( 'crop_x' ) ?? 0 );
        $y     = (int) ( $req->get_param( 'y' ) ?? $req->get_param( 'crop_y' ) ?? 0 );

        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }

        $image = RinCWC_Data::get_image_by_gallery_attach( $gid, $aid );
        if ( ! $image ) {
            return new WP_REST_Response( [ 'error' => 'Image not in DB' ], 404 );
        }

        $crop = RinCWC_Data::clamp_custom_crop( $image, $scale, $x, $y );
        $ok   = RinCWC_Data::upsert_selection( (int) $image['id'], [
            'crop_variant'      => 'custom',
            'custom_crop_scale' => $crop['scale'],
            'custom_crop_x'     => $crop['x'],
            'custom_crop_y'     => $crop['y'],
        ] );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => 'Could not save crop' ], 400 );
        }
        RinCWC_Data::set_status( (int) $image['id'], RinCWC_Data::STATUS_SELECTED );

        $row    = array_merge( $image, [
            'crop_variant'      => 'custom',
            'custom_crop_scale' => $crop['scale'],
            'custom_crop_x'     => $crop['x'],
            'custom_crop_y'     => $crop['y'],
        ] );
        $result = self::generate_selection_crop( $row, true );
        return new WP_REST_Response( [ 'ok' => $result['status'] === 'ok', 'crop' => $crop, 'result' => $result ], $result['status'] === 'ok' ? 200 : 500 );
    }

    public static function crop_offset( WP_REST_Request $req ): WP_REST_Response {
        $gid    = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid    = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $offset = (int) ( $req->get_param( 'offset' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }

        $image = RinCWC_Data::get_image_by_gallery_attach( $gid, $aid );
        if ( ! $image ) {
            return new WP_REST_Response( [ 'error' => 'Image not in DB' ], 404 );
        }

        $scale = RinCWC_Data::max_crop_scale( (int) $image['orig_w'], (int) $image['orig_h'] );
        $source_offset = (int) round( $offset * $scale );
        $x = 0;
        $y = 0;
        if ( (int) $image['orig_w'] / max( 1, (int) $image['orig_h'] ) >= 16 / 9 ) {
            $x = $source_offset;
        } else {
            $y = $source_offset;
        }

        $req->set_param( 'crop_scale', $scale );
        $req->set_param( 'crop_x', $x );
        $req->set_param( 'crop_y', $y );
        return self::crop_custom( $req );
    }

    public static function generate_crops( WP_REST_Request $req ): WP_REST_Response {
        $out = [];
        foreach ( RinCWC_Data::get_review_images() as $row ) {
            if ( empty( $row['crop_variant'] ) || $row['status'] === RinCWC_Data::STATUS_CANDIDATE ) {
                continue;
            }
            $out[] = self::generate_selection_crop( $row, true );
        }
        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    public static function apply_watermarks( WP_REST_Request $req ): WP_REST_Response {
        $gravity_map = [
            'top-left'     => 'NorthWest',
            'top-right'    => 'NorthEast',
            'bottom-left'  => 'SouthWest',
            'bottom-right' => 'SouthEast',
        ];
        $out = [];

        foreach ( RinCWC_Data::get_review_images() as $row ) {
            if ( empty( $row['crop_variant'] ) || empty( $row['wm_corner'] ) || (int) $row['wm_applied'] ) {
                continue;
            }

            $watermark = RinCWC_Data::get_effective_watermark( (int) $row['gallery_id'] );
            if ( ! $watermark || empty( $watermark['file_path'] ) || ! file_exists( $watermark['file_path'] ) ) {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => 'Watermark file missing' ];
                continue;
            }

            $crop_result = self::generate_selection_crop( $row, false );
            if ( $crop_result['status'] !== 'ok' ) {
                $out[] = $crop_result;
                continue;
            }

            $gravity = $gravity_map[ $row['wm_corner'] ] ?? '';
            if ( ! $gravity ) {
                continue;
            }

            $all_ok = true;
            foreach ( self::TIERS as $tier ) {
                $src = self::raw_crop_path( $row, $tier['suffix'] );
                $dst = self::watermarked_crop_path( $row, $tier['suffix'] );
                if ( ! file_exists( $src ) ) {
                    $all_ok = false;
                    continue;
                }
                $wm_w = trim( (string) shell_exec( 'identify -format "%[fx:w*0.10]" ' . escapeshellarg( $src ) ) );
                $cmd  = 'convert ' . escapeshellarg( $src )
                    . ' \( ' . escapeshellarg( $watermark['file_path'] ) . ' -resize ' . escapeshellarg( $wm_w . 'x' ) . ' \)'
                    . ' -gravity ' . escapeshellarg( $gravity )
                    . ' -geometry +10+10 -composite -quality 95 '
                    . escapeshellarg( $dst ) . ' 2>&1';
                shell_exec( $cmd );
                if ( ! file_exists( $dst ) ) {
                    $all_ok = false;
                }
            }

            if ( $all_ok ) {
                RinCWC_Data::mark_watermark_applied( (int) $row['id'], (int) $watermark['id'] );
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'ok' ];
            } else {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'partial' ];
            }
        }

        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    public static function watermarks_list( WP_REST_Request $req ): WP_REST_Response {
        return new WP_REST_Response( [ 'watermarks' => RinCWC_Data::get_watermarks() ], 200 );
    }

    public static function watermarks_add( WP_REST_Request $req ): WP_REST_Response {
        $name = sanitize_text_field( $req->get_param( 'name' ) ?? '' );
        $path = (string) ( $req->get_param( 'file_path' ) ?? '' );
        if ( ! $name || ! $path || ! file_exists( $path ) ) {
            return new WP_REST_Response( [ 'error' => 'Missing or invalid watermark path' ], 400 );
        }
        $id = RinCWC_Data::add_watermark( $name, $path, ! empty( $req->get_param( 'is_default' ) ) );
        return new WP_REST_Response( [ 'ok' => (bool) $id, 'id' => $id ], $id ? 201 : 500 );
    }

    public static function watermarks_delete( WP_REST_Request $req ): WP_REST_Response {
        $ok = RinCWC_Data::delete_watermark( (int) $req['id'] );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function gallery_wm( WP_REST_Request $req ): WP_REST_Response {
        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $wid = (int) ( $req->get_param( 'wm_file_id' ) ?? 0 );
        $ok  = RinCWC_Data::set_gallery_watermark( $gid, $wid );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function sync_galleries( WP_REST_Request $req ): WP_REST_Response {
        if ( ! class_exists( 'RinCWC_Gallery_Sync' ) ) {
            return new WP_REST_Response( [ 'error' => 'Gallery sync is not available.' ], 501 );
        }
        $summary = RinCWC_Gallery_Sync::sync();
        return new WP_REST_Response( [ 'ok' => true, 'job_token' => 'sync-' . time(), 'summary' => $summary ], 200 );
    }

    private static function generate_selection_crop( array $row, bool $overwrite ): array {
        $src = $row['original_path'] ?? '';
        if ( ! $src || ! file_exists( $src ) ) {
            return [ 'aid' => (int) ( $row['attach_id'] ?? 0 ), 'status' => 'error', 'msg' => 'Source missing' ];
        }

        if ( ! is_dir( RINCWC_CROPS_DIR ) ) {
            wp_mkdir_p( RINCWC_CROPS_DIR );
        }

        $variant = (string) ( $row['crop_variant'] ?? '' );
        if ( $variant === 'custom' ) {
            $crop = RinCWC_Data::clamp_custom_crop(
                $row,
                (float) ( $row['custom_crop_scale'] ?? 1.0 ),
                (int) ( $row['custom_crop_x'] ?? 0 ),
                (int) ( $row['custom_crop_y'] ?? 0 )
            );
        } else {
            $crop = RinCWC_Data::preset_crop_box( $row, $variant );
        }

        $made = 0;
        foreach ( self::TIERS as $tier ) {
            $dst = self::raw_crop_path( $row, $tier['suffix'] );
            if ( file_exists( $dst ) && ! $overwrite ) {
                $made++;
                continue;
            }
            $geometry = "{$crop['box_w']}x{$crop['box_h']}+{$crop['x']}+{$crop['y']}";
            $resize   = "{$tier['width']}x{$tier['height']}";
            $cmd = 'convert ' . escapeshellarg( $src )
                . ' -crop ' . escapeshellarg( $geometry )
                . ' +repage -resize ' . escapeshellarg( $resize )
                . ' -quality 88 ' . escapeshellarg( $dst ) . ' 2>&1';
            $result = shell_exec( $cmd );
            if ( ! file_exists( $dst ) ) {
                return [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => $result ?: 'Crop failed' ];
            }
            $made++;
        }

        return [ 'aid' => (int) $row['attach_id'], 'status' => 'ok', 'files' => $made ];
    }

    private static function raw_crop_path( array $row, string $suffix ): string {
        return RINCWC_CROPS_DIR . "{$row['gallery_slug']}_{$row['attach_id']}_{$row['crop_variant']}{$suffix}.jpg";
    }

    private static function watermarked_crop_path( array $row, string $suffix ): string {
        return RINCWC_CROPS_DIR . "{$row['gallery_slug']}_{$row['position']}_{$row['crop_variant']}{$suffix}_wm.jpg";
    }

    private static function image_from_request( WP_REST_Request $req ): ?array {
        $image_id = (int) ( $req->get_param( 'image_id' ) ?? 0 );
        if ( ! $image_id ) {
            return null;
        }
        return RinCWC_Data::get_image( $image_id );
    }
}
