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
            'approveAllowed' => RinCWC_Data::can_current_user_approve(),
            'initFilter'     => isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '',
        ] ) . ';', 'before' );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'No access.' );
        }

        $rows          = RinCWC_Data::get_visible_images();
        $excluded_rows = RinCWC_Data::get_excluded_images();
        $counts        = RinCWC_Data::counts();
        $filter        = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';

        echo '<div class="wrap rincwc-review">';
        echo '<h1>Wallpaper Review</h1>';

        if ( empty( $rows ) && empty( $excluded_rows ) ) {
            echo '<p>No candidates found. Scan one or more galleries from the Wallpaper scanner page.</p></div>';
            return;
        }

        $grouped     = self::group_rows( array_merge( $rows, $excluded_rows ) );
        $set_counts  = self::compute_set_counts( $grouped );

        echo '<p class="rincwc-summary"><strong>' . esc_html( $counts['candidates'] ) . '</strong> candidates · ';
        echo '<strong>' . esc_html( $counts['selected'] ) . '</strong> selected · ';
        echo '<strong>' . esc_html( $counts['approved'] ) . '</strong> approved</p>';

        $nothing_to_inspect = $set_counts['untouched'] === 0;

        echo '<p class="rincwc-set-counts">';
        echo '<strong>' . esc_html( $set_counts['approved'] ) . '</strong> set' . ( $set_counts['approved'] !== 1 ? 's' : '' ) . ' with an approved image · ';
        echo '<strong>' . esc_html( $set_counts['ready'] ) . '</strong> set' . ( $set_counts['ready'] !== 1 ? 's' : '' ) . ' ready for review · ';
        if ( $nothing_to_inspect ) {
            echo 'All sets passed initial inspection · ';
        } else {
            echo '<strong>' . esc_html( $set_counts['passed'] ) . '</strong> set' . ( $set_counts['passed'] !== 1 ? 's' : '' ) . ' passed initial inspection · ';
        }
        echo '<strong>' . esc_html( $set_counts['excluded'] ) . '</strong> set' . ( $set_counts['excluded'] !== 1 ? 's' : '' ) . ' excluded · ';
        echo '<strong>' . esc_html( $set_counts['untouched'] ) . '</strong> untouched set' . ( $set_counts['untouched'] !== 1 ? 's' : '' );
        echo '</p>';

        $commented_keys = RinCWC_DB::get_commented_image_keys();

        echo '<div class="rincwc-toolbar">';
        echo '<input type="text" id="rincwc-review-search" placeholder="Filter galleries…" class="rincwc-search-input">';
        echo '<button class="button button-small rincwc-filter-btn" id="rincwc-filter-approved">Approved</button> ';
        echo '<button class="button button-small rincwc-filter-btn" id="rincwc-filter-sel">Ready for review</button> ';
        echo '<button class="button button-small rincwc-filter-btn" id="rincwc-filter-unreviewed"' . disabled( $nothing_to_inspect, true, false ) . '>Initial inspection</button> ';
        echo '<button class="button button-small rincwc-filter-btn" id="rincwc-filter-excluded">Exclusions</button> ';
        echo '<button class="button button-small rincwc-filter-btn" id="rincwc-filter-comments">Comments</button> ';
        echo '<button class="button button-small" id="rincwc-clear-filters">Clear filters</button> ';
        echo '<span class="rincwc-expanders">';
        echo '<a href="#" id="rincwc-expand-all">Expand all</a> · <a href="#" id="rincwc-collapse-all">Collapse all</a>';
        echo '</span></div>';

        echo '<div class="rincwc-toolbar">';
        echo '<button class="button button-small" id="rincwc-generate-crops">Generate pending crops</button> ';
        echo '<button class="button button-small" id="rincwc-apply-wm">Apply pending watermarks</button> ';
        echo '<button class="button button-secondary button-small" id="rincwc-sync-galleries">Publish to galleries</button>';
        echo '<span id="rincwc-batch-msg"></span>';
        echo '</div>';

        foreach ( $grouped as $gid => $imgs ) {
            if ( ! self::gallery_matches_filter( $imgs, (int) $gid, $filter, $commented_keys ) ) {
                continue;
            }
            $is_fully_excluded = ! array_filter( $imgs, fn( $row ) => empty( $row['excluded'] ) );
            $filtered          = self::filter_rows_for_filter( $imgs, $filter, $commented_keys );
            if ( ! $filtered ) {
                continue;
            }
            self::render_gallery( (int) $gid, $filtered, $is_fully_excluded, $imgs );
        }

        echo '</div>';
    }

    private static function gallery_matches_filter( array $imgs, int $gid, string $filter, array $commented_keys = [] ): bool {
        $visible           = array_values( array_filter( $imgs, fn( $row ) => empty( $row['excluded'] ) ) );
        $is_fully_excluded = empty( $visible );
        $cutoff            = RinCWC_Data::get_cutoff( $gid );

        if ( $filter === 'excluded' ) {
            return $is_fully_excluded || $cutoff > 0;
        }
        if ( $filter === 'comments' ) {
            // Comments can be on an excluded image, so check every row, not just visible.
            foreach ( $imgs as $row ) {
                if ( isset( $commented_keys[ $row['gallery_id'] . ':' . $row['attach_id'] ] ) ) {
                    return true;
                }
            }
            return false;
        }
        if ( $is_fully_excluded ) {
            // Fully-excluded sets only ever show under the Exclusions/Comments filters.
            return false;
        }
        if ( $filter === '' ) {
            return true;
        }

        $has_selected = false;
        $has_approved = false;
        foreach ( $visible as $row ) {
            if ( $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                $has_approved = true;
            }
            if ( in_array( $row['status'], [ RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ], true )
                && ( $row['crop_variant'] ?? '' ) !== '' ) {
                $has_selected = true;
            }
        }
        if ( $filter === 'selected' ) {
            return $has_selected;
        }
        if ( $filter === 'approved' ) {
            return $has_approved;
        }
        if ( $filter === 'unreviewed' ) {
            return ! $has_selected && $cutoff === 0;
        }
        return true;
    }

    private static function filter_rows_for_filter( array $imgs, string $filter, array $commented_keys = [] ): array {
        if ( $filter === 'excluded' ) {
            // Only the excluded rows themselves — the point is to review what got cut,
            // not the whole set's still-active candidates alongside them.
            return array_values( array_filter( $imgs, fn( $row ) => ! empty( $row['excluded'] ) ) );
        }
        if ( $filter === 'comments' ) {
            // Only the commented images themselves, visible or excluded.
            return array_values( array_filter( $imgs, fn( $row ) =>
                isset( $commented_keys[ $row['gallery_id'] . ':' . $row['attach_id'] ] )
            ) );
        }
        $visible = array_values( array_filter( $imgs, fn( $row ) => empty( $row['excluded'] ) ) );
        if ( $filter === 'selected' ) {
            return array_values( array_filter( $visible, fn( $row ) =>
                in_array( $row['status'], [ RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ], true )
                && ( $row['crop_variant'] ?? '' ) !== ''
            ) );
        }
        if ( $filter === 'approved' ) {
            return array_values( array_filter( $visible, fn( $row ) => $row['status'] === RinCWC_Data::STATUS_APPROVED ) );
        }
        return $visible;
    }

    private static function compute_set_counts( array $grouped ): array {
        $approved  = 0;
        $ready     = 0;
        $passed    = 0;
        $excluded  = 0;
        $untouched = 0;

        foreach ( $grouped as $gid => $imgs ) {
            $visible = array_filter( $imgs, fn( $row ) => empty( $row['excluded'] ) );

            $has_approved  = false;
            $has_selected  = false;
            $has_candidate = false;
            foreach ( $visible as $row ) {
                if ( $row['status'] === RinCWC_Data::STATUS_APPROVED ) {
                    $has_approved = true;
                } elseif ( $row['status'] === RinCWC_Data::STATUS_SELECTED ) {
                    $has_selected = true;
                } elseif ( $row['status'] === RinCWC_Data::STATUS_CANDIDATE ) {
                    $has_candidate = true;
                }
            }
            $is_fully_excluded = empty( $visible );
            $cutoff            = RinCWC_Data::get_cutoff( (int) $gid );

            if ( $has_approved ) {
                $approved++;
            } elseif ( $has_selected ) {
                $ready++;
            } elseif ( $is_fully_excluded ) {
                $excluded++;
            } elseif ( $cutoff > 0 ) {
                // Reviewed but nothing selected yet — either a real partial cutoff, or
                // "Accept all" (sent as a position far beyond any real gallery size, so
                // it lands in this same bucket with no special-casing needed).
                $passed++;
            } elseif ( $has_candidate ) {
                $untouched++;
            }
        }

        return [
            'approved'  => $approved,
            'ready'     => $ready,
            'passed'    => $passed,
            'excluded'  => $excluded,
            'untouched' => $untouched,
        ];
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

    private static function render_gallery( int $gid, array $imgs, bool $is_fully_excluded = false, array $full_imgs = [] ): void {
        $full_imgs = $full_imgs ?: $imgs;
        $post      = get_post( $gid );
        $title     = $post ? $post->post_title : ( $imgs[0]['gallery_title'] ?? "Gallery {$gid}" );
        $pub_date  = $post ? date( 'Y-m-d', strtotime( $post->post_date ) ) : '';
        $slug      = $imgs[0]['gallery_slug'] ?? '';
        $permalink = get_option( 'siteurl' ) . "/envira/{$slug}/";
        $tags      = self::matching_category_tags( $gid );

        $selected    = array_values( array_filter( $imgs, fn( $row ) => in_array( $row['status'], [ 'SELECTED', 'APPROVED' ], true ) ) );
        $others      = array_values( array_filter( $imgs, fn( $row ) => ! in_array( $row['status'], [ 'SELECTED', 'APPROVED' ], true ) ) );
        $has_sel     = count( $selected ) > 0;
        $has_cutoff  = RinCWC_Data::get_cutoff( $gid ) > 0;
        $summary     = self::build_gallery_summary( $full_imgs );

        // Cutoff button only appears after the last selected/approved image.
        $max_sel_pos = $has_sel
            ? max( array_map( fn( $row ) => (int) $row['position'], $selected ) )
            : 0;

        $gal_attrs = ' id="rincwc-gallery-' . esc_attr( (string) $gid ) . '"'
            . ' data-title="' . esc_attr( strtolower( $title ) ) . '"'
            . ( $has_sel ? ' data-has-selection="1"' : '' )
            . ( $has_cutoff ? ' data-has-cutoff="1"' : '' )
            . ( $is_fully_excluded ? ' data-fully-excluded="1"' : '' );
        echo '<div class="rincwc-gallery"' . $gal_attrs . '>';
        echo '<div class="rincwc-gal-head">';
        echo '<h3><a href="' . esc_url( $permalink ) . '" target="_blank">' . esc_html( $title . ( $pub_date ? " ({$pub_date})" : '' ) ) . '</a>';
        if ( $tags ) {
            echo ' <span class="rincwc-gal-tags">' . esc_html( implode( ' · ', $tags ) ) . '</span>';
        }
        if ( $is_fully_excluded ) {
            echo ' <span class="rincwc-gal-badge badge-excluded">Fully excluded</span>';
        }
        echo '</h3>';
        echo '<button class="button button-small rincwc-accept-all-btn" data-gid="' . esc_attr( (string) $gid ) . '">Accept all</button>';
        echo '<button class="button button-small rincwc-exclude-all-btn" data-gid="' . esc_attr( (string) $gid ) . '">Exclude all</button>';
        echo '</div>';
        echo '<details class="rincwc-details" open><summary>' . esc_html( $summary ) . '</summary>';

        if ( $selected ) {
            echo '<div class="rincwc-section">Selection</div><div class="rincwc-grid">';
            foreach ( $selected as $row ) {
                self::render_candidate( $row, $max_sel_pos );
            }
            echo '</div><div class="rincwc-section">Other Candidates</div><div class="rincwc-grid">';
            foreach ( $others as $row ) {
                self::render_candidate( $row, $max_sel_pos );
            }
            echo '</div>';
        } else {
            echo '<div class="rincwc-grid">';
            foreach ( $imgs as $row ) {
                self::render_candidate( $row, $max_sel_pos );
            }
            echo '</div>';
        }
        echo '<div class="rincwc-back-to-top"><a href="#" class="rincwc-scroll-top" title="Back to top">&uarr; Top</a></div>';
        echo '</details></div>';
    }

    private static function render_candidate( array $row, int $max_sel_pos = 0 ): void {
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
        $is_excluded = ! empty( $row['excluded'] );
        $is_sel = in_array( $status, [ 'SELECTED', 'APPROVED' ], true ) && $sel_crop !== '';
        $image_key = "{$gid}:{$aid}";
        $max_scale = RinCWC_Data::max_crop_scale( $orig_w, $orig_h );
        $geom = RinCWC_Data::crop_geometry( $row, $row, $sel_crop ?: 'center' );

        // The true full-resolution original — not wp_get_attachment_url()/'large', both
        // of which return WP's auto-scaled "-scaled.jpg" derivative when the original
        // exceeds the big-image threshold.
        $original_url = wp_get_original_image_url( $aid ) ?: ( wp_get_attachment_url( $aid ) ?: (string) $row['src_url'] );
        $thumb        = (string) $row['src_url'];
        $thumb_is_wm  = false;
        $wm_4k_url    = null;
        if ( $is_sel && $wm_applied ) {
            $wm_thumb_f = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg";
            if ( file_exists( $wm_thumb_f ) ) {
                // Grid thumbnail only — small on purpose, this is just for layout.
                $thumb       = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}_1080p_wm.jpg" );
                $thumb_is_wm = true;
            }
            $wm_4k_f = RINCWC_CROPS_DIR . "{$slug}_{$pos}_{$sel_crop}_wm.jpg";
            if ( file_exists( $wm_4k_f ) ) {
                $wm_4k_url = content_url( "uploads/wallpaper-crops/{$slug}_{$pos}_{$sel_crop}_wm.jpg" );
            }
        }
        // Lightbox/arrow-nav never uses the small grid thumbnail — the point is to
        // inspect quality, so it's always either the full original or the 4K crop.
        $lightbox_url = $wm_4k_url ?: $original_url;

        // "Compare" needs the selection's best available file even before a watermark
        // is applied (raw 4K crop), so resolve it independently of $wm_4k_url above.
        $selection_url = null;
        if ( $is_sel ) {
            if ( $wm_4k_url ) {
                $selection_url = $wm_4k_url;
            } else {
                $raw_4k_f = RINCWC_CROPS_DIR . "{$slug}_{$aid}_{$sel_crop}.jpg";
                if ( file_exists( $raw_4k_f ) ) {
                    $selection_url = content_url( "uploads/wallpaper-crops/{$slug}_{$aid}_{$sel_crop}.jpg" );
                }
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
            'scaledUrl'   => $lightbox_url,
            'originalUrl' => $original_url,
            'selectionUrl' => $selection_url,
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

        $card_cls = 'rincwc-card status-' . strtolower( $status ) . ( $is_sel ? ' is-selected' : '' ) . ( $is_excluded ? ' is-excluded' : '' );
        echo '<div class="' . esc_attr( $card_cls ) . '" data-c="' . $card_data . '" data-key="' . esc_attr( $image_key ) . '">';
        echo '<div class="rincwc-thumb-wrap">';
        echo '<img class="rincwc-thumb" src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $fname ) . '" loading="lazy">';
        self::render_badge( $status, $is_sel, $wm_corner, $wm_applied, $is_excluded );
        echo '</div>';

        $show_cutoff = ! $is_sel && ! $is_excluded && $pos > $max_sel_pos;
        echo '<div class="rincwc-info">';
        echo '<div class="rincwc-fname">' . esc_html( $fname ) . '</div>';
        echo '<div class="rincwc-dims">' . esc_html( "{$orig_w}x{$orig_h} · img {$pos}/{$row['total']}" ) . '</div>';
        if ( $thumb_is_wm ) {
            echo '<a href="#" class="rincwc-view-original" data-url="' . esc_url( $original_url ) . '" data-alt="' . esc_attr( $fname ) . '">View original</a>';
        }
        if ( $selection_url ) {
            echo '<a href="#" class="rincwc-compare-link" data-original="' . esc_url( $original_url ) . '" data-selection="' . esc_url( $selection_url ) . '">Compare to original</a>';
        }
        if ( $show_cutoff ) {
            echo '<button class="button button-small rincwc-cutoff-btn" data-gid="' . esc_attr( (string) $gid ) . '" data-pos="' . esc_attr( (string) $pos ) . '" title="Exclude this image and all after it">Set cutoff here</button>';
        }
        if ( $is_excluded ) {
            echo '<button class="button button-small rincwc-include-from-btn" data-gid="' . esc_attr( (string) $gid ) . '" data-pos="' . esc_attr( (string) $pos ) . '" title="Include this image and everything before it">Include through here</button>';
        }
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

        self::render_approval_row( $status, $is_sel, $wm_corner, $wm_applied );
        self::render_crop_links( $row, $is_sel, $sel_crop, $wm_applied );
        self::render_comments( $image_key );
        echo '</div>';
    }

    private static function render_badge( string $status, bool $is_sel, string $wm_corner, bool $wm_applied, bool $is_excluded = false ): void {
        if ( $is_excluded ) {
            echo '<span class="rincwc-badge badge-excluded">Excluded</span>';
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

    private static function render_approval_row( string $status, bool $is_sel, string $wm_corner, bool $wm_applied = false ): void {
        if ( ! $wm_applied && $status !== 'APPROVED' ) {
            echo '<div class="rincwc-approve-row hidden"></div>';
            return;
        }
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

    /**
     * "X selected, Y (other) candidates, Z excluded · best Wpx" — always computed from
     * the set's full row list, not whatever subset the active filter is currently
     * rendering, so the breakdown stays accurate regardless of which filter matched
     * this set. Zero-count segments are omitted; "candidates" becomes "other
     * candidates" once there's at least one selection, since at that point the
     * remaining un-selected candidates are "other" relative to what's been picked.
     */
    private static function build_gallery_summary( array $imgs ): string {
        $selected_count  = 0;
        $candidate_count = 0;
        $excluded_count  = 0;
        foreach ( $imgs as $row ) {
            if ( ! empty( $row['excluded'] ) ) {
                $excluded_count++;
            } elseif ( in_array( $row['status'], [ RinCWC_Data::STATUS_SELECTED, RinCWC_Data::STATUS_APPROVED ], true ) ) {
                $selected_count++;
            } else {
                $candidate_count++;
            }
        }

        $parts = [];
        if ( $selected_count > 0 ) {
            $parts[] = "{$selected_count} selected";
        }
        if ( $candidate_count > 0 ) {
            $noun    = $candidate_count === 1 ? 'candidate' : 'candidates';
            $label   = $selected_count > 0 ? "other {$noun}" : $noun;
            $parts[] = "{$candidate_count} {$label}";
        }
        if ( $excluded_count > 0 ) {
            $parts[] = "{$excluded_count} excluded";
        }

        $best_w = max( array_map( fn( $row ) => (int) $row['orig_w'], $imgs ) );
        return implode( ', ', $parts ) . ' · best ' . $best_w . 'px';
    }

    private static function variant_label( string $variant ): string {
        return ucwords( str_replace( '-', ' ', $variant ) );
    }

    private static function matching_category_tags( int $gid ): array {
        $terms = get_the_terms( $gid, 'envira-category' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }
        return array_values( array_filter( array_map( fn( $term ) => $term->name, $terms ), fn( $name ) =>
            str_starts_with( $name, 'Model:' ) || str_starts_with( $name, 'Dustrat' )
        ) );
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
