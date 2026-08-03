<?php
/**
 * Pure filesystem-path to S3-key conversion.
 */

final class RinMS_Paths {
    /** Convert an absolute path below wp-content into its S3 object key. */
    public static function key_for(
        string $path,
        string $content_dir,
        string $state_dir = '/var/tmp/rincity-media-sync',
        bool $must_exist = true
    ): ?string {
        if ( '' === $path || '' === $content_dir || ! self::is_absolute( $path ) ) {
            return null;
        }
        if ( in_array( '..', preg_split( '#[\\\\/]#', $path ), true ) ) {
            return null;
        }

        $resolved_path = $must_exist ? realpath( $path ) : self::normalise( $path );
        $resolved_content = realpath( $content_dir );
        if ( false === $resolved_content ) {
            $resolved_content = self::normalise( $content_dir );
        }
        $resolved_state = realpath( $state_dir );
        if ( false === $resolved_state ) {
            $resolved_state = self::normalise( $state_dir );
        }

        if ( false === $resolved_path || null === $resolved_path ) {
            return null;
        }
        $resolved_path = self::normalise( $resolved_path );
        $resolved_content = rtrim( self::normalise( $resolved_content ), '/' );
        $resolved_state = rtrim( self::normalise( $resolved_state ), '/' );

        if ( self::is_within( $resolved_path, $resolved_state ) ) {
            return null;
        }
        if ( ! self::is_within( $resolved_path, $resolved_content ) || $resolved_path === $resolved_content ) {
            return null;
        }

        $relative = ltrim( substr( $resolved_path, strlen( $resolved_content ) ), '/' );
        foreach ( explode( '/', $relative ) as $component ) {
            if ( '' === $component || str_starts_with( $component, '.' ) ) {
                return null;
            }
        }

        return 'wp-content/' . $relative;
    }

    private static function is_absolute( string $path ): bool {
        return str_starts_with( $path, '/' );
    }

    private static function is_within( string $path, string $directory ): bool {
        return '' !== $directory && ( $path === $directory || str_starts_with( $path, $directory . '/' ) );
    }

    private static function normalise( string $path ): string {
        $parts = [];
        foreach ( preg_split( '#/+#', str_replace( '\\', '/', $path ) ) as $part ) {
            if ( '' === $part || '.' === $part ) {
                continue;
            }
            if ( '..' === $part ) {
                array_pop( $parts );
                continue;
            }
            $parts[] = $part;
        }
        return '/' . implode( '/', $parts );
    }
}

