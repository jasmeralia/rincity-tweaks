<?php
/**
 * Host-specific media-sync configuration.
 */

final class RinMS_Config {
    public const STATE_DIR = '/var/tmp/rincity-media-sync';

    private const HOSTS = [
        'gelfling' => [
            'bucket'       => 'windsofstorm-rincity-s3',
            'distribution' => 'E2TEG3T8F8AC6S',
            'region'       => 'us-east-1',
        ],
        'rinling' => [
            'bucket'       => 'windsofstorm-rincity-test-s3',
            'distribution' => 'EG4OSA4RQXBRS',
            'region'       => 'us-east-1',
        ],
    ];

    /** Return the compiled host map for dependency-free tests. */
    public static function host_map(): array {
        return self::HOSTS;
    }

    /** Resolve a hostname and optional override without calling WordPress. */
    public static function for_hostname( string $hostname, array $override = [] ): ?array {
        $base = self::HOSTS[ $hostname ] ?? null;
        if ( ! $base && ( empty( $override['bucket'] ) || empty( $override['distribution'] ) ) ) {
            return null;
        }

        $config = $base ?? [ 'region' => 'us-east-1' ];
        foreach ( [ 'bucket', 'distribution', 'region' ] as $key ) {
            if ( isset( $override[ $key ] ) && is_string( $override[ $key ] ) && '' !== $override[ $key ] ) {
                $config[ $key ] = $override[ $key ];
            }
        }

        if ( empty( $config['bucket'] ) || empty( $config['distribution'] ) || empty( $config['region'] ) ) {
            return null;
        }
        return $config;
    }

    /** Return the runtime configuration, including the soft-switch state. */
    public static function runtime(): array {
        $option = get_option( 'rincity_media_sync', [] );
        $option = is_array( $option ) ? $option : [];
        $config = self::for_hostname( (string) gethostname(), $option );

        return [
            'enabled' => ! file_exists( self::STATE_DIR . '/DISABLED' )
                && ! ( array_key_exists( 'enabled', $option ) && ! (bool) $option['enabled'] ),
            'config'  => $config,
        ];
    }
}
