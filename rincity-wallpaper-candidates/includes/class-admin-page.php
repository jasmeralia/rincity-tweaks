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

        $preview    = null;
        $notice     = '';
        $prescan_id = (int) ( $_GET['prescan'] ?? 0 );

        if ( isset( $_POST['rincwc_action'], $_POST['rincwc_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rincwc_nonce'] ) ), 'rincwc_scan_gallery' ) ) {
            $gallery_id = (int) ( $_POST['rincwc_gallery_id'] ?? 0 );
            $action     = sanitize_key( $_POST['rincwc_action'] );
            if ( $gallery_id ) {
                $preview = RinCity_Wallpaper_Scanner::scan_gallery( $gallery_id, $action === 'commit_scan' );
                $notice  = $preview['message'] ?? '';
            }
        }

        $summary   = RinCity_Wallpaper_Scanner::database_summary();
        $galleries = RinCWC_Data::scan_target_galleries();

        echo '<div class="wrap rincwc-admin">';
        self::render_inline_styles();
        echo '<h1>Wallpaper Candidates</h1>';

        if ( $notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $notice ) . '</p></div>';
        }

        echo '<div class="rincwc-admin-panel">';
        echo '<h2>Scan Gallery</h2>';
        echo '<input type="text" id="rincwc-gallery-search" placeholder="Filter galleries…" class="rincwc-gallery-search">';
        echo '<form method="post">';
        wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
        echo '<input type="hidden" name="rincwc_action" value="dry_run">';
        echo '<select name="rincwc_gallery_id" id="rincwc-gallery-select" class="rincwc-gallery-select">';
        echo '<option value="">Select an Envira gallery</option>';
        foreach ( $galleries as $gallery ) {
            $label    = sprintf( '%s (%d, %s)', $gallery->post_title, $gallery->ID, date( 'Y-m-d', strtotime( $gallery->post_date ) ) );
            $selected = selected( $prescan_id, (int) $gallery->ID, false );
            echo '<option value="' . esc_attr( $gallery->ID ) . '"' . $selected . '>' . esc_html( $label ) . '</option>';
        }
        echo '</select> ';
        submit_button( 'Dry Run Scan', 'secondary', 'submit', false );
        echo '</form>';
        echo '</div>';

        self::render_scan_all( $galleries );

        if ( $preview ) {
            self::render_preview( $preview );
        }

        self::render_summary( $summary );

        echo '</div>';
    }

    /**
     * Scans every target gallery client-side, one REST call per gallery, so a
     * server with hundreds of galleries (e.g. a fresh install with no scan
     * history yet) can't hit PHP's max_execution_time in one request.
     */
    private static function render_scan_all( array $galleries ): void {
        $ids = wp_json_encode( array_values( array_map( static fn( $g ) => (int) $g->ID, $galleries ) ) );

        echo '<div class="rincwc-admin-panel">';
        echo '<h2>Scan All Galleries</h2>';
        echo '<p>Scans every target gallery (<strong>' . count( $galleries ) . '</strong> total) and commits new/updated candidates to the database — useful the first time this plugin runs on a server, or to pick up galleries added since the last scan.</p>';
        echo '<button type="button" id="rincwc-scan-all" class="button button-secondary">Scan All Galleries</button>';
        echo '<div id="rincwc-scan-all-progress" hidden><div class="rincwc-scan-all-progress-track"><span></span></div><p id="rincwc-scan-all-status"></p></div>';
        echo '</div>';
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var button   = document.getElementById('rincwc-scan-all');
            var progress = document.getElementById('rincwc-scan-all-progress');
            var status   = document.getElementById('rincwc-scan-all-status');
            var track    = progress ? progress.querySelector('span') : null;
            var galleryIds = <?php echo $ids; ?>;
            var nonce = '<?php echo esc_js( wp_create_nonce( 'wp_rest' ) ); ?>';
            var restUrl = '<?php echo esc_url_raw( rest_url( 'rincity/v1/wpc/scan-gallery' ) ); ?>';

            if (!button) return;

            button.addEventListener('click', function() {
                button.disabled = true;
                progress.hidden = false;
                var totals = { new: 0, updated: 0, candidates: 0, errors: 0 };

                function scanNext(index) {
                    if (index >= galleryIds.length) {
                        status.textContent = 'Done: ' + totals.new + ' new, ' + totals.updated +
                            ' updated, ' + totals.candidates + ' candidates across ' + galleryIds.length +
                            ' galleries' + (totals.errors ? ' (' + totals.errors + ' errors)' : '') +
                            '. Reloading…';
                        window.location.reload();
                        return;
                    }
                    track.style.width = (index / galleryIds.length * 100) + '%';
                    status.textContent = 'Scanning gallery ' + (index + 1) + '/' + galleryIds.length + '…';

                    fetch(restUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                        credentials: 'same-origin',
                        body: JSON.stringify({ gallery_id: galleryIds[index], commit: true })
                    }).then(function(response) { return response.json(); }).then(function(result) {
                        if (result && result.ok && result.counts) {
                            totals.new += result.counts.new || 0;
                            totals.updated += result.counts.updated || 0;
                            totals.candidates += result.counts.candidates || 0;
                        } else {
                            totals.errors++;
                        }
                        scanNext(index + 1);
                    }).catch(function() {
                        totals.errors++;
                        scanNext(index + 1);
                    });
                }

                scanNext(0);
            });
        });
        </script>
        <?php
    }

    private static function render_summary( array $summary ): void {
        $review_base = admin_url( 'admin.php?page=rincwc-review' );
        $scan_base   = admin_url( 'admin.php?page=rincwc-wallpaper-candidates' );

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

        echo '<input type="text" id="rincwc-summary-search" placeholder="Filter galleries…" class="rincwc-gallery-search" style="margin:8px 0">';

        echo '<table id="rincwc-summary-table" class="widefat striped rincwc-sortable">';
        echo '<thead><tr>';
        echo '<th data-col="0" class="sortable">Gallery</th>';
        echo '<th data-col="1" class="sortable">Pub date</th>';
        echo '<th data-col="2" class="sortable num">Images</th>';
        echo '<th data-col="3" class="sortable num">Excluded</th>';
        echo '<th data-col="4" class="sortable num">Selected</th>';
        echo '<th data-col="5" class="sortable num">Approved</th>';
        echo '<th data-col="6" class="sortable num">Candidates</th>';
        echo '<th>Actions</th>';
        echo '</tr></thead><tbody>';
        foreach ( $summary['galleries'] as $gallery_id => $gallery ) {
            $selected    = (int) ( $gallery['selected'] ?? 0 );
            $approved    = (int) ( $gallery['approved'] ?? 0 );
            $candidates  = $gallery['images'] - $gallery['excluded'] - $selected - $approved;
            $review_url  = esc_url( $review_base . '#rincwc-gallery-' . $gallery_id );
            $rescan_url  = esc_url( $scan_base . '&prescan=' . $gallery_id );
            echo '<tr data-title="' . esc_attr( strtolower( $gallery['title'] ) ) . '">';
            echo '<td>' . esc_html( $gallery['title'] ) . ' <span class="description">#' . esc_html( (string) $gallery_id ) . '</span></td>';
            echo '<td>' . esc_html( $gallery['pub_date'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( (string) $gallery['images'] ) . '</td>';
            echo '<td>' . esc_html( (string) $gallery['excluded'] ) . '</td>';
            echo '<td>' . esc_html( (string) $selected ) . '</td>';
            echo '<td>' . esc_html( (string) $approved ) . '</td>';
            echo '<td>' . esc_html( (string) $candidates ) . '</td>';
            echo '<td class="rincwc-actions"><a href="' . $review_url . '">Review</a> · <a href="' . $rescan_url . '">Rescan</a></td>';
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

        $seen       = (int) ( $counts['seen'] ?? 0 );
        $candidates = (int) ( $counts['candidates'] ?? 0 );
        $new        = (int) ( $counts['new'] ?? 0 );
        $updated    = (int) ( $counts['updated'] ?? 0 );
        $portrait   = (int) ( $counts['portrait'] ?? 0 );
        $too_small  = (int) ( $counts['too_small'] ?? 0 );
        $scale_fail = (int) ( $counts['scale_fail'] ?? 0 );
        $no_file    = (int) ( $counts['no_file'] ?? 0 );
        $no_dims    = (int) ( $counts['no_dims'] ?? 0 );
        echo '<p><strong>' . esc_html( (string) $seen ) . '</strong> gallery images checked. ';
        echo '<strong>' . esc_html( (string) $candidates ) . '</strong> 4K landscape candidates';
        if ( $new || $updated ) {
            echo ' (' . esc_html( (string) $new ) . ' new, ' . esc_html( (string) $updated ) . ' updated)';
        }
        echo '.</p>';
        $skip_parts = [];
        if ( $portrait )  { $skip_parts[] = "{$portrait} portrait"; }
        if ( $too_small ) { $skip_parts[] = "{$too_small} <4K"; }
        if ( $scale_fail ){ $skip_parts[] = "{$scale_fail} too narrow for 16:9"; }
        if ( $no_file )   { $skip_parts[] = "{$no_file} file not found"; }
        if ( $no_dims )   { $skip_parts[] = "{$no_dims} unreadable"; }
        if ( $skip_parts ) {
            echo '<p>Skipped: ' . esc_html( implode( ', ', $skip_parts ) ) . '.</p>';
        }

        if ( ( $preview['message'] ?? '' ) !== 'Scan committed.' ) {
            echo '<form method="post" class="rincwc-commit-form">';
            wp_nonce_field( 'rincwc_scan_gallery', 'rincwc_nonce' );
            echo '<input type="hidden" name="rincwc_action" value="commit_scan">';
            echo '<input type="hidden" name="rincwc_gallery_id" value="' . esc_attr( (string) ( $preview['gallery_id'] ?? 0 ) ) . '">';
            submit_button( 'Commit These Results to DB', 'primary', 'submit', false );
            echo '</form>';
        }

        echo '<table class="widefat striped rincwc-scan-table"><thead><tr>';
        echo '<th>Pos</th><th>Attachment</th><th>File</th><th>Dimensions</th><th>Status</th><th>Reason</th>';
        echo '</tr></thead><tbody>';
        foreach ( $preview['rows'] as $row ) {
            $status = sanitize_html_class( $row['status'] ?? 'skipped' );
            echo '<tr class="rincwc-row-' . esc_attr( $status ) . '">';
            echo '<td>' . esc_html( ( $row['position'] ?? '' ) . '/' . ( $row['total'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( (string) ( $row['attach_id'] ?? '' ) ) . '</td>';
            echo '<td>' . esc_html( $row['filename'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( ( $row['width'] ?? 0 ) && ( $row['height'] ?? 0 ) ? $row['width'] . '×' . $row['height'] : '-' ) . '</td>';
            echo '<td>' . esc_html( $row['status'] ?? '' ) . '</td>';
            echo '<td>' . esc_html( $row['reason'] ?? '' ) . '</td>';
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
        .rincwc-gallery-search { width: 320px; max-width: 100%; margin-bottom: 6px; display: block; }
        .rincwc-gallery-select { min-width: 420px; max-width: 100%; margin-top: 4px; }
        .rincwc-commit-form { margin: 0 0 1em; }
        .rincwc-scan-table .rincwc-row-new td { background: #edfaef; }
        .rincwc-scan-table .rincwc-row-existing td { background: #f0f6fc; color: #555; }
        .rincwc-scan-table .rincwc-row-skipped td { color: #999; font-style: italic; }
        .rincwc-sortable th.sortable { cursor: pointer; user-select: none; white-space: nowrap; }
        .rincwc-sortable th.sortable::after { content: ' ↕'; opacity: 0.4; font-size: 11px; }
        .rincwc-sortable th.sort-asc::after { content: ' ↑'; opacity: 1; }
        .rincwc-sortable th.sort-desc::after { content: ' ↓'; opacity: 1; }
        #rincwc-summary-table .rincwc-actions { white-space: nowrap; }
        .rincwc-scan-all-progress-track { height: 14px; max-width: 700px; background: #dcdcde; border-radius: 3px; overflow: hidden; margin-top: 8px; }
        .rincwc-scan-all-progress-track span { display: block; width: 0; height: 100%; background: #2271b1; transition: width .15s; }
        </style>
        <script>
        document.addEventListener('DOMContentLoaded', function() {

            // --- Scan gallery dropdown search ---
            var galSearch = document.getElementById('rincwc-gallery-search');
            var galSelect = document.getElementById('rincwc-gallery-select');
            if (galSearch && galSelect) {
                var allOptions = Array.from(galSelect.options).map(function(o) {
                    return { value: o.value, text: o.text };
                });
                galSearch.addEventListener('input', function() {
                    var q = galSearch.value.toLowerCase();
                    var cur = galSelect.value;
                    while (galSelect.options.length) galSelect.remove(0);
                    allOptions.forEach(function(o) {
                        if (!q || !o.value || o.text.toLowerCase().indexOf(q) !== -1) {
                            var opt = document.createElement('option');
                            opt.value = o.value;
                            opt.text = o.text;
                            if (o.value === cur) opt.selected = true;
                            galSelect.add(opt);
                        }
                    });
                });
            }

            // --- Summary table search ---
            var sumSearch = document.getElementById('rincwc-summary-search');
            var tbl = document.getElementById('rincwc-summary-table');
            if (sumSearch && tbl) {
                sumSearch.addEventListener('input', function() {
                    var q = sumSearch.value.toLowerCase();
                    Array.from(tbl.tBodies[0].rows).forEach(function(row) {
                        row.style.display = (!q || row.dataset.title.indexOf(q) !== -1) ? '' : 'none';
                    });
                });
            }

            // --- Summary table column sort ---
            if (tbl) {
                var sortCol = -1, sortDir = 1;
                tbl.querySelectorAll('th.sortable').forEach(function(th) {
                    th.addEventListener('click', function() {
                        var col = parseInt(th.dataset.col, 10);
                        var isNum = th.classList.contains('num');
                        if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }
                        tbl.querySelectorAll('th.sortable').forEach(function(h) {
                            h.classList.remove('sort-asc', 'sort-desc');
                        });
                        th.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
                        var tbody = tbl.tBodies[0];
                        var rows = Array.from(tbody.rows);
                        rows.sort(function(a, b) {
                            var av = a.cells[col] ? a.cells[col].textContent.trim() : '';
                            var bv = b.cells[col] ? b.cells[col].textContent.trim() : '';
                            if (isNum) {
                                av = parseFloat(av) || 0;
                                bv = parseFloat(bv) || 0;
                                return (av - bv) * sortDir;
                            }
                            return av.localeCompare(bv) * sortDir;
                        });
                        rows.forEach(function(r) { tbody.appendChild(r); });
                    });
                });
            }
        });
        </script>
        <?php
    }
}
