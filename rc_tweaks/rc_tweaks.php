<?php
/**
 * Plugin Name: RinCity Tweaks
 * Description: A plugin to provide several tweaks to customize Envira Gallery functionality for RinCity.
 * Version: 2.1.12
 * Author: Morgan Blackthorne
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Include the feed generator
require_once plugin_dir_path( __FILE__ ) . 'includes/feed-generator.php';

// Include the gallery table generator
require_once plugin_dir_path( __FILE__ ) . 'includes/gallery-table-generator.php';

// Include the widgets
require_once plugin_dir_path( __FILE__ ) . 'includes/widgets.php';

// Include the random gallery redirect
require_once plugin_dir_path( __FILE__ ) . 'includes/random-gallery-redirect.php';

// Include the album page functionality
require_once plugin_dir_path( __FILE__ ) . 'includes/album-page.php';

// Bridge aMember session auth into WordPress (fixes comment form login check)
require_once plugin_dir_path( __FILE__ ) . 'includes/amember-wp-bridge.php';

// Prevent browser caching of Envira gallery standalone pages
require_once plugin_dir_path( __FILE__ ) . 'includes/envira-gallery-nocache.php';
