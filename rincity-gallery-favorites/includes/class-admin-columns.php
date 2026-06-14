<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Gallery_Favorites_Admin_Columns {

    private const POST_TYPE   = 'envira';
    private const COL_KEY     = 'favorites_count';
    private const ORDERBY_KEY = 'favorites_count';
    private const JOIN_ALIAS  = 'rincgf_fav_counts';

    public static function register(): void {
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns',         [ __CLASS__, 'add_column' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column',   [ __CLASS__, 'render_column' ], 10, 2 );
        add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', [ __CLASS__, 'sortable_columns' ] );
        add_action( 'pre_get_posts', [ __CLASS__, 'pre_get_posts' ] );
        add_filter( 'posts_join',    [ __CLASS__, 'posts_join' ],    10, 2 );
        add_filter( 'posts_orderby', [ __CLASS__, 'posts_orderby' ], 10, 2 );
    }

    public static function add_column( array $columns ): array {
        $out = [];
        foreach ( $columns as $key => $label ) {
            $out[ $key ] = $label;
            if ( $key === 'title' ) {
                $out[ self::COL_KEY ] = 'Favorites';
            }
        }
        return $out;
    }

    public static function render_column( string $column, int $post_id ): void {
        if ( $column !== self::COL_KEY ) {
            return;
        }
        $repo  = new RinCity_Gallery_Favorites_Repository();
        $count = $repo->count_favorites_for_gallery( $post_id );
        echo $count > 0 ? esc_html( (string) $count ) : '&mdash;';
    }

    public static function sortable_columns( array $columns ): array {
        $columns[ self::COL_KEY ] = self::ORDERBY_KEY;
        return $columns;
    }

    // Flags the query so our join/orderby filters know to activate.
    public static function pre_get_posts( WP_Query $query ): void {
        if (
            ! is_admin() ||
            ! $query->is_main_query() ||
            $query->get( 'post_type' ) !== self::POST_TYPE ||
            $query->get( 'orderby' ) !== self::ORDERBY_KEY
        ) {
            return;
        }
        $query->set( '_rincgf_sort_by_favorites', true );
    }

    public static function posts_join( string $join, WP_Query $query ): string {
        if ( ! $query->get( '_rincgf_sort_by_favorites' ) ) {
            return $join;
        }
        global $wpdb;
        $fav_table = $wpdb->prefix . 'rincity_gallery_favorites';
        $alias     = self::JOIN_ALIAS;
        $join .= " LEFT JOIN (
            SELECT gallery_id, COUNT(*) AS cnt
            FROM {$fav_table}
            GROUP BY gallery_id
        ) AS {$alias} ON {$alias}.gallery_id = {$wpdb->posts}.ID ";
        return $join;
    }

    public static function posts_orderby( string $orderby, WP_Query $query ): string {
        if ( ! $query->get( '_rincgf_sort_by_favorites' ) ) {
            return $orderby;
        }
        global $wpdb;
        $dir   = strtoupper( $query->get( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
        $alias = self::JOIN_ALIAS;
        // NULL (no favorites) sorts as zero — COALESCE ensures they go last on DESC.
        return "COALESCE({$alias}.cnt, 0) {$dir}, {$wpdb->posts}.post_title ASC";
    }
}
