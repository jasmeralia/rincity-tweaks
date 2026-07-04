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
            'restBase'   => rest_url( 'rincity/v1/wpc/' ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'userId'     => get_current_user_id(),
            'canApprove' => RinCWC_Data::can_current_user_approve(),
        ] ) . ';', 'before' );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No access.' );
        }

        $rows  = RinCWC_Data::get_review_images();
        $stats = self::stats( $rows );

        echo '<div class="wrap rincwc-review">';
        echo '<h1>Wallpaper Review</h1>';

        if ( empty( $rows ) ) {
            echo '<p>No candidates found. Scan an Envira gallery from the Wallpaper page.</p></div>';
            return;
        }

        echo '<p class="rincwc-summary"><strong>' . esc_html( (string) $stats['candidates'] ) . '</strong> candidates &middot; ';
        echo '<strong>' . esc_html( (string) $stats['selected'] ) . '</strong> selected &middot; ';
        echo '<strong>' . esc_html( (string) $stats['approved'] ) . '</strong> approved &middot; ';
        echo '<strong>' . esc_html( (string) $stats['wm_pending'] ) . '</strong> watermarks pending</p>';

        echo '<div class="rincwc-toolbar">';
        echo '<button class="button" id="rincwc-filter-sel">Selections only</button> ';
        echo '<button class="button" id="rincwc-generate-crops">Generate pending crops</button> ';
        echo '<button class="button" id="rincwc-apply-wm">Apply pending watermarks</button> ';
        echo '<button class="button button-primary" id="rincwc-sync-galleries">Publish to galleries</button>';
        echo '<span id="rincwc-batch-msg"></span>';
        echo '<span class="rincwc-expanders">';
        echo '<a href="#" id="rincwc-expand-all">Expand all</a> &middot; <a href="#" id="rincwc-collapse-all">Collapse all</a>';
        echo '</span>';
        echo '</div>';

        foreach ( self::group_rows( $rows ) as $gid => $gallery_rows ) {
            self::render_gallery( (int) $gid, $gallery_rows );
        }

        echo '</div>';
    }

    private static function group_rows( array $rows ): array {
        $by_gallery = [];
        foreach ( $rows as $row ) {
            $by_gallery[ (int) $row['gallery_id'] ][] = $row;
        }

        uksort( $by_gallery, function ( $a, $b ) {
            $pa = get_post( (int) $a );
            $pb = get_post( (int) $b );
            return strcmp( $pb ? $pb->post_date : '', $pa ? $pa->post_date : '' );
        } );

        foreach ( $by_gallery as &$gallery_rows ) {
            usort( $gallery_rows, fn( $a, $b ) => (int) $a['position'] <=> (int) $b['position'] );
        }
        unset( $gallery_rows );

        return $by_gallery;
    }

    private static function stats( array $rows ): array {
        $stats = [ 'candidates' => count( $rows ), 'selected' => 0, 'approved' => 0, 'wm_pending' => 0 ];
        foreach ( $rows as $row ) {
            if ( $row['status'] === RinCWC_Data::STATUS_SELECTED || $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $stats['selected']++;
            }
            if ( $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $stats['approved']++;
            }
            if ( ! empty( $row['crop_variant'] ) && ! empty( $row['wm_corner'] ) && ! (int) $row['wm_applied'] ) {
                $stats['wm_pending']++;
            }
        }
        return $stats;
    }

    private static function render_gallery( int $gid, array $rows ): void {
        $post      = get_post( $gid );
        $title     = $post ? $post->post_title : "Gallery {$gid}";
        $date_str  = $post ? ' (' . date( 'Y-m-d', strtotime( $post->post_date ) ) . ')' : '';
        $gal_slug  = $rows[0]['gallery_slug'] ?? '';
        $permalink = get_option( 'siteurl' ) . "/envira/{$gal_slug}/";

        $selected = [];
        $others   = [];
        foreach ( $rows as $row ) {
            if ( $row['status'] === RinCWC_Data::STATUS_SELECTED || $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $selected[] = $row;
            } else {
                $others[] = $row;
            }
        }

        $best_w  = max( array_map( fn( $r ) => (int) $r['orig_w'], $rows ) );
        $summary = count( $selected )
            ? count( $selected ) . ' selection' . ( count( $selected ) !== 1 ? 's' : '' ) . ', ' . count( $others ) . " other; best {$best_w}px"
            : count( $rows ) . " candidates; best {$best_w}px";

        echo '<div class="rincwc-gallery">';
        echo '<div class="rincwc-gal-head"><h3><a href="' . esc_url( $permalink ) . '" target="_blank">' . esc_html( $title . $date_str ) . '</a></h3></div>';
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
            foreach ( $rows as $row ) {
                self::render_candidate( $row );
            }
            echo '</div>';
        }

        echo '</details></div>';
    }

    private static function render_candidate( array $r ): void {
        $gid       = (int) $r['gallery_id'];
        $aid       = (int) $r['attach_id'];
        $pos       = (int) $r['position'];
        $slug      = (string) $r['gallery_slug'];
        $fname     = basename( $r['original_path'] ?? '' );
        $orig_w    = (int) $r['orig_w'];
        $orig_h    = (int) $r['orig_h'];
        $src_url   = (string) ( $r['src_url'] ?? '' );
        $image_key = "{$gid}:{$aid}";

        $sel_crop   = (string) ( $r['crop_variant'] ?? '' );
        $wm_corner  = (string) ( $r['wm_corner'] ?? '' );
        $wm_applied = (int) ( $r['wm_applied'] ?? 0 ) === 1;
        $status     = (string) $r['status'];
        $is_sel     = $status === RinCWC_Data::STATUS_SELECTED || $status === RinCWC_Data::STATUS_APPROVED;
        $is_approved = $status === RinCWC_Data::STATUS_APPROVED;
        $max_scale  = RinCWC_Data::max_crop_scale( $orig_w, $orig_h );

        $variants = [ 'top', 'center-top', 'center', 'center-bottom', 'bottom' ];
        $scaled_url = wp_get_attachment_image_url( $aid, 'large' ) ?: $src_url;

        $thumb = $src_url;
        if ( $is_sel && $wm_applied ) {
            $wm_f = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg";
            if ( file_exists( $wm_f ) ) {
                $thumb = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg" );
            }
        }

        $card_data = esc_attr( wp_json_encode( [
            'gid'         => $gid,
            'aid'         => $aid,
            'pos'         => $pos,
            'slug'        => $slug,
            'title'       => ( $r['gallery_title'] ?? '' ) . " #{$pos}",
            'fname'       => $fname,
            'total'       => $r['total'] ?? '',
            'origW'       => $orig_w,
            'origH'       => $orig_h,
            'maxScale'    => $max_scale,
            'imageKey'    => $image_key,
            'scaledUrl'   => $scaled_url,
            'selCrop'     => $sel_crop,
            'wmCorner'    => $wm_corner,
            'wmApplied'   => $wm_applied,
            'status'      => $status,
            'customScale' => $r['custom_crop_scale'] ? (float) $r['custom_crop_scale'] : $max_scale,
            'customX'     => (int) ( $r['custom_crop_x'] ?? 0 ),
            'customY'     => (int) ( $r['custom_crop_y'] ?? 0 ),
            'gSlug'       => $r['gallery_slug'] ?? '',
            'gTitle'      => $r['gallery_title'] ?? '',
        ] ) );

        $card_cls = 'rincwc-card status-' . strtolower( $status ) . ( $is_sel ? ' is-selected' : '' ) . ( $is_approved ? ' is-approved' : '' );
        echo '<div class="' . esc_attr( $card_cls ) . '" data-c="' . $card_data . '" data-key="' . esc_attr( $image_key ) . '">';

        echo '<div class="rincwc-thumb-wrap">';
        echo '<img class="rincwc-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $fname ) . '" loading="lazy">';
        if ( $is_sel ) {
            if ( $is_approved && ! $wm_applied ) {
                $badge = 'Approved; WM pending';
                $cls   = 'badge-pending';
            } elseif ( $is_approved ) {
                $badge = 'Approved';
                $cls   = 'badge-approved';
            } else {
                $badge = $wm_applied ? 'WM applied' : 'Selected';
                $cls   = $wm_applied ? 'badge-wm' : 'badge-sel';
            }
            echo '<span class="rincwc-badge ' . esc_attr( $cls ) . '">' . esc_html( $badge ) . '</span>';
        }
        echo '</div>';

        echo '<div class="rincwc-info">';
        echo '<div class="rincwc-fname">' . esc_html( $fname ) . '</div>';
        echo '<div class="rincwc-dims">' . esc_html( "{$orig_w}x{$orig_h}; img {$pos}/{$r['total']}" ) . '</div>';
        echo '</div>';

        echo '<div class="rincwc-variants">';
        foreach ( $variants as $vname ) {
            $active = $sel_crop === $vname ? ' active' : '';
            $links  = self::crop_links_for( $slug, (string) $aid, $vname );
            echo '<span class="rincwc-vbtn' . esc_attr( $active ) . '" data-v="' . esc_attr( $vname ) . '" data-4k="' . esc_url( $links['4k'] ) . '" data-1440p="' . esc_url( $links['1440p'] ) . '" data-1080p="' . esc_url( $links['1080p'] ) . '">' . esc_html( self::variant_label( $vname ) ) . '</span>';
        }
        echo '<span class="rincwc-vbtn rincwc-custbtn' . esc_attr( $sel_crop === 'custom' ? ' active' : '' ) . '" data-v="custom">Custom...</span>';
        if ( $is_sel ) {
            echo '<span class="rincwc-desel" title="Remove selection">x</span>';
        }
        echo '</div>';

        echo '<div class="rincwc-wm-row' . ( $is_sel ? '' : ' hidden' ) . '">';
        echo '<select class="rincwc-wm-sel">';
        echo '<option value="">- no watermark -</option>';
        foreach ( [ 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right' ] as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $wm_corner, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        $wm_cls = $wm_applied ? 'wm-done' : ( $is_sel && $wm_corner ? 'wm-pending' : '' );
        $wm_txt = $wm_applied ? 'applied' : ( $is_sel && $wm_corner ? 'pending' : '' );
        echo '<span class="rincwc-wm-status ' . esc_attr( $wm_cls ) . '">' . esc_html( $wm_txt ) . '</span>';
        echo '</div>';

        echo '<div class="rincwc-approve-row' . ( $is_sel ? '' : ' hidden' ) . '">';
        $disabled = RinCWC_Data::can_current_user_approve() && $is_sel ? '' : ' disabled';
        $label    = $is_approved ? 'Unapprove' : 'Approve';
        echo '<button class="button rincwc-approve-btn" data-approved="' . esc_attr( $is_approved ? '1' : '0' ) . '"' . $disabled . '>' . esc_html( $label ) . '</button>';
        if ( ! RinCWC_Data::can_current_user_approve() ) {
            echo '<span class="rincwc-approve-note">Approval restricted</span>';
        }
        echo '</div>';

        if ( $is_sel ) {
            self::render_crop_links( $slug, $aid, $pos, $sel_crop );
        }

        echo '<div class="rincwc-comments" data-key="' . esc_attr( $image_key ) . '">';
        foreach ( RinCWC_DB::get_comments( $image_key ) as $c ) {
            self::render_comment( $c );
        }
        echo '<div class="rincwc-add-comment">';
        echo '<textarea class="rincwc-comment-ta" placeholder="Add a comment..." rows="2"></textarea>';
        echo '<button class="button rincwc-post-btn">Post</button>';
        echo '</div></div></div>';
    }

    private static function render_crop_links( string $slug, int $aid, int $pos, string $sel_crop ): void {
        echo '<div class="rincwc-crop-dl">';
        foreach ( [ '' => '4K', '_1440p' => '1440p', '_1080p' => '1080p' ] as $sfx => $res_lbl ) {
            $wm_f  = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg";
            $raw_f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg";
            if ( file_exists( $wm_f ) ) {
                echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg" ) ) . '">' . esc_html( $res_lbl ) . ' WM</a> ';
            } elseif ( file_exists( $raw_f ) ) {
                echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg" ) ) . '">' . esc_html( $res_lbl ) . '</a> ';
            }
        }
        echo '</div>';
    }

    private static function render_comment( array $c ): void {
        $is_mine = (int) $c['user_id'] === get_current_user_id();
        $name    = $c['display_name'] ?: $c['user_login'];
        $date    = date( 'M j, Y H:i', strtotime( $c['created_at'] ) );
        echo '<div class="rincwc-comment" data-cid="' . esc_attr( $c['id'] ) . '">';
        echo '<div class="rincwc-comment-meta"><strong>' . esc_html( $name ) . '</strong> &middot; ' . esc_html( $date );
        if ( $is_mine ) {
            echo ' <button class="rincwc-edit-btn button-link">Edit</button>';
            echo ' <button class="rincwc-del-btn button-link">Delete</button>';
        }
        echo '</div><div class="rincwc-comment-body">' . nl2br( esc_html( $c['body'] ) ) . '</div>';
        if ( $is_mine ) {
            echo '<div class="rincwc-edit-form" hidden>';
            echo '<textarea class="rincwc-edit-ta" rows="2">' . esc_textarea( $c['body'] ) . '</textarea>';
            echo ' <button class="button rincwc-save-edit-btn">Save</button>';
            echo ' <button class="button-link rincwc-cancel-edit-btn">Cancel</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    private static function variant_label( string $v ): string {
        return ucwords( str_replace( '-', ' ', $v ) );
    }

    private static function crop_links_for( string $slug, string $aid, string $vname ): array {
        $links = [ '4k' => '', '1440p' => '', '1080p' => '' ];
        foreach ( [ '4k' => '', '1440p' => '_1440p', '1080p' => '_1080p' ] as $res => $sfx ) {
            $f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$vname}{$sfx}.jpg";
            if ( file_exists( $f ) ) {
                $links[ $res ] = content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$vname}{$sfx}.jpg" );
            }
        }
        return $links;
    }
}
