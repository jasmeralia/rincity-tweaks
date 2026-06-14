<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Assets {

    public static function enqueue(): void {
        wp_enqueue_style(
            'rincgf-styles',
            RINCGF_PLUGIN_URL . 'assets/favorites.css',
            [],
            RINCGF_VERSION
        );

        $session = new RinCity_Amember_Session();
        if ( ! $session->is_logged_in() ) {
            return;
        }

        $data = [
            'restBase'     => rest_url( 'rincity-gallery-favorites/v1' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'favoritesUrl' => home_url( '/my-favorite-galleries/' ),
        ];

        wp_enqueue_script(
            'rincgf-widget',
            RINCGF_PLUGIN_URL . 'assets/favorites-widget.js',
            [],
            RINCGF_VERSION,
            true
        );
        wp_localize_script( 'rincgf-widget', 'rincgf', $data );

        wp_enqueue_script(
            'rincgf-table',
            RINCGF_PLUGIN_URL . 'assets/favorites-table.js',
            [],
            RINCGF_VERSION,
            true
        );
    }

    public static function render_gallery_count(): void {
        if ( ! is_singular( RinCity_Current_Gallery::POST_TYPE ) ) {
            return;
        }

        $gallery_id = (int) get_queried_object_id();
        if ( ! $gallery_id ) {
            return;
        }

        $repo  = new RinCity_Gallery_Favorites_Repository();
        $count = $repo->count_favorites_for_gallery( $gallery_id );

        if ( $count <= 0 ) {
            return;
        }

        $label = $count === 1
            ? 'Favorited by 1 member'
            : 'Favorited by ' . $count . ' members';

        ?>
        <script>
        (function () {
            var meta = document.querySelector('.post-meta');
            if (!meta) return;
            meta.appendChild(document.createTextNode(' / '));
            var span = document.createElement('span');
            span.className   = 'rincgf-gallery-count';
            span.textContent = <?php echo wp_json_encode( $label ); ?>;
            meta.appendChild(span);
        }());
        </script>
        <?php
    }
}
