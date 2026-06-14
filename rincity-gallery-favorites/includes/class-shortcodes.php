<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Shortcodes {

    public static function render(): string {
        $session = new RinCity_Amember_Session();

        if ( ! $session->is_logged_in() ) {
            $login_url = home_url( '/amember/login/' );
            return '<p class="rincgf-login-required">Please <a href="' . esc_url( $login_url ) . '">log in</a> to view your favorite galleries.</p>';
        }

        // The table JS is always enqueued for authenticated users (see class-assets.php),
        // so no extra enqueue call is needed here.

        return '<div class="rin-favorites-table-container"><p class="rincgf-table-loading">Loading your favorites&hellip;</p></div>';
    }
}
