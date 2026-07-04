<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Rest {

    public const NS = 'rincity/v1';

    public static function register(): void {
        $admin = [ __CLASS__, 'is_admin' ];

        register_rest_route( self::NS, '/wpc/comments', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'get_comments' ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'post_comment' ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/comments/(?P<id>\d+)', [
            [ 'methods' => 'PUT', 'callback' => [ __CLASS__, 'put_comment' ], 'permission_callback' => $admin ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_comment' ], 'permission_callback' => $admin ],
        ] );

        register_rest_route( self::NS, '/wpc/select', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'select' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/deselect', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'deselect' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/watermark', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'watermark' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/approve', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'approve' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/unapprove', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'unapprove' ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/crop-custom', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_custom' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/crop-offset', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_offset' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/generate-crops', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'generate_crops' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/apply-watermarks', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'apply_watermarks' ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/watermarks', [
            [ 'methods' => 'GET', 'callback' => [ __CLASS__, 'watermarks' ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'add_watermark' ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/watermarks/(?P<id>\d+)', [
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_watermark' ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/gallery-wm', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'gallery_wm' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/sync-galleries', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'sync_galleries' ], 'permission_callback' => $admin ] );
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
        $gid     = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid     = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $variant = sanitize_key( $req->get_param( 'selected_crop' ) ?? '' );
        if ( ! $gid || ! $aid || ! $variant ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::select_image( $gid, $aid, $variant );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
    }

    public static function deselect( WP_REST_Request $req ): WP_REST_Response {
        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        $ok = RinCWC_Data::deselect_image( $gid, $aid );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
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

    public static function approve( WP_REST_Request $req ): WP_REST_Response {
        if ( ! RinCWC_Data::approve_allowed() ) {
            return new WP_REST_Response( [ 'error' => 'Approval is restricted.' ], 403 );
        }
        $ok = RinCWC_Data::approve( (int) ( $req->get_param( 'gallery_id' ) ?? 0 ), (int) ( $req->get_param( 'attach_id' ) ?? 0 ) );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function unapprove( WP_REST_Request $req ): WP_REST_Response {
        if ( ! RinCWC_Data::approve_allowed() ) {
            return new WP_REST_Response( [ 'error' => 'Approval is restricted.' ], 403 );
        }
        $ok = RinCWC_Data::unapprove( (int) ( $req->get_param( 'gallery_id' ) ?? 0 ), (int) ( $req->get_param( 'attach_id' ) ?? 0 ) );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function crop_offset( WP_REST_Request $req ): WP_REST_Response {
        $gid    = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid    = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $offset = (int) ( $req->get_param( 'offset' ) ?? 0 );
        $image  = RinCWC_Data::get_image_by_gallery_attach( $gid, $aid );
        if ( ! $image ) {
            return new WP_REST_Response( [ 'error' => 'Image not found' ], 404 );
        }

        $scale  = RinCWC_Data::max_crop_scale( (int) $image['orig_w'], (int) $image['orig_h'] );
        $source = (int) round( $offset * $scale );
        $x      = ( (int) $image['orig_w'] / max( 1, (int) $image['orig_h'] ) >= 16 / 9 ) ? $source : 0;
        $y      = $x ? 0 : $source;
        $crop   = RinCWC_Data::clamp_custom_crop( $image, $scale, $x, $y );

        return self::save_custom_crop( $image, $crop );
    }

    public static function crop_custom( WP_REST_Request $req ): WP_REST_Response {
        $gid   = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid   = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $image = RinCWC_Data::get_image_by_gallery_attach( $gid, $aid );
        if ( ! $image ) {
            return new WP_REST_Response( [ 'error' => 'Image not found' ], 404 );
        }

        $crop = RinCWC_Data::clamp_custom_crop(
            $image,
            (float) ( $req->get_param( 'scale' ) ?? 1.0 ),
            (int) ( $req->get_param( 'x' ) ?? 0 ),
            (int) ( $req->get_param( 'y' ) ?? 0 )
        );

        return self::save_custom_crop( $image, $crop );
    }

    public static function generate_crops( WP_REST_Request $req ): WP_REST_Response {
        unset( $req );
        $out = [];
        foreach ( RinCWC_Data::get_review_images() as $row ) {
            if ( empty( $row['crop_variant'] ) ) {
                continue;
            }
            $out[] = self::generate_crop_for_row( $row, false );
        }
        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    public static function apply_watermarks( WP_REST_Request $req ): WP_REST_Response {
        unset( $req );
        $out         = [];
        $gravity_map = [
            'top-left'     => 'NorthWest',
            'top-right'    => 'NorthEast',
            'bottom-left'  => 'SouthWest',
            'bottom-right' => 'SouthEast',
        ];

        foreach ( RinCWC_Data::get_review_images() as $row ) {
            if ( empty( $row['crop_variant'] ) || empty( $row['wm_corner'] ) || ! empty( $row['wm_applied'] ) ) {
                continue;
            }
            $wm = RinCWC_Data::get_effective_watermark( (int) $row['gallery_id'] );
            if ( ! $wm || empty( $wm['file_path'] ) || ! file_exists( $wm['file_path'] ) ) {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => 'Watermark missing' ];
                continue;
            }
            $gravity = $gravity_map[ $row['wm_corner'] ] ?? '';
            if ( ! $gravity ) {
                continue;
            }

            $crop_result = self::generate_crop_for_row( $row, false );
            if ( $crop_result['status'] === 'error' ) {
                $out[] = $crop_result;
                continue;
            }

            $ok = true;
            foreach ( self::resolution_suffixes() as $suffix ) {
                $src = self::raw_crop_path( $row, $suffix );
                $dst = self::watermarked_crop_path( $row, $suffix );
                if ( ! file_exists( $src ) ) {
                    $ok = false;
                    continue;
                }
                $wm_w = max( 1, (int) trim( (string) shell_exec( 'identify -format "%[fx:w*0.10]" ' . escapeshellarg( $src ) . ' 2>/dev/null' ) ) );
                $cmd  = 'convert ' . escapeshellarg( $src )
                    . ' \( ' . escapeshellarg( $wm['file_path'] ) . ' -resize ' . escapeshellarg( $wm_w . 'x' ) . ' \)'
                    . ' -gravity ' . escapeshellarg( $gravity )
                    . ' -geometry +10+10 -composite -quality 95 '
                    . escapeshellarg( $dst ) . ' 2>&1';
                shell_exec( $cmd );
                if ( ! file_exists( $dst ) ) {
                    $ok = false;
                }
            }

            if ( $ok ) {
                RinCWC_Data::mark_watermark_applied( (int) $row['id'], (int) $wm['id'] );
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'ok' ];
            } else {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'partial' ];
            }
        }

        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    public static function watermarks( WP_REST_Request $req ): WP_REST_Response {
        unset( $req );
        return new WP_REST_Response( [ 'watermarks' => RinCWC_Data::get_watermarks() ], 200 );
    }

    public static function add_watermark( WP_REST_Request $req ): WP_REST_Response {
        $name = sanitize_text_field( $req->get_param( 'name' ) ?? '' );
        $path = (string) ( $req->get_param( 'file_path' ) ?? '' );
        if ( ! $name || ! $path || ! file_exists( $path ) ) {
            return new WP_REST_Response( [ 'error' => 'Missing or invalid watermark file.' ], 400 );
        }
        $id = RinCWC_Data::add_watermark( $name, $path, ! empty( $req->get_param( 'is_default' ) ) );
        return new WP_REST_Response( [ 'id' => $id ], 201 );
    }

    public static function delete_watermark( WP_REST_Request $req ): WP_REST_Response {
        $ok = RinCWC_Data::delete_watermark( (int) $req['id'] );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function gallery_wm( WP_REST_Request $req ): WP_REST_Response {
        $ok = RinCWC_Data::set_gallery_watermark(
            (int) ( $req->get_param( 'gallery_id' ) ?? 0 ),
            (int) ( $req->get_param( 'wm_file_id' ) ?? 0 )
        );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function sync_galleries( WP_REST_Request $req ): WP_REST_Response {
        unset( $req );
        if ( ! class_exists( 'RinCWC_Gallery_Sync' ) ) {
            return new WP_REST_Response( [ 'error' => 'Gallery sync is unavailable.' ], 500 );
        }
        return new WP_REST_Response( RinCWC_Gallery_Sync::sync(), 200 );
    }

    private static function save_custom_crop( array $image, array $crop ): WP_REST_Response {
        $ok = RinCWC_Data::select_image( (int) $image['gallery_id'], (int) $image['attach_id'], 'custom', $crop );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => 'Could not save custom crop.' ], 400 );
        }

        $row = array_merge( $image, [
            'crop_variant'      => 'custom',
            'custom_crop_scale' => $crop['scale'],
            'custom_crop_x'     => $crop['x'],
            'custom_crop_y'     => $crop['y'],
        ] );

        return new WP_REST_Response( [
            'ok'     => true,
            'crop'   => $crop,
            'result' => self::generate_crop_for_row( $row, true ),
        ], 200 );
    }

    private static function generate_crop_for_row( array $row, bool $force ): array {
        $variant = (string) ( $row['crop_variant'] ?? '' );
        if ( ! $variant ) {
            return [ 'aid' => (int) $row['attach_id'], 'status' => 'skip' ];
        }
        $src = (string) ( $row['original_path'] ?? '' );
        if ( ! $src || ! file_exists( $src ) ) {
            return [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => 'Source missing' ];
        }
        if ( ! is_dir( RINCWC_CROPS_DIR ) ) {
            wp_mkdir_p( RINCWC_CROPS_DIR );
        }

        $crop = $variant === 'custom'
            ? RinCWC_Data::clamp_custom_crop( $row, (float) $row['custom_crop_scale'], (int) $row['custom_crop_x'], (int) $row['custom_crop_y'] )
            : RinCWC_Data::preset_crop_box( $row, $variant );

        $done = 0;
        foreach ( [
            ''       => [ 3840, 2160 ],
            '_1440p' => [ 2560, 1440 ],
            '_1080p' => [ 1920, 1080 ],
        ] as $suffix => [ $dw, $dh ] ) {
            $dst = self::raw_crop_path( $row, $suffix );
            if ( file_exists( $dst ) && ! $force ) {
                $done++;
                continue;
            }
            $cmd = 'convert ' . escapeshellarg( $src )
                . ' -crop ' . escapeshellarg( $crop['box_w'] . 'x' . $crop['box_h'] . '+' . $crop['x'] . '+' . $crop['y'] )
                . ' +repage -resize ' . escapeshellarg( "{$dw}x{$dh}" )
                . ' -quality 88 ' . escapeshellarg( $dst ) . ' 2>&1';
            $result = shell_exec( $cmd );
            if ( ! file_exists( $dst ) ) {
                return [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => $result ?: 'convert failed' ];
            }
            $done++;
        }

        return [ 'aid' => (int) $row['attach_id'], 'status' => 'ok', 'files' => $done ];
    }

    private static function raw_crop_path( array $row, string $suffix ): string {
        return RINCWC_CROPS_DIR . sanitize_title( $row['gallery_slug'] ) . '_' . (int) $row['attach_id'] . '_' . sanitize_key( $row['crop_variant'] ) . $suffix . '.jpg';
    }

    private static function watermarked_crop_path( array $row, string $suffix ): string {
        return RINCWC_CROPS_DIR . sanitize_title( $row['gallery_slug'] ) . '_' . (int) $row['position'] . '_' . sanitize_key( $row['crop_variant'] ) . $suffix . '_wm.jpg';
    }

    private static function resolution_suffixes(): array {
        return [ '', '_1440p', '_1080p' ];
    }
}
