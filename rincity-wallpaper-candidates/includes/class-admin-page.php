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

        $preview = null;
        $notice  = '';

        if ( isset( $_POST['rincwc_action'], $_POST['rincwc_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_scan_gallery' ) ) {
            $gallery_id = (int) ( $_POST['rincwc_gallery_id'] ?? 0 );
            if ( $_POST['rincwc_action'] === 'scan_preview' ) {
                $preview = RinCity_Wallpaper_Scanner::scan_gallery( $gallery_id, false );
            } elseif ( $_POST['rincwc_action'] === 'scan_commit' ) {
                $preview = RinCity_Wallpaper_Scanner::scan_gallery( $gallery_id, true );
                $notice  = sprintf( 'Scan committed: %d candidate rows written.', (int) ( $preview['inserted'] ?? 0 ) );
            }
        }

        $rows = RinCWC_Data::get_candidate_rows_for_admin();

        echo '<div class="wrap">';
        self::render_inline_styles();
        echo '<h1>Wallpaper Candidates</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        self::render_scan_form( $preview );
        self::render_inventory( $rows );
        echo '</div>';
    }

    private static function render_scan_form( ?array $preview ): void {
        $selected = (int) ( $_POST['rincwc_gallery_id'] ?? 0 );
        echo '<div class="rincwc-panel">';
        echo '<h2>Scan Gallery</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="scan_preview">';
        echo '<label for="rincwc_gallery_id">Envira gallery</label> ';
        echo '<select id="rincwc_gallery_id" name="rincwc_gallery_id">';
        foreach ( RinCWC_Data::envira_galleries() as $post ) {
            echo '<option value="' . esc_attr( $post->ID ) . '"' . selected( $selected, (int) $post->ID, false ) . '>';
            echo esc_html( $post->post_title . ' #' . $post->ID );
            echo '</option>';
        }
        echo '</select> ';
        submit_button( 'Dry Run', 'secondary', 'submit', false );
        echo '</form>';

        if ( is_array( $preview ) ) {
            if ( ! empty( $preview['error'] ) ) {
                echo '<p class="notice notice-error inline"><span>' . esc_html( $preview['error'] ) . '</span></p>';
            } else {
                $rows = $preview['rows'] ?? [];
                echo '<h3>' . esc_html( $preview['title'] ?? 'Scan result' ) . '</h3>';
                echo '<p>' . esc_html( count( $rows ) ) . ' landscape candidates found. Cutoff after position ' . esc_html( (int) ( $preview['cutoff'] ?? 0 ) ?: 'none' ) . '.</p>';
                if ( $rows ) {
                    echo '<form method="post" class="rincwc-commit-form">';
                    wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
                    echo '<input type="hidden" name="rincwc_action" value="scan_commit">';
                    echo '<input type="hidden" name="rincwc_gallery_id" value="' . esc_attr( $preview['gallery_id'] ) . '">';
                    submit_button( 'Commit Scan to Database', 'primary', 'submit', false );
                    echo '</form>';
                    self::render_rows_table( $rows );
                }
            }
        }

        echo '</div>';
    }

    private static function render_inventory( array $rows ): void {
        echo '<h2>Database Inventory</h2>';
        if ( empty( $rows ) ) {
            echo '<p>No candidates in the v3 database yet. Run a scan above.</p>';
            return;
        }
        self::render_rows_table( $rows );
    }

    private static function render_rows_table( array $rows ): void {
        echo '<table class="widefat striped rincwc-scan-table">';
        echo '<thead><tr><th>Preview</th><th>Gallery</th><th>Position</th><th>Resolution</th><th>Status</th><th>Excluded</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $src = $row['src_url'] ?? wp_get_attachment_url( (int) ( $row['attach_id'] ?? 0 ) );
            echo '<tr>';
            echo '<td><img src="' . esc_url( $src ) . '" alt="" loading="lazy"></td>';
            echo '<td>' . esc_html( ( $row['gallery_title'] ?? '' ) . ' #' . ( $row['gallery_id'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( ( $row['position'] ?? '' ) . ' / ' . ( $row['total'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( (int) ( $row['orig_w'] ?? 0 ) ) . ' x ' . number_format_i18n( (int) ( $row['orig_h'] ?? 0 ) ) ) . '</td>';
            echo '<td>' . esc_html( $row['status'] ?? 'CANDIDATE' ) . '</td>';
            echo '<td>' . ( ! empty( $row['excluded'] ) ? 'yes' : 'no' ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_inline_styles(): void {
        echo '<style>
        .rincwc-panel{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}
        .rincwc-panel h2{margin-top:0}
        .rincwc-commit-form{margin:10px 0}
        .rincwc-scan-table img{width:180px;height:auto;display:block}
        .rincwc-scan-table th{white-space:nowrap}
        </style>';
    }
}
