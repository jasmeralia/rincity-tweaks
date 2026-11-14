<?php
/**
 * Exit successfully with POST or SKIP when checking today's published sets.
 * Run through WP-CLI so the query uses WordPress's database and site timezone.
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( defined( 'STDERR' ) ? STDERR : fopen( 'php://stderr', 'w' ), "This script must run via WP-CLI eval-file.\n" );
	exit( 1 );
}

$post_type = '';
foreach ( array( 'envira_gallery', 'envira' ) as $candidate ) {
	if ( post_type_exists( $candidate ) ) {
		$post_type = $candidate;
		break;
	}
}

if ( '' === $post_type ) {
	WP_CLI::error( 'Could not detect Envira gallery post type.' );
}

$today = current_time( 'Y-m-d' );
$post_ids = get_posts(
	array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'date_query'     => array(
			array(
				'after'     => $today,
				'before'    => $today,
				'inclusive' => true,
			),
		),
	)
);

foreach ( $post_ids as $post_id ) {
	$title = html_entity_decode( get_the_title( $post_id ) ?: 'Untitled', ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	if ( 'bonus content' === strtolower( $title ) ) {
		continue;
	}

	$is_excluded = false;
	foreach ( get_object_taxonomies( $post_type ) as $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( is_wp_error( $terms ) || ! $terms ) {
			continue;
		}
		foreach ( $terms as $term ) {
			if ( 'dustrat' === strtolower( $term->slug ) ) {
				$is_excluded = true;
				break 2;
			}
		}
	}

	if ( ! $is_excluded ) {
		WP_CLI::line( "SKIP: a set was published today (gallery ID {$post_id})." );
		return;
	}
}

WP_CLI::line( 'POST' );
