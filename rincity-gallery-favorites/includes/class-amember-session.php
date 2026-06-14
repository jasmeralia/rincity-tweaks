<?php
defined( 'ABSPATH' ) || exit;

/**
 * Wraps the amember4 WP plugin's Am_Lite API.
 * Member identity is an array keyed by 'user_id', 'login', 'email', 'name_f', 'name_l'.
 * Accessed via am4PluginsManager::getAPI() which bootstraps Am_Lite and reads the PHP session.
 */
final class RinCity_Amember_Session {

    /** @var object|null Am_Lite instance, or null if not authenticated */
    private $api     = null;
    private bool $fetched = false;

    private function fetch(): void {
        if ( $this->fetched ) {
            return;
        }
        $this->fetched = true;

        if ( ! class_exists( 'am4PluginsManager' ) ) {
            $this->debug( 'am4PluginsManager class not found' );
            return;
        }

        $api = am4PluginsManager::getAPI();
        if ( ! $api ) {
            $this->debug( 'am4PluginsManager::getAPI() returned false' );
            return;
        }

        if ( $api->isLoggedIn() ) {
            $this->api = $api;
            $this->debug( 'resolved via am4PluginsManager::getAPI()', $api );
        } else {
            $this->debug( 'Am_Lite available but isLoggedIn() returned false' );
        }
    }

    public function is_logged_in(): bool {
        $this->fetch();
        return $this->api !== null;
    }

    public function get_member_id(): ?int {
        $this->fetch();
        if ( $this->api === null ) {
            return null;
        }
        $user = $this->api->getUser();
        if ( ! $user || empty( $user['user_id'] ) ) {
            return null;
        }
        return (int) $user['user_id'];
    }

    public function get_member_email(): ?string {
        $this->fetch();
        if ( $this->api === null ) {
            return null;
        }
        $email = $this->api->getEmail();
        return is_string( $email ) && $email !== '' ? $email : null;
    }

    public function get_member_display_name(): ?string {
        $this->fetch();
        if ( $this->api === null ) {
            return null;
        }
        $name = $this->api->getName();
        return is_string( $name ) && $name !== '' ? $name : null;
    }

    private function debug( string $msg, $api = null ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
            return;
        }
        $line = '[rincgf amember-session] ' . $msg;
        if ( $api ) {
            $user = $api->getUser();
            $line .= ' | user_id=' . ( $user['user_id'] ?? '?' );
        }
        error_log( $line );
    }

    /**
     * Returns diagnostic data for WP-CLI / staging use only.
     */
    public function diagnostics(): array {
        $this->fetch();
        $api_available = class_exists( 'am4PluginsManager' ) && am4PluginsManager::getAPI() !== false;
        return [
            'am4PluginsManager_exists' => class_exists( 'am4PluginsManager' ),
            'Am_Lite_exists'           => class_exists( 'Am_Lite' ),
            'api_available'            => $api_available,
            'resolved'                 => $this->api !== null,
            'member_id'                => $this->get_member_id(),
        ];
    }
}
