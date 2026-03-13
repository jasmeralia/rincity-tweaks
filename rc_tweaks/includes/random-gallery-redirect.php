<?php
// Register the random gallery redirect page on plugin activation
register_activation_hook( __FILE__, 'rc_tweaks_create_random_gallery_page' );

function rc_tweaks_create_random_gallery_page() {
    if ( ! get_page_by_path( 'random-set' ) ) {
        wp_insert_post( array(
            'post_title'     => 'Random Envira Gallery',
            'post_name'      => 'random-set',
            'post_content'   => 'Redirects to a random Envira gallery.',
            'post_status'    => 'publish',
            'post_type'      => 'page',
            'post_author'    => get_current_user_id(),
        ) );
    }
}

// On template redirect, if on the random gallery page, redirect to a random Envira gallery
add_action( 'template_redirect', function() {
    if ( is_page( 'random-set' ) ) {
        // Get all published envira galleries
        $query = new WP_Query( array(
            'post_type'      => 'envira',
            'post_status'    => 'publish',
            'posts_per_page' => 50, // Fetch more to allow filtering
            'orderby'        => 'rand',
        ) );

        $valid_galleries = array();
        $now_ts = (new DateTime('now', new DateTimeZone('UTC')))->getTimestamp();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                $gallery_id = get_the_ID();
                $gallery_data = get_post_meta($gallery_id, '_eg_gallery_data', true);

                $schedule = $gallery_data['config']['schedule'] ?? 0;
                $start_raw = $gallery_data['config']['schedule_start'] ?? 0;
                $end_raw = $gallery_data['config']['schedule_end'] ?? 0;

                $start = (is_numeric($start_raw) && $start_raw > 0) ? intval($start_raw) : ($start_raw ? strtotime($start_raw) : 0);
                $end = (is_numeric($end_raw) && $end_raw > 0) ? intval($end_raw) : ($end_raw ? strtotime($end_raw) : 0);

                $in_window = true;
                if ( !empty($schedule) && $schedule == 1 ) {
                    if ($start && $end) {
                        if ($now_ts < $start || $now_ts > $end) $in_window = false;
                    } elseif ($start) {
                        if ($now_ts < $start) $in_window = false;
                    } elseif ($end) {
                        if ($now_ts > $end) $in_window = false;
                    }
                }
                if ( $in_window ) {
                    $valid_galleries[] = $gallery_id;
                }
            }
            wp_reset_postdata();
        }

        if ( !empty($valid_galleries) ) {
            // Pick a random valid gallery
            $random_id = $valid_galleries[ array_rand($valid_galleries) ];
            $url = get_permalink($random_id);
            wp_redirect( $url, 302 );
            exit;
        } else {
            // No valid galleries found, redirect to home
            wp_redirect( home_url(), 302 );
            exit;
        }
    }
});