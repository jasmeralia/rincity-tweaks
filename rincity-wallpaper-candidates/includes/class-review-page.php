<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Review_Page {

    private static string $hook = '';

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        self::$hook = add_submenu_page(
            'rincwc-wallpaper-candidates',
            'Wallpaper Review',
            'Review',
            'manage_options',
            'rincwc-review',
            [ __CLASS__, 'render' ]
        );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        if ( $hook !== self::$hook ) {
            return;
        }
        wp_enqueue_style( 'rincwc-review', RINCWC_PLUGIN_URL . 'assets/review.css', [], RINCWC_VERSION );
        wp_enqueue_script( 'rincwc-review', RINCWC_PLUGIN_URL . 'assets/review.js', [ 'wp-api-fetch' ], RINCWC_VERSION, true );
        wp_add_inline_script( 'rincwc-review', 'var rincwcCfg=' . wp_json_encode( [
            'restBase'     => rest_url( 'rincity/v1/wpc/' ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'userId'       => get_current_user_id(),
            'approveAllowed' => RinCWC_Data::can_current_user_approve(),
        ] ) . ';', 'before' );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No access.' );
        }

        $rows   = RinCWC_Data::get_visible_images();
        $counts = RinCWC_Data::counts();

        echo '<div class="wrap rincwc-review">';
        echo '<h1>Wallpaper Review</h1>';

        if ( empty( $rows ) ) {
            echo '<p>No candidates found. Scan one or more galleries from the Wallpaper scanner page.</p></div>';
            return;
        }

        echo '<p class="rincwc-summary"><strong>' . esc_html( $counts['candidates'] ) . '</strong> candidates · ';
        echo '<strong>' . esc_html( $counts['selected'] ) . '</strong> selected · ';
        echo '<strong>' . esc_html( $counts['approved'] ) . '</strong> approved · ';
        echo '<strong>' . esc_html( $counts['wm_pending'] ) . '</strong> approved with watermark pending</p>';

        echo '<div class="rincwc-toolbar">';
        echo '<button class="button" id="rincwc-filter-sel">Selections only</button> ';
        echo '<button class="button" id="rincwc-generate-crops">Generate pending crops</button> ';
        echo '<button class="button button-primary" id="rincwc-apply-wm">Apply pending watermarks</button> ';
        echo '<button class="button button-secondary" id="rincwc-sync-galleries">Publish to galleries</button>';
        echo '<span id="rincwc-batch-msg"></span>';
        echo '<span class="rincwc-expanders">';
        echo '<a href="#" id="rincwc-expand-all">Expand all</a> · <a href="#" id="rincwc-collapse-all">Collapse all</a>';
        echo '</span></div>';

        foreach ( self::group_rows( $rows ) as $gid => $imgs ) {
            self::render_gallery( (int) $gid, $imgs );
        }

        echo '</div>';
    }

    private static function group_rows( array $rows ): array {
        $pub_dates = [];
        $by_gallery = [];
        foreach ( $rows as $row ) {
            $gid = (int) $row['gallery_id'];
            if ( ! isset( $pub_dates[ $gid ] ) ) {
                $post = get_post( $gid );
                $pub_dates[ $gid ] = $post ? $post->post_date : '0000-00-00 00:00:00';
            }
            $by_gallery[ $gid ][] = $row;
        }
        uksort( $by_gallery, fn( $a, $b ) => strcmp( $pub_dates[ (int) $b ] ?? '', $pub_dates[ (int) $a ] ?? '' ) );
        foreach ( $by_gallery as &$imgs ) {
            usort( $imgs, fn( $a, $b ) => (int) $a['position'] <=> (int) $b['position'] );
        }
        unset( $imgs );
        return $by_gallery;
    }

    private static function render_gallery( int $gid, array $imgs ): void {
        $post      = get_post( $gid );
        $title     = $post ? $post->post_title : ( $imgs[0]['gallery_title'] ?? "Gallery {$gid}" );
        $pub_date  = $post ? date( 'Y-m-d', strtotime( $post->post_date ) ) : '';
        $slug      = $imgs[0]['gallery_slug'] ?? '';
        $permalink = get_option( 'siteurl' ) . "/envira/{$slug}/";

        $selected = array_values( array_filter( $imgs, fn( $row ) => in_array( $row['status'], [ 'SELECTED', 'APPROVED' ], true ) ) );
        $others   = array_values( array_filter( $imgs, fn( $row ) => ! in_array( $row['status'], [ 'SELECTED', 'APPROVED' ], true ) ) );
        $best_w   = max( array_map( fn( $row ) => (int) $row['orig_w'], $imgs ) );
        $summary  = count( $selected )
            ? count( $selected ) . ' selected, ' . count( $others ) . ' other · best ' . $best_w . 'px'
            : count( $imgs ) . ' candidates · best ' . $best_w . 'px';

        echo '<div class="rincwc-gallery">';
        echo '<div class="rincwc-gal-head"><h3><a href="' . esc_url( $permalink ) . '" target="_blank">' . esc_html( $title . ( $pub_date ? " ({$pub_date})" : '' ) ) . '</a></h3></div>';
        echo '<details class="rincwc-details" open><summary>' . esc_html( $summary ) . '</summary>';

        if ( $selected ) {
            echo '<div class="rincwc-section">Selection</div><div class="rincwc-grid">';
            foreach ( $selected as $row ) {
                self::render_candidate( $row );
            }
            echo '</div><div class="rincwc-section">Other Candidates</div><div class="rincwc-grid">';
            foreach ( $others as $row ) {
                self::render_candidate( $row );
            }
            echo '</div>';
        } else {
            echo '<div class="rincwc-grid">';
            foreach ( $imgs as $row ) {
                self::render_candidate( $row );
            }
            echo '</div>';
        }
        echo '</details></div>';
    }

    private static function render_candidate( array $row ): void {
        $image_id = (int) $row['id'];
        $gid      = (int) $row['gallery_id'];
        $aid      = (int) $row['attach_id'];
        $pos      = (int) $row['position'];
        $slug     = (string) $row['gallery_slug'];
        $fname    = basename( (string) $row['original_path'] );
        $orig_w   = (int) $row['orig_w'];
        $orig_h   = (int) $row['orig_h'];
        $status   = (string) $row['status'];
        $sel_crop = (string) ( $row['crop_variant'] ?? '' );
        $wm_corner = (string) ( $row['wm_corner'] ?? '' );
        $wm_applied = ! empty( $row['wm_applied'] );
        $is_sel = in_array( $status, [ 'SELECTED', 'APPROVED' ], true ) && $sel_crop !== '';
        $image_key = "{$gid}:{$aid}";
        $max_scale = RinCWC_Data::max_crop_scale( $orig_w, $orig_h );
        $geom = RinCWC_Data::crop_geometry( $row, $row, $sel_crop ?: 'center' );

        $scaled_url = wp_get_attachment_image_url( $aid, 'large' ) ?: (string) $row['src_url'];
        $thumb = (string) $row['src_url'];
        if ( $is_sel && $wm_applied ) {
            $wm_f = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg";
            if ( file_exists( $wm_f ) ) {
                $thumb = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg" );
            }
        }

        $card_data = esc_attr( wp_json_encode( [
            'imageId'     => $image_id,
            'gid'         => $gid,
            'aid'         => $aid,
            'pos'         => $pos,
            'slug'        => $slug,
            'title'       => ( $row['gallery_title'] ?? '' ) . " #{$pos}",
            'fname'       => $fname,
            'total'       => (int) ( $row['total'] ?? 0 ),
            'origW'       => $orig_w,
            'origH'       => $orig_h,
            'imageKey'    => $image_key,
            'scaledUrl'   => $scaled_url,
            'selCrop'     => $sel_crop,
            'wmCorner'    => $wm_corner,
            'wmApplied'   => $wm_applied,
            'status'      => $status,
            'customScale' => (float) ( $row['custom_crop_scale'] ?: $geom['scale'] ),
            'customX'     => (int) ( $row['custom_crop_x'] ?? $geom['x'] ),
            'customY'     => (int) ( $row['custom_crop_y'] ?? $geom['y'] ),
            'maxScale'    => $max_scale,
            'gSlug'       => $slug,
            'gTitle'      => $row['gallery_title'] ?? '',
            'approveAllowed' => RinCWC_Data::can_current_user_approve(),
        ] ) );

        $card_cls = 'rincwc-card status-' . strtolower( $status ) . ( $is_sel ? ' is-selected' : '' );
        echo '<div class="' . esc_attr( $card_cls ) . '" data-c="' . $card_data . '" data-key="' . esc_attr( $image_key ) . '">';
        echo '<div class="rincwc-thumb-wrap">';
        echo '<img class="rincwc-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $fname ) . '" loading="lazy">';
        self::render_badge( $status, $is_sel, $wm_corner, $wm_applied );
        echo '</div>';

        echo '<div class="rincwc-info">';
        echo '<div class="rincwc-fname">' . esc_html( $fname ) . '</div>';
        echo '<div class="rincwc-dims">' . esc_html( "{$orig_w}x{$orig_h} · img {$pos}/{$row['total']}" ) . '</div>';
        echo '</div>';

        echo '<div class="rincwc-variants">';
        foreach ( [ 'top', 'center-top', 'center', 'center-bottom', 'bottom' ] as $vname ) {
            $active = $sel_crop === $vname ? ' active' : '';
            $links  = self::crop_links_for( $slug, $aid, $vname );
            echo '<span class="rincwc-vbtn' . esc_attr( $active ) . '" data-v="' . esc_attr( $vname ) . '" '
                . 'data-4k="' . esc_url( $links['4k'] ) . '" data-1440p="' . esc_url( $links['1440p'] ) . '" data-1080p="' . esc_url( $links['1080p'] ) . '">'
                . esc_html( self::variant_label( $vname ) )
                . '</span>';
        }
        echo '<span class="rincwc-vbtn rincwc-custbtn' . esc_attr( $sel_crop === 'custom' ? ' active' : '' ) . '" data-v="custom">Custom</span>';
        if ( $is_sel ) {
            echo '<span class="rincwc-desel" title="Remove selection">x</span>';
        }
        echo '</div>';

        echo '<div class="rincwc-wm-row' . ( $is_sel ? '' : ' hidden' ) . '">';
        echo '<select class="rincwc-wm-sel">';
        echo '<option value="">No watermark</option>';
        foreach ( [ 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right' ] as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $wm_corner, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        $wm_cls = $wm_applied ? 'wm-done' : ( $is_sel && $wm_corner ? 'wm-pending' : '' );
        $wm_txt = $wm_applied ? 'applied' : ( $is_sel && $wm_corner ? 'pending' : '' );
        echo '<span class="rincwc-wm-status ' . esc_attr( $wm_cls ) . '">' . esc_html( $wm_txt ) . '</span>';
        echo '</div>';

        self::render_approval_row( $status, $is_sel, $wm_corner );
        self::render_crop_links( $row, $is_sel, $sel_crop, $wm_applied );
        self::render_comments( $image_key );
        echo '</div>';
    }

    private static function render_badge( string $status, bool $is_sel, string $wm_corner, bool $wm_applied ): void {
        if ( $status === 'APPROVED' && ! $wm_applied ) {
            echo '<span class="rincwc-badge badge-pending">Approved · WM pending</span>';
            return;
        }
        if ( $status === 'APPROVED' ) {
            echo '<span class="rincwc-badge badge-approved">Approved</span>';
            return;
        }
        if ( $is_sel ) {
            echo '<span class="rincwc-badge ' . ( $wm_applied ? 'badge-wm' : 'badge-sel' ) . '">' . esc_html( $wm_applied ? 'WM applied' : ( $wm_corner ? 'Selected' : 'Needs WM' ) ) . '</span>';
        }
    }

    private static function render_approval_row( string $status, bool $is_sel, string $wm_corner ): void {
        $can_approve = RinCWC_Data::can_current_user_approve();
        $disabled = ! $can_approve || ! $is_sel || ! $wm_corner;
        echo '<div class="rincwc-approve-row">';
        if ( $status === 'APPROVED' ) {
            echo '<button class="button rincwc-unapprove-btn"' . disabled( $disabled, true, false ) . '>Unapprove</button>';
        } else {
            echo '<button class="button button-secondary rincwc-approve-btn"' . disabled( $disabled, true, false ) . '>Approve</button>';
        }
        if ( ! $can_approve ) {
            echo '<span class="rincwc-approve-note">restricted</span>';
        }
        echo '</div>';
    }

    private static function render_crop_links( array $row, bool $is_sel, string $sel_crop, bool $wm_applied ): void {
        if ( ! $is_sel ) {
            return;
        }
        $slug = (string) $row['gallery_slug'];
        $aid  = (int) $row['attach_id'];
        $pos  = (int) $row['position'];
        echo '<div class="rincwc-crop-dl">';
        foreach ( [ '' => '4K', '_1440p' => '1440p', '_1080p' => '1080p' ] as $sfx => $res_lbl ) {
            $wm_f  = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg";
            $raw_f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg";
            if ( $wm_applied && file_exists( $wm_f ) ) {
                echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg" ) ) . '">' . esc_html( $res_lbl ) . ' WM</a> ';
            } elseif ( file_exists( $raw_f ) ) {
                echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg" ) ) . '">' . esc_html( $res_lbl ) . '</a> ';
            }
        }
        echo '</div>';
    }

    private static function render_comments( string $image_key ): void {
        echo '<div class="rincwc-comments" data-key="' . esc_attr( $image_key ) . '">';
        foreach ( RinCWC_DB::get_comments( $image_key ) as $comment ) {
            self::render_comment( $comment );
        }
        echo '<div class="rincwc-add-comment">';
        echo '<textarea class="rincwc-comment-ta" placeholder="Add a comment..." rows="2"></textarea>';
        echo '<button class="button rincwc-post-btn">Post</button>';
        echo '</div></div>';
    }

    private static function render_comment( array $comment ): void {
        $is_mine = (int) $comment['user_id'] === get_current_user_id();
        $name = $comment['display_name'] ?: $comment['user_login'];
        $date = date( 'M j, Y H:i', strtotime( $comment['created_at'] ) );
        echo '<div class="rincwc-comment" data-cid="' . esc_attr( $comment['id'] ) . '">';
        echo '<div class="rincwc-comment-meta"><strong>' . esc_html( $name ) . '</strong> · ' . esc_html( $date );
        if ( $is_mine ) {
            echo ' <button class="rincwc-edit-btn button-link">Edit</button>';
            echo ' <button class="rincwc-del-btn button-link">Delete</button>';
        }
        echo '</div><div class="rincwc-comment-body">' . nl2br( esc_html( $comment['body'] ) ) . '</div>';
        if ( $is_mine ) {
            echo '<div class="rincwc-edit-form" hidden>';
            echo '<textarea class="rincwc-edit-ta" rows="2">' . esc_textarea( $comment['body'] ) . '</textarea>';
            echo ' <button class="button rincwc-save-edit-btn">Save</button>';
            echo ' <button class="button-link rincwc-cancel-edit-btn">Cancel</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function variant_label( string $variant ): string {
        return ucwords( str_replace( '-', ' ', $variant ) );
    }

    private static function crop_links_for( string $slug, int $aid, string $variant ): array {
        $links = [ '4k' => '', '1440p' => '', '1080p' => '' ];
        foreach ( [ '4k' => '', '1440p' => '_1440p', '1080p' => '_1080p' ] as $res => $sfx ) {
            $file = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$variant}{$sfx}.jpg";
            if ( file_exists( $file ) ) {
                $links[ $res ] = content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$variant}{$sfx}.jpg" );
            }
        }
        return $links;
    }
}
