<?php
/**
 * Plugin Name: RinCity Wallpaper Candidates
 * Description: Wallpaper candidate scanner and interactive review/selection tool.
 * Version:     2.0.2
 * Author:      Morgan Blackthorne
 */

defined( 'ABSPATH' ) || exit;

define( 'RINCWC_VERSION',    '2.0.2' );
define( 'RINCWC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINCWC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

define( 'RINCWC_DB_CSV',    WP_CONTENT_DIR . '/uploads/wallpaper-candidates/wallpaper_db.csv' );
define( 'RINCWC_SEL_CSV',   WP_CONTENT_DIR . '/uploads/wallpaper-candidates/wallpaper_selections.csv' );
define( 'RINCWC_CROPS_DIR', WP_CONTENT_DIR . '/uploads/wallpaper-crops/' );
define( 'RINCWC_WM_FILE',   '/home/morgan/rincity-infra/images/RC_WM_Plain.png' );

require_once RINCWC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-db.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-csv.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-rest.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once RINCWC_PLUGIN_DIR . 'includes/class-review-page.php';

register_activation_hook( __FILE__, [ 'RinCWC_DB', 'create_table' ] );
add_action( 'plugins_loaded', [ 'RinCWC_DB', 'maybe_create_table' ] );
add_action( 'rest_api_init',  [ 'RinCWC_Rest', 'register' ] );

if ( is_admin() ) {
    RinCity_Wallpaper_Admin_Page::register();
    RinCWC_Review_Page::register();
}
