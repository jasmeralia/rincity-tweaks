<?php
/**
 * Plugin Name: RinCity Nav Tweaks
 * Description: Fixes mobile submenu expand/collapse on the Ashe Pro theme.
 * Version: 1.1.1
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RINCITY_NAV_TWEAKS_VERSION', '1.1.1' );

add_action( 'wp_enqueue_scripts', function () {
    if ( is_admin() ) {
        return;
    }
    wp_enqueue_script(
        'rincity-nav-tweaks',
        plugin_dir_url( __FILE__ ) . 'assets/js/nav-tweaks.js',
        [ 'jquery' ],
        RINCITY_NAV_TWEAKS_VERSION,
        true
    );
} );
