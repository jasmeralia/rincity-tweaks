<?php
/**
 * Plugin Name: RinCity WF Block Logger
 * Description: Logs Wordfence IP ban events to the systemd journal for OpenSearch ingestion via Vector.
 * Version:     1.0.0
 * Author:      Morgan Blackthorne
 *
 * Must-use plugin — place at wp-content/mu-plugins/rincity-wf-block-logger.php
 *
 * Events are written as JSON to the systemd journal under syslog identifier
 * 'rincity-wf-block-logger'. Vector reads these via its journald source and
 * routes them to the ip-blocks-* OpenSearch index for use by the daily error
 * summary script to suppress 5xx errors from already-banned IPs.
 *
 * See: rincity-infra/plans/ip-block-event-ingestion.md
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rate-limiting and WAF blocks.
 *
 * Fired by wfLog::takeBlockingAction() and wfLog::do503() when a request is
 * blocked (not throttled). Covers flood/rate blocks and WAF rule triggers.
 *
 * @param string $event  'block' or 'throttle'
 * @param array  $data   Keys: ip (string), reason (string), duration (int seconds)
 */
add_action( 'wordfence_security_event', function ( $event, $data ) {
	if ( $event !== 'block' || empty( $data['ip'] ) ) {
		return;
	}
	rincity_wfbl_log( $data['ip'], $data['reason'] ?? '' );
}, 10, 2 );

/**
 * Manual and automatic-permanent IP blocks.
 *
 * Fired by wfBlock::createIP() for TYPE_IP_MANUAL and
 * TYPE_IP_AUTOMATIC_PERMANENT. Does NOT fire for createRateBlock(),
 * createLockout(), or createWFSN() — those are covered above.
 *
 * @param int          $type    wfBlock::TYPE_* constant
 * @param string       $reason  Human-readable reason
 * @param string|array $ip      IP string for IP blocks; array for pattern blocks (skipped)
 */
add_action( 'wordfence_created_ip_pattern_block', function ( $type, $reason, $ip ) {
	if ( ! is_string( $ip ) || $ip === '' ) {
		return;
	}
	rincity_wfbl_log( $ip, $reason );
}, 10, 3 );

/**
 * Write a ban event as JSON to the systemd journal via syslog.
 */
function rincity_wfbl_log( $ip, $reason ) {
	openlog( 'rincity-wf-block-logger', LOG_PID, LOG_DAEMON );
	syslog( LOG_INFO, wp_json_encode( array(
		'action' => 'ban',
		'ip'     => $ip,
		'reason' => $reason,
		'source' => 'wordfence',
	) ) );
	closelog();
}
