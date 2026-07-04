<?php
defined( 'ABSPATH' ) || exit;

final class RinCity_Wallpaper_Admin_Page {

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        add_menu_page(
            'Wallpaper Candidates',
            'Wallpaper',
            'manage_options',
            'rincwc-wallpaper-candidates',
            [ __CLASS__, 'render' ],
            'dashicons-images-alt2',
            58
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.' ) );
        }

        $notice = '';
        $scan   = null;
        $gid    = isset( $_POST['rincwc_gallery_id'] ) ? (int) $_POST['rincwc_gallery_id'] : 0;

        if ( isset( $_POST['rincwc_action'], $_POST['rincwc_nonce'] ) ) {
            $action = sanitize_key( wp_unslash( $_POST['rincwc_action'] ) );
            if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_scan_gallery' ) ) {
                if ( $action === 'scan_preview' && $gid ) {
                    $scan = RinCity_Wallpaper_Scanner::scan_gallery( $gid, false );
                } elseif ( $action === 'scan_commit' && $gid ) {
                    $scan = RinCity_Wallpaper_Scanner::scan_gallery( $gid, true );
                    RinCity_Wallpaper_Scanner::mark_scan_timestamp();
                    $notice = $scan['ok']
                        ? sprintf( 'Scan committed. %d candidate rows written or updated.', (int) $scan['written'] )
                        : ( $scan['error'] ?? 'Scan failed.' );
                }
            }
        }

        $galleries = RinCWC_Data::envira_galleries();
        $rows      = RinCWC_Data::get_candidate_rows_for_admin();

        echo '<div class="wrap rincwc-admin">';
        self::render_inline_styles();
        echo '<h1>Wallpaper Candidates</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<form method="post" class="rincwc-scan-form">';
        wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="scan_preview">';
        echo '<label for="rincwc_gallery_id"><strong>Scan gallery</strong></label> ';
        echo '<select id="rincwc_gallery_id" name="rincwc_gallery_id">';
        echo '<option value="">Select an Envira gallery</option>';
        foreach ( $galleries as $gallery ) {
            echo '<option value="' . esc_attr( $gallery->ID ) . '"' . selected( $gid, (int) $gallery->ID, false ) . '>'
                . esc_html( sprintf( '%s (#%d)', $gallery->post_title, $gallery->ID ) )
                . '</option>';
        }
        echo '</select> ';
        submit_button( 'Dry run', 'secondary', 'submit', false );
        echo '</form>';

        if ( $scan ) {
            self::render_scan_result( $scan, $gid );
        }

        echo '<h2>Current DB Inventory</h2>';
        echo '<p class="description">Excluded rows are retained for audit/migration but hidden from the review page.</p>';
        self::render_inventory( $rows );
        echo '</div>';
    }

    private static function render_scan_result( array $scan, int $gid ): void {
        if ( empty( $scan['ok'] ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html( $scan['error'] ?? 'Scan failed.' ) . '</p></div>';
            return;
        }

        $rows     = $scan['rows'];
        $included = count( array_filter( $rows, fn( $row ) => empty( $row['excluded'] ) ) );
        $excluded = count( $rows ) - $included;

        echo '<h2>Dry Run: ' . esc_html( $scan['gallery_title'] ) . '</h2>';
        echo '<p><strong>' . esc_html( count( $rows ) ) . '</strong> qualifying landscape images found. ';
        echo esc_html( "{$included} included, {$excluded} excluded by cutoff." ) . '</p>';

        echo '<form method="post" class="rincwc-commit-form">';
        wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="scan_commit">';
        echo '<input type="hidden" name="rincwc_gallery_id" value="' . esc_attr( $gid ) . '">';
        submit_button( 'Commit scan to DB', 'primary', 'submit', false );
        echo '</form>';

        self::render_scan_rows( $rows );
    }

    private static function render_scan_rows( array $rows ): void {
        if ( empty( $rows ) ) {
            echo '<p>No candidate images matched the scanner filters.</p>';
            return;
        }

        echo '<table class="widefat striped rincwc-scan-table"><thead><tr>';
        echo '<th>Preview</th><th>Position</th><th>Attachment</th><th>Resolution</th><th>Status</th><th>Path</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $scan_row ) {
            $row = $scan_row['row'];
            echo '<tr class="' . ( ! empty( $scan_row['excluded'] ) ? 'is-excluded' : '' ) . '">';
            echo '<td><img src="' . esc_url( $row['src_url'] ) . '" alt="" loading="lazy"></td>';
            echo '<td>' . esc_html( $row['position'] . ' of ' . $row['total'] ) . '</td>';
            echo '<td>' . esc_html( $row['attach_id'] ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) $row['orig_w'] ) . ' x ' . number_format_i18n( (int) $row['orig_h'] ) ) . '</td>';
            echo '<td>' . esc_html( ! empty( $scan_row['excluded'] ) ? 'Excluded' : 'Candidate' ) . '</td>';
            echo '<td class="path">' . esc_html( $row['original_path'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_inventory( array $rows ): void {
        if ( empty( $rows ) ) {
            echo '<p>No rows have been scanned into the v3 database yet.</p>';
            return;
        }

        $by_gallery = [];
        foreach ( $rows as $row ) {
            $by_gallery[ (int) $row['gallery_id'] ][] = $row;
        }

        foreach ( $by_gallery as $gid => $gallery_rows ) {
            $post     = get_post( $gid );
            $title    = $post ? $post->post_title : ( $gallery_rows[0]['gallery_title'] ?? "Gallery {$gid}" );
            $included = count( array_filter( $gallery_rows, fn( $row ) => empty( $row['excluded'] ) ) );
            $selected = count( array_filter( $gallery_rows, fn( $row ) => in_array( $row['status'], [ 'SELECTED', 'APPROVED' ], true ) ) );
            $approved = count( array_filter( $gallery_rows, fn( $row ) => $row['status'] === 'APPROVED' ) );

            echo '<details class="rincwc-inv-gallery">';
            echo '<summary><strong>' . esc_html( $title ) . '</strong> ';
            echo esc_html( sprintf( '(%d included, %d excluded, %d selected, %d approved)', $included, count( $gallery_rows ) - $included, $selected, $approved ) );
            echo '</summary>';
            echo '<table class="widefat striped"><thead><tr><th>Position</th><th>Attachment</th><th>Resolution</th><th>Status</th><th>Excluded</th></tr></thead><tbody>';
            foreach ( $gallery_rows as $row ) {
                echo '<tr>';
                echo '<td>' . esc_html( $row['position'] . ' of ' . $row['total'] ) . '</td>';
                echo '<td>' . esc_html( $row['attach_id'] ) . '</td>';
                echo '<td>' . esc_html( (int) $row['orig_w'] . ' x ' . (int) $row['orig_h'] ) . '</td>';
                echo '<td>' . esc_html( $row['status'] ) . '</td>';
                echo '<td>' . esc_html( ! empty( $row['excluded'] ) ? 'yes' : 'no' ) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></details>';
        }
    }

    private static function render_inline_styles(): void {
        ?>
        <style>
        .rincwc-scan-form,
        .rincwc-commit-form {
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 12px;
            margin: 12px 0;
        }
        .rincwc-scan-form select { min-width: 360px; }
        .rincwc-scan-table img {
            width: 180px;
            height: auto;
            display: block;
        }
        .rincwc-scan-table .path {
            max-width: 460px;
            word-break: break-all;
            font-size: 12px;
            color: #646970;
        }
        .rincwc-scan-table tr.is-excluded td {
            color: #777;
            background: #f6f7f7;
        }
        .rincwc-inv-gallery { margin: 8px 0; }
        .rincwc-inv-gallery summary { cursor: pointer; padding: 8px 0; }
        </style>
        <?php
    }
}
