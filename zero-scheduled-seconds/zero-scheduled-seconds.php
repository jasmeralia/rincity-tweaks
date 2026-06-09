<?php
/**
 * Plugin Name: Zero Scheduled Seconds
 * Description: Strips sub-minute precision from scheduled post times so WP-Cron fires on the exact minute.
 * Version: 1.0.0
 */

// Zeroing seconds prevents the "missed schedule" flash caused by posts saved at e.g. 07:00:25 UTC
// instead of 07:00:00 UTC — WordPress would flag them missed until the next cron tick fires.
add_filter( 'wp_insert_post_data', function ( $data ) {
	if ( $data['post_status'] === 'future' ) {
		$data['post_date']     = date( 'Y-m-d H:i:00', strtotime( $data['post_date'] ) );
		$data['post_date_gmt'] = date( 'Y-m-d H:i:00', strtotime( $data['post_date_gmt'] ) );
	}
	return $data;
} );
