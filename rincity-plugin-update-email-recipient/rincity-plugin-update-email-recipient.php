<?php
/**
 * Plugin Name: RinCity Plugin/Theme Update Email Recipient
 * Description: Reroutes WordPress's background plugin/theme auto-update summary email to a single recipient, independent of admin_email.
 * Version: 1.0.0
 * Author: Morgan Blackthorne
 */

add_filter( 'auto_plugin_theme_update_email', function ( $email ) {
	if ( defined( 'RINCITY_PLUGIN_UPDATE_EMAIL_RECIPIENT' ) && RINCITY_PLUGIN_UPDATE_EMAIL_RECIPIENT ) {
		$email['to'] = RINCITY_PLUGIN_UPDATE_EMAIL_RECIPIENT;
	}
	return $email;
} );
