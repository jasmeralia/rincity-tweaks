<?php
defined( 'ABSPATH' ) || exit;

final class RinCWC_Settings_Page {

    private static string $hook = '';

    public static function register(): void {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu' ] );
    }

    public static function add_menu(): void {
        self::$hook = add_submenu_page(
            'rincwc-wallpaper-candidates',
            'Wallpaper Settings',
            'Settings',
            'manage_options',
            'rincwc-settings',
            [ __CLASS__, 'render' ]
        );
    }

    public static function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.' ) );
        }

        $notice = self::handle_save();
        $settings = RinCWC_Data::get_settings();
        $galleries = RinCWC_Data::envira_galleries();

        echo '<div class="wrap rincwc-settings">';
        echo '<h1>Wallpaper Settings</h1>';
        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field( 'rincwc_save_settings', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="save">';

        echo '<h2>Publish Galleries</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        self::gallery_select_row( '4K gallery (3840x2160)', 'publish_galleries[4k]', (int) $settings['publish_galleries']['4k'], $galleries );
        self::gallery_select_row( '1440p gallery (2560x1440)', 'publish_galleries[1440p]', (int) $settings['publish_galleries']['1440p'], $galleries );
        self::gallery_select_row( '1080p gallery (1920x1080)', 'publish_galleries[1080p]', (int) $settings['publish_galleries']['1080p'], $galleries );
        echo '</tbody></table>';

        echo '<h2>Approval</h2>';
        echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row">Allow test approve</th><td>';
        echo '<label><input type="checkbox" name="allow_test_approve" value="1"' . checked( ! empty( $settings['allow_test_approve'] ), true, false ) . '> Allow all admins to approve for testing</label>';
        echo '</td></tr></tbody></table>';

        echo '<h2>Scanner Cutoffs</h2>';
        echo '<p class="description">A value of 0 means no cutoff. If set, images after that position are stored as excluded and hidden from review.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Gallery</th><th>Exclude after position</th></tr></thead><tbody>';
        foreach ( $galleries as $gallery ) {
            $value = (int) ( $settings['excluded_after'][ (int) $gallery->ID ] ?? 0 );
            echo '<tr><td>' . esc_html( sprintf( '%s (#%d)', $gallery->post_title, $gallery->ID ) ) . '</td>';
            echo '<td><input type="number" min="0" step="1" name="excluded_after[' . esc_attr( $gallery->ID ) . ']" value="' . esc_attr( $value ) . '" class="small-text"></td></tr>';
        }
        echo '</tbody></table>';

        submit_button( 'Save Settings' );
        echo '</form></div>';
    }

    private static function handle_save(): string {
        if ( empty( $_POST['rincwc_action'] ) || $_POST['rincwc_action'] !== 'save' || empty( $_POST['rincwc_nonce'] ) ) {
            return '';
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_save_settings' ) ) {
            return '';
        }

        $publish = isset( $_POST['publish_galleries'] ) && is_array( $_POST['publish_galleries'] )
            ? wp_unslash( $_POST['publish_galleries'] )
            : [];
        $cutoffs = isset( $_POST['excluded_after'] ) && is_array( $_POST['excluded_after'] )
            ? wp_unslash( $_POST['excluded_after'] )
            : [];

        $clean_publish = [
            '4k'    => (int) ( $publish['4k'] ?? 0 ),
            '1440p' => (int) ( $publish['1440p'] ?? 0 ),
            '1080p' => (int) ( $publish['1080p'] ?? 0 ),
        ];
        $clean_cutoffs = [];
        foreach ( $cutoffs as $gallery_id => $position ) {
            $gallery_id = (int) $gallery_id;
            $position   = (int) $position;
            if ( $gallery_id > 0 && $position > 0 ) {
                $clean_cutoffs[ $gallery_id ] = $position;
            }
        }

        RinCWC_Data::update_settings( [
            'publish_galleries'  => $clean_publish,
            'allow_test_approve' => ! empty( $_POST['allow_test_approve'] ),
            'excluded_after'     => $clean_cutoffs,
        ] );

        return 'Settings saved.';
    }

    private static function gallery_select_row( string $label, string $name, int $selected, array $galleries ): void {
        echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
        echo '<select name="' . esc_attr( $name ) . '">';
        echo '<option value="0">Select gallery</option>';
        foreach ( $galleries as $gallery ) {
            echo '<option value="' . esc_attr( $gallery->ID ) . '"' . selected( $selected, (int) $gallery->ID, false ) . '>'
                . esc_html( sprintf( '%s (#%d)', $gallery->post_title, $gallery->ID ) )
                . '</option>';
        }
        echo '</select></td></tr>';
    }
}
