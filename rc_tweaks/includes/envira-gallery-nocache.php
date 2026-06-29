<?php
/**
 * Send no-store Cache-Control headers for Envira gallery standalone pages.
 *
 * Envira caches gallery HTML server-side in transients. When that cache is
 * rebuilt (e.g. after gallery edits), browsers that cached the old page via
 * HTTP caching would silently serve stale HTML — hiding newly-added UI such
 * as the downloads addon buttons. No-store prevents this without affecting
 * the server-side transient cache.
 */
add_action( 'template_redirect', function () {
    if ( ! is_singular( 'envira' ) ) {
        return;
    }
    header( 'Cache-Control: no-store' );
    header( 'Pragma: no-cache' );
} );
