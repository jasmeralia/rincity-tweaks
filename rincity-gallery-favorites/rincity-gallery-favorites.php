<?php
/**
 * Plugin Name: RinCity Gallery Favorites
 * Description: Member-only favorites/bookmarks for Envira standalone gallery pages, with sidebar widget and management page.
 * Version:     1.0.4
 * Author:      Morgan Blackthorne
 */

defined( 'ABSPATH' ) || exit;

define( 'RINCGF_VERSION',    '1.0.3' );
define( 'RINCGF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RINCGF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'RINCGF_NOTE_MAX',   2000 );

require_once RINCGF_PLUGIN_DIR . 'includes/class-activator.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-amember-session.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-current-gallery.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-repository.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-sidebar-widget.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-shortcodes.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-assets.php';
require_once RINCGF_PLUGIN_DIR . 'includes/class-admin-columns.php';

register_activation_hook( __FILE__, [ 'RinCity_Gallery_Favorites_Activator', 'activate' ] );

add_action( 'widgets_init', function () {
    register_widget( 'RinCity_Gallery_Favorites_Widget' );
} );

add_action( 'rest_api_init', function () {
    $controller = new RinCity_Gallery_Favorites_Rest_Controller(
        new RinCity_Amember_Session(),
        new RinCity_Gallery_Favorites_Repository()
    );
    $controller->register_routes();
} );

add_action( 'wp_enqueue_scripts', [ 'RinCity_Gallery_Favorites_Assets', 'enqueue' ] );
add_action( 'wp_footer', [ 'RinCity_Gallery_Favorites_Assets', 'render_gallery_count' ], 20 );

add_shortcode( 'rincity_gallery_favorites_page', [ 'RinCity_Gallery_Favorites_Shortcodes', 'render' ] );

if ( is_admin() ) {
    RinCity_Gallery_Favorites_Admin_Columns::register();
}

// ---------------------------------------------------------------------------
// WP-CLI diagnostic
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'rincity-gallery-favorites', 'RinCity_Gallery_Favorites_CLI' );
}

class RinCity_Gallery_Favorites_CLI {

    /**
     * Check which aMember session detection methods are available.
     *
     * Run this on staging to confirm the aMember integration.
     * Note: WP-CLI has no HTTP session, so 'resolved' will be false —
     * the important output is which functions/classes exist.
     *
     * ## EXAMPLES
     *
     *     wp rincity-gallery-favorites amember-check
     *
     * @when after_wp_load
     */
    public function amember_check( $args, $assoc_args ) {
        $session = new RinCity_Amember_Session();
        $diag    = $session->diagnostics();

        WP_CLI::line( 'am4PluginsManager class exists  : ' . ( $diag['am4PluginsManager_exists'] ? 'YES' : 'no' ) );
        WP_CLI::line( 'Am_Lite class exists            : ' . ( $diag['Am_Lite_exists']           ? 'YES' : 'no' ) );
        WP_CLI::line( 'API instance available          : ' . ( $diag['api_available']            ? 'YES' : 'no' ) );
        WP_CLI::line( 'Session resolved (expected: no) : ' . ( $diag['resolved']                 ? 'yes' : 'no (expected in CLI — no HTTP session)' ) );
        WP_CLI::line( '' );
        WP_CLI::line( 'To confirm live session resolution, enable WP_DEBUG + WP_DEBUG_LOG,' );
        WP_CLI::line( 'log in as an aMember member, visit any gallery page, then:' );
        WP_CLI::line( '  grep "rincgf amember-session" wp-content/debug.log' );
    }

    /**
     * Show current plugin database table status.
     *
     * ## EXAMPLES
     *
     *     wp rincity-gallery-favorites db-status
     *
     * @when after_wp_load
     */
    public function db_status( $args, $assoc_args ) {
        global $wpdb;
        $table = $wpdb->prefix . 'rincity_gallery_favorites';
        $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;

        if ( ! $exists ) {
            WP_CLI::warning( "Table {$table} does not exist. Activate the plugin to create it." );
            return;
        }

        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $users = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT amember_user_id) FROM {$table}" );
        WP_CLI::line( "Table   : {$table}" );
        WP_CLI::line( "Rows    : {$count}" );
        WP_CLI::line( "Members : {$users}" );
    }
}
