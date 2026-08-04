<?php
/**
 * Shared resolver for Members Gallery cover crop URLs.
 *
 * Both the album shortcode (rc_tweaks) and the favorites repository
 * (rincity-gallery-favorites) previously built this URL independently with a
 * regex that only matched "-scaled.<ext>" sources, so non-scaled sources
 * (the majority of covers) fell through unchanged to the full-size original.
 * This resolver builds the crop candidate for any source shape, validates it
 * actually exists on disk at the expected physical dimensions, and only
 * falls back to the original — loudly, via error_log() — when no valid crop
 * can be found or generated.
 */


if ( ! function_exists( "rincity_cover_crop_candidate" ) ) {
    /**
     * Build the crop URL/path candidate for a source, inserting
     * "-{width}x{height}_{alignment}" before the extension. Works for both
     * "-scaled.<ext>" and plain "<name>.<ext>" sources.
     */
    function rincity_cover_crop_candidate( string $src, int $width, int $height, string $alignment = "c" ): ?string {
        if ( $src === "" || $width <= 0 || $height <= 0 ) {
            return null;
        }
        $ext = pathinfo( $src, PATHINFO_EXTENSION );
        if ( $ext === "" ) {
            return null;
        }
        return preg_replace(
            "/\\." . preg_quote( $ext, "/" ) . "$/i",
            "-{$width}x{$height}_{$alignment}.{$ext}",
            $src
        );
    }
}

if ( ! function_exists( "rincity_cover_crop_is_valid" ) ) {
    /** True only if the local file exists and its physical dimensions match exactly. */
    function rincity_cover_crop_is_valid( string $local_path, int $width, int $height ): bool {
        if ( $local_path === "" || ! is_file( $local_path ) ) {
            return false;
        }
        $size = @getimagesize( $local_path );
        return is_array( $size ) && (int) $size[0] === $width && (int) $size[1] === $height;
    }
}

if ( ! function_exists( "rincity_cover_url_to_path" ) ) {
    /** Map a wp-content/uploads URL to its local filesystem path. */
    function rincity_cover_url_to_path( string $url, string $baseurl, string $basedir ): ?string {
        if ( $url === "" || $baseurl === "" || strpos( $url, $baseurl ) !== 0 ) {
            return null;
        }
        return $basedir . substr( $url, strlen( $baseurl ) );
    }
}

if ( ! function_exists( "rincity_resolve_gallery_cover_url" ) ) {
    /**
     * Resolve the cover URL to emit for a gallery: an existing, correctly
     * sized crop when possible, generated on demand via envira_resize_image()
     * if the crop is missing or malformed. Falls back to the full-size
     * source only as a last resort, and always logs when it does so this
     * never fails silently.
     */
    function rincity_resolve_gallery_cover_url( string $src, int $width = 320, int $height = 400, string $alignment = "c" ): string {
        if ( $src === "" ) {
            return $src;
        }

        $candidate = rincity_cover_crop_candidate( $src, $width, $height, $alignment );
        if ( $candidate === null ) {
            return $src;
        }

        $upload_dir = function_exists( "wp_get_upload_dir" ) ? wp_get_upload_dir() : array();
        $baseurl    = (string) ( $upload_dir["baseurl"] ?? "" );
        $basedir    = (string) ( $upload_dir["basedir"] ?? "" );
        $local_path = rincity_cover_url_to_path( $candidate, $baseurl, $basedir );

        if ( $local_path !== null && rincity_cover_crop_is_valid( $local_path, $width, $height ) ) {
            return $candidate;
        }

        if ( function_exists( "envira_resize_image" ) ) {
            $generated = envira_resize_image( $src, $width, $height, true, $alignment, 100, false );
            if ( ! is_wp_error( $generated ) && $local_path !== null && rincity_cover_crop_is_valid( $local_path, $width, $height ) ) {
                return $candidate;
            }
        }

        error_log( sprintf(
            "rincity: no valid %dx%d cover crop for \"%s\" (candidate \"%s\"); falling back to full-size source.",
            $width,
            $height,
            $src,
            $candidate
        ) );

        return $src;
    }
}
