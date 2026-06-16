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

        // Handle Re-scan.
        if (
            isset( $_POST['rincwc_action'], $_POST['rincwc_nonce'] ) &&
            $_POST['rincwc_action'] === 'rescan' &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_rescan' )
        ) {
            RinCity_Wallpaper_Scanner::clear_cache();
            RinCity_Wallpaper_Scanner::get_results( true );
            wp_safe_redirect( add_query_arg( 'rincwc_notice', 'rescanned', self::page_url() ) );
            exit;
        }

        // Handle settings save (clears cache and rescans).
        if (
            isset( $_POST['rincwc_action'], $_POST['rincwc_nonce'] ) &&
            $_POST['rincwc_action'] === 'save_settings' &&
            wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_save_settings' )
        ) {
            $tail_pct = max( 0, min( 100, (int) ( $_POST['rincwc_tail_exclude_pct'] ?? 33 ) ) );
            $min_tier = sanitize_key( $_POST['rincwc_min_tier'] ?? '1080p' );
            if ( ! in_array( $min_tier, [ '1080p', '1440p', '4k' ], true ) ) {
                $min_tier = '1080p';
            }
            update_option( 'rincwc_tail_exclude_pct', $tail_pct );
            update_option( 'rincwc_min_tier', $min_tier );
            RinCity_Wallpaper_Scanner::clear_cache();
            RinCity_Wallpaper_Scanner::get_results( true );
            wp_safe_redirect( add_query_arg( 'rincwc_notice', 'settings_saved', self::page_url() ) );
            exit;
        }

        $sort      = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'newest';
        $results   = RinCity_Wallpaper_Scanner::get_results();
        $timestamp = RinCity_Wallpaper_Scanner::get_timestamp();
        $results   = self::sort_results( $results, $sort );

        $tail_pct = (int) get_option( 'rincwc_tail_exclude_pct', 33 );
        $min_tier = (string) get_option( 'rincwc_min_tier', '1080p' );

        ?>
        <div class="wrap">
        <?php self::render_inline_styles(); ?>
        <h1>Wallpaper Candidates</h1>

        <?php if ( isset( $_GET['rincwc_notice'] ) ) :
            $notice = sanitize_key( $_GET['rincwc_notice'] );
        ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php if ( $notice === 'rescanned' ) : ?>Scan complete.
                <?php elseif ( $notice === 'settings_saved' ) : ?>Settings saved and scan refreshed.
                <?php endif; ?>
            </p></div>
        <?php endif; ?>

        <div class="rincwc-settings-wrap">
            <form method="post">
                <?php wp_nonce_field( 'rincwc_save_settings', 'rincwc_nonce' ); ?>
                <input type="hidden" name="rincwc_action" value="save_settings">
                <table class="form-table rincwc-settings-table">
                    <tr>
                        <th scope="row">
                            <label for="rincwc_tail_pct">Exclude last N% of each set</label>
                        </th>
                        <td>
                            <input type="number" id="rincwc_tail_pct" name="rincwc_tail_exclude_pct"
                                   min="0" max="100" value="<?php echo esc_attr( $tail_pct ); ?>"
                                   style="width:70px">
                            <span class="description">
                                Images beyond position <?php echo esc_html( 100 - $tail_pct ); ?>% through the set are skipped.
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="rincwc_min_tier">Minimum qualifying tier</label>
                        </th>
                        <td>
                            <select id="rincwc_min_tier" name="rincwc_min_tier">
                                <option value="1080p" <?php selected( $min_tier, '1080p' ); ?>>1080p — Full HD (1920×1080)</option>
                                <option value="1440p" <?php selected( $min_tier, '1440p' ); ?>>1440p — QHD (2560×1440)</option>
                                <option value="4k"    <?php selected( $min_tier, '4k' ); ?>>4K — UHD (3840×2160)</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Save Settings &amp; Re-scan', 'primary', 'submit', false ); ?>
            </form>

            <form method="post" style="display:inline;margin-left:0.75em">
                <?php wp_nonce_field( 'rincwc_rescan', 'rincwc_nonce' ); ?>
                <input type="hidden" name="rincwc_action" value="rescan">
                <?php submit_button( 'Re-scan', 'secondary', 'submit', false ); ?>
            </form>

            <?php if ( $timestamp ) : ?>
                <span class="rincwc-timestamp">
                    Last scanned: <?php echo esc_html( $timestamp ); ?>
                </span>
            <?php else : ?>
                <span class="rincwc-timestamp">Not yet scanned — click Re-scan to begin.</span>
            <?php endif; ?>
        </div>

        <?php if ( empty( $results ) ) : ?>
            <p class="rincwc-empty">No landscape wallpaper candidates found matching the current settings.</p>
        <?php else :
            $total_candidates = array_sum( array_map( fn( $g ) => count( $g['candidates'] ), $results ) );
        ?>
            <p class="rincwc-summary">Found <strong><?php echo esc_html( $total_candidates ); ?></strong>
            candidate<?php echo $total_candidates !== 1 ? 's' : ''; ?> across
            <strong><?php echo esc_html( count( $results ) ); ?></strong>
            <?php echo count( $results ) !== 1 ? 'galleries' : 'gallery'; ?>.</p>

            <div class="rincwc-sort-bar">
                <strong>Sort by:</strong>
                <?php
                $sorts = [
                    'newest'      => 'Newest first',
                    'oldest'      => 'Oldest first',
                    'most_faves'  => 'Most favorites',
                    'least_faves' => 'Least favorites',
                    'comments'    => 'Comments',
                ];
                foreach ( $sorts as $key => $label ) :
                    $url   = add_query_arg( 'sort', $key, self::page_url() );
                    $class = $sort === $key ? 'rincwc-sort-link rincwc-sort-active' : 'rincwc-sort-link';
                ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </div>

            <?php foreach ( $results as $gallery ) :
                self::render_gallery_card( $gallery );
            endforeach; ?>
        <?php endif; ?>
        </div>
        <?php
    }

    private static function page_url(): string {
        return admin_url( 'admin.php?page=rincwc-wallpaper-candidates' );
    }

    private static function sort_results( array $results, string $sort ): array {
        usort( $results, function ( $a, $b ) use ( $sort ) {
            switch ( $sort ) {
                case 'oldest':
                    return strcmp( $a['published_at'], $b['published_at'] );
                case 'most_faves':
                    return $b['favorites_count'] <=> $a['favorites_count'];
                case 'least_faves':
                    return $a['favorites_count'] <=> $b['favorites_count'];
                case 'comments':
                    return $b['comment_count'] <=> $a['comment_count'];
                default: // newest
                    return strcmp( $b['published_at'], $a['published_at'] );
            }
        } );
        return $results;
    }

    private static function render_gallery_card( array $gallery ): void {
        $cat_parts = [];
        foreach ( $gallery['categories'] as $cat ) {
            if ( $cat['url'] ) {
                $cat_parts[] = '<a href="' . esc_url( $cat['url'] ) . '">' . esc_html( $cat['name'] ) . '</a>';
            } else {
                $cat_parts[] = esc_html( $cat['name'] );
            }
        }
        $cat_html = implode( ', ', $cat_parts );
        $fav_n    = $gallery['favorites_count'];
        $com_n    = $gallery['comment_count'];
        ?>
        <div class="rincwc-gallery-card">
            <h2 class="rincwc-gallery-title">
                <a href="<?php echo esc_url( $gallery['url'] ); ?>"><?php echo esc_html( $gallery['title'] ); ?></a>
            </h2>
            <p class="rincwc-gallery-meta">
                <?php if ( $cat_html ) : ?>
                    <?php echo wp_kses( $cat_html, [ 'a' => [ 'href' => [] ] ] ); ?> &bull;
                <?php endif; ?>
                Published <?php echo esc_html( date( 'M j, Y', strtotime( $gallery['published_at'] ) ) ); ?>
                &bull; <?php echo esc_html( $fav_n ); ?> favorite<?php echo $fav_n !== 1 ? 's' : ''; ?>
                &bull; <?php echo esc_html( $com_n ); ?> comment<?php echo $com_n !== 1 ? 's' : ''; ?>
            </p>
            <table class="widefat striped rincwc-candidates-table">
                <thead>
                    <tr>
                        <th>Preview</th>
                        <th>Position</th>
                        <th>Resolution</th>
                        <th>File size</th>
                        <th>Aspect</th>
                        <th>4K</th>
                        <th>1440p</th>
                        <th>1080p</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $gallery['candidates'] as $candidate ) :
                        self::render_candidate_row( $candidate );
                    endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private static function render_candidate_row( array $c ): void {
        ?>
        <tr>
            <td class="rincwc-thumb-cell">
                <img src="<?php echo esc_url( $c['thumbnail_url'] ); ?>"
                     alt=""
                     width="240"
                     loading="lazy"
                     style="width:240px;height:auto;display:block">
            </td>
            <td><?php echo esc_html( $c['position'] . ' of ' . $c['set_total'] ); ?></td>
            <td><?php echo esc_html( number_format( $c['width'] ) . ' × ' . number_format( $c['height'] ) ); ?></td>
            <td><?php echo esc_html( self::format_filesize( $c['filesize'] ) ); ?></td>
            <td><?php echo esc_html( $c['aspect_ratio'] . ':1' ); ?></td>
            <?php foreach ( [ '4k', '1440p', '1080p' ] as $tier_key ) :
                $tier = $c['tiers'][ $tier_key ];
            ?>
            <td class="rincwc-tier-cell <?php echo $tier['qualifies'] ? 'rincwc-tier-yes' : 'rincwc-tier-no'; ?>">
                <?php if ( $tier['qualifies'] ) : ?>
                    &#10003;
                    <?php if ( $tier['direction'] !== 'none' && $tier['crop_px'] > 0 ) : ?>
                        <span class="rincwc-crop-note">
                            <?php if ( $tier['direction'] === 'sides' ) : ?>
                                crop <?php echo esc_html( $tier['crop_px'] ); ?>px/side
                            <?php else : ?>
                                crop <?php echo esc_html( $tier['crop_px'] ); ?>px top+bottom
                            <?php endif; ?>
                            <br><span class="rincwc-pct">(<?php echo esc_html( $tier['pct_retained'] ); ?>% kept)</span>
                        </span>
                    <?php endif; ?>
                <?php else : ?>
                    <span class="rincwc-tier-dash">&mdash;</span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
        </tr>
        <?php
    }

    private static function format_filesize( int $bytes ): string {
        if ( $bytes >= 1024 * 1024 ) {
            return round( $bytes / ( 1024 * 1024 ), 1 ) . ' MB';
        }
        if ( $bytes >= 1024 ) {
            return round( $bytes / 1024 ) . ' KB';
        }
        return $bytes . ' B';
    }

    private static function render_inline_styles(): void {
        ?>
        <style>
        .rincwc-settings-wrap {
            background: #fff;
            border: 1px solid #c3c4c7;
            padding: 1em 1.5em 0.5em;
            margin-bottom: 1.5em;
        }
        .rincwc-settings-table { margin-top: 0; }
        .rincwc-timestamp {
            color: #666;
            font-size: 0.88em;
            margin-left: 1.5em;
            vertical-align: middle;
        }
        .rincwc-sort-bar { margin: 0.75em 0 1em; }
        .rincwc-sort-link { margin-right: 1em; }
        .rincwc-sort-active { font-weight: bold; }
        .rincwc-gallery-card { margin-bottom: 2.5em; }
        .rincwc-gallery-title { margin-bottom: 0.2em; }
        .rincwc-gallery-meta { margin-top: 0; color: #555; font-size: 0.9em; }
        .rincwc-candidates-table th { white-space: nowrap; }
        .rincwc-thumb-cell { width: 256px; vertical-align: top; padding: 4px; }
        .rincwc-tier-cell { vertical-align: top; text-align: center; }
        .rincwc-tier-yes { color: #1a7a1a; }
        .rincwc-tier-no { color: #bbb; }
        .rincwc-crop-note { display: block; font-size: 0.82em; color: #555; margin-top: 0.2em; }
        .rincwc-pct { color: #888; }
        .rincwc-tier-dash { color: #ccc; }
        .rincwc-empty { color: #666; font-style: italic; }
        </style>
        <?php
    }
}
