<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Rest_Controller {

    const NS = 'rincity-gallery-favorites/v1';

    private RinCity_Amember_Session              $session;
    private RinCity_Gallery_Favorites_Repository $repo;

    public function __construct(
        RinCity_Amember_Session $session,
        RinCity_Gallery_Favorites_Repository $repo
    ) {
        $this->session = $session;
        $this->repo    = $repo;
    }

    public function register_routes(): void {
        $gallery_arg = [
            'gallery_id' => [
                'required'          => true,
                'validate_callback' => fn( $v ) => is_numeric( $v ) && (int) $v > 0,
                'sanitize_callback' => 'absint',
            ],
        ];

        register_rest_route( self::NS, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => [ $this, 'require_amember' ],
            'args'                => $gallery_arg,
        ] );

        register_rest_route( self::NS, '/favorite', [
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ $this, 'add_favorite' ],
                'permission_callback' => [ $this, 'require_amember' ],
                'args'                => array_merge( $gallery_arg, [
                    'note' => [
                        'default'           => '',
                        'sanitize_callback' => [ $this, 'sanitize_note_arg' ],
                    ],
                ] ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'remove_favorite' ],
                'permission_callback' => [ $this, 'require_amember' ],
                'args'                => $gallery_arg,
            ],
        ] );

        register_rest_route( self::NS, '/note', [
            'methods'             => 'PATCH',
            'callback'            => [ $this, 'update_note' ],
            'permission_callback' => [ $this, 'require_amember' ],
            'args'                => array_merge( $gallery_arg, [
                'note' => [
                    'required'          => true,
                    'sanitize_callback' => [ $this, 'sanitize_note_arg' ],
                ],
            ] ),
        ] );

        register_rest_route( self::NS, '/favorites', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_favorites' ],
            'permission_callback' => [ $this, 'require_amember' ],
        ] );
    }

    /** @return true|WP_Error */
    public function require_amember( WP_REST_Request $request ) {
        if ( ! $this->session->is_logged_in() ) {
            return new WP_Error(
                'rincgf_unauthorized',
                'aMember authentication required.',
                [ 'status' => 401 ]
            );
        }
        return true;
    }

    /** @return WP_REST_Response */
    public function get_status( WP_REST_Request $request ) {
        $member_id  = $this->session->get_member_id();
        $gallery_id = (int) $request->get_param( 'gallery_id' );

        $row = $this->repo->get_favorite( $member_id, $gallery_id );

        return $this->nocache_response(
            $row
                ? [
                    'is_favorited'    => true,
                    'gallery_id'      => $gallery_id,
                    'created_at'      => $row['created_at'],
                    'note'            => (string) ( $row['note'] ?? '' ),
                    'note_updated_at' => $row['note_updated_at'],
                ]
                : [
                    'is_favorited' => false,
                    'gallery_id'   => $gallery_id,
                ]
        );
    }

    /** @return WP_REST_Response|WP_Error */
    public function add_favorite( WP_REST_Request $request ) {
        $member_id  = $this->session->get_member_id();
        $gallery_id = (int) $request->get_param( 'gallery_id' );
        $note       = (string) ( $request->get_param( 'note' ) ?? '' );

        if ( ! $this->repo->is_valid_gallery( $gallery_id ) ) {
            return new WP_Error( 'rincgf_invalid_gallery', 'Gallery not found.', [ 'status' => 404 ] );
        }

        if ( ! $this->check_rate_limit( $member_id, 'toggle' ) ) {
            return new WP_Error( 'rincgf_rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
        }

        $row = $this->repo->add_favorite( $member_id, $gallery_id, $note );
        if ( $row === null ) {
            return new WP_Error( 'rincgf_error', 'Could not save favorite.', [ 'status' => 500 ] );
        }

        return $this->nocache_response( [
            'is_favorited'    => true,
            'gallery_id'      => $gallery_id,
            'created_at'      => $row['created_at'],
            'note'            => (string) ( $row['note'] ?? '' ),
            'note_updated_at' => $row['note_updated_at'],
        ] );
    }

    /** @return WP_REST_Response|WP_Error */
    public function remove_favorite( WP_REST_Request $request ) {
        $member_id  = $this->session->get_member_id();
        $gallery_id = (int) $request->get_param( 'gallery_id' );

        if ( ! $this->check_rate_limit( $member_id, 'toggle' ) ) {
            return new WP_Error( 'rincgf_rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
        }

        $this->repo->remove_favorite( $member_id, $gallery_id );

        return $this->nocache_response( [
            'is_favorited' => false,
            'gallery_id'   => $gallery_id,
        ] );
    }

    /** @return WP_REST_Response|WP_Error */
    public function update_note( WP_REST_Request $request ) {
        $member_id  = $this->session->get_member_id();
        $gallery_id = (int) $request->get_param( 'gallery_id' );
        $note       = (string) ( $request->get_param( 'note' ) ?? '' );

        if ( ! $this->repo->get_favorite( $member_id, $gallery_id ) ) {
            return new WP_Error(
                'rincgf_not_favorited',
                'Gallery is not in your favorites.',
                [ 'status' => 404 ]
            );
        }

        if ( ! $this->check_rate_limit( $member_id, 'note' ) ) {
            return new WP_Error( 'rincgf_rate_limited', 'Too many requests. Please try again shortly.', [ 'status' => 429 ] );
        }

        $row = $this->repo->update_note( $member_id, $gallery_id, $note );
        if ( $row === null ) {
            return new WP_Error( 'rincgf_error', 'Could not update note.', [ 'status' => 500 ] );
        }

        return $this->nocache_response( [
            'is_favorited'    => true,
            'gallery_id'      => $gallery_id,
            'note'            => (string) ( $row['note'] ?? '' ),
            'note_updated_at' => $row['note_updated_at'],
        ] );
    }

    /** @return WP_REST_Response */
    public function list_favorites( WP_REST_Request $request ) {
        $member_id = $this->session->get_member_id();
        $rows      = $this->repo->list_favorites( $member_id );

        return $this->nocache_response( [
            'total' => count( $rows ),
            'rows'  => $rows,
        ] );
    }

    public function sanitize_note_arg( $value ): string {
        $note = wp_unslash( (string) $value );
        $note = sanitize_textarea_field( $note );
        return mb_substr( $note, 0, RINCGF_NOTE_MAX );
    }

    private function check_rate_limit( int $member_id, string $action ): bool {
        $limits = [ 'toggle' => 30, 'note' => 20 ];
        $limit  = $limits[ $action ] ?? 20;
        $key    = 'rincgf_rl_' . md5( $member_id . '_' . $action );
        $count  = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return false;
        }

        set_transient( $key, $count + 1, 60 );
        return true;
    }

    private function nocache_response( array $data, int $status = 200 ): WP_REST_Response {
        $response = new WP_REST_Response( $data, $status );
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }
}
