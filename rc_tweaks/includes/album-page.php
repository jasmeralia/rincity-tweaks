<?php
// Register thumbnail size
function rincity_register_image_sizes() {
    add_image_size( 'rincity-thumb', 320, 400, true );
}
add_action( 'init', 'rincity_register_image_sizes' );

// Enqueue styles
function rincity_enqueue_assets() {
    wp_enqueue_style( 'rincity-style', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/style.css' );
}
add_action( 'wp_enqueue_scripts', 'rincity_enqueue_assets' );

function rincity_envira_album_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'id' => '',
        // 'lazyload' => '1', // Remove this attribute
    ), $atts, 'rincity_envira_album' );

    // Use the admin setting instead of shortcode attribute
    $use_lazy = get_option('rincity_envira_album_lazyload', true);

    $album_id = intval( $atts['id'] );
    // $debug    = filter_var( $atts['debug'], FILTER_VALIDATE_BOOLEAN );

    if ( ! $album_id ) {
        return '<p>No album ID provided.</p>';
    }
    if ( ! function_exists( 'envira_get_album_galleries' ) ) {
        return '<p><strong>Error:</strong> Envira Albums Addon unavailable.</p>';
    }
    
    // Fetch album data via post meta
    $album_data = get_post_meta( $album_id, '_eg_album_data', true );
    // if ( $debug ) {
    //     echo '<textarea style="width:100%;height:150px;"><strong>Album Data:</strong> ' . esc_html( print_r( $album_data, true ) ) . '</textarea>';
    // }

    // Prepare IDs and gallery metadata
    $ids = array();
    $items_data = array();
    if ( isset( $album_data['galleryIDs'] ) && is_array( $album_data['galleryIDs'] ) ) {
        $ids = array_map( 'intval', $album_data['galleryIDs'] );
    }
    if ( isset( $album_data['galleries'] ) && is_array( $album_data['galleries'] ) ) {
        $items_data = $album_data['galleries'];
    }

    // Query Envira galleries by IDs
    $posts = array();
    if ( ! empty( $ids ) ) {
        // Filter by envira-category query parameter if present
        $cat_param = isset($_GET['envira-category']) ? sanitize_text_field(wp_unslash($_GET['envira-category'])) : '';
        $term_id = 0;
        if (preg_match('/envira-category-(\d+)/', $cat_param, $matches)) {
            $term_id = intval($matches[1]);
        }
        $args = array(
            'post_type' => 'envira',
            'post__in'  => $ids,
            'posts_per_page' => -1,
            'orderby'   => 'post__in',
        );
        if ($term_id) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'envira-category',
                    'field'    => 'term_id',
                    'terms'    => $term_id,
                ),
            );
        }
        $q = new WP_Query($args);
        if ( $q->have_posts() ) {
            foreach ( $q->posts as $p ) {
                $meta = isset( $items_data[ $p->ID ] ) ? (array) $items_data[ $p->ID ] : array();
                $posts[ $p->ID ] = array( 'post' => $p, 'meta' => $meta );
            }
        }
        wp_reset_postdata();
    }

    if ( empty( $posts ) ) {
        return '<p>No galleries found in this album.</p>';
    }


    // Sort posts by date desc
    uasort( $posts, function( $a, $b ) {
        return strcmp( $b['post']->post_date, $a['post']->post_date );
    } );

    // Build grid
    $output = '<div class="rincity-album-grid">';
    foreach ( $posts as $gid => $data ) {
        $p    = $data['post'];
        $meta = $data['meta'];

        // Debug: output the full $meta array for this gallery
        $output .= '<!-- data: ' . esc_html( print_r( $data, true ) ) . " -->\n";
        // $output .= '<!-- album meta: ' . esc_html( print_r( $meta, true ) ) . " -->\n";
        // $output .= '<!-- gallery post: ' . esc_html( print_r( $p, true ) ) . " -->\n";

        // ——————————————————————————————————————————
        // Instead of envira_resize_image(), do a straight rename
        $src    = $meta['cover_image_url'];
        // read the real crop dims that Envira stored in the album meta
        $width  = ! empty( $data['crop_width'] )  ? intval( $data['crop_width'] )  : 320;
        $height = ! empty( $data['crop_height'] ) ? intval( $data['crop_height'] ) : 400;

        // get the file extension (jpg, png, etc.)
        $ext = pathinfo( $src, PATHINFO_EXTENSION );

        // replace "-scaled.ext" with "-scaled-{$width}x{$height}_c.ext"
        $cropped_src = preg_replace(
            '/-scaled\.' . preg_quote( $ext, '/' ) . '$/i',
            "-scaled-{$width}x{$height}_c.{$ext}",
            $src
        );

        // ——————————————————————————————————————————
        // then render as before
        $thumb = sprintf(
            '<img src="%s" width="144" height="180" style="width:144px;height:180px;object-fit:cover;object-position:center;" alt="%s" />',
            esc_url(   $cropped_src ),
            esc_attr(  $data['alt'] ?? '' )
        );

        // Count images using Envira API
        $count = 0;
        if ( function_exists( 'envira_get_gallery_images' ) ) {
            $imgs = envira_get_gallery_images( $gid, true );
            if ( is_array( $imgs ) ) {
                $count = count( $imgs );
            } elseif ( is_object( $imgs ) && property_exists( $imgs, 'posts' ) ) {
                $count = count( $imgs->posts );
            }
        }

        $title = ! empty( $meta['title'] ) ? esc_html( $meta['title'] ) : get_the_title( $gid );
        $link  = get_permalink( $gid );

        $output .= '<div class="envira-gallery-item">' . "\n";
        if ( $thumb ) {
            $output .= '<a href="' . esc_url( $link ) . '">' . $thumb . '</a>' . "\n";
        }
        $output .= '<div class="envira-album-title"><a href="' . esc_url( $link ) . '">' . $title . '</a></div>' . "\n";
        $output .= '<div class="envira-album-image-count">' . intval( $count ) . ' Photos</div>' . "\n";
        $output .= '</div>' . "\n";
    }
    $output .= '</div>';

    $output .= '<!-- full album data: ' . esc_html( print_r( $album_data, true ) ) . " -->\n";

    return $output;
}
add_shortcode( 'rincity_envira_album', 'rincity_envira_album_shortcode' );
