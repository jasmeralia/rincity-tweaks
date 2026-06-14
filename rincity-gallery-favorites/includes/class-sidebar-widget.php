<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'rincgf_widget',
            'Gallery Favorites',
            [ 'description' => 'Favorite/bookmark control for Envira standalone gallery pages.' ]
        );
    }

    public function widget( $args, $instance ): void {
        $session = new RinCity_Amember_Session();

        if ( ! $session->is_logged_in() ) {
            return;
        }

        $gallery        = new RinCity_Current_Gallery();
        $gallery_id     = $gallery->get_current_gallery_id();
        $favorites_url  = home_url( '/my-favorite-galleries/' );

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( 'Gallery Tools' ) . $args['after_title'];

        if ( ! $gallery_id ) {
            // Not an Envira gallery page — show only the favorites page link.
            ?>
            <p class="rincgf-link">
                <a href="<?php echo esc_url( $favorites_url ); ?>">View my favorite galleries</a>
            </p>
            <?php
            echo $args['after_widget'];
            return;
        }

        $member_id   = $session->get_member_id();
        $repo        = new RinCity_Gallery_Favorites_Repository();
        $favorite    = $repo->get_favorite( $member_id, $gallery_id );
        $is_favorited = $favorite !== null;
        $note         = $is_favorited ? (string) ( $favorite['note'] ?? '' ) : '';

        ?>
        <div class="rin-gallery-favorite-widget"
             data-gallery-id="<?php echo esc_attr( (string) $gallery_id ); ?>"
             data-is-favorited="<?php echo $is_favorited ? '1' : '0'; ?>"
             data-initial-note="<?php echo esc_attr( $note ); ?>"
             data-favorites-url="<?php echo esc_attr( $favorites_url ); ?>">
            <p class="rincgf-widget-loading">Loading&hellip;</p>
        </div>
        <?php

        echo $args['after_widget'];
    }

    public function form( $instance ): void {
        echo '<p>No settings. Place this widget in the sidebar shown on Envira standalone gallery pages.</p>';
    }

    public function update( $new_instance, $old_instance ): array {
        return [];
    }
}
