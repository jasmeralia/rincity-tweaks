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
            $action     = sanitize_key( $_POST['rincwc_action'] );
            if ( $gallery_id ) {
                $preview = RinCity_Wallpaper_Scanner::scan_gallery( $gallery_id, $action === 'commit_scan' );
                $notice  = $preview['message'] ?? '';
            }
        }

        $summary   = RinCity_Wallpaper_Scanner::database_summary();
        $galleries = RinCWC_Data::envira_galleries();

        echo '<div class="wrap rincwc-admin">';
        self::render_inline_styles();
        echo '<h1>Wallpaper Candidates</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<div class="rincwc-admin-panel">';
        echo '<h2>Scan Gallery</h2>';
        echo '<form method="post">';
        wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="dry_run">';
        echo '<select name="rincwc_gallery_id" class="rincwc-gallery-select">';
        echo '<option value="">Select an Envira gallery</option>';
        foreach ( $galleries as $gallery ) {
            $label = sprintf( '%s (%d, %s)', $gallery->post_title, $gallery->ID, date( 'Y-m-d', strtotime( $gallery->post_date ) ) );
            echo '<option value="' . esc_attr( $gallery->ID ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select> ';
        submit_button( 'Dry Run Scan', 'secondary', 'submit', false );
        echo '</form>';
        echo '</div>';

        self::render_summary( $summary );

        if ( $preview ) {
            self::render_preview( $preview );
        }

        echo '</div>';
    }

    private static function render_summary( array $summary ): void {
        echo '<div class="rincwc-admin-panel">';
        echo '<h2>Database Summary</h2>';
        echo '<p><strong>' . esc_html( $summary['visible'] ) . '</strong> visible candidates, ';
        echo '<strong>' . esc_html( $summary['excluded'] ) . '</strong> excluded, ';
        echo '<strong>' . esc_html( $summary['selected'] ) . '</strong> selected, ';
        echo '<strong>' . esc_html( $summary['approved'] ) . '</strong> approved.</p>';

        if ( empty( $summary['galleries'] ) ) {
            echo '<p>No DB candidates yet. Run a scan to populate the catalog.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Gallery</th><th>Images</th><th>Excluded</th></tr></thead><tbody>';
        foreach ( $summary['galleries'] as $gallery_id => $gallery ) {
            echo '<tr>';
            echo '<td>' . esc_html( $gallery['title'] ) . ' <span class="description">#' . esc_html( (string) $gallery_id ) . '</span></td>';
            echo '<td>' . esc_html( (string) $gallery['images'] ) . '</td>';
            echo '<td>' . esc_html( (string) $gallery['excluded'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_preview( array $preview ): void {
        $gallery = $preview['gallery'] ?? [];
        $counts  = $preview['counts'] ?? [];

        echo '<div class="rincwc-admin-panel">';
        echo '<h2>Scan Results';
        if ( ! empty( $gallery['title'] ) ) {
            echo ': ' . esc_html( $gallery['title'] );
        }
        echo '</h2>';

        if ( empty( $preview['ok'] ) ) {
            echo '<p>' . esc_html( $preview['message'] ?? 'Scan failed.' ) . '</p></div>';
            return;
        }

        echo '<p><strong>' . esc_html( (string) ( $counts['candidates'] ?? 0 ) ) . '</strong> candidates, ';
        echo '<strong>' . esc_html( (string) ( $counts['excluded'] ?? 0 ) ) . '</strong> excluded, ';
        echo '<strong>' . esc_html( (string) ( $counts['skipped'] ?? 0 ) ) . '</strong> skipped.</p>';

        if ( ( $preview['message'] ?? '' ) !== 'Scan committed.' ) {
            echo '<form method="post" class="rincwc-commit-form">';
            wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
            echo '<input type="hidden" name="rincwc_action" value="commit_scan">';
            echo '<input type="hidden" name="rincwc_gallery_id" value="' . esc_attr( (string) ( $gallery['id'] ?? 0 ) ) . '">';
            submit_button( 'Commit These Results to DB', 'primary', 'submit', false );
            echo '</form>';
        }

        echo '<table class="widefat striped rincwc-scan-table"><thead><tr>';
        echo '<th>Position</th><th>Attachment</th><th>File</th><th>Dimensions</th><th>Status</th><th>Reason</th>';
        echo '</tr></thead><tbody>';
        foreach ( $preview['rows'] as $row ) {
            $status = sanitize_html_class( $row['status'] );
            echo '<tr class="rincwc-row-' . esc_attr( $status ) . '">';
            echo '<td>' . esc_html( $row['position'] . ' of ' . $row['total'] ) . '</td>';
            echo '<td>' . esc_html( (string) $row['attach_id'] ) . '</td>';
            echo '<td>' . esc_html( $row['filename'] ) . '</td>';
            echo '<td>' . esc_html( $row['width'] && $row['height'] ? $row['width'] . ' x ' . $row['height'] : '-' ) . '</td>';
            echo '<td>' . esc_html( $row['status'] ) . '</td>';
            echo '<td>' . esc_html( $row['reason'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    private static function render_inline_styles(): void {
        ?>
        <style>
        .rincwc-admin-panel {
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 1em 1.25em;
            margin: 1em 0;
        }
        .rincwc-admin-panel h2 { margin-top: 0; }
        .rincwc-gallery-select { min-width: 420px; max-width: 100%; }
        .rincwc-commit-form { margin: 0 0 1em; }
        .rincwc-scan-table .rincwc-row-candidate td { background: #f0f6fc; }
        .rincwc-scan-table .rincwc-row-excluded td { color: #996800; }
        .rincwc-scan-table .rincwc-row-skipped td { color: #777; }
        </style>
        <?php
    }
}
