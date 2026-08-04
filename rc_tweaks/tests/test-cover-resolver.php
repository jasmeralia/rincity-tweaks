<?php
/** Dependency-free assertions for the shared Members Gallery cover crop resolver. */

require_once __DIR__ . "/../includes/cover-resolver.php";

$assertions = 0;
$assert_same = static function ( $expected, $actual, string $label ) use ( &$assertions ): void {
    $assertions++;
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true )
            . "\nActual:   " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
};
$assert_true = static function ( bool $actual, string $label ) use ( &$assertions ): void {
    $assertions++;
    if ( ! $actual ) {
        fwrite( STDERR, "FAIL: {$label}\nExpected: true\nActual:   false\n" );
        exit( 1 );
    }
};

// --- rincity_cover_crop_candidate(): builds the same candidate shape for
// --- both "-scaled" and plain sources ---
$assert_same(
    "/uploads/2026/08/IMG_8251-scaled-320x400_c.jpg",
    rincity_cover_crop_candidate( "/uploads/2026/08/IMG_8251-scaled.jpg", 320, 400 ),
    "scaled source: crop inserted before extension"
);
$assert_same(
    "/uploads/2024/10/full_acidwash_00_19e7e15ea7-320x400_c.jpg",
    rincity_cover_crop_candidate( "/uploads/2024/10/full_acidwash_00_19e7e15ea7.jpg", 320, 400 ),
    "non-scaled source: crop inserted before extension (this was the 215-cover bug)"
);
$assert_same(
    null,
    rincity_cover_crop_candidate( "", 320, 400 ),
    "empty source returns null"
);
$assert_same(
    null,
    rincity_cover_crop_candidate( "/uploads/2026/08/no-extension", 320, 400 ),
    "extensionless source returns null"
);

// --- rincity_cover_url_to_path(): URL -> local path mapping ---
$assert_same(
    "/var/www/uploads/2026/08/IMG_8251-scaled-320x400_c.jpg",
    rincity_cover_url_to_path(
        "https://test.rin-city.com/wp-content/uploads/2026/08/IMG_8251-scaled-320x400_c.jpg",
        "https://test.rin-city.com/wp-content/uploads",
        "/var/www/uploads"
    ),
    "URL under baseurl maps to basedir-relative path"
);
$assert_same(
    null,
    rincity_cover_url_to_path( "https://other.example/x.jpg", "https://test.rin-city.com/wp-content/uploads", "/var/www/uploads" ),
    "URL outside baseurl returns null"
);

// --- rincity_cover_crop_is_valid(): existence + physical-dimension checks,
// --- using real temp files the same way test-paths.php does ---
$root = sys_get_temp_dir() . "/rincr-test-" . getmypid();
mkdir( $root, 0777, true );
register_shutdown_function( static function () use ( $root ): void {
    foreach ( glob( $root . "/*" ) ?: [] as $file ) {
        unlink( $file );
    }
    rmdir( $root );
} );

$make_jpeg = static function ( string $path, int $width, int $height ): void {
    $img = imagecreatetruecolor( $width, $height );
    imagejpeg( $img, $path );
    imagedestroy( $img );
};

$valid_crop = $root . "/valid-320x400_c.jpg";
$make_jpeg( $valid_crop, 320, 400 );
$assert_true(
    rincity_cover_crop_is_valid( $valid_crop, 320, 400 ),
    "existing file at exactly the expected dimensions is valid"
);

// Malformed derivative: named as a 320x400 crop but physically full-size —
// the exact failure mode the audit found for three galleries.
$malformed_crop = $root . "/malformed-320x400_c.jpg";
$make_jpeg( $malformed_crop, 1707, 2560 );
$assert_true(
    ! rincity_cover_crop_is_valid( $malformed_crop, 320, 400 ),
    "file present but wrong physical dimensions is invalid (malformed derivative)"
);

$missing_crop = $root . "/does-not-exist-320x400_c.jpg";
$assert_true(
    ! rincity_cover_crop_is_valid( $missing_crop, 320, 400 ),
    "missing file is invalid"
);


// --- rincity_cover_rewrite_url_host(): swaps scheme/host/port to match a target
// --- base URL while preserving path+query — used to work around Envira's
// --- Cropping::resize_image() silently no-op'ing when the source URL's domain
// --- doesn't contain site_url() (e.g. a CDN-rewritten upload baseurl) ---
$assert_same(
    "https://test.rin-city.com/wp-content/uploads/2025/02/IMG_6293-scaled.jpg",
    rincity_cover_rewrite_url_host(
        "https://cdn.test.rin-city.com/wp-content/uploads/2025/02/IMG_6293-scaled.jpg",
        "https://test.rin-city.com"
    ),
    "CDN-domain source rewritten to the real site_url domain, path preserved"
);
$assert_same(
    "https://test.rin-city.com/wp-content/uploads/2025/02/x.jpg?ver=3",
    rincity_cover_rewrite_url_host(
        "https://cdn.test.rin-city.com/wp-content/uploads/2025/02/x.jpg?ver=3",
        "https://test.rin-city.com"
    ),
    "query string is preserved across the host rewrite"
);
$assert_same(
    "",
    rincity_cover_rewrite_url_host( "", "https://test.rin-city.com" ),
    "empty source returns empty"
);
$assert_same(
    "https://cdn.test.rin-city.com/wp-content/uploads/x.jpg",
    rincity_cover_rewrite_url_host( "https://cdn.test.rin-city.com/wp-content/uploads/x.jpg", "" ),
    "empty target returns source unchanged"
);
$assert_same(
    "not-a-url",
    rincity_cover_rewrite_url_host( "not-a-url", "https://test.rin-city.com" ),
    "unparseable/pathless source returns unchanged rather than guessing"
);

fwrite( STDOUT, "OK: {$assertions} assertions passed.\n" );
