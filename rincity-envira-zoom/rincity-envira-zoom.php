<?php
/**
 * Plugin Name: Rin Envira Zoom
 * Description: Replaces Envira's ElevateZoom with Panzoom (scroll/pinch/drag).
 * Version: 0.5.0
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_script(
        'panzoom',
        plugin_dir_url( __FILE__ ) . 'assets/panzoom.min.js',
        [],
        '4.6.2',
        true
    );

    wp_enqueue_script(
        'rincity-envira-zoom',
        plugin_dir_url( __FILE__ ) . 'assets/rincity-envira-zoom.js',
        [ 'jquery', 'panzoom' ],
        '0.5.0',
        true
    );

    wp_enqueue_style(
        'rincity-envira-zoom',
        plugin_dir_url( __FILE__ ) . 'assets/rincity-envira-zoom.css',
        [],
        '0.5.0'
    );
}, 20 );
