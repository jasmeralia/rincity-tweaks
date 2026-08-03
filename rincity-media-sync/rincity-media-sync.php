<?php
/**
 * Plugin Name: RinCity Media Sync
 * Description: Asynchronously mirrors new, changed, and deleted WordPress media to S3.
 * Version:     1.0.0
 * Author:      Morgan Blackthorne
 */

defined( 'ABSPATH' ) || exit;

$rinms_plugin_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'RINMS_VERSION', $rinms_plugin_data['Version'] );
define( 'RINMS_PLUGIN_DIR', __DIR__ . '/rincity-media-sync/' );

require_once RINMS_PLUGIN_DIR . 'includes/class-config.php';
require_once RINMS_PLUGIN_DIR . 'includes/class-paths.php';
require_once RINMS_PLUGIN_DIR . 'includes/class-queue.php';
require_once RINMS_PLUGIN_DIR . 'includes/class-hooks.php';

RinMS_Hooks::register();
