<?php
// Default "Make Gallery Title Linkable?" to enabled for all galleries in all albums.
// Only fires on first pencil save for a given gallery (when the key doesn't exist yet).
add_filter( 'envira_albums_update_gallery', function( $album_data, $meta, $gallery_id, $post_id ) {
    if ( ! array_key_exists( 'link_title_gallery', $album_data['galleries'][ $gallery_id ] ) ) {
        $album_data['galleries'][ $gallery_id ]['link_title_gallery'] = '1';
    }
    return $album_data;
}, 10, 4 );

// Include future-status galleries in the album picker (both the initial metabox list and the
// AJAX search). Without this, galleries scheduled via WordPress native scheduling are invisible
// in the album editor until they publish.
add_action( 'pre_get_posts', function( $query ) {
    if ( ! is_admin() ) {
        return;
    }
    if ( $query->get( 'post_type' ) !== 'envira' ) {
        return;
    }
    if ( $query->get( 'post_status' ) !== 'publish' ) {
        return;
    }
    if ( ! current_user_can( 'edit_posts' ) ) {
        return;
    }
    $action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $in_album_context =
        $action === 'envira_albums_search_galleries' ||
        ( $screen && isset( $screen->post_type ) && $screen->post_type === 'envira_album' );
    if ( $in_album_context ) {
        $query->set( 'post_status', array( 'publish', 'future' ) );
    }
} );

// Allow admins to preview a future-status gallery via the WordPress preview link.
// Envira's standalone_maybe_redirect() explicitly 404s future posts without checking is_preview().
add_filter( 'envira_allowed_publish_statuses', function( $statuses ) {
    if ( is_preview() && current_user_can( 'edit_posts' ) ) {
        $statuses[] = 'future';
    }
    return $statuses;
} );

// Enqueue styles
function rincity_enqueue_assets() {
    wp_enqueue_style( 'rincity-style', plugin_dir_url( dirname( __FILE__ ) ) . 'assets/css/style.css' );
}
add_action( 'wp_enqueue_scripts', 'rincity_enqueue_assets' );

function rincity_envira_album_shortcode( $atts ) {
    $atts = shortcode_atts( array(
        'id' => '',
    ), $atts, 'rincity_envira_album' );

    $use_lazy = get_option('rincity_envira_album_lazyload', true);

    $album_id = intval( $atts['id'] );

    if ( ! $album_id ) {
        return '<p>No album ID provided.</p>';
    }
    if ( ! function_exists( 'envira_get_album_galleries' ) ) {
        return '<p><strong>Error:</strong> Envira Albums Addon unavailable.</p>';
    }
    
    // Fetch album data via post meta
    $album_data = get_post_meta( $album_id, '_eg_album_data', true );

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
            'post_type'   => 'envira',
            'post__in'    => $ids,
            'posts_per_page' => -1,
            'orderby'     => 'post__in',
            'post_status' => current_user_can( 'edit_posts' ) ? array( 'publish', 'future' ) : 'publish',
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

        // ——————————————————————————————————————————
        // Resolve the cover crop via the shared resolver (includes/cover-resolver.php),
        // which validates on-disk existence/dimensions for both scaled and non-scaled
        // source filenames instead of only matching "-scaled.<ext>" sources.
        $src    = $meta['cover_image_url'];
        // read the real crop dims that Envira stored in the album meta
        $width  = ! empty( $data['crop_width'] )  ? intval( $data['crop_width'] )  : 320;
        $height = ! empty( $data['crop_height'] ) ? intval( $data['crop_height'] ) : 400;

        $cropped_src = function_exists( 'rincity_resolve_gallery_cover_url' )
            ? rincity_resolve_gallery_cover_url( $src, $width, $height )
            : $src;

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

        $is_future  = 'future' === $p->post_status;

        $output .= '<div class="envira-gallery-item' . ( $is_future ? ' rincity-future-gallery' : '' ) . '">' . "\n";
        if ( $thumb ) {
            $output .= '<a href="' . esc_url( $link ) . '">' . $thumb . '</a>' . "\n";
        }
        $output .= '<div class="envira-album-title"><a href="' . esc_url( $link ) . '">' . $title . '</a></div>' . "\n";
        $output .= '<div class="envira-album-image-count">' . intval( $count ) . ' Photos</div>' . "\n";
        if ( $is_future ) {
            $coming = 'Coming ' . date_i18n( 'Y-m-d', strtotime( $p->post_date ) ) . '!';
            $output .= '<div class="rincity-scheduled-badge"><em>' . esc_html( $coming ) . '</em></div>' . "\n";
        }
        $output .= '</div>' . "\n";
    }
    $output .= '</div>';

    return $output;
}
add_shortcode( 'rincity_envira_album', 'rincity_envira_album_shortcode' );
