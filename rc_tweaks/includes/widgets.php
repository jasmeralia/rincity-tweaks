<?php
// filepath: /home/morgan/git_local/rc_tweaks/includes/widgets.php

// Register Envira Tags Widget
add_action( 'widgets_init', function() {
    register_widget( 'RC_Envira_Tags_Widget' );
    register_widget( 'RC_Envira_Album_Categories_Widget' );
});

function rc_category_tree_assets() {
    // NOTE: this second parameter must point at the root plugin file
    $css_url = plugins_url(
        'assets/css/category-tree.css',
        dirname( __FILE__, 2 ) . '/rc_tweaks.php'
    );
    $js_url  = plugins_url(
        'assets/js/category-tree.js',
        dirname( __FILE__, 2 ) . '/rc_tweaks.php'
    );
    wp_enqueue_style(  'rc-category-tree-css', $css_url, [], '1.0' );
    wp_enqueue_script( 'rc-category-tree-js',  $js_url,  ['jquery'], '1.0', true );
}
add_action( 'wp_enqueue_scripts', 'rc_category_tree_assets' );

// New widget: Display Envira categories for Envira galleries (only on Envira gallery posts)
class RC_Envira_Tags_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'rc_envira_tags_widget',
            __('Envira Gallery Categories', 'rc_tweaks'),
            array( 'description' => __( 'Displays Envira categories for Envira galleries (only on Envira gallery posts).', 'rc_tweaks' ) )
        );
    }

    public function widget( $args, $instance ) {
        global $post;

        // Try to get the current Envira post object robustly, including pretty permalinks like /envira/gallery-slug/
        $post_id = null;

        // Prefer global $post if it's an Envira post
        if ( isset( $post ) && $post instanceof WP_Post && $post->post_type === 'envira' ) {
            $post_id = $post->ID;
        }

        // Fallback: check queried object
        if ( ! $post_id ) {
            $queried = get_queried_object();
            if ( $queried instanceof WP_Post && $queried->post_type === 'envira' ) {
                $post_id = $queried->ID;
            }
        }

        // Fallback: try to get by URL if on a single envira post (handles pretty permalinks)
        if ( ! $post_id && is_singular('envira') ) {
            $post_id = get_queried_object_id();
        }

        // Hide widget if no post ID
        if ( ! $post_id ) {
            return;
        }

        // Use Envira categories taxonomy (usually 'envira-category')
        $categories = get_the_terms( $post_id, 'envira-category' );
        if ( is_wp_error( $categories ) ) {
            echo $args['before_widget'];
            echo $args['before_title'] . esc_html__( 'Gallery Categories', 'rc_tweaks' ) . $args['after_title'];
            echo '<p style="color:red;">' . esc_html__( 'Error retrieving categories for this gallery:', 'rc_tweaks' ) . ' ' . esc_html( $categories->get_error_message() ) . '</p>';
            echo $args['after_widget'];
            return;
        } elseif ( empty( $categories ) ) {
            // Hide widget if no categories found
            return;
        }

        // Show widget with categories and counts
        echo $args['before_widget'];
        echo $args['before_title'] . esc_html__( 'Gallery Categories', 'rc_tweaks' ) . $args['after_title'];
        echo '<ul class="rc-envira-categories">';
        foreach ( $categories as $cat ) {
            $count = (int) $cat->count;
            $link = '/members-gallery/?envira-category=envira-category-' . $cat->term_id;
            echo '<li>&bull; <a href="' . esc_url( $link ) . '">' . esc_html( $cat->name ) . '</a> (' . $count . ')</li>';
        }
        echo '</ul>';
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        // No options for this widget
        echo '<p>' . esc_html__( 'Displays Envira categories for Envira galleries. Only appears on Envira gallery posts.', 'rc_tweaks' ) . '</p>';
    }
}

// New widget: Display all categories with counts on Envira album pages only
class RC_Envira_Album_Categories_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct(
            'rc_envira_album_categories_widget',
            __('Envira Album Categories', 'rc_tweaks'),
            array( 'description' => __( 'Displays all Envira categories with their counts on Envira album pages.', 'rc_tweaks' ) )
        );
    }

    /**
     * Recursive helper to render a term and its children.
     */
    public function rc_render_term_branch( $term, $cat_counts ) {
        // Check for children
        $children = get_terms([
            'taxonomy'   => 'envira-category',
            'hide_empty' => false,
            'parent'     => $term->term_id,
        ]);

        // Toggle icon — only add it if there are children
        $has_children = ! empty( $children );
        $icon_html    = $has_children
            ? '<span class="rc-toggle-icon closed">▶</span> '
            : '<span class="rc-toggle-icon no-children"></span> ';

        echo '<li class="rc-category-item">';
        echo $icon_html;
        $filter = '.envira-category-' . $term->term_id;
        $link = '/members-gallery/?envira-category=envira-category-' . $term->term_id;
        echo '&bull; <a href="' . esc_url( $link ) . '" class="envira-album-filter">' . esc_html( $term->name ) . ' (' . $cat_counts[$term->term_id] . ')</a>';

        if ( $has_children ) {
            // Hidden by default; CSS will hide .children
            echo '<ul class="children">';
            foreach ( $children as $child ) {
                $this->rc_render_term_branch( $child, $cat_counts );
            }
            echo '</ul>';
        }

        echo '</li>';
    }

    public function widget( $args, $instance ) {
        global $post;

        $is_album_page = false;

        // Check if current post is an Envira album
        if ( isset($post) && $post instanceof WP_Post && $post->post_type === 'envira_album' ) {
            $is_album_page = true;
        }

        // Check if this is an Envira album taxonomy archive (pretty permalinks like /album/slug)
        if ( is_tax('envira_album') ) {
            $is_album_page = true;
        }
        if ( function_exists('is_envira_album') && is_envira_album() ) {
            $is_album_page = true;
        }

        // Also display on /members-gallery/ page
        if ( ! $is_album_page ) {
            $queried = get_queried_object();
            if (
                isset($queried->post_name) &&
                $queried->post_name === 'members-gallery' &&
                $queried->post_type === 'page'
            ) {
                $is_album_page = true;
            }
        }

        if ( ! $is_album_page ) {
            return;
        }

        // Get all envira galleries in the Members Gallery album (ID 1411)
        $album_id = 1411;
        $album_data = get_post_meta($album_id, '_eg_album_data', true);
        $gallery_ids = [];
        if (!empty($album_data['galleryIDs']) && is_array($album_data['galleryIDs'])) {
            $gallery_ids = $album_data['galleryIDs'];
        }

        // Get all top level categories assigned to galleries in the album
        $categories = get_terms( array(
            'taxonomy' => 'envira-category',
            'hide_empty' => false,
        ) );

        // 1. Get all top-level terms.
        $terms = get_terms([
            'taxonomy'   => 'envira-category',
            'hide_empty' => false,
            'parent'     => 0,
        ]);

        if ( is_wp_error( $categories ) || empty( $categories ) ) {
            return;
        }

        // Count galleries per category, but only for those in the album
        $cat_counts = [];
        foreach ( $categories as $cat ) {
            $cat_counts[$cat->term_id] = 0;
        }
        $total_galleries = 0;
        if ( !empty($gallery_ids) ) {
            foreach ( $gallery_ids as $gid ) {
                $gallery_cats = wp_get_object_terms( $gid, 'envira-category', array('fields' => 'ids') );
                if ( is_array($gallery_cats) ) {
                    foreach ( $gallery_cats as $cid ) {
                        if ( isset($cat_counts[$cid]) ) {
                            $cat_counts[$cid]++;
                        }
                    }
                }
                $total_galleries++;
            }
        }

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html__( 'All Gallery Categories', 'rc_tweaks' ) . $args['after_title'];
        echo '<ul class="rc-category-tree">';

        // Add "All" link first
        echo '<li class="rc-category-item"><span class="rc-toggle-icon no-children"></span> &bull; <a href="/members-gallery/" class="envira-album-filter-all" data-envira-filter="*">All Categories (' . intval($total_galleries) . ')</a></li>';
        // Output each category with count
        foreach ( $terms as $term ) {
            $this->rc_render_term_branch( $term, $cat_counts );
        }

        echo '</ul>';
        echo $args['after_widget'];
    }

    public function form( $instance ) {
        echo '<p>' . esc_html__( 'Displays all Envira categories with their gallery counts (for galleries in the Members Gallery album). Only appears on Envira album pages.', 'rc_tweaks' ) . '</p>';
    }
}