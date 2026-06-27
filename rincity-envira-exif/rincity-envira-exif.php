<?php
/**
 * Plugin Name: RinCity Envira EXIF
 * Description: Appends a representative camera EXIF block to Envira gallery pages.
 * Version: 1.0.0
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rincity_envira_exif_data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'RINCITY_ENVIRA_EXIF_VERSION', $rincity_envira_exif_data['Version'] );

add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_singular( 'envira' ) ) {
        return;
    }
    wp_enqueue_style(
        'rincity-envira-exif',
        plugin_dir_url( __FILE__ ) . 'assets/rincity-envira-exif.css',
        [],
        RINCITY_ENVIRA_EXIF_VERSION
    );
} );

add_filter( 'the_content', 'rincity_envira_exif_append', 20 );

/**
 * Append a camera EXIF summary block to the bottom of Envira gallery page content.
 * Placed inside .post-content, visually between the gallery grid and footer.post-footer.
 */
function rincity_envira_exif_append( $content ) {
    if ( ! is_singular( 'envira' ) || ! in_the_loop() || ! is_main_query() ) {
        return $content;
    }

    $gallery_data = get_post_meta( get_the_ID(), '_eg_gallery_data', true );

    if ( empty( $gallery_data['gallery'] ) ) {
        return $content;
    }

    $exif = rincity_envira_exif_find( $gallery_data['gallery'] );

    if ( ! $exif ) {
        return $content;
    }

    $parts = rincity_envira_exif_format( $exif );

    if ( empty( $parts ) ) {
        return $content;
    }

    $sep   = ' <span class="rincity-gallery-exif-sep" aria-hidden="true">&middot;</span> ';
    $line  = implode( $sep, array_map( 'esc_html', $parts ) );
    $block = '<div class="rincity-gallery-exif">' . $line . '</div>';

    return $content . $block;
}

/**
 * Find the first gallery image that has camera EXIF data.
 *
 * Checks _envira_exif post meta first (richer: has Lens, pre-formatted ShutterSpeed,
 * separate Make/Model). Falls back to _wp_attachment_metadata['image_meta'] which
 * WordPress populates on upload for all JPEG files.
 *
 * @param array $gallery Gallery items keyed by attachment ID.
 * @return array|false EXIF data with a '_source' key ('envira' or 'wp'), or false.
 */
function rincity_envira_exif_find( $gallery ) {
    foreach ( $gallery as $img_id => $item ) {
        $img_id = (int) $img_id;

        $exif = get_post_meta( $img_id, '_envira_exif', true );
        if ( is_array( $exif ) && ( ! empty( $exif['Make'] ) || ! empty( $exif['Model'] ) ) ) {
            $exif['_source'] = 'envira';
            return $exif;
        }

        $meta       = get_post_meta( $img_id, '_wp_attachment_metadata', true );
        $image_meta = is_array( $meta ) && isset( $meta['image_meta'] ) ? $meta['image_meta'] : [];
        if ( ! empty( $image_meta['camera'] ) ) {
            $image_meta['_source'] = 'wp';
            return $image_meta;
        }
    }

    return false;
}

/**
 * Format EXIF data into an ordered array of display strings.
 *
 * @param array $exif Data returned by rincity_envira_exif_find().
 * @return string[]
 */
function rincity_envira_exif_format( $exif ) {
    $parts  = [];
    $source = $exif['_source'] ?? 'wp';

    // Camera name
    if ( 'envira' === $source ) {
        $make  = trim( $exif['Make'] ?? '' );
        $model = trim( $exif['Model'] ?? '' );
        // Canon (and others) embed Make at the start of Model; avoid "Canon Canon EOS R10".
        if ( $make && str_starts_with( $model, $make ) ) {
            $camera = $model;
        } else {
            $camera = trim( $make . ' ' . $model );
        }
    } else {
        $camera = $exif['camera'] ?? '';
    }
    if ( $camera ) {
        $parts[] = $camera;
    }

    // Aperture
    $ap_raw = ( 'envira' === $source ) ? ( $exif['Aperture'] ?? '' ) : ( $exif['aperture'] ?? '' );
    $ap     = (float) $ap_raw;
    if ( $ap > 0 ) {
        $parts[] = 'f/' . rincity_envira_exif_clean_number( $ap, 1 );
    }

    // Shutter speed
    if ( 'envira' === $source && ! empty( $exif['ShutterSpeed'] ) ) {
        // Pre-formatted as "1/100" or "1/100.0" — strip any .0 suffix from denominator
        $ss      = preg_replace( '/^(\d+\/\d+)\.0+$/', '$1', trim( $exif['ShutterSpeed'] ) );
        $parts[] = $ss . 's';
    } elseif ( ! empty( $exif['shutter_speed'] ) ) {
        $ss = (float) $exif['shutter_speed'];
        if ( $ss > 0 ) {
            $parts[] = rincity_envira_exif_shutter_fraction( $ss );
        }
    }

    // ISO
    $iso = $exif['iso'] ?? '';
    if ( $iso ) {
        $parts[] = 'ISO ' . $iso;
    }

    // Focal length
    $fl = ( 'envira' === $source ) ? ( $exif['FocalLength'] ?? '' ) : ( $exif['focal_length'] ?? '' );
    if ( $fl ) {
        $parts[] = $fl . 'mm';
    }

    // Lens (only in _envira_exif)
    if ( 'envira' === $source && ! empty( $exif['Lens'] ) ) {
        $parts[] = $exif['Lens'];
    }

    return $parts;
}

/**
 * Convert a decimal shutter speed to a fraction string (e.g. 0.01 → "1/100s").
 * Values >= 1 second are returned as "Xs".
 *
 * @param float $decimal
 * @return string
 */
function rincity_envira_exif_shutter_fraction( $decimal ) {
    if ( $decimal >= 1 ) {
        return rincity_envira_exif_clean_number( $decimal, 1 ) . 's';
    }
    return '1/' . (int) round( 1 / $decimal ) . 's';
}

/**
 * Format a float, stripping unnecessary trailing zeros.
 * e.g. 5.60 → "5.6", 5.00 → "5"
 *
 * @param float $val
 * @param int   $precision
 * @return string
 */
function rincity_envira_exif_clean_number( $val, $precision ) {
    return rtrim( rtrim( number_format( $val, $precision, '.', '' ), '0' ), '.' );
}
