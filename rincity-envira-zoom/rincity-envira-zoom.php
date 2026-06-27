<?php
/**
 * Plugin Name: RinCity Envira Zoom
 * Description: Replaces Envira's ElevateZoom with Panzoom (scroll/pinch/drag).
 * Version: 0.6.20
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rincity_envira_zoom_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'RINCITY_ENVIRA_ZOOM_VERSION', $rincity_envira_zoom_data['Version'] );

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
        RINCITY_ENVIRA_ZOOM_VERSION,
        true
    );

    wp_enqueue_style(
        'rincity-envira-zoom',
        plugin_dir_url( __FILE__ ) . 'assets/rincity-envira-zoom.css',
        [],
        RINCITY_ENVIRA_ZOOM_VERSION
    );
}, 20 );
