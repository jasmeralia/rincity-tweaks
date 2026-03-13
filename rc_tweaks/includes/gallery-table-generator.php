<?php
// filepath: /home/morgan/git_local/rc_tweaks/includes/feed-generator.php

// Register the gallery page on plugin activation
register_activation_hook( __FILE__, 'rc_tweaks_create_gallery_page' );

function rc_tweaks_create_gallery_page() {
    // Only create if not already present
    if ( ! get_page_by_path( 'envira-gallery-table' ) ) {
        wp_insert_post( array(
            'post_title'     => 'Envira Gallery Table',
            'post_name'      => 'envira-gallery-table',
            'post_content'   => '[rc_envira_gallery_table]',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_author'    => get_current_user_id(),
        ) );
    }
}

// Register the shortcode
add_shortcode( 'rc_envira_gallery_table', 'rc_tweaks_gallery_table_shortcode' );

function rc_tweaks_gallery_table_shortcode() {
    ob_start();

    // Get the term ID for the 'Dustrat' category (if it exists)
    $dustrat_term = get_term_by('name', 'Dustrat', 'envira-category');
    $exclude_ids = $dustrat_term ? array($dustrat_term->term_id) : array();

    // Query 3 random published 'envira' posts, excluding those in 'Dustrat' category
    $envira_query = new WP_Query( array(
        'post_type'      => 'envira',
        'post_status'    => 'publish',
        'posts_per_page' => 3,
        'orderby'        => 'rand',
        'tax_query'      => !empty($exclude_ids) ? array(
            array(
                'taxonomy' => 'envira-category',
                'field'    => 'term_id',
                'terms'    => $exclude_ids,
                'operator' => 'NOT IN',
            ),
        ) : array(),
    ) );

    // Collect posts and sort by publication date ascending (oldest first)
    $galleries = array();
    if ( $envira_query->have_posts() ) {
        while ( $envira_query->have_posts() ) {
            $envira_query->the_post();
            $galleries[] = array(
                'ID'    => get_the_ID(),
                'title' => get_the_title(),
                'link'  => get_permalink(),
                'date'  => get_the_date(),
                'date_raw' => get_the_date('U'),
                'gallery_data' => get_post_meta( get_the_ID(), '_eg_gallery_data', true ),
            );
        }
        // Sort by publication date ascending (oldest first)
        usort($galleries, function($a, $b) {
            return $a['date_raw'] <=> $b['date_raw'];
        });

        echo '<table style="width:100%; border-collapse:collapse;"><thead><tr>';
        echo '<th>Gallery Title &amp; Link</th><th>Images</th><th>Categories (Hashtags)</th></tr></thead><tbody>';
        foreach ( $galleries as $gallery ) {
            $gallery_id = $gallery['ID'];
            $gallery_title = $gallery['title'];
            $gallery_link = $gallery['link'];
            $gallery_date = $gallery['date'];
            $gallery_data = $gallery['gallery_data'];

            // Get up to 3 images
            $images = array();
            if ( ! empty( $gallery_data['gallery'] ) && is_array( $gallery_data['gallery'] ) ) {
                $images = array_slice( $gallery_data['gallery'], 0, 3 );
            }

            // Get Envira categories as hashtags
            $categories = get_the_terms( $gallery_id, 'envira-category' );
            $hashtags = '';
            if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
                $tags = array();
                foreach ( $categories as $cat ) {
                    $tags[] = '#' . preg_replace('/[^A-Za-z0-9_]/', '', $cat->slug);
                }
                $hashtags = implode(' ', $tags);
            }

            echo '<tr>';
            // Title cell: clickable title, copyable link, and publication date
            echo '<td style="vertical-align:top;">';
            echo '<a href="' . esc_url( $gallery_link ) . '" target="_blank" rel="noopener">' . esc_html( $gallery_title ) . '</a><br />';
            echo '<input type="text" value="' . esc_url( $gallery_link ) . '" readonly style="width:120px;font-size:10px;text-align:center;margin-top:4px;" onclick="this.select();" /><br />';
            echo '<span style="font-size:11px;color:#666;">' . esc_html( $gallery_date ) . '</span>';
            echo '</td>';

            // Images cell: each image is a link, with copyable link below
            echo '<td>';
            foreach ( $images as $img ) {
                if ( ! empty( $img['src'] ) ) {
                    $img_url = esc_url( $img['src'] );
                    echo '<div style="display:inline-block;text-align:center;margin:2px;">';
                    echo '<a href="' . $img_url . '" target="_blank" rel="noopener">';
                    echo '<img src="' . $img_url . '" alt="" style="max-width:100px;max-height:100px;display:block;margin-bottom:4px;" />';
                    echo '</a>';
                    echo '<input type="text" value="' . $img_url . '" readonly style="width:100px;font-size:10px;text-align:center;" onclick="this.select();" />';
                    echo '</div>';
                }
            }
            echo '</td>';

            // Hashtags cell: copyable hashtags
            echo '<td style="vertical-align:top;text-align:center;">';
            echo '<input type="text" value="' . esc_attr( $hashtags ) . '" readonly style="width:140px;font-size:10px;text-align:center;" onclick="this.select();" />';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        wp_reset_postdata();
    } else {
        echo '<p>No Envira galleries found.</p>';
    }

    return ob_get_clean();
}