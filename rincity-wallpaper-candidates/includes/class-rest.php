<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Rest {

    public const NS = 'rincity/v1';

    private const RESOLUTIONS = [
        ''       => [ 3840, 2160 ],
        '_1440p' => [ 2560, 1440 ],
        '_1080p' => [ 1920, 1080 ],
    ];

    public static function register(): void {
        $admin = [ __CLASS__, 'is_admin' ];

        register_rest_route( self::NS, '/wpc/comments', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_comments' ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'post_comment' ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/comments/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'put_comment' ], 'permission_callback' => $admin ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_comment' ], 'permission_callback' => $admin ],
        ] );

        register_rest_route( self::NS, '/wpc/select', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'select' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/deselect', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'deselect' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/approve', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'approve' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/unapprove', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'unapprove' ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/crop-custom', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_custom' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/crop-offset', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_offset' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/watermark', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'watermark' ], 'permission_callback' => $admin ] );

        register_rest_route( self::NS, '/wpc/generate-crops', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'generate_crops' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/apply-watermarks', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'apply_watermarks' ], 'permission_callback' => $admin ] );
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

        $gid = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }

        $ok = RinCWC_Data::unapprove( $gid, $aid );
        return new WP_REST_Response( [ 'ok' => $ok, 'status' => $ok ? RinCWC_Data::STATUS_SELECTED : null ], $ok ? 200 : 400 );
    }

    public static function crop_custom( WP_REST_Request $req ): WP_REST_Response {
        $gid   = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid   = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $scale = (float) ( $req->get_param( 'scale' ) ?? 1.0 );
        $x     = (int) ( $req->get_param( 'x' ) ?? 0 );
        $y     = (int) ( $req->get_param( 'y' ) ?? 0 );
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }

        $image = RinCWC_Data::get_image_by_gallery_attach( $gid, $aid );
        if ( ! $image ) {
            return new WP_REST_Response( [ 'error' => 'Image not found' ], 404 );
        }

        $crop = RinCWC_Data::clamp_custom_crop( $image, $scale, $x, $y );
        $ok   = RinCWC_Data::select_image( $gid, $aid, 'custom', $crop );
        if ( ! $ok ) {
            return new WP_REST_Response( [ 'error' => 'Could not save crop' ], 400 );
        }

        $selection = RinCWC_Data::get_selection( (int) $image['id'] );
        $result    = self::generate_crop_files( $image, $selection, true );
        return new WP_REST_Response( [
            'ok'     => $result['status'] === 'ok',
            'status' => RinCWC_Data::STATUS_SELECTED,
            'crop'   => $crop,
            'result' => $result,
        ], $result['status'] === 'ok' ? 200 : 500 );
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
            return new WP_REST_Response( [ 'error' => 'Image not found' ], 404 );
        }

        $scale  = RinCWC_Data::max_crop_scale( (int) $image['orig_w'], (int) $image['orig_h'] );
        $source = (int) round( $offset * $scale );
        $x      = ( (int) $image['orig_w'] / max( 1, (int) $image['orig_h'] ) >= 16 / 9 ) ? $source : 0;
        $y      = $x ? 0 : $source;
        $req->set_param( 'scale', $scale );
        $req->set_param( 'x', $x );
        $req->set_param( 'y', $y );
        return self::crop_custom( $req );
    }

    public static function watermark( WP_REST_Request $req ): WP_REST_Response {
        $gid    = (int) ( $req->get_param( 'gallery_id' ) ?? 0 );
        $aid    = (int) ( $req->get_param( 'attach_id' ) ?? 0 );
        $corner = sanitize_text_field( $req->get_param( 'wm_corner' ) ?? '' );
        $valid  = [ '', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
        if ( ! $gid || ! $aid ) {
            return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        }
        if ( ! in_array( $corner, $valid, true ) ) {
            return new WP_REST_Response( [ 'error' => 'Invalid corner' ], 400 );
        }

        $ok = RinCWC_Data::set_watermark_corner( $gid, $aid, $corner );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 400 );
    }

    public static function generate_crops( WP_REST_Request $req ): WP_REST_Response {
        $out = [];
        foreach ( RinCWC_Data::get_review_images() as $row ) {
            if ( empty( $row['crop_variant'] ) ) {
                continue;
            }
            $out[] = self::generate_crop_files( $row, $row, false );
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
            if ( empty( $row['crop_variant'] ) || empty( $row['wm_corner'] ) || ! empty( $row['wm_applied'] ) ) {
                continue;
            }

            $gravity = $gravity_map[ $row['wm_corner'] ] ?? '';
            if ( ! $gravity ) {
                continue;
            }

            $wm = RinCWC_Data::get_effective_watermark( (int) $row['gallery_id'] );
            if ( ! $wm || ! file_exists( $wm['file_path'] ) ) {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'error', 'msg' => 'Watermark file missing' ];
                continue;
            }

            $crop_result = self::generate_crop_files( $row, $row, false );
            if ( $crop_result['status'] !== 'ok' ) {
                $out[] = $crop_result;
                continue;
            }

            $all_ok = true;
            foreach ( self::RESOLUTIONS as $sfx => $_dims ) {
                $src = self::raw_crop_path( $row, $sfx );
                $dst = self::wm_crop_path( $row, $sfx );
                if ( ! file_exists( $src ) ) {
                    $all_ok = false;
                    continue;
                }

                $wm_w = trim( (string) shell_exec( 'identify -format "%[fx:w*0.10]" ' . escapeshellarg( $src ) . ' 2>/dev/null' ) );
                if ( ! is_numeric( $wm_w ) || (float) $wm_w <= 0 ) {
                    $wm_w = '384';
                }
                $cmd = 'convert ' . escapeshellarg( $src )
                    . ' \( ' . escapeshellarg( $wm['file_path'] ) . ' -resize ' . escapeshellarg( $wm_w . 'x' ) . ' \)'
                    . ' -gravity ' . escapeshellarg( $gravity )
                    . ' -geometry +10+10 -composite -quality 95 '
                    . escapeshellarg( $dst ) . ' 2>&1';
                shell_exec( $cmd );
                if ( ! file_exists( $dst ) ) {
                    $all_ok = false;
                }
            }

            if ( $all_ok ) {
                RinCWC_Data::mark_watermark_applied( (int) $row['id'], (int) $wm['id'] );
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'ok' ];
            } else {
                $out[] = [ 'aid' => (int) $row['attach_id'], 'status' => 'partial' ];
            }
        }

        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    private static function generate_crop_files( array $image, ?array $selection, bool $force ): array {
        $src = $image['original_path'] ?? '';
        if ( ! $src || ! file_exists( $src ) ) {
            return [ 'aid' => (int) ( $image['attach_id'] ?? 0 ), 'status' => 'error', 'msg' => 'Source missing' ];
        }
        if ( ! $selection || empty( $selection['crop_variant'] ) ) {
            return [ 'aid' => (int) $image['attach_id'], 'status' => 'skipped', 'msg' => 'No crop selected' ];
        }

        wp_mkdir_p( RINCWC_CROPS_DIR );
        $variant = sanitize_key( $selection['crop_variant'] );
        if ( $variant === 'custom' ) {
            $crop = RinCWC_Data::clamp_custom_crop(
                $image,
                (float) ( $selection['custom_crop_scale'] ?? 1.0 ),
                (int) ( $selection['custom_crop_x'] ?? 0 ),
                (int) ( $selection['custom_crop_y'] ?? 0 )
            );
        } else {
            $crop = RinCWC_Data::preset_crop_box( $image, $variant );
        }

        $files = 0;
        foreach ( self::RESOLUTIONS as $sfx => [ $dw, $dh ] ) {
            $dst = self::raw_crop_path( $image + [ 'crop_variant' => $variant ], $sfx );
            if ( $force || ! file_exists( $dst ) ) {
                $cmd = 'convert ' . escapeshellarg( $src )
                    . ' -crop ' . escapeshellarg( "{$crop['box_w']}x{$crop['box_h']}+{$crop['x']}+{$crop['y']}" )
                    . ' +repage -resize ' . escapeshellarg( "{$dw}x{$dh}" )
                    . ' -quality 88 ' . escapeshellarg( $dst ) . ' 2>&1';
                $result = shell_exec( $cmd );
                if ( ! file_exists( $dst ) ) {
                    return [
                        'aid'    => (int) $image['attach_id'],
                        'status' => 'error',
                        'msg'    => $result ?: "Failed generating {$dst}",
                    ];
                }
            }
            $files++;
        }

        return [ 'aid' => (int) $image['attach_id'], 'status' => 'ok', 'files' => $files, 'variant' => $variant ];
    }

    private static function raw_crop_path( array $row, string $sfx ): string {
        $variant = sanitize_key( $row['crop_variant'] ?? '' );
        return RINCWC_CROPS_DIR . "{$row['gallery_slug']}_{$row['attach_id']}_{$variant}{$sfx}.jpg";
    }

    private static function wm_crop_path( array $row, string $sfx ): string {
        $variant = sanitize_key( $row['crop_variant'] ?? '' );
        return RINCWC_CROPS_DIR . "{$row['gallery_slug']}_{$row['position']}_{$variant}{$sfx}_wm.jpg";
    }
}
