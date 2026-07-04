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
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.' ) );
        }

        $notice = self::handle_posts();
        $watermarks = RinCWC_Data::get_watermarks();
        $gallery_wm = RinCWC_Data::get_gallery_watermarks();
        $galleries  = RinCWC_Data::envira_galleries();

        echo '<div class="wrap rincwc-watermarks">';
        self::render_styles();
        echo '<h1>Wallpaper Watermarks</h1>';
        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<h2>Registered Files</h2>';
        echo '<table class="widefat striped rincwc-wm-table"><thead><tr><th>Preview</th><th>Name</th><th>Path</th><th>Default</th><th>Actions</th></tr></thead><tbody>';
        foreach ( $watermarks as $wm ) {
            $url = self::url_for_path( $wm['file_path'] );
            echo '<tr>';
            echo '<td>' . ( $url ? '<img src="' . esc_url( $url ) . '" alt="">' : '' ) . '</td>';
            echo '<td>' . esc_html( $wm['name'] ) . '</td>';
            echo '<td class="path">' . esc_html( $wm['file_path'] ) . '</td>';
            echo '<td>' . ( ! empty( $wm['is_default'] ) ? '<span class="rincwc-pill">Default</span>' : '' ) . '</td>';
            echo '<td>';
            if ( empty( $wm['is_default'] ) ) {
                self::post_button( 'set_default', 'Set default', [ 'wm_id' => (int) $wm['id'] ], 'secondary' );
                if ( ! RinCWC_Data::watermark_in_use( (int) $wm['id'] ) ) {
                    self::post_button( 'delete', 'Delete', [ 'wm_id' => (int) $wm['id'] ], 'link-delete' );
                } else {
                    echo '<span class="description">In use</span>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';

        echo '<h2>Upload Watermark</h2>';
        echo '<form method="post" enctype="multipart/form-data" class="rincwc-panel">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="upload">';
        echo '<p><label>Name<br><input type="text" name="rincwc_wm_name" class="regular-text" required></label></p>';
        echo '<p><input type="file" name="rincwc_wm_file" accept="image/png" required></p>';
        echo '<p><label><input type="checkbox" name="rincwc_wm_default" value="1"> Set as default</label></p>';
        submit_button( 'Upload watermark', 'primary', 'submit', false );
        echo '</form>';

        echo '<h2>Per-Gallery Override</h2>';
        echo '<form method="post" class="rincwc-panel">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="gallery_overrides">';
        echo '<table class="widefat striped"><thead><tr><th>Gallery</th><th>Watermark</th></tr></thead><tbody>';
        foreach ( $galleries as $gallery ) {
            echo '<tr><td>' . esc_html( sprintf( '%s (#%d)', $gallery->post_title, $gallery->ID ) ) . '</td><td>';
            echo '<select name="gallery_wm[' . esc_attr( $gallery->ID ) . ']">';
            echo '<option value="0">Default watermark</option>';
            foreach ( $watermarks as $wm ) {
                echo '<option value="' . esc_attr( $wm['id'] ) . '"' . selected( $gallery_wm[ (int) $gallery->ID ] ?? 0, (int) $wm['id'], false ) . '>'
                    . esc_html( $wm['name'] )
                    . '</option>';
            }
            echo '</select></td></tr>';
        }
        echo '</tbody></table>';
        submit_button( 'Save gallery overrides', 'primary', 'submit', false );
        echo '</form>';

        echo '</div>';
    }

    private static function handle_posts(): string {
        if ( empty( $_POST['rincwc_action'] ) || empty( $_POST['rincwc_nonce'] ) ) {
            return '';
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_watermarks' ) ) {
            return '';
        }

        $action = sanitize_key( wp_unslash( $_POST['rincwc_action'] ) );
        if ( $action === 'set_default' ) {
            return RinCWC_Data::set_default_watermark( (int) ( $_POST['wm_id'] ?? 0 ) ) ? 'Default watermark updated.' : 'Could not update default watermark.';
        }
        if ( $action === 'delete' ) {
            return RinCWC_Data::delete_watermark( (int) ( $_POST['wm_id'] ?? 0 ) ) ? 'Watermark deleted.' : 'Watermark could not be deleted.';
        }
        if ( $action === 'gallery_overrides' ) {
            $map = isset( $_POST['gallery_wm'] ) && is_array( $_POST['gallery_wm'] ) ? wp_unslash( $_POST['gallery_wm'] ) : [];
            foreach ( $map as $gallery_id => $wm_id ) {
                RinCWC_Data::set_gallery_watermark( (int) $gallery_id, (int) $wm_id );
            }
            return 'Gallery watermark overrides saved.';
        }
        if ( $action === 'upload' ) {
            return self::handle_upload();
        }

        return '';
    }

    private static function handle_upload(): string {
        if ( empty( $_FILES['rincwc_wm_file']['tmp_name'] ) ) {
            return 'No file uploaded.';
        }
        if ( ! is_dir( RINCWC_WATERMARKS_DIR ) ) {
            wp_mkdir_p( RINCWC_WATERMARKS_DIR );
        }

        $file = $_FILES['rincwc_wm_file'];
        $name = sanitize_text_field( wp_unslash( $_POST['rincwc_wm_name'] ?? '' ) );
        if ( ! $name ) {
            $name = sanitize_file_name( $file['name'] );
        }

        $filename = wp_unique_filename( RINCWC_WATERMARKS_DIR, sanitize_file_name( $file['name'] ) );
        $dest     = RINCWC_WATERMARKS_DIR . $filename;
        if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return 'Upload failed.';
        }

        RinCWC_Data::add_watermark( $name, $dest, ! empty( $_POST['rincwc_wm_default'] ) );
        return 'Watermark uploaded.';
    }

    private static function post_button( string $action, string $label, array $hidden, string $class ): void {
        echo '<form method="post" class="rincwc-inline-form">';
        wp_nonce_field( 'rincwc_watermarks', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="' . esc_attr( $action ) . '">';
        foreach ( $hidden as $key => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
        }
        submit_button( $label, $class, 'submit', false );
        echo '</form>';
    }

    private static function url_for_path( string $path ): string {
        $upload = wp_upload_dir();
        if ( str_starts_with( $path, trailingslashit( $upload['basedir'] ) ) ) {
            return trailingslashit( $upload['baseurl'] ) . ltrim( substr( $path, strlen( trailingslashit( $upload['basedir'] ) ) ), '/' );
        }
        return '';
    }

    private static function render_styles(): void {
        ?>
        <style>
        .rincwc-wm-table img { max-width: 160px; max-height: 80px; background: #f0f0f1; }
        .rincwc-wm-table .path { word-break: break-all; color: #646970; font-size: 12px; }
        .rincwc-pill { display: inline-block; padding: 2px 7px; border-radius: 3px; background: #00a32a; color: #fff; font-size: 12px; font-weight: 600; }
        .rincwc-panel { background: #fff; border: 1px solid #c3c4c7; padding: 12px; margin-bottom: 18px; }
        .rincwc-inline-form { display: inline-block; margin-right: 6px; }
        </style>
        <?php
    }
}
