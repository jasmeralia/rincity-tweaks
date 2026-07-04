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
        if ( $hook !== self::$hook ) return;
        wp_enqueue_style(
            'rincwc-review',
            RINCWC_PLUGIN_URL . 'assets/review.css',
            [],
            RINCWC_VERSION
        );
        wp_enqueue_script(
            'rincwc-review',
            RINCWC_PLUGIN_URL . 'assets/review.js',
            [ 'wp-api-fetch' ],
            RINCWC_VERSION,
            true
        );
        wp_add_inline_script( 'rincwc-review', 'var rincwcCfg=' . wp_json_encode( [
            'restBase' => rest_url( 'rincity/v1/wpc/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'userId'   => get_current_user_id(),
        ] ) . ';', 'before' );
    }

    // ── Page render ───────────────────────────────────────────────────────────

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No access.' );

        $db_flat    = RinCWC_CSV::read_db_flat();
        $selections = RinCWC_CSV::read_selections();

        echo '<div class="wrap rincwc-review">';
        echo '<h1>Wallpaper Review</h1>';

        if ( empty( $db_flat ) ) {
            echo '<p>No candidates found — run the wallpaper pipeline to populate wallpaper_db.csv.</p></div>';
            return;
        }

        // Gallery pub dates for sorting.
        $pub_dates = [];
        foreach ( $db_flat as $r ) {
            $gid = $r['gallery_id'];
            if ( ! isset( $pub_dates[ $gid ] ) ) {
                $post = get_post( (int) $gid );
                $pub_dates[ $gid ] = $post ? $post->post_date : '0000-00-00';
            }
        }

        // Group by gallery.
        $by_gallery = [];
        foreach ( $db_flat as $r ) {
            $by_gallery[ $r['gallery_id'] ][] = $r;
        }
        uksort( $by_gallery, fn( $a, $b ) => strcmp( $pub_dates[ $b ] ?? '', $pub_dates[ $a ] ?? '' ) );

        // Stats.
        $total_cands = count( $db_flat );
        $total_sel   = array_sum( array_map( 'count', $selections ) );
        $wm_pending  = 0;
        foreach ( $selections as $aids ) {
            foreach ( $aids as $sel ) {
                if ( ( $sel['wm_applied'] ?? 'false' ) !== 'true'
                  && ! empty( $sel['selected_crop'] )
                  && ! empty( $sel['wm_corner'] ) ) {
                    $wm_pending++;
                }
            }
        }

        echo '<p class="rincwc-summary"><strong>' . esc_html( $total_cands ) . '</strong> candidates · ';
        echo '<strong>' . esc_html( $total_sel ) . '</strong> selected · ';
        echo '<strong>' . esc_html( $wm_pending ) . '</strong> watermarks pending</p>';

        echo '<div class="rincwc-toolbar">';
        echo '<button class="button" id="rincwc-filter-sel">Selections only</button> ';
        echo '<button class="button" id="rincwc-generate-crops">Generate pending crops</button> ';
        echo '<button class="button button-primary" id="rincwc-apply-wm">Apply pending watermarks</button>';
        echo '<span id="rincwc-batch-msg"></span>';
        echo '<span class="rincwc-expanders">';
        echo '<a href="#" id="rincwc-expand-all">Expand all</a> · <a href="#" id="rincwc-collapse-all">Collapse all</a>';
        echo '</span>';
        echo '</div>';

        foreach ( $by_gallery as $gid => $imgs ) {
            usort( $imgs, fn( $a, $b ) => (int) $a['position'] <=> (int) $b['position'] );
            self::render_gallery( $gid, $imgs, $selections[ $gid ] ?? [], $pub_dates );
        }

        echo '</div>'; // .rincwc-review
    }

    // ── Gallery block ─────────────────────────────────────────────────────────

    private static function render_gallery( string $gid, array $imgs, array $sel_map, array $pub_dates ): void {
        $post      = get_post( (int) $gid );
        $title     = $post ? $post->post_title : "Gallery {$gid}";
        $pub_date  = isset( $pub_dates[ $gid ] ) ? date( 'Y-m-d', strtotime( $pub_dates[ $gid ] ) ) : '';
        $date_str  = $pub_date ? " ({$pub_date})" : '';
        $gal_slug  = $imgs[0]['gallery_slug'] ?? '';
        $permalink = get_option( 'siteurl' ) . "/envira/{$gal_slug}/";

        $n_sel    = count( $sel_map );
        $n_other  = count( $imgs ) - $n_sel;
        $best_w   = max( array_map( fn( $r ) => (int) $r['width'], $imgs ) );
        $summary  = $n_sel
            ? "{$n_sel} selection" . ( $n_sel !== 1 ? 's' : '' ) . ", {$n_other} other · best {$best_w}px"
            : count( $imgs ) . ' candidates · best ' . $best_w . 'px';

        echo '<div class="rincwc-gallery">';
        echo '<div class="rincwc-gal-head">';
        echo '<h3><a href="' . esc_url( $permalink ) . '" target="_blank">' . esc_html( $title . $date_str ) . '</a></h3>';
        echo '</div>';
        echo '<details class="rincwc-details">';
        echo '<summary>' . esc_html( $summary ) . '</summary>';

        $selected = [];
        $others   = [];
        foreach ( $imgs as $r ) {
            if ( isset( $sel_map[ $r['attach_id'] ] ) ) {
                $selected[] = [ $r, $sel_map[ $r['attach_id'] ] ];
            } else {
                $others[] = [ $r, null ];
            }
        }

        if ( $selected ) {
            echo '<div class="rincwc-section">Selection</div>';
            echo '<div class="rincwc-grid">';
            foreach ( $selected as [ $r, $sel ] ) self::render_candidate( $r, $sel );
            echo '</div>';
            echo '<div class="rincwc-section">Other Candidates</div>';
            echo '<div class="rincwc-grid">';
            foreach ( $others as [ $r, ] ) self::render_candidate( $r, null );
            echo '</div>';
        } else {
            echo '<div class="rincwc-grid">';
            foreach ( $imgs as $r ) self::render_candidate( $r, null );
            echo '</div>';
        }

        echo '</details></div>';
    }

    // ── Candidate card ────────────────────────────────────────────────────────

    private static function render_candidate( array $r, ?array $sel ): void {
        $gid   = $r['gallery_id'];
        $aid   = $r['attach_id'];
        $pos   = $r['position'];
        $slug  = $r['gallery_slug'];
        $fname = basename( $r['original_path'] ?? '' );
        $orig_w = (int) $r['width'];
        $orig_h = (int) $r['height'];
        $src_url = $r['src_url'] ?? '';
        $image_key = "{$gid}:{$aid}";

        $sel_crop   = $sel['selected_crop'] ?? '';
        $wm_corner  = $sel['wm_corner']     ?? '';
        $wm_applied = ( $sel['wm_applied']  ?? 'false' ) === 'true';
        $custom_off = (int) ( $sel['custom_crop_offset'] ?? 0 );
        $is_sel     = $sel_crop !== '';

        // Crop geometry for this image.
        $t_w = 3840; $t_h = 2160;
        if ( $orig_w / $orig_h >= $t_w / $t_h ) {
            $crop_range = (int) floor( $orig_w * $t_h / $orig_h ) - $t_w;
            $crop_axis  = 'x';
        } else {
            $crop_range = (int) floor( $orig_h * $t_w / $orig_w ) - $t_h;
            $crop_axis  = 'y';
        }

        // Preset offsets.
        $variants = [
            'top'           => 0,
            'center-top'    => (int) round( $crop_range * 0.25 ),
            'center'        => (int) round( $crop_range * 0.50 ),
            'center-bottom' => (int) round( $crop_range * 0.75 ),
            'bottom'        => $crop_range,
        ];

        // Preview image for crop tool: largest available WP registered size.
        $scaled_url = wp_get_attachment_image_url( (int) $aid, 'large' ) ?: $src_url;

        // Card thumbnail.
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
            'total'       => $r['total']         ?? '',
            'origW'       => $orig_w,
            'origH'       => $orig_h,
            'cropRange'   => $crop_range,
            'cropAxis'    => $crop_axis,
            'imageKey'    => $image_key,
            'scaledUrl'   => $scaled_url,
            'selCrop'     => $sel_crop,
            'wmCorner'    => $wm_corner,
            'wmApplied'   => $wm_applied,
            'customOff'   => $custom_off,
            'gSlug'       => $r['gallery_slug']  ?? '',
            'gTitle'      => $r['gallery_title'] ?? '',
        ] ) );

        $card_cls = 'rincwc-card' . ( $is_sel ? ' is-selected' : '' );
        echo '<div class="' . esc_attr( $card_cls ) . '" data-c="' . $card_data . '" data-key="' . esc_attr( $image_key ) . '">';

        // Thumbnail.
        echo '<div class="rincwc-thumb-wrap">';
        echo '<img class="rincwc-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $fname ) . '" loading="lazy">';
        if ( $is_sel ) {
            $badge = $wm_applied ? 'WM ✓' : 'Selected';
            $cls   = $wm_applied ? 'badge-wm' : 'badge-sel';
            echo '<span class="rincwc-badge ' . esc_attr( $cls ) . '">' . esc_html( $badge ) . '</span>';
        }
        echo '</div>';

        // File/dimension info.
        echo '<div class="rincwc-info">';
        echo '<div class="rincwc-fname">' . esc_html( $fname ) . '</div>';
        echo '<div class="rincwc-dims">' . esc_html( "{$orig_w}×{$orig_h} · {$r['tier']} · img {$pos}/{$r['total']}" ) . '</div>';
        echo '</div>';

        // Variant buttons.
        echo '<div class="rincwc-variants">';
        foreach ( $variants as $vname => $voff ) {
            $active = $sel_crop === $vname ? ' active' : '';
            // Crop preview links.
            $links = self::crop_links_for( $slug, $aid, $vname );
            echo '<span class="rincwc-vbtn' . esc_attr( $active ) . '" '
               . 'data-v="' . esc_attr( $vname ) . '" '
               . 'data-off="' . esc_attr( $voff ) . '" '
               . 'data-4k="'    . esc_url( $links['4k'] )    . '" '
               . 'data-1440p="' . esc_url( $links['1440p'] ) . '" '
               . 'data-1080p="' . esc_url( $links['1080p'] ) . '">'
               . esc_html( self::variant_label( $vname ) )
               . '</span>';
        }
        $cust_active = $sel_crop === 'custom' ? ' active' : '';
        echo '<span class="rincwc-vbtn rincwc-custbtn' . esc_attr( $cust_active ) . '" data-v="custom">Custom…</span>';
        if ( $is_sel ) {
            echo '<span class="rincwc-desel" title="Remove selection">✕</span>';
        }
        echo '</div>'; // .rincwc-variants

        // Custom crop tool (hidden initially).
        if ( $crop_range > 0 ) {
            echo '<div class="rincwc-crop-tool" style="display:none">';
            echo '<div class="rincwc-crop-preview">';
            echo '<img class="rincwc-preview-img" src="" data-src="' . esc_url( $scaled_url ) . '" alt="">';
            echo '<div class="rincwc-crop-box"></div>';
            echo '</div>';
            echo '<div class="rincwc-crop-ctrl">';
            echo '<label>Offset <input type="range" class="rincwc-slider" min="0" max="' . esc_attr( $crop_range ) . '" value="' . esc_attr( $custom_off ) . '"></label>';
            echo '<span class="rincwc-off-val">' . esc_html( $custom_off ) . '</span>px ';
            echo '<button class="button rincwc-save-off">Save</button>';
            echo '</div>';
            echo '</div>'; // .rincwc-crop-tool
        }

        // Watermark controls (hidden until selected).
        echo '<div class="rincwc-wm-row' . ( $is_sel ? '' : ' hidden' ) . '">';
        echo '<select class="rincwc-wm-sel">';
        echo '<option value="">— no watermark —</option>';
        foreach ( [ 'top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right' ] as $val => $label ) {
            echo '<option value="' . esc_attr( $val ) . '"' . selected( $wm_corner, $val, false ) . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select>';
        $wm_cls = $wm_applied ? 'wm-done' : ( $is_sel && $wm_corner ? 'wm-pending' : '' );
        $wm_txt = $wm_applied ? '✓ applied' : ( $is_sel && $wm_corner ? '⏳ pending' : '' );
        echo '<span class="rincwc-wm-status ' . esc_attr( $wm_cls ) . '">' . esc_html( $wm_txt ) . '</span>';
        echo '</div>';

        // Crop file download links for selected variant.
        if ( $is_sel ) {
            echo '<div class="rincwc-crop-dl">';
            foreach ( [ '' => '4K', '_1440p' => '1440p', '_1080p' => '1080p' ] as $sfx => $res_lbl ) {
                $wm_f  = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg";
                $raw_f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg";
                if ( file_exists( $wm_f ) ) {
                    $url = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}{$sfx}_wm.jpg" );
                    echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $res_lbl ) . ' WM</a> ';
                } elseif ( file_exists( $raw_f ) ) {
                    $url = content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$sel_crop}{$sfx}.jpg" );
                    echo '<a href="' . esc_url( $url ) . '" target="_blank">' . esc_html( $res_lbl ) . '</a> ';
                }
            }
            echo '</div>';
        }

        // Comments.
        echo '<div class="rincwc-comments" data-key="' . esc_attr( $image_key ) . '">';
        foreach ( RinCWC_DB::get_comments( $image_key ) as $c ) {
            self::render_comment( $c );
        }
        echo '<div class="rincwc-add-comment">';
        echo '<textarea class="rincwc-comment-ta" placeholder="Add a comment…" rows="2"></textarea>';
        echo '<button class="button rincwc-post-btn">Post</button>';
        echo '</div>';
        echo '</div>'; // .rincwc-comments

        echo '</div>'; // .rincwc-card
    }

    private static function render_comment( array $c ): void {
        $is_mine = (int) $c['user_id'] === get_current_user_id();
        $name    = $c['display_name'] ?: $c['user_login'];
        $date    = date( 'M j, Y H:i', strtotime( $c['created_at'] ) );
        echo '<div class="rincwc-comment" data-cid="' . esc_attr( $c['id'] ) . '">';
        echo '<div class="rincwc-comment-meta"><strong>' . esc_html( $name ) . '</strong> · ' . esc_html( $date );
        if ( $is_mine ) {
            echo ' <button class="rincwc-edit-btn button-link">Edit</button>';
            echo ' <button class="rincwc-del-btn button-link">Delete</button>';
        }
        echo '</div>';
        echo '<div class="rincwc-comment-body">' . nl2br( esc_html( $c['body'] ) ) . '</div>';
        if ( $is_mine ) {
            echo '<div class="rincwc-edit-form" hidden>';
            echo '<textarea class="rincwc-edit-ta" rows="2">' . esc_textarea( $c['body'] ) . '</textarea>';
            echo ' <button class="button rincwc-save-edit-btn">Save</button>';
            echo ' <button class="button-link rincwc-cancel-edit-btn">Cancel</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
