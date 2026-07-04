<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Rest {

    const NS = 'rincity/v1';

    public static function register(): void {
        $admin = [ __CLASS__, 'is_admin' ];

        // Comments.
        register_rest_route( self::NS, '/wpc/comments', [
            [ 'methods' => 'GET',  'callback' => [ __CLASS__, 'get_comments'  ], 'permission_callback' => $admin ],
            [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'post_comment'  ], 'permission_callback' => $admin ],
        ] );
        register_rest_route( self::NS, '/wpc/comments/(?P<id>\d+)', [
            [ 'methods' => 'PUT',    'callback' => [ __CLASS__, 'put_comment'    ], 'permission_callback' => $admin ],
            [ 'methods' => 'DELETE', 'callback' => [ __CLASS__, 'delete_comment' ], 'permission_callback' => $admin ],
        ] );

        // Selections.
        register_rest_route( self::NS, '/wpc/select',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'select'      ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/deselect', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'deselect'    ], 'permission_callback' => $admin ] );

        // Crop offset + watermark.
        register_rest_route( self::NS, '/wpc/crop-offset', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'crop_offset' ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/watermark',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'watermark'   ], 'permission_callback' => $admin ] );

        // Batch operations.
        register_rest_route( self::NS, '/wpc/generate-crops',   [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'generate_crops'   ], 'permission_callback' => $admin ] );
        register_rest_route( self::NS, '/wpc/apply-watermarks', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'apply_watermarks'  ], 'permission_callback' => $admin ] );
    }

    public static function is_admin(): bool {
        return current_user_can( 'manage_options' );
    }

    // ── Comments ──────────────────────────────────────────────────────────────

    public static function get_comments( WP_REST_Request $req ): WP_REST_Response {
        $key = sanitize_text_field( $req->get_param( 'image_key' ) ?? '' );
        return new WP_REST_Response( $key ? RinCWC_DB::get_comments( $key ) : [], 200 );
    }

    public static function post_comment( WP_REST_Request $req ): WP_REST_Response {
        $key  = sanitize_text_field( $req->get_param( 'image_key' ) ?? '' );
        $body = sanitize_textarea_field( $req->get_param( 'body' ) ?? '' );
        if ( ! $key || ! $body ) return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
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
        if ( ! $body ) return new WP_REST_Response( [ 'error' => 'Empty body' ], 400 );
        $ok   = RinCWC_DB::update( $id, get_current_user_id(), $body );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 403 );
    }

    public static function delete_comment( WP_REST_Request $req ): WP_REST_Response {
        $ok = RinCWC_DB::delete( (int) $req['id'], get_current_user_id() );
        return new WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 403 );
    }

    // ── Selections ────────────────────────────────────────────────────────────

    public static function select( WP_REST_Request $req ): WP_REST_Response {
        $required = [ 'gallery_id', 'gallery_slug', 'gallery_title', 'attach_id', 'position', 'total', 'filename', 'selected_crop' ];
        $data = [];
        foreach ( $required as $f ) {
            $v = $req->get_param( $f );
            if ( $v === null ) return new WP_REST_Response( [ 'error' => "Missing $f" ], 400 );
            $data[ $f ] = sanitize_text_field( $v );
        }

        // Preserve existing wm fields; reset wm_applied if crop changed.
        $existing = RinCWC_CSV::read_selections()[ $data['gallery_id'] ][ $data['attach_id'] ] ?? [];
        $data['wm_corner']          = $existing['wm_corner']          ?? '';
        $data['wm_applied']         = $existing['wm_applied']         ?? 'false';
        $data['custom_crop_offset'] = $existing['custom_crop_offset'] ?? '';
        if ( ! empty( $existing['selected_crop'] ) && $existing['selected_crop'] !== $data['selected_crop'] ) {
            $data['wm_applied'] = 'false';
        }
        return new WP_REST_Response( [ 'ok' => RinCWC_CSV::upsert_selection( $data ) ], 200 );
    }

    public static function deselect( WP_REST_Request $req ): WP_REST_Response {
        $gid = sanitize_text_field( $req->get_param( 'gallery_id' ) ?? '' );
        $aid = sanitize_text_field( $req->get_param( 'attach_id' )  ?? '' );
        if ( ! $gid || ! $aid ) return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        return new WP_REST_Response( [ 'ok' => RinCWC_CSV::remove_selection( $gid, $aid ) ], 200 );
    }

    // ── Crop offset ───────────────────────────────────────────────────────────

    public static function crop_offset( WP_REST_Request $req ): WP_REST_Response {
        $gid    = sanitize_text_field( $req->get_param( 'gallery_id' )    ?? '' );
        $aid    = sanitize_text_field( $req->get_param( 'attach_id' )     ?? '' );
        $offset = (int) ( $req->get_param( 'offset' ) ?? 0 );
        if ( ! $gid || ! $aid ) return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );

        $row = RinCWC_CSV::read_selections()[ $gid ][ $aid ] ?? null;

        if ( ! $row ) {
            // New selection via custom crop — build row from posted metadata.
            $required = [ 'gallery_slug', 'gallery_title', 'position', 'total', 'filename' ];
            foreach ( $required as $f ) {
                if ( $req->get_param( $f ) === null ) {
                    return new WP_REST_Response( [ 'error' => "Missing $f" ], 400 );
                }
            }
            $row = [
                'gallery_id'    => $gid,
                'gallery_slug'  => sanitize_text_field( $req->get_param( 'gallery_slug' ) ),
                'gallery_title' => sanitize_text_field( $req->get_param( 'gallery_title' ) ),
                'attach_id'     => $aid,
                'position'      => sanitize_text_field( $req->get_param( 'position' ) ),
                'total'         => sanitize_text_field( $req->get_param( 'total' ) ),
                'filename'      => sanitize_text_field( $req->get_param( 'filename' ) ),
                'wm_corner'     => '',
                'wm_applied'    => 'false',
            ];
        }

        $row['selected_crop']      = 'custom';
        $row['custom_crop_offset'] = (string) $offset;
        $row['wm_applied']         = 'false';
        return new WP_REST_Response( [ 'ok' => RinCWC_CSV::upsert_selection( $row ) ], 200 );
    }

    // ── Watermark ─────────────────────────────────────────────────────────────

    public static function watermark( WP_REST_Request $req ): WP_REST_Response {
        $gid    = sanitize_text_field( $req->get_param( 'gallery_id' ) ?? '' );
        $aid    = sanitize_text_field( $req->get_param( 'attach_id' )  ?? '' );
        $corner = sanitize_text_field( $req->get_param( 'wm_corner' )  ?? '' );
        $valid  = [ '', 'top-left', 'top-right', 'bottom-left', 'bottom-right' ];
        if ( ! $gid || ! $aid ) return new WP_REST_Response( [ 'error' => 'Missing fields' ], 400 );
        if ( ! in_array( $corner, $valid, true ) ) return new WP_REST_Response( [ 'error' => 'Invalid corner' ], 400 );

        $row = RinCWC_CSV::read_selections()[ $gid ][ $aid ] ?? null;
        if ( ! $row ) return new WP_REST_Response( [ 'error' => 'Image not selected' ], 400 );

        if ( ( $row['wm_corner'] ?? '' ) !== $corner ) {
            $row['wm_applied'] = 'false';
        }
        $row['wm_corner'] = $corner;
        return new WP_REST_Response( [ 'ok' => RinCWC_CSV::upsert_selection( $row ) ], 200 );
    }

    // ── Batch: generate custom crops ──────────────────────────────────────────

    public static function generate_crops( WP_REST_Request $req ): WP_REST_Response {
        $db  = RinCWC_CSV::read_db();
        $sel = RinCWC_CSV::read_selections();
        $out = [];

        foreach ( $sel as $gid => $aids ) {
            foreach ( $aids as $aid => $row ) {
                if ( $row['selected_crop'] !== 'custom' ) continue;
                $db_row = $db[ $gid ][ $aid ] ?? null;
                if ( ! $db_row ) { $out[] = [ 'aid' => $aid, 'status' => 'error', 'msg' => 'Not in DB' ]; continue; }
                $out[] = self::generate_custom_crop( $db_row, (int) ( $row['custom_crop_offset'] ?? 0 ) );
            }
        }
        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }

    private static function generate_custom_crop( array $db_row, int $offset ): array {
        $src = $db_row['original_path'] ?? '';
        if ( ! $src || ! file_exists( $src ) ) {
            return [ 'aid' => $db_row['attach_id'], 'status' => 'error', 'msg' => 'Source missing' ];
        }

        $orig_w = (int) $db_row['width'];
        $orig_h = (int) $db_row['height'];
        $slug   = $db_row['gallery_slug'];
        $aid    = $db_row['attach_id'];

        $tw = 3840; $th = 2160;
        if ( $orig_w / $orig_h >= $tw / $th ) {
            $resize = "x{$th}";
            $slack  = (int) floor( $orig_w * $th / $orig_h ) - $tw;
            $x_off  = min( max( 0, $offset ), $slack );
            $y_off  = 0;
        } else {
            $resize = "{$tw}x";
            $slack  = (int) floor( $orig_h * $tw / $orig_w ) - $th;
            $x_off  = 0;
            $y_off  = min( max( 0, $offset ), $slack );
        }

        $base_out = RINCWC_CROPS_DIR . "{$slug}_{$aid}_custom.jpg";
        if ( ! file_exists( $base_out ) ) {
            $cmd = 'convert ' . escapeshellarg( $src )
                 . ' -resize ' . escapeshellarg( $resize )
                 . ' -crop ' . escapeshellarg( "{$tw}x{$th}+{$x_off}+{$y_off}" )
                 . ' +repage -quality 88 ' . escapeshellarg( $base_out ) . ' 2>&1';
            $result = shell_exec( $cmd );
            if ( ! file_exists( $base_out ) ) {
                return [ 'aid' => $aid, 'status' => 'error', 'msg' => $result ];
            }
        }

        $n = 1;
        foreach ( [ [ 2560, 1440, '_1440p' ], [ 1920, 1080, '_1080p' ] ] as [ $dw, $dh, $sfx ] ) {
            $dst = RINCWC_CROPS_DIR . "{$slug}_{$aid}_custom{$sfx}.jpg";
            if ( ! file_exists( $dst ) ) {
                $cmd = 'convert ' . escapeshellarg( $base_out )
                     . ' -resize ' . escapeshellarg( "{$dw}x{$dh}" )
                     . ' -quality 88 ' . escapeshellarg( $dst ) . ' 2>&1';
                shell_exec( $cmd );
            }
            if ( file_exists( $dst ) ) $n++;
        }
        return [ 'aid' => $aid, 'status' => 'ok', 'files' => $n ];
    }

    // ── Batch: apply watermarks ───────────────────────────────────────────────

    public static function apply_watermarks( WP_REST_Request $req ): WP_REST_Response {
        if ( ! file_exists( RINCWC_WM_FILE ) ) {
            return new WP_REST_Response( [ 'error' => 'Watermark file missing' ], 500 );
        }
        $db  = RinCWC_CSV::read_db();
        $sel = RinCWC_CSV::read_selections();
        $gravity_map = [
            'top-left'     => 'NorthWest',
            'top-right'    => 'NorthEast',
            'bottom-left'  => 'SouthWest',
            'bottom-right' => 'SouthEast',
        ];
        $out = [];

        foreach ( $sel as $gid => $aids ) {
            foreach ( $aids as $aid => $row ) {
                if ( ( $row['wm_applied'] ?? '' ) === 'true' ) continue;
                $crop    = $row['selected_crop'] ?? '';
                $corner  = $row['wm_corner']     ?? '';
                $gravity = $gravity_map[ $corner ] ?? null;
                if ( ! $crop || ! $gravity ) continue;

                $db_row = $db[ $gid ][ $aid ] ?? null;
                if ( ! $db_row ) continue;

                $slug = $db_row['gallery_slug'];
                $pos  = $row['position'];
                $all_ok = true;

                foreach ( [ '' => '', '_1440p' => '_1440p', '_1080p' => '_1080p' ] as $sfx => $_ ) {
                    $src = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$crop}{$sfx}.jpg";
                    $dst = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$crop}{$sfx}_wm.jpg";
                    if ( ! file_exists( $src ) ) { $all_ok = false; continue; }
                    if ( file_exists( $dst ) )   { continue; }

                    $wm_w = trim( (string) shell_exec( 'identify -format "%[fx:w*0.10]" ' . escapeshellarg( $src ) ) );
                    $cmd  = 'convert ' . escapeshellarg( $src )
                          . ' \( ' . escapeshellarg( RINCWC_WM_FILE ) . ' -resize ' . escapeshellarg( $wm_w . 'x' ) . ' \)'
                          . ' -gravity ' . escapeshellarg( $gravity )
                          . ' -geometry +10+10 -composite -quality 95'
                          . ' ' . escapeshellarg( $dst ) . ' 2>&1';
                    shell_exec( $cmd );
                    if ( ! file_exists( $dst ) ) $all_ok = false;
                }

                if ( $all_ok ) {
                    $row['wm_applied'] = 'true';
                    RinCWC_CSV::upsert_selection( $row );
                    $out[] = [ 'aid' => $aid, 'status' => 'ok' ];
                } else {
                    $out[] = [ 'aid' => $aid, 'status' => 'partial' ];
                }
            }
        }
        return new WP_REST_Response( [ 'results' => $out ], 200 );
    }
}
