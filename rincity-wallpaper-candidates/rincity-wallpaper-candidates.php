<?php
/**
 * Plugin Name: RinCity Wallpaper Candidates
 * Description: Admin-only report identifying landscape gallery images suitable as 16:9 desktop wallpaper.
 * Version:     1.0.0
 * Author:      Morgan Blackthorne
 */

defined( 'ABSPATH' ) || exit;

define( 'RINCWC_VERSION',    '1.0.0' );
define( 'RINCWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once RINCWC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-admin-page.php';

if ( is_admin() ) {
    RinCity_Wallpaper_Admin_Page::register();
}
