<?php
/**
 * WordPress write-path hooks for media synchronization.
 */

defined( 'ABSPATH' ) || exit;

final class RinMS_Hooks {
    /** Register the three core hooks, public API actions, and cron backstop. */
    public static function register(): void {
        add_filter( 'wp_handle_upload', [ self::class, 'handle_upload' ], 10, 2 );
        add_filter( 'image_make_intermediate_size', [ self::class, 'handle_intermediate_size' ] );
        add_action( 'delete_attachment', [ self::class, 'handle_delete_attachment' ], 10, 2 );
        add_action( 'rincity_media_sync_file', [ RinMS_Queue::class, 'put' ] );
        add_action( 'rincity_media_sync_delete', [ RinMS_Queue::class, 'delete' ] );
        add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] );
        add_action( 'rincity_media_sync_drain', [ RinMS_Queue::class, 'spawn' ] );
        add_action( 'init', [ self::class, 'ensure_cron_event' ] );
    }

    /** Queue a completed WordPress upload and preserve the filter value verbatim. */
    public static function handle_upload( array $upload, string $context ): array {
        unset( $context );
        if ( isset( $upload['file'] ) && is_string( $upload['file'] ) ) {
            RinMS_Queue::put( $upload['file'] );
        }
        return $upload;
    }

    /** Queue a completed derivative and preserve the filename verbatim. */
    public static function handle_intermediate_size( string $filename ): string {
        RinMS_Queue::put( $filename );
        // Load-bearing: core stores this return value in attachment metadata.
        return $filename;
    }

    /** Queue every attachment file while its metadata still exists. */
    public static function handle_delete_attachment( int $post_id, WP_Post $post ): void {
        unset( $post );
        $attached = get_attached_file( $post_id );
        if ( ! is_string( $attached ) || '' === $attached ) {
            return;
        }

        $paths = [ $attached ];
        $metadata = wp_get_attachment_metadata( $post_id );
        if ( is_array( $metadata ) ) {
            $directory = dirname( $attached );
            if ( ! empty( $metadata['original_image'] ) && is_string( $metadata['original_image'] ) ) {
                $paths[] = $directory . '/' . $metadata['original_image'];
            }
            foreach ( $metadata['sizes'] ?? [] as $size ) {
                if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
                    $paths[] = $directory . '/' . $size['file'];
                }
            }
        }

        $backups = get_post_meta( $post_id, '_wp_attachment_backup_sizes', true );
        if ( is_array( $backups ) ) {
            $directory = dirname( $attached );
            foreach ( $backups as $backup ) {
                if ( is_array( $backup ) && ! empty( $backup['file'] ) && is_string( $backup['file'] ) ) {
                    $paths[] = $directory . '/' . $backup['file'];
                }
            }
        }

        foreach ( array_unique( $paths ) as $path ) {
            RinMS_Queue::delete( $path );
        }
    }

    /** Add the five-minute safety-net interval. */
    public static function cron_schedules( array $schedules ): array {
        $schedules['rincity_media_sync_five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every five minutes (RinCity media sync)',
        ];
        return $schedules;
    }

    /** Ensure the recurring drain exists even though mu-plugins have no activation hook. */
    public static function ensure_cron_event(): void {
        if ( ! wp_next_scheduled( 'rincity_media_sync_drain' ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'rincity_media_sync_five_minutes', 'rincity_media_sync_drain' );
        }
    }
}

