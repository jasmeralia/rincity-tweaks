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

if ( ! function_exists( "rincity_cover_rewrite_url_host" ) ) {
    /**
     * Rewrite a URL's scheme/host/port to match $target_base_url, preserving its
     * path and query string. Pure string manipulation, no WordPress dependency.
     */
    function rincity_cover_rewrite_url_host( string $src, string $target_base_url ): string {
        if ( $src === "" || $target_base_url === "" ) {
            return $src;
        }
        $target = parse_url( $target_base_url );
        $source = parse_url( $src );
        if (
            ! $target || ! $source
            || empty( $target["host"] )
            || empty( $source["path"] )
            || $source["path"][0] !== "/"
        ) {
            return $src;
        }
        $scheme    = $target["scheme"] ?? "https";
        $port      = isset( $target["port"] ) ? ":" . $target["port"] : "";
        $rewritten = "{$scheme}://{$target['host']}{$port}{$source['path']}";
        if ( ! empty( $source["query"] ) ) {
            $rewritten .= "?" . $source["query"];
        }
        return $rewritten;
    }
}

if ( ! function_exists( "rincity_cover_url_host_differs" ) ) {
    /**
     * True if $src's host differs from $target_base_url's host — compares parsed
     * host components, not a raw substring match, so a CDN host that merely starts
     * with the site host (e.g. site "a.com" vs CDN "a.com.cdn.net") is correctly
     * treated as different rather than false-positive matching via strpos().
     */
    function rincity_cover_url_host_differs( string $src, string $target_base_url ): bool {
        $target_host = $target_base_url !== "" ? ( parse_url( $target_base_url, PHP_URL_HOST ) ?: "" ) : "";
        if ( $target_host === "" ) {
            return false;
        }
        $src_host = parse_url( $src, PHP_URL_HOST ) ?: "";
        return $src_host !== $target_host;
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
            // Envira's Cropping::resize_image() silently no-ops (no error, just 
            // `return $url` unchanged) if $url doesn't contain site_url() as a substring.
            // A CDN-rewritten source domain (CDN Enabler et al.) fails that check, so
            // rewrite to the real site URL for the resize call itself; envira_resize_image()
            // already re-applies any CDN rewriting to its own return value via the
            // envira_gallery_resize_image_resized_url filter.
            $resize_src = $src;
            if ( function_exists( "site_url" ) ) {
                $site_url = site_url();
                if ( rincity_cover_url_host_differs( $src, $site_url ) ) {
                    $resize_src = rincity_cover_rewrite_url_host( $src, $site_url );
                }
            }
            $generated = envira_resize_image( $resize_src, $width, $height, true, $alignment, 100, false );
            // Validate the path Envira actually generated, not the candidate we guessed:
            // its naming convention for the requested size/alignment is not guaranteed to
            // match rincity_cover_crop_candidate()'s output exactly.
            if ( ! is_wp_error( $generated ) && is_string( $generated ) ) {
                $generated_path = rincity_cover_url_to_path( $generated, $baseurl, $basedir );
                if ( $generated_path !== null && rincity_cover_crop_is_valid( $generated_path, $width, $height ) ) {
                    return $generated;
                }
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
