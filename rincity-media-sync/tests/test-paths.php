<?php
/** Dependency-free assertions for media-sync path and host mapping logic. */

require_once __DIR__ . '/../includes/class-paths.php';
require_once __DIR__ . '/../includes/class-config.php';

$root = sys_get_temp_dir() . '/rinms-test-' . getmypid();
$content = $root . '/wp-content';
$state = $root . '/state';
$outside = $root . '/outside';

$remove_tree = static function ( string $path ) use ( &$remove_tree ): void {
    if ( is_link( $path ) || is_file( $path ) ) {
        unlink( $path );
        return;
    }
    if ( ! is_dir( $path ) ) {
        return;
    }
    foreach ( array_diff( scandir( $path ), [ '.', '..' ] ) as $item ) {
        $remove_tree( $path . '/' . $item );
    }
    rmdir( $path );
};
register_shutdown_function( static function () use ( $root, $remove_tree ): void {
    $remove_tree( $root );
} );

foreach ( [
    $content . '/uploads/2026/08',
    $content . '/uploads/wallpaper-crops',
    $content . '/themes/ashe/assets',
    $content . '/uploads/.private',
    $state,
    $outside,
] as $directory ) {
    mkdir( $directory, 0777, true );
}
foreach ( [
    $content . '/uploads/2026/08/photo.jpg',
    $content . '/uploads/wallpaper-crops/alice_1_top_wm.jpg',
    $content . '/themes/ashe/assets/theme.css',
    $content . '/uploads/.private/secret.jpg',
    $state . '/queue-entry',
    $outside . '/outside.jpg',
] as $file ) {
    file_put_contents( $file, 'test' );
}
symlink( $outside . '/outside.jpg', $content . '/uploads/linked.jpg' );

$assertions = 0;
$assert_same = static function ( $expected, $actual, string $label ) use ( &$assertions ): void {
    $assertions++;
    if ( $expected !== $actual ) {
        fwrite( STDERR, "FAIL: {$label}\nExpected: " . var_export( $expected, true )
            . "\nActual:   " . var_export( $actual, true ) . "\n" );
        exit( 1 );
    }
};

$assert_same( 'wp-content/uploads/2026/08/photo.jpg', RinMS_Paths::key_for( $content . '/uploads/2026/08/photo.jpg', $content, $state ), 'normal upload' );
$assert_same( 'wp-content/uploads/wallpaper-crops/alice_1_top_wm.jpg', RinMS_Paths::key_for( $content . '/uploads/wallpaper-crops/alice_1_top_wm.jpg', $content, $state ), 'wallpaper crop' );
$assert_same( 'wp-content/themes/ashe/assets/theme.css', RinMS_Paths::key_for( $content . '/themes/ashe/assets/theme.css', $content, $state ), 'theme asset' );
$assert_same( null, RinMS_Paths::key_for( $outside . '/outside.jpg', $content, $state ), 'outside content directory' );
$assert_same( null, RinMS_Paths::key_for( $content . '/uploads/../uploads/2026/08/photo.jpg', $content, $state ), 'traversal syntax' );
$assert_same( null, RinMS_Paths::key_for( $content . '/uploads/linked.jpg', $content, $state ), 'symlink traversal' );
$assert_same( null, RinMS_Paths::key_for( $state . '/queue-entry', $content, $state ), 'state directory' );
$assert_same( null, RinMS_Paths::key_for( $content . '/uploads/.private/secret.jpg', $content, $state ), 'dotfile component' );
$assert_same( RinMS_Paths::key_for( $content . '/uploads/2026/08/photo.jpg', $content, $state ), RinMS_Paths::key_for( $content . '/uploads/2026/08/photo.jpg', $content . '/', $state ), 'trailing content slash' );
$assert_same( null, RinMS_Config::for_hostname( 'unknown-host' ), 'unknown host fails closed' );

$worker = file_get_contents( __DIR__ . '/../bin/rincity-media-sync-worker.sh' );
foreach ( RinMS_Config::host_map() as $hostname => $config ) {
    $pattern = '/' . preg_quote( $hostname, '/' ) . '\\)\\s+bucket=([^\\s]+)\\s+distribution=([^\\s]+)\\s+region=([^\\s]+)/s';
    preg_match( $pattern, $worker, $matches );
    $assert_same( [ $config['bucket'], $config['distribution'], $config['region'] ], array_slice( $matches, 1 ), "worker map for {$hostname}" );
}

echo "PASS: {$assertions} media-sync path/config assertions\n";

