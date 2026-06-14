<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Current_Gallery {

    // Confirmed from rincity-image-size-control.php and Envira Gallery source.
    const POST_TYPE = 'envira';

    public function get_current_gallery_id(): ?int {
        if ( ! is_singular( self::POST_TYPE ) ) {
            return null;
        }

        $id   = (int) get_queried_object_id();
        $post = get_post( $id );

        if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== self::POST_TYPE ) {
            return null;
        }

        return $id;
    }
}
