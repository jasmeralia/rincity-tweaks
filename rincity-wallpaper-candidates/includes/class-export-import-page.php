<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Export_Import_Page {

    private static string $hook = '';

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        self::$hook = add_submenu_page(
            'rincwc-wallpaper-candidates',
            'Wallpaper Export/Import',
            'Export/Import',
            'manage_options',
            'rincwc-export-import',
            [ __CLASS__, 'render' ]
        );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        if ( $hook !== self::$hook ) {
            return;
        }
        wp_enqueue_script(
            'rincwc-export-import',
            RINCWC_PLUGIN_URL . 'assets/export-import.js',
            [ 'wp-api-fetch' ],
            RINCWC_VERSION,
            true
        );
        wp_add_inline_script( 'rincwc-export-import', 'var rincwcImportCfg=' . wp_json_encode( [
            'restBase' => rest_url( 'rincity/v1/wpc/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
        ] ) . ';', 'before' );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.' ) );
        }

        echo '<div class="wrap rincwc-export-import-page">';
        echo '<h1>Wallpaper Export / Import</h1>';
        self::render_styles();
        self::render_export_import();
        echo '</div>';
    }

    private static function render_export_import(): void {
        echo '<div class="rincwc-wm-box rincwc-import-box">';
        echo '<p>Export all review activity, comments, cutoffs, watermark assignments, and embedded watermark PNGs as one portable JSON file.</p>';
        echo '<p><a class="button button-secondary" href="' . esc_url( add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( 'rincity/v1/wpc/export' ) ) ) . '">Download JSON export</a></p>';
        echo '<hr>';
        echo '<form id="rincwc-import-form">';
        echo '<label for="rincwc-import-file"><strong>Import JSON</strong></label> ';
        echo '<input id="rincwc-import-file" type="file" accept="application/json,.json" required> ';
        echo '<button id="rincwc-import-dry-run" type="submit" class="button button-secondary">Preview import</button>';
        echo '</form>';
        echo '<p id="rincwc-import-status" aria-live="polite"></p>';
        echo '<div id="rincwc-import-report" hidden></div>';
        echo '<p id="rincwc-import-actions" hidden>';
        echo '<label><input id="rincwc-import-force" type="checkbox"> Force all imported image values and comment conflicts</label> ';
        echo '<button id="rincwc-import-apply" type="button" class="button button-primary">Apply import</button>';
        echo '</p>';
        echo '<div id="rincwc-import-progress" hidden><div class="rincwc-import-progress-track"><span></span></div><p></p></div>';
        echo '<div id="rincwc-import-final" hidden></div>';
        echo '</div>';
    }

    private static function render_styles(): void {
        echo '<style>
            .rincwc-wm-box{background:#fff;border:1px solid #c3c4c7;padding:12px;margin:12px 0}
            .rincwc-import-box hr{margin:18px 0}
            .rincwc-import-box table{margin:10px 0 18px}
            .rincwc-import-box td{vertical-align:top}
            .rincwc-import-box .rincwc-import-value{max-width:420px;white-space:pre-wrap;word-break:break-word}
            .rincwc-import-box .rincwc-import-conflict{display:grid;grid-template-columns:1fr 1fr;gap:12px;min-width:520px}
            .rincwc-import-box .rincwc-import-conflict>div{border:1px solid #dcdcde;padding:8px;background:#fff}
            .rincwc-import-progress-track{height:14px;max-width:700px;background:#dcdcde;border-radius:3px;overflow:hidden}
            .rincwc-import-progress-track span{display:block;width:0;height:100%;background:#2271b1;transition:width .15s}
            .rincwc-import-errors{color:#b32d2e}
            .rincwc-import-fallbacks{color:#996800}
        </style>';
    }
}
