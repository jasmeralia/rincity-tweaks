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
            'restBase'       => rest_url( 'rincity/v1/wpc/' ),
            'nonce'          => wp_create_nonce( 'wp_rest' ),
            'userId'         => get_current_user_id(),
            'approveAllowed' => RinCWC_Data::approve_allowed(),
        ] ) . ';', 'before' );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No access.' );
        }

        $rows  = RinCWC_Data::get_review_images();
        $stats = RinCWC_Data::count_by_status();

        echo '<div class="wrap rincwc-review">';
        echo '<h1>Wallpaper Review</h1>';
        if ( empty( $rows ) ) {
            echo '<p>No candidates found. Run the Wallpaper scanner to populate the review queue.</p></div>';
            return;
        }

        $wm_pending = 0;
        foreach ( $rows as $row ) {
            if ( ! empty( $row['crop_variant'] ) && ! empty( $row['wm_corner'] ) && empty( $row['wm_applied'] ) ) {
                $wm_pending++;
            }
        }

        echo '<p class="rincwc-summary"><strong>' . esc_html( count( $rows ) ) . '</strong> candidates · ';
        echo '<strong>' . esc_html( $stats[ RinCWC_Data::STATUS_SELECTED ] + $stats[ RinCWC_Data::STATUS_APPROVED ] ) . '</strong> selected · ';
        echo '<strong>' . esc_html( $stats[ RinCWC_Data::STATUS_APPROVED ] ) . '</strong> approved · ';
        echo '<strong>' . esc_html( $wm_pending ) . '</strong> watermarks pending</p>';

        echo '<div class="rincwc-toolbar">';
        echo '<button class="button" id="rincwc-filter-sel">Selections only</button> ';
        echo '<button class="button" id="rincwc-generate-crops">Generate pending crops</button> ';
        echo '<button class="button button-primary" id="rincwc-apply-wm">Apply pending watermarks</button> ';
        echo '<button class="button" id="rincwc-sync-galleries">Publish to galleries</button>';
        echo '<span id="rincwc-batch-msg"></span>';
        echo '<span class="rincwc-expanders">';
        echo '<a href="#" id="rincwc-expand-all">Expand all</a> · <a href="#" id="rincwc-collapse-all">Collapse all</a>';
        echo '</span>';
        echo '</div>';

        $by_gallery = [];
        foreach ( $rows as $row ) {
            $by_gallery[ (int) $row['gallery_id'] ][] = $row;
        }

        foreach ( $by_gallery as $gid => $imgs ) {
            self::render_gallery( $gid, $imgs );
        }

        echo '</div>';
    }

    private static function render_gallery( int $gid, array $imgs ): void {
        $post      = get_post( $gid );
        $title     = $post ? $post->post_title : ( $imgs[0]['gallery_title'] ?? "Gallery {$gid}" );
        $date_str  = $post ? ' (' . date( 'Y-m-d', strtotime( $post->post_date ) ) . ')' : '';
        $permalink = $post ? get_permalink( $post ) : '';
        $selected  = array_values( array_filter( $imgs, fn( $r ) => ! empty( $r['crop_variant'] ) ) );
        $others    = array_values( array_filter( $imgs, fn( $r ) => empty( $r['crop_variant'] ) ) );
        $best_w    = max( array_map( fn( $r ) => (int) $r['orig_w'], $imgs ) );
        $summary   = count( $selected )
            ? count( $selected ) . ' selected, ' . count( $others ) . " other · best {$best_w}px"
            : count( $imgs ) . " candidates · best {$best_w}px";

        echo '<div class="rincwc-gallery">';
        echo '<div class="rincwc-gal-head"><h3>';
        if ( $permalink ) {
            echo '<a href="' . esc_url( $permalink ) . '" target="_blank">' . esc_html( $title . $date_str ) . '</a>';
        } else {
            echo esc_html( $title . $date_str );
        }
        echo '</h3></div>';
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
        $gid        = (int) $row['gallery_id'];
        $aid        = (int) $row['attach_id'];
        $pos        = (int) $row['position'];
        $slug       = (string) $row['gallery_slug'];
        $fname      = basename( (string) $row['original_path'] );
        $orig_w     = (int) $row['orig_w'];
        $orig_h     = (int) $row['orig_h'];
        $src_url    = (string) $row['src_url'];
        $image_key  = "{$gid}:{$aid}";
        $sel_crop   = (string) ( $row['crop_variant'] ?? '' );
        $wm_corner  = (string) ( $row['wm_corner'] ?? '' );
        $wm_applied = ! empty( $row['wm_applied'] );
        $status     = (string) $row['status'];
        $is_sel     = $sel_crop !== '';
        $is_approved = $status === RinCWC_Data::STATUS_APPROVED;
        $scaled_url = wp_get_attachment_image_url( $aid, 'large' ) ?: $src_url;

        $thumb = $src_url;
        if ( $is_sel && $wm_applied ) {
            $wm_f = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg";
            if ( file_exists( $wm_f ) ) {
                $thumb = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg" );
            }
        }

        $max_scale = RinCWC_Data::max_crop_scale( $orig_w, $orig_h );
        $custom_x  = (int) ( $row['custom_crop_x'] ?? 0 );
        $custom_y  = (int) ( $row['custom_crop_y'] ?? 0 );
        $custom_scale = (float) ( $row['custom_crop_scale'] ?: $max_scale );

        $card_data = esc_attr( wp_json_encode( [
            'gid'         => $gid,
            'aid'         => $aid,
            'pos'         => $pos,
            'slug'        => $slug,
            'title'       => ( $row['gallery_title'] ?? '' ) . " #{$pos}",
            'fname'       => $fname,
            'total'       => (int) $row['total'],
            'origW'       => $orig_w,
            'origH'       => $orig_h,
            'maxScale'    => $max_scale,
            'customScale' => $custom_scale,
            'customX'     => $custom_x,
            'customY'     => $custom_y,
            'imageKey'    => $image_key,
            'scaledUrl'   => $scaled_url,
            'selCrop'     => $sel_crop,
            'status'      => $status,
            'wmCorner'    => $wm_corner,
            'wmApplied'   => $wm_applied,
            'gSlug'       => $slug,
            'gTitle'      => (string) $row['gallery_title'],
        ] ) );

        $card_cls = 'rincwc-card' . ( $is_sel ? ' is-selected' : '' ) . ( $is_approved ? ' is-approved' : '' );
        echo '<div class="' . esc_attr( $card_cls ) . '" data-c="' . $card_data . '" data-key="' . esc_attr( $image_key ) . '">';
        echo '<div class="rincwc-thumb-wrap"><img class="rincwc-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $fname ) . '" loading="lazy">';
        if ( $is_approved ) {
            echo '<span class="rincwc-badge badge-approved">Approved</span>';
        } elseif ( $is_sel ) {
            echo '<span class="rincwc-badge ' . esc_attr( $wm_applied ? 'badge-wm' : 'badge-sel' ) . '">' . esc_html( $wm_applied ? 'WM applied' : 'Selected' ) . '</span>';
        }
        echo '</div>';

        echo '<div class="rincwc-info"><div class="rincwc-fname">' . esc_html( $fname ) . '</div>';
        echo '<div class="rincwc-dims">' . esc_html( "{$orig_w}x{$orig_h} · img {$pos}/{$row['total']} · {$status}" ) . '</div></div>';

        echo '<div class="rincwc-variants">';
        foreach ( [ 'top', 'center-top', 'center', 'center-bottom', 'bottom' ] as $vname ) {
            echo '<span class="rincwc-vbtn' . esc_attr( $sel_crop === $vname ? ' active' : '' ) . '" data-v="' . esc_attr( $vname ) . '">' . esc_html( self::variant_label( $vname ) ) . '</span>';
        }
        echo '<span class="rincwc-vbtn rincwc-custbtn' . esc_attr( $sel_crop === 'custom' ? ' active' : '' ) . '" data-v="custom">Custom...</span>';
        if ( $is_sel ) {
            echo '<span class="rincwc-desel" title="Remove selection">x</span>';
        }
        echo '</div>';

        echo '<div class="rincwc-wm-row' . ( $is_sel ? '' : ' hidden' ) . '"><select class="rincwc-wm-sel">';
        echo '<option value="">-- no watermark --</option>';
        foreach ( [ 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right' ] as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $wm_corner, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        $wm_cls = $wm_applied ? 'wm-done' : ( $is_sel && $wm_corner ? 'wm-pending' : '' );
        $wm_txt = $wm_applied ? 'applied' : ( $is_sel && $wm_corner ? 'pending' : '' );
        echo '<span class="rincwc-wm-status ' . esc_attr( $wm_cls ) . '">' . esc_html( $wm_txt ) . '</span></div>';

        echo '<div class="rincwc-approval-row' . ( $is_sel ? '' : ' hidden' ) . '">';
        $disabled = RinCWC_Data::approve_allowed() ? '' : ' disabled';
        $label    = $is_approved ? 'Unapprove' : 'Approve';
        echo '<button class="button rincwc-approve-btn" data-approved="' . esc_attr( $is_approved ? '1' : '0' ) . '"' . $disabled . '>' . esc_html( $label ) . '</button>';
        if ( $is_approved && ! $wm_applied ) {
            echo '<span class="rincwc-approved-pending">approved but watermark pending</span>';
        }
        echo '</div>';

        if ( $is_sel ) {
            echo '<div class="rincwc-crop-dl">';
            foreach ( [ '' => '4K', '_1440p' => '1440p', '_1080p' => '1080p' ] as $sfx => $label ) {
                $wm_f  = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg";
                $raw_f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg";
                if ( file_exists( $wm_f ) ) {
                    echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg" ) ) . '">' . esc_html( "{$label} WM" ) . '</a> ';
                } elseif ( file_exists( $raw_f ) ) {
                    echo '<a href="' . esc_url( content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg" ) ) . '">' . esc_html( $label ) . '</a> ';
                }
            }
            echo '</div>';
        }

        echo '<div class="rincwc-comments" data-key="' . esc_attr( $image_key ) . '">';
        foreach ( RinCWC_DB::get_comments( $image_key ) as $comment ) {
            self::render_comment( $comment );
        }
        echo '<div class="rincwc-add-comment"><textarea class="rincwc-comment-ta" placeholder="Add a comment..." rows="2"></textarea>';
        echo '<button class="button rincwc-post-btn">Post</button></div></div></div>';
    }

    private static function render_comment( array $comment ): void {
        $is_mine = (int) $comment['user_id'] === get_current_user_id();
        $name    = $comment['display_name'] ?: $comment['user_login'];
        $date    = date( 'M j, Y H:i', strtotime( $comment['created_at'] ) );
        echo '<div class="rincwc-comment" data-cid="' . esc_attr( $comment['id'] ) . '">';
        echo '<div class="rincwc-comment-meta"><strong>' . esc_html( $name ) . '</strong> · ' . esc_html( $date );
        if ( $is_mine ) {
            echo ' <button class="rincwc-edit-btn button-link">Edit</button> <button class="rincwc-del-btn button-link">Delete</button>';
        }
        echo '</div><div class="rincwc-comment-body">' . nl2br( esc_html( $comment['body'] ) ) . '</div>';
        if ( $is_mine ) {
            echo '<div class="rincwc-edit-form" hidden><textarea class="rincwc-edit-ta" rows="2">' . esc_textarea( $comment['body'] ) . '</textarea> ';
            echo '<button class="button rincwc-save-edit-btn">Save</button> <button class="button-link rincwc-cancel-edit-btn">Cancel</button></div>';
        }
        echo '</div>';
    }

    private static function variant_label( string $variant ): string {
        return ucwords( str_replace( '-', ' ', $variant ) );
    }
}
