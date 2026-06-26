<?php
/**
 * Bridge aMember session authentication into WordPress.
 *
 * aMember manages its own PHP session and never calls wp_set_auth_cookie(),
 * so WordPress's is_user_logged_in() always returns false for aMember-only
 * sessions — breaking native WP comment forms. This filter maps the active
 * aMember identity to the corresponding WP user account so that WP considers
 * the member properly authenticated.
 */

add_filter( 'determine_current_user', function( $user_id ) {
    // WP already resolved a user via its own auth cookie — leave it alone.
    if ( $user_id ) {
        return $user_id;
    }

    // aMember plugin must be active and fully initialised. am4_Settings_Config is
    // loaded lazily; in non-HTTP contexts (e.g. wp-cron.php) it may not exist yet
    // when Wordfence triggers determine_current_user early in bootstrap.
    if ( ! class_exists( 'am4PluginsManager' ) || ! class_exists( 'am4_Settings_Config' ) ) {
        return $user_id;
    }

    $api = am4PluginsManager::getAPI();
    if ( ! $api || ! $api->isLoggedIn() ) {
        return $user_id;
    }

    $email = $api->getEmail();
    if ( ! $email ) {
        return $user_id;
    }

    $wp_user = get_user_by( 'email', $email );
    return $wp_user ? $wp_user->ID : $user_id;
}, 20 );
