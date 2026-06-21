<?php
/**
 * Plugin Name: RinCity News Widget
 * Description: Sidebar widget showing recent posts and Envira galleries in a unified date-sorted feed, with photo/video counts on galleries.
 * Version: 1.2.0
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RINCITY_NEWS_WIDGET_VERSION', '1.2.0' );

class RinCity_News_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'rincity_news_widget',
            'RinCity Recent Updates',
            array( 'description' => 'Recent posts and Envira galleries in a unified feed.' )
        );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
    }

    public function enqueue_styles() {
        if ( is_active_widget( false, false, $this->id_base ) ) {
            wp_enqueue_style(
                'rincity-news-widget',
                plugin_dir_url( __FILE__ ) . 'rincity-news-widget.css',
                array(),
                RINCITY_NEWS_WIDGET_VERSION
            );
        }
    }

    public function widget( $args, $instance ) {
        $title = ! empty( $instance['title'] ) ? $instance['title'] : 'Recent Updates';
        $count = ! empty( $instance['count'] ) ? (int) $instance['count'] : 5;

        $items = $this->get_recent_items( $count );
        if ( empty( $items ) ) {
            return;
        }

        echo $args['before_widget'];
        echo $args['before_title'] . esc_html( $title ) . $args['after_title'];
        ?>
        <ul class="rcnw-list">
        <?php foreach ( $items as $item ) : ?>
            <li class="rcnw-item">
                <div class="rcnw-item-top">
                    <span class="rcnw-badge rcnw-badge--<?php echo esc_attr( $item['type'] ); ?>">
                        <?php echo $item['type'] === 'gallery' ? 'New Gallery' : 'New Post'; ?>
                    </span>
                    <a class="rcnw-title" href="<?php echo esc_url( $item['url'] ); ?>">
                        <?php echo esc_html( $item['title'] ); ?><?php if ( $item['count_label'] ) : ?><span class="rcnw-count"> (<?php echo esc_html( $item['count_label'] ); ?>)</span><?php endif; ?>
                    </a>
                </div>
                <div class="rcnw-meta">
                    <?php echo esc_html( $item['date'] ); ?> by <?php echo esc_html( $item['author'] ); ?>
                </div>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php
        echo $args['after_widget'];
    }

    protected function get_album_gallery_ids() {
        $album_ids = get_posts( array(
            'post_type'      => 'envira_album',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ) );

        $gallery_ids = array();
        foreach ( $album_ids as $album_id ) {
            $meta = get_post_meta( $album_id, '_eg_album_data', true );
            if ( ! empty( $meta['galleryIDs'] ) && is_array( $meta['galleryIDs'] ) ) {
                foreach ( $meta['galleryIDs'] as $id ) {
                    $gallery_ids[] = (int) $id;
                }
            }
        }

        return array_values( array_unique( $gallery_ids ) );
    }

    protected function get_recent_items( $count ) {
        $post_query = new WP_Query( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );

        $album_gallery_ids = $this->get_album_gallery_ids();

        $gallery_query = new WP_Query( array(
            'post_type'      => 'envira',
            'post_status'    => 'publish',
            'posts_per_page' => $count,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post__in'       => ! empty( $album_gallery_ids ) ? $album_gallery_ids : array( 0 ),
        ) );

        $items = array();
        foreach ( array_merge( $post_query->posts, $gallery_query->posts ) as $post ) {
            $is_gallery  = $post->post_type === 'envira';
            $count_label = $is_gallery ? $this->format_count_label( $this->get_gallery_counts( $post->ID ) ) : '';
            $items[] = array(
                'type'        => $is_gallery ? 'gallery' : 'post',
                'title'       => get_the_title( $post ),
                'url'         => get_permalink( $post ),
                'date'        => get_the_date( 'F j, Y', $post ),
                'timestamp'   => get_post_datetime( $post )->getTimestamp(),
                'author'      => get_the_author_meta( 'display_name', $post->post_author ),
                'count_label' => $count_label,
            );
        }
        wp_reset_postdata();

        usort( $items, function ( $a, $b ) {
            return $b['timestamp'] - $a['timestamp'];
        } );

        return array_slice( $items, 0, $count );
    }

    // Returns ['photos' => n, 'videos' => n] for active items in an Envira gallery.
    protected function get_gallery_counts( $post_id ) {
        $meta   = get_post_meta( $post_id, '_eg_gallery_data', true );
        $counts = array( 'photos' => 0, 'videos' => 0 );
        if ( empty( $meta['gallery'] ) || ! is_array( $meta['gallery'] ) ) {
            return $counts;
        }
        foreach ( $meta['gallery'] as $img ) {
            if ( ! isset( $img['status'] ) || $img['status'] !== 'active' ) {
                continue;
            }
            if ( $this->is_video_item( $img ) ) {
                $counts['videos']++;
            } else {
                $counts['photos']++;
            }
        }
        return $counts;
    }

    protected function is_video_item( $img ) {
        $link = isset( $img['link'] ) ? $img['link'] : '';
        if ( ! $link ) {
            return false;
        }
        if ( function_exists( 'envira_video_get_video_type' ) ) {
            return (bool) envira_video_get_video_type( $link );
        }
        if ( preg_match( '/\.(mp4|webm)(\?|$)/i', $link ) ) {
            return true;
        }
        if ( preg_match( '/(?:youtube\.com|youtu\.be|vimeo\.com)/i', $link ) ) {
            return true;
        }
        return false;
    }

    protected function format_count_label( $counts ) {
        $parts = array();
        if ( $counts['photos'] > 0 ) {
            $parts[] = sprintf( _n( '%d photo', '%d photos', $counts['photos'] ), $counts['photos'] );
        }
        if ( $counts['videos'] > 0 ) {
            $parts[] = sprintf( _n( '%d video', '%d videos', $counts['videos'] ), $counts['videos'] );
        }
        return implode( ' & ', $parts );
    }

    public function form( $instance ) {
        $title = ! empty( $instance['title'] ) ? $instance['title'] : 'Recent Updates';
        $count = ! empty( $instance['count'] ) ? (int) $instance['count'] : 5;
        ?>
        <p>
            <label for="<?php echo $this->get_field_id( 'title' ); ?>">Title:</label>
            <input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>"
                   name="<?php echo $this->get_field_name( 'title' ); ?>"
                   type="text" value="<?php echo esc_attr( $title ); ?>">
        </p>
        <p>
            <label for="<?php echo $this->get_field_id( 'count' ); ?>">Number of items:</label>
            <input id="<?php echo $this->get_field_id( 'count' ); ?>"
                   name="<?php echo $this->get_field_name( 'count' ); ?>"
                   type="number" min="1" max="20"
                   value="<?php echo esc_attr( $count ); ?>"
                   style="width: 3em;">
        </p>
        <?php
    }

    public function update( $new_instance, $old_instance ) {
        return array(
            'title' => sanitize_text_field( $new_instance['title'] ),
            'count' => min( 20, max( 1, (int) $new_instance['count'] ) ),
        );
    }
}

add_action( 'widgets_init', function () {
    register_widget( 'RinCity_News_Widget' );
} );
