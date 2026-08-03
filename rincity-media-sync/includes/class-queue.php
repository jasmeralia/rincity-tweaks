<?php
/**
 * Atomic filesystem queue and detached worker spawning.
 */

defined( 'ABSPATH' ) || exit;

final class RinMS_Queue {
    private static bool $shutdown_registered = false;

    /** Add a readable file to the upload queue. */
    public static function put( string $path ): void {
        $key = RinMS_Paths::key_for( $path, WP_CONTENT_DIR, RinMS_Config::STATE_DIR, true );
        if ( null === $key || ! is_file( $path ) || ! is_readable( $path ) ) {
            return;
        }
        $real = realpath( $path );
        if ( false === $real ) {
            return; // File removed between the is_file() check and here; nothing to sync.
        }
        self::enqueue( 'put', $real );
    }

    /** Add a path to the delete queue without requiring the file to still exist. */
    public static function delete( string $path ): void {
        $key = RinMS_Paths::key_for( $path, WP_CONTENT_DIR, RinMS_Config::STATE_DIR, false );
        if ( null === $key ) {
            return;
        }
        self::enqueue( 'del', $path );
    }

    /** Spawn the detached worker once for this PHP request. */
    public static function schedule_spawn(): void {
        if ( self::$shutdown_registered ) {
            return;
        }
        self::$shutdown_registered = true;
        add_action( 'shutdown', [ self::class, 'spawn' ], PHP_INT_MAX );
    }

    /** Spawn the queue worker with the resolved option overrides in its environment. */
    public static function spawn(): void {
        $runtime = RinMS_Config::runtime();
        $config = $runtime['config'];
        $environment = 'RINMS_ENABLED=' . ( $runtime['enabled'] ? '1' : '0' ) . ' ';
        if ( is_array( $config ) ) {
            $environment .= 'RINMS_BUCKET=' . escapeshellarg( $config['bucket'] ) . ' '
                . 'RINMS_DISTRIBUTION=' . escapeshellarg( $config['distribution'] ) . ' '
                . 'RINMS_REGION=' . escapeshellarg( $config['region'] ) . ' ';
        }

        $worker = RINMS_PLUGIN_DIR . 'bin/rincity-media-sync-worker.sh';
        // Trailing `&` backgrounds setsid and the shell returns 0 immediately either way,
        // so exec()'s own exit code can never detect a spawn failure here -- the WP-Cron
        // drain (rincity_media_sync_drain) is the recovery path for that case instead.
        exec( $environment . '/usr/bin/setsid ' . escapeshellarg( $worker ) . ' >/dev/null 2>&1 < /dev/null &' );
    }

    private static function enqueue( string $operation, string $path ): void {
        $directory = RinMS_Config::STATE_DIR . '/queue/' . $operation;
        if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
            syslog( LOG_ERR, 'rincity-media-sync: could not create queue directory ' . $directory );
            return;
        }

        $target = $directory . '/' . sha1( $path );
        $temporary = $target . '.tmp';
        if ( false === file_put_contents( $temporary, $path . "\n", LOCK_EX ) || ! rename( $temporary, $target ) ) {
            @unlink( $temporary );
            syslog( LOG_ERR, 'rincity-media-sync: could not enqueue ' . $operation . ' for ' . $path );
            return;
        }
        self::schedule_spawn();
    }
}

