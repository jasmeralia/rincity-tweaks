<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Watermarks {

    private static string $hook = '';

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        self::$hook = add_submenu_page(
            'rincwc-wallpaper-candidates',
            'Wallpaper Watermarks',
            'Watermarks',
            'manage_options',
            'rincwc-watermarks',
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

        $notice = self::handle_post();

        echo '<div class="wrap rincwc-watermarks">';
        echo '<h1>Wallpaper Watermarks</h1>';
        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }
        self::render_styles();
        self::render_upload_form();
        self::render_watermark_table();
        self::render_gallery_overrides();
        self::render_export_import();
        echo '</div>';
    }

    private static function handle_post(): string {
        if ( empty( $_POST['rincwc_wm_action'] ) || empty( $_POST['rincwc_wm_nonce'] ) ) {
            return '';
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_wm_nonce'] ) ), 'rincwc_watermarks' ) ) {
            return '';
        }

        $action = sanitize_key( wp_unslash( $_POST['rincwc_wm_action'] ) );
        if ( $action === 'upload' ) {
            return self::handle_upload();
        }
        if ( $action === 'default' ) {
            $id = (int) ( $_POST['wm_id'] ?? 0 );
            return RinCWC_Data::set_default_watermark( $id ) ? 'Default watermark updated.' : 'Could not update default watermark.';
        }
        if ( $action === 'delete' ) {
            $id = (int) ( $_POST['wm_id'] ?? 0 );
            $wm = RinCWC_Data::get_watermark( $id );
            if ( $wm && RinCWC_Data::delete_watermark( $id ) ) {
                if ( str_starts_with( realpath( $wm['file_path'] ) ?: '', realpath( RINCWC_WATERMARKS_DIR ) ?: RINCWC_WATERMARKS_DIR ) && file_exists( $wm['file_path'] ) ) {
                    wp_delete_file( $wm['file_path'] );
                }
                return 'Watermark deleted.';
            }
            return 'Watermark is default or in use and could not be deleted.';
        }
        if ( $action === 'gallery_overrides' ) {
            $posted = is_array( $_POST['gallery_wm'] ?? null ) ? wp_unslash( $_POST['gallery_wm'] ) : [];
            foreach ( $posted as $gallery_id => $wm_id ) {
                RinCWC_Data::set_gallery_watermark( (int) $gallery_id, (int) $wm_id );
            }
            return 'Gallery watermark overrides saved.';
        }

        return '';
    }

    private static function handle_upload(): string {
        if ( empty( $_FILES['watermark_file']['name'] ) ) {
            return 'Choose a PNG, PSD, or PSB file to upload.';
        }
        if ( ( $_FILES['watermark_file']['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
            return 'Upload failed.';
        }

        $orig_name = $_FILES['watermark_file']['name'];
        $ext       = strtolower( pathinfo( $orig_name, PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, [ 'png', 'psd', 'psb' ], true ) ) {
            return 'Choose a PNG, PSD, or PSB file to upload.';
        }

        wp_mkdir_p( RINCWC_WATERMARKS_DIR );
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( $ext === 'png' ) {
            add_filter( 'upload_dir', [ __CLASS__, 'upload_dir' ] );
            $file = wp_handle_upload( $_FILES['watermark_file'], [
                'test_form' => false,
                'mimes'     => [ 'png' => 'image/png' ],
            ] );
            remove_filter( 'upload_dir', [ __CLASS__, 'upload_dir' ] );

            if ( isset( $file['error'] ) ) {
                return 'Upload failed: ' . $file['error'];
            }
            $dest_path = $file['file'];
        } else {
            // PSD/PSB: wp_handle_upload()'s image-content check (getimagesize() /
            // exif_imagetype()) doesn't recognize Photoshop files, so handle this
            // upload directly and let Imagick validate the file by trying to read it.
            $tmp_name = $_FILES['watermark_file']['tmp_name'];
            if ( ! is_uploaded_file( $tmp_name ) ) {
                return 'Upload failed.';
            }
            if ( ! class_exists( 'Imagick' ) ) {
                return 'Imagick is not available on this server; cannot process PSD/PSB files.';
            }
            try {
                $imagick = new Imagick();
                // Flatten against transparent, not ImageMagick's default white, so a
                // PSD with no background layer doesn't end up with an opaque box
                // behind the watermark.
                $imagick->setBackgroundColor( new ImagickPixel( 'transparent' ) );
                // Frame 0 of a PSD is Photoshop's own merged/flattened composite —
                // exactly "the relevant PNG", no layer picking needed.
                $imagick->readImage( $tmp_name . '[0]' );
                $imagick->setImageFormat( 'png32' ); // force RGBA so alpha survives
                $filename  = wp_unique_filename( RINCWC_WATERMARKS_DIR, sanitize_file_name( pathinfo( $orig_name, PATHINFO_FILENAME ) . '.png' ) );
                $dest_path = trailingslashit( RINCWC_WATERMARKS_DIR ) . $filename;
                $imagick->writeImage( $dest_path );
                $imagick->clear();
                $imagick->destroy();
            } catch ( ImagickException $e ) {
                return 'Could not read PSD/PSB file: ' . $e->getMessage();
            }
        }

        $name = sanitize_text_field( $_POST['watermark_name'] ?? '' );
        if ( ! $name ) {
            $name = basename( $dest_path );
        }
        RinCWC_Data::add_watermark( $name, $dest_path, false );
        return 'Watermark uploaded.';
    }

    public static function upload_dir( array $dirs ): array {
        $dirs['path']   = RINCWC_WATERMARKS_DIR;
        $dirs['url']    = content_url( 'uploads/wallpaper-watermarks' );
        $dirs['subdir'] = '/wallpaper-watermarks';
        return $dirs;
    }

    private static function render_upload_form(): void {
        echo '<h2>Add Watermark</h2>';
        echo '<form method="post" enctype="multipart/form-data" class="rincwc-wm-box">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_wm_nonce' );
        echo '<input type="hidden" name="rincwc_wm_action" value="upload">';
        echo '<label>Name <input type="text" name="watermark_name"></label> ';
        echo '<input type="file" name="watermark_file" accept="image/png,.psd,.psb" required> ';
        echo '<span class="description">PSD/PSB files are flattened to a PNG automatically; the original is discarded.</span> ';
        submit_button( 'Upload watermark', 'primary', 'submit', false );
        echo '</form>';
    }

    private static function render_watermark_table(): void {
        $watermarks = RinCWC_Data::get_watermarks();
        echo '<h2>Registered Watermarks</h2>';
        if ( empty( $watermarks ) ) {
            echo '<p>No watermarks registered.</p>';
            return;
        }

        echo '<table class="widefat striped rincwc-wm-table"><thead><tr><th>Preview</th><th>Name</th><th>Path</th><th>Default</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $watermarks as $wm ) {
            $in_use = RinCWC_Data::watermark_in_use( (int) $wm['id'] );
            echo '<tr>';
            echo '<td>';
            if ( file_exists( $wm['file_path'] ) ) {
                echo '<img src="' . esc_url( self::file_url( $wm['file_path'] ) ) . '" alt="">';
            }
            echo '</td>';
            echo '<td>' . esc_html( $wm['name'] ) . '</td>';
            echo '<td class="path">' . esc_html( $wm['file_path'] ) . '</td>';
            echo '<td>' . ( ! empty( $wm['is_default'] ) ? '<span class="rincwc-default-badge">default</span>' : '' ) . '</td>';
            echo '<td class="actions">';
            if ( empty( $wm['is_default'] ) ) {
                self::action_button( 'default', (int) $wm['id'], 'Set default', 'secondary' );
            }
            if ( empty( $wm['is_default'] ) && ! $in_use ) {
                self::action_button( 'delete', (int) $wm['id'], 'Delete', 'delete' );
            } elseif ( empty( $wm['is_default'] ) ) {
                echo '<span class="description">in use</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function render_gallery_overrides(): void {
        $watermarks = RinCWC_Data::get_watermarks();
        $overrides  = RinCWC_Data::get_gallery_watermarks();
        $galleries  = RinCWC_Data::source_galleries();

        echo '<h2>Per-Gallery Watermark Override</h2>';
        if ( empty( $galleries ) ) {
            echo '<p>No scanned source galleries yet.</p>';
            return;
        }

        echo '<input type="text" id="rincwc-wm-gal-search" placeholder="Filter galleries…" style="width:320px;margin-bottom:6px;display:block">';
        echo '<form method="post" class="rincwc-wm-box">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_wm_nonce' );
        echo '<input type="hidden" name="rincwc_wm_action" value="gallery_overrides">';
        echo '<table id="rincwc-wm-gal-table" class="widefat striped"><thead><tr><th>Source gallery</th><th>Watermark</th></tr></thead><tbody>';
        foreach ( $galleries as $gallery ) {
            $gid = (int) $gallery['gallery_id'];
            echo '<tr data-title="' . esc_attr( strtolower( $gallery['gallery_title'] ) ) . '"><td>' . esc_html( $gallery['gallery_title'] . " (#{$gid})" ) . '</td><td>';
            echo '<select name="gallery_wm[' . esc_attr( $gid ) . ']">';
            echo '<option value="0">Use default</option>';
            foreach ( $watermarks as $wm ) {
                echo '<option value="' . esc_attr( $wm['id'] ) . '"' . selected( $overrides[ $gid ] ?? 0, (int) $wm['id'], false ) . '>' . esc_html( $wm['name'] ) . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        submit_button( 'Save overrides', 'primary', 'submit', false );
        echo '</form>';
        echo '<script>
        (function(){
            var s = document.getElementById("rincwc-wm-gal-search");
            var t = document.getElementById("rincwc-wm-gal-table");
            if (!s || !t) return;
            s.addEventListener("input", function() {
                var q = s.value.toLowerCase();
                Array.from(t.tBodies[0].rows).forEach(function(r) {
                    r.style.display = (!q || r.dataset.title.indexOf(q) !== -1) ? "" : "none";
                });
            });
        })();
        </script>';
    }

    private static function render_export_import(): void {
        echo '<h2>Export / Import Review State</h2>';
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

    private static function action_button( string $action, int $id, string $label, string $class ): void {
        echo '<form method="post" style="display:inline">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_wm_nonce' );
        echo '<input type="hidden" name="rincwc_wm_action" value="' . esc_attr( $action ) . '">';
        echo '<input type="hidden" name="wm_id" value="' . esc_attr( $id ) . '">';
        submit_button( $label, $class, 'submit', false );
        echo '</form> ';
    }

    private static function file_url( string $path ): string {
        $content_dir = wp_normalize_path( WP_CONTENT_DIR );
        $path_norm   = wp_normalize_path( $path );
        if ( str_starts_with( $path_norm, $content_dir ) ) {
            return content_url( ltrim( substr( $path_norm, strlen( $content_dir ) ), '/' ) );
        }
        return '';
    }

    private static function render_styles(): void {
        echo '<style>
            .rincwc-wm-box{background:#fff;border:1px solid #c3c4c7;padding:12px;margin:12px 0}
            .rincwc-wm-table img{max-width:120px;max-height:60px;background:#222;padding:4px}
            .rincwc-wm-table .path{word-break:break-all;color:#646970;font-size:12px}
            .rincwc-default-badge{display:inline-block;background:#2271b1;color:#fff;border-radius:3px;padding:2px 6px;font-size:11px}
            .rincwc-wm-table .actions form{margin-right:4px}
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
