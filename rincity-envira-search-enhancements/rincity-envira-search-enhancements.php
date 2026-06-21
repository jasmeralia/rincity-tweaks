<?php
/**
 * Plugin Name: RinCity Envira Search Enhancements
 * Description: Extends default WordPress search to include Envira Gallery posts whose Envira Categories/Tags match the search term. Shows a thumbnail preview in search results.
 * Version: 1.3.0
 * Author: Morgan Blackthorne
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RinCity_Envira_Search_By_Category {

    protected $taxonomies = array(
        'envira-tag',
        'envira_tags',
        'envira-category',
        'envira_categories',
    );

    public function __construct() {
        add_action( 'pre_get_posts',    array( $this, 'include_envira_in_search' ) );
        add_filter( 'posts_search',     array( $this, 'extend_search_with_envira_terms' ), 10, 2 );
        add_filter( 'posts_distinct',   array( $this, 'make_distinct' ), 10, 2 );
        add_filter( 'the_content',      array( $this, 'prepend_search_thumbnail' ) );
    }

    public function include_envira_in_search( $query ) {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
            return;
        }

        $post_types = $query->get( 'post_type' );

        if ( empty( $post_types ) ) {
            $post_types = array( 'post', 'page', 'envira' );
        } else {
            $post_types = (array) $post_types;
            if ( ! in_array( 'envira', $post_types, true ) ) {
                $post_types[] = 'envira';
            }
        }

        $query->set( 'post_type', $post_types );
    }

    protected function get_matching_envira_ids( WP_Query $query ) {
        $s = $query->get( 's' );
        if ( ! $s ) {
            return array();
        }

        $taxonomies = array_filter( $this->taxonomies, 'taxonomy_exists' );
        if ( empty( $taxonomies ) ) {
            return array();
        }

        $terms = get_terms( array(
            'taxonomy'   => $taxonomies,
            'hide_empty' => true,
            'search'     => $s,
        ) );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return array();
        }

        $gallery_ids = array();

        foreach ( $terms as $term ) {
            $ids = get_objects_in_term( $term->term_id, $term->taxonomy );
            if ( ! is_wp_error( $ids ) && ! empty( $ids ) ) {
                foreach ( $ids as $id ) {
                    $gallery_ids[] = (int) $id;
                }
            }
        }

        return array_values( array_unique( $gallery_ids ) );
    }

    public function extend_search_with_envira_terms( $search, $query ) {
        if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
            return $search;
        }

        global $wpdb;

        $gallery_ids = $this->get_matching_envira_ids( $query );
        if ( empty( $gallery_ids ) ) {
            return $search;
        }

        $ids_sql = implode( ',', array_map( 'intval', $gallery_ids ) );

        if ( trim( $search ) === '' ) {
            return " AND ({$wpdb->posts}.ID IN ($ids_sql))";
        }

        $search = preg_replace(
            '/\)\s*$/',
            " OR {$wpdb->posts}.ID IN ($ids_sql))",
            $search,
            1
        );

        return $search;
    }

    public function make_distinct( $distinct, $query ) {
        if ( $query->is_main_query() && $query->is_search() ) {
            return 'DISTINCT';
        }

        return $distinct;
    }

    // Returns [ 'photos' => n, 'videos' => n ] for active items in an Envira gallery.
    protected function get_gallery_counts( $post_id ) {
        $meta = get_post_meta( $post_id, '_eg_gallery_data', true );
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

    // Detects video items using envira-videos' own function when available, otherwise
    // falls back to URL pattern matching for self-hosted and common hosted video types.
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

    // Returns the attachment ID of the first image in an Envira gallery, or 0.
    protected function get_first_gallery_attachment_id( $post_id ) {
        $meta = get_post_meta( $post_id, '_eg_gallery_data', true );
        if ( empty( $meta['gallery'] ) || ! is_array( $meta['gallery'] ) ) {
            return 0;
        }
        reset( $meta['gallery'] );
        return (int) key( $meta['gallery'] );
    }

    // Prepends a thumbnail and media count to the content for Envira posts in search results.
    public function prepend_search_thumbnail( $content ) {
        if ( ! is_search() || is_admin() ) {
            return $content;
        }
        $post = get_post();
        if ( ! $post || $post->post_type !== 'envira' ) {
            return $content;
        }
        $attachment_id = $this->get_first_gallery_attachment_id( $post->ID );
        if ( ! $attachment_id ) {
            return $content;
        }
        $img = wp_get_attachment_image( $attachment_id, 'full', false, [
            'style' => 'width: 400px; height: auto; display: block; margin: 0 auto;',
        ] );
        if ( ! $img ) {
            return $content;
        }

        $counts = $this->get_gallery_counts( $post->ID );
        $parts  = array();
        if ( $counts['photos'] > 0 ) {
            $parts[] = sprintf( _n( '%d photo', '%d photos', $counts['photos'] ), $counts['photos'] );
        }
        if ( $counts['videos'] > 0 ) {
            $parts[] = sprintf( _n( '%d video', '%d videos', $counts['videos'] ), $counts['videos'] );
        }
        $count_html = '';
        if ( $parts ) {
            $count_html = '<p style="text-align: center; margin: 0.25em 0 0.5em;">'
                . esc_html( implode( ' &amp; ', $parts ) )
                . '</p>';
        }

        return $img . $count_html . $content;
    }
}

new RinCity_Envira_Search_By_Category();
