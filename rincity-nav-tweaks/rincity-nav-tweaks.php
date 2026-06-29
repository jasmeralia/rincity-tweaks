<?php
/**
 * Plugin Name: RinCity Nav Tweaks
 * Description: Fixes mobile submenu expand/collapse on the Ashe Pro theme.
 * Version: 1.1.3
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RINCITY_NAV_TWEAKS_VERSION', '1.1.3' );

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

// Remove href from parent nav items that use "#" — an <a> without href has no
// default browser action, preventing scroll-to-top on touch with no JS needed.
add_filter( 'nav_menu_link_attributes', function ( $atts, $item ) {
    if ( in_array( 'menu-item-has-children', $item->classes, true )
        && ( $atts['href'] ?? '' ) === '#' ) {
        unset( $atts['href'] );
    }
    return $atts;
}, 10, 2 );
