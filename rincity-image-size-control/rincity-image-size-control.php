<?php
/**
 * Plugin Name: RinCity Image Size Control
 * Description: Suppresses unused WordPress/Ashe derivative sizes for Envira gallery uploads.
 * Version: 0.1.1
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'intermediate_image_sizes_advanced', 'rincity_filter_envira_image_sizes', 10, 3 );

function rincity_filter_envira_image_sizes( $sizes, $metadata, $attachment_id ) {
    if ( ! rincity_is_envira_gallery_attachment( $attachment_id ) ) {
        return $sizes;
    }

    $disabled_sizes = [
        'medium_large',
        'large',
        '1536x1536',
        '2048x2048',
        'ashe-slider-grid-thumbnail',
        'ashe-full-thumbnail',
        'ashe-grid-thumbnail',
        'ashe-list-thumbnail',
        'ashe-single-navigation',
        'rincity-thumb',
    ];

    foreach ( $disabled_sizes as $size ) {
        unset( $sizes[ $size ] );
    }

    return $sizes;
}

function rincity_is_envira_gallery_attachment( $attachment_id ) {
    $parent_id = wp_get_post_parent_id( $attachment_id );
    if ( ! $parent_id ) {
        return false;
    }

    $parent = get_post( $parent_id );
    return $parent && 'envira' === $parent->post_type;
}
