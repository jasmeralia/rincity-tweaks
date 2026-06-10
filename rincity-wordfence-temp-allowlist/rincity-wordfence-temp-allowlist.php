<?php
/**
 * Plugin Name: RinCity Wordfence Temp Allowlist
 * Description: Temporarily allowlists Rin's client IP in Wordfence on successful login.
 * Version:     1.0.1
 * Author:      Morgan Blackthorne
 *
 * Must-use plugin — place at wp-content/mu-plugins/rincity-wordfence-temp-allowlist.php
 *
 * Required constants in wp-config.php:
 *   RINCITY_WF_TEMP_ALLOWLIST_USER_IDS  comma-separated WP user IDs to cover (e.g. '3,50')
 *   RINCITY_WF_TEMP_ALLOWLIST_TTL       allowlist duration in seconds (e.g. 21600 for 6h)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const RINCITY_WF_OPTION    = 'rincity_wf_temp_allowlist';
const RINCITY_WF_CRON_HOOK = 'rincity_wf_temp_allowlist_expire';

// ---------------------------------------------------------------------------
// Login hook
// ---------------------------------------------------------------------------

add_action( 'wp_login', 'rincity_wf_on_login', 10, 2 );

function rincity_wf_on_login( $user_login, $user ) {
	if ( ! defined( 'RINCITY_WF_TEMP_ALLOWLIST_USER_IDS' ) || ! defined( 'RINCITY_WF_TEMP_ALLOWLIST_TTL' ) ) {
		rincity_wf_log( 'Skipped on login: required constants not defined.' );
		return;
	}

	$allowed_ids = array_map(
		'intval',
		array_filter( array_map( 'trim', explode( ',', RINCITY_WF_TEMP_ALLOWLIST_USER_IDS ) ) )
	);

	if ( ! in_array( (int) $user->ID, $allowed_ids, true ) ) {
		return;
	}

	$ip = rincity_wf_get_client_ip();
	if ( ! $ip ) {
		rincity_wf_log( 'Skipped on login: client IP validation failed.' );
		return;
	}

	rincity_wf_activate( $user->ID, $user_login, $ip );
}

// ---------------------------------------------------------------------------
// Allowlist activation
// ---------------------------------------------------------------------------

function rincity_wf_activate( $user_id, $user_login, $ip ) {
	if ( ! class_exists( 'wfConfig' ) ) {
		rincity_wf_log( 'Skipped: wfConfig class not available (Wordfence inactive?).' );
		return;
	}

	$now        = time();
	$expires_at = $now + (int) RINCITY_WF_TEMP_ALLOWLIST_TTL;

	$meta     = get_option( RINCITY_WF_OPTION );
	$prev_ip  = is_array( $meta ) && ! empty( $meta['ip'] ) ? $meta['ip'] : null;

	// Remove any previous tracked IP
	if ( $prev_ip ) {
		rincity_wf_remove_ip( $prev_ip );
		rincity_wf_log( "Replaced previous temporary allowlist IP: {$prev_ip}." );
	}

	// Cancel existing expiry event
	$existing = wp_next_scheduled( RINCITY_WF_CRON_HOOK );
	if ( $existing ) {
		wp_unschedule_event( $existing, RINCITY_WF_CRON_HOOK );
	}

	// Add new IP
	rincity_wf_add_ip( $ip );

	// Persist metadata
	update_option( RINCITY_WF_OPTION, array(
		'user_id'    => $user_id,
		'ip'         => $ip,
		'added_at'   => $now,
		'expires_at' => $expires_at,
		'source'     => 'rincity-wordfence-temp-allowlist',
	) );

	// Schedule expiry
	wp_schedule_single_event( $expires_at, RINCITY_WF_CRON_HOOK );

	rincity_wf_log( sprintf(
		'%s (user ID %d) logged in from %s, added to Wordfence allowlist, expires %s UTC.',
		$user_login,
		$user_id,
		$ip,
		gmdate( 'Y-m-d H:i:s', $expires_at )
	) );
}

// ---------------------------------------------------------------------------
// Expiry cron
// ---------------------------------------------------------------------------

add_action( RINCITY_WF_CRON_HOOK, 'rincity_wf_expire' );

function rincity_wf_expire() {
	$meta = get_option( RINCITY_WF_OPTION );
	if ( ! is_array( $meta ) || empty( $meta['ip'] ) ) {
		return;
	}

	$ip = $meta['ip'];
	rincity_wf_remove_ip( $ip );
	delete_option( RINCITY_WF_OPTION );

	rincity_wf_log( "Expired {$ip} from Wordfence allowlist." );
}

// ---------------------------------------------------------------------------
// Lazy cleanup on admin init (guard against delayed WP-Cron)
// ---------------------------------------------------------------------------

add_action( 'init', 'rincity_wf_lazy_cleanup' );

function rincity_wf_lazy_cleanup() {
	if ( ! is_admin() ) {
		return;
	}

	$meta = get_option( RINCITY_WF_OPTION );
	if ( ! is_array( $meta ) || empty( $meta['expires_at'] ) ) {
		return;
	}

	if ( time() < (int) $meta['expires_at'] ) {
		return;
	}

	$ip = $meta['ip'] ?? '';
	if ( $ip ) {
		rincity_wf_remove_ip( $ip );
	}
	delete_option( RINCITY_WF_OPTION );

	rincity_wf_log( "Lazy-expired {$ip} from Wordfence allowlist." );
}

// ---------------------------------------------------------------------------
// Wordfence allowlist helpers
// ---------------------------------------------------------------------------

function rincity_wf_add_ip( $ip ) {
	$entries = rincity_wf_get_entries();
	if ( ! in_array( $ip, $entries, true ) ) {
		$entries[] = $ip;
	}
	rincity_wf_set_entries( $entries );
}

function rincity_wf_remove_ip( $ip ) {
	if ( ! class_exists( 'wfConfig' ) ) {
		return;
	}
	$entries = rincity_wf_get_entries();
	$entries = array_values( array_filter( $entries, fn( $e ) => $e !== $ip ) );
	rincity_wf_set_entries( $entries );
}

function rincity_wf_get_entries() {
	if ( ! class_exists( 'wfConfig' ) ) {
		return array();
	}
	$csv = wfConfig::get( 'whitelisted', '' );
	if ( ! is_string( $csv ) || $csv === '' ) {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', explode( ',', $csv ) ) ) );
}

function rincity_wf_set_entries( array $entries ) {
	wfConfig::set( 'whitelisted', implode( ',', $entries ) );
	rincity_wf_purge_waf_cache();
}

function rincity_wf_purge_waf_cache() {
	if ( ! class_exists( 'wfWAF' ) || ! class_exists( 'wfWAFStorageInterface' ) ) {
		return;
	}
	$waf = wfWAF::getInstance();
	if ( ! $waf ) {
		return;
	}
	$engine = $waf->getStorageEngine();
	if ( $engine && method_exists( $engine, 'purgeIPBlocks' ) ) {
		$engine->purgeIPBlocks( wfWAFStorageInterface::IP_BLOCKS_BLACKLIST );
	}
}

// ---------------------------------------------------------------------------
// IP detection
// ---------------------------------------------------------------------------

function rincity_wf_get_client_ip() {
	// Use REMOTE_ADDR directly — X-Forwarded-For is not trusted on this OLS stack.
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return null;
	}
	return $ip;
}

// ---------------------------------------------------------------------------
// Audit logging
// ---------------------------------------------------------------------------

function rincity_wf_log( $message ) {
	error_log( '[' . gmdate( 'Y-m-d H:i:s' ) . ' UTC] [rincity-wf-temp-allowlist] ' . $message );
}

// ---------------------------------------------------------------------------
// WP-CLI commands
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'rincity-wf-temp-allowlist', 'RinCity_WF_Temp_Allowlist_Command' );
}

class RinCity_WF_Temp_Allowlist_Command {

	/**
	 * Show the current temporary allowlist status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rincity-wf-temp-allowlist status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$meta = get_option( RINCITY_WF_OPTION );

		if ( ! is_array( $meta ) ) {
			WP_CLI::line( 'No temporary allowlist entry tracked.' );
			return;
		}

		$ip      = $meta['ip']         ?? '?';
		$added   = isset( $meta['added_at'] )
			? gmdate( 'Y-m-d H:i:s', $meta['added_at'] ) . ' UTC'
			: '?';
		$expires = isset( $meta['expires_at'] )
			? gmdate( 'Y-m-d H:i:s', $meta['expires_at'] ) . ' UTC'
			: '?';
		$stale   = isset( $meta['expires_at'] ) && time() >= (int) $meta['expires_at']
			? ' (EXPIRED — lazy cleanup pending)'
			: '';

		$in_wf = false;
		if ( class_exists( 'wfConfig' ) ) {
			$in_wf = in_array( $ip, rincity_wf_get_entries(), true );
		}

		WP_CLI::line( "IP:           {$ip}" );
		WP_CLI::line( "Added:        {$added}" );
		WP_CLI::line( "Expires:      {$expires}{$stale}" );
		WP_CLI::line( 'In Wordfence: ' . ( $in_wf ? 'yes' : 'no' ) );
	}

	/**
	 * Remove the tracked temporary allowlist entry.
	 *
	 * ## EXAMPLES
	 *
	 *     wp rincity-wf-temp-allowlist clear
	 *
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$meta = get_option( RINCITY_WF_OPTION );

		if ( ! is_array( $meta ) || empty( $meta['ip'] ) ) {
			WP_CLI::line( 'No temporary allowlist entry to clear.' );
			return;
		}

		$ip = $meta['ip'];
		rincity_wf_remove_ip( $ip );
		delete_option( RINCITY_WF_OPTION );

		$ts = wp_next_scheduled( RINCITY_WF_CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, RINCITY_WF_CRON_HOOK );
		}

		WP_CLI::success( "Cleared temporary allowlist entry for IP {$ip}." );
	}
}
