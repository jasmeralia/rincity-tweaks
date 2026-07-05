# rincity-wallpaper-candidates

**Version:** 3.5.0
**Deploy path:** `wp-content/plugins/rincity-wallpaper-candidates/`

## Description

Admin-only WordPress plugin for scanning Envira galleries, selecting wallpaper crops,
applying watermarks, approving publish-ready images, and syncing the wallpaper download
galleries.

## Admin pages

- **Wallpaper** — pick an Envira gallery (search-as-you-type filter over the dropdown),
  dry-run a scan to preview candidates, then commit them to the database. Scan results
  show every image checked, including skip reasons (portrait, too small, too narrow for
  16:9, file not found, unreadable). The gallery picker excludes the three configured
  publish-target galleries and the Envira default gallery — they only ever hold
  already-processed crop output, never source photos. A Database Summary table below
  lists every scanned gallery with Images/Excluded/Selected/Approved/Candidates counts,
  sortable columns, a search-as-you-type filter, and Review/Rescan action links per row.
- **Review** — the main workflow page. Galleries are grouped into cards, newest first,
  each showing any `envira-category` tags starting with `Model:` or `Dustrat` next to
  the title/publish date. Toolbar is two rows: filters + search on the first, batch
  actions on the second.
  - Search-as-you-type filter on gallery name.
  - Filters: **Approved**, **Ready for review** (has a selection), **Initial inspection**
    (untouched — no selection and no cutoff set yet), **Exclusions** (a cutoff is set
    and/or the set is fully excluded), and **Clear filters** to reset search + filter
    state. Every filter button (and Clear filters) reloads the page rather than toggling
    client-side, since PHP renders a different row/gallery subset per filter value.
  - A summary line of sets with an approved image / ready for review / passed initial
    inspection / excluded / untouched.
  - Per-gallery **Exclude all** and **Accept all** cutoff buttons, plus a per-image
    "Set cutoff here" button that excludes that image and everything after it in the
    set, and an **Include from here** button on already-excluded images that raises the
    cutoff back past that position (includes it and everything before it — works the
    same whether the set was partially or fully excluded). No confirmation dialogs on
    any of these; all are one click to undo. Cutoffs use an explicit sentinel: -1 =
    entire gallery excluded, 0 = no cutoff, >0 = exclude from that position onward.
    A gallery with zero visible images shows a "Fully excluded" badge in its header and,
    under the Exclusions filter, its excluded images are shown (each marked with an
    "Excluded" badge) so the decision can be reviewed or undone.
  - Per-image crop selection: five preset crops (top/center-top/center/center-bottom/
    bottom) or a custom 2D crop overlay with a zoom slider, X/Y sliders, scroll-to-zoom
    (cursor-anchored), and touch pan/pinch support.
  - Thumbnail lightbox supports arrow-key or on-screen prev/next navigation between a
    set's images. Resolution-variant download links (4K/1440p/1080p) still open in the
    same lightbox but standalone, with no set navigation.
  - Watermark corner selection per image, batch "Generate pending crops" and "Apply
    pending watermarks" actions.
  - Approve/Unapprove per card, gated to the `rincity`/`rincity_member` accounts or the
    "Allow test approve" setting. Approval is a hard invariant: an image can't reach
    APPROVED without an applied watermark, and any later change that invalidates the
    watermark automatically demotes it back to SELECTED.
  - Per-image threaded comments (add/edit/delete own comments).
  - "Publish to galleries" batch action to sync approved+watermarked images out.
  - Expand all / Collapse all, and deep links to a specific gallery
    (`#rincwc-gallery-{id}`) that auto-expand that gallery and collapse the rest.
- **Watermarks** — upload/register watermark PNGs, set the default watermark, delete
  unused files, and set per-gallery overrides (with its own search-as-you-type filter).
- **Settings** — configure the 4K/1440p/1080p target Envira galleries, the test-approve
  toggle, and per-gallery scanner cutoffs, with a search-as-you-type filter over the
  cutoffs table and a "Fully excluded" badge on any gallery with zero visible images.

## Storage

Version 3 stores state in `wp_rincwc_*` tables (images, selections, watermarks,
gallery_wm, comments). The v2 CSV migration has been removed; a JSON export/import
is planned separately.

## Deploy

```bash
make rincity-wallpaper-candidates
```

## Changelog

- **3.5.0** — Removed the v2→v3 CSV migration entirely (`class-db.php`'s
  `migrate_csv_once()`/`migrate_db_row()`/`migrate_selection_row()`/
  `legacy_offset_to_custom_crop()`/`read_csv_flat()`/`migrate_legacy_comments()`, and
  the `RINCWC_DB_CSV`/`RINCWC_SEL_CSV` constants) — dead code now that v3 has been
  running on live data for a while. A proper JSON export/import is planned separately.
  Cutoffs now use an explicit sentinel scheme: **-1** = entire gallery excluded, **0** =
  no cutoff, **>0** = exclude from that position onward — replacing the old scheme where
  "Accept all" sent a magic `999` and "no cutoff" and "fully excluded" were both stored
  as an absent/zero setting. Excluded images now have an **Include from here** button
  (mirrors "Set cutoff here": raises the cutoff past that position, including it and
  everything before it, whether the set was partially or fully excluded) — Rin can
  reconsider an excluded image without needing the coarser Accept all. Removed the
  confirmation dialogs on Set cutoff here / Exclude all / Accept all, since undoing any
  of them is one click away. `set-gallery-cutoff` REST endpoint now requires `position`
  explicitly instead of silently defaulting to a (now destructive) `-1`. Review-page
  filter buttons and Clear filters now always reload the page instead of toggling
  client-side, since PHP renders a different row/gallery subset per filter — switching
  between filters without a reload could show stale or empty content that belonged to
  whichever filter was active on the last real page load. Lightbox now supports
  arrow-key/on-screen prev-next navigation between a set's images (not the resolution-
  variant download links, which still open standalone).
- **3.4.1** — Fixed the Exclusions filter (introduced in 3.4.0): it was showing every
  card in a matching gallery, including still-active candidates, and never showed images
  excluded via a partial cutoff at all — only fully-excluded sets ever had their excluded
  rows fetched (`get_fully_excluded_gallery_rows()`, which only queried fully-excluded
  gallery IDs). Replaced with `RinCWC_Data::get_excluded_images()`, which fetches every
  excluded row regardless of whether its gallery is partially or fully cut off, and
  narrowed the filter's row selection to excluded rows only. The "Fully excluded" header
  badge now uses the gallery's true (pre-filter) visibility state instead of being
  recomputed from the already-excluded-only row set, so it no longer mislabels
  partially-excluded galleries.
- **3.4.0** — Review page: category tags starting with `Model:` or `Dustrat` now show
  next to the set title/publish date. Toolbar split into two rows (filters/search on
  the first, batch actions on the second) and gained an **Exclusions** filter (cutoff
  set and/or fully excluded). Fully-excluded sets — previously invisible everywhere,
  including to their own "Accept all" undo button — now render under the Exclusions
  filter with a "Fully excluded" header badge and per-image "Excluded" badges. Settings
  page's Scanner Cutoffs table gained a search-as-you-type filter and the same
  "Fully excluded" badge per row. Removed the "Import cutoffs from legacy CSV" button
  and its handler (`RinCWC_Data::migrate_cutoffs_from_csv()`, `apply_cutoffs_to_db()`)
  from the Wallpaper admin page — it read a CSV path nothing writes to anymore. A
  proper JSON export/import is planned separately.
- **3.3.0** — Review page set-count summary now has two more buckets, both placed before
  "untouched sets": **N sets passed initial inspection** (a cutoff is set and the set still
  has visible candidates below it, but nothing selected yet) and **N sets excluded** (every
  scanned image in the set is excluded — via "Exclude all" or a cutoff at/before the first
  position — so the set never appears anywhere else on the page). New
  `RinCWC_Data::count_fully_excluded_galleries()` covers the "excluded" count, since fully
  excluded galleries have zero visible rows and are otherwise invisible to
  `get_visible_images()`.
- **3.2.0** — "Approved with watermark pending" is no longer a reachable state: `approve()`
  now requires `wm_applied` in addition to a crop variant and watermark corner, and any
  change that invalidates a selection's applied watermark (crop change, watermark-corner
  change, or a batch "mark pending" from the Watermarks page) now automatically demotes an
  APPROVED image back to SELECTED instead of leaving it approved with a stale watermark.
  Removed the now-impossible "approved with watermark pending" summary count and badge.
  "Apply pending watermarks" button no longer uses the blue primary style. Untouched-set
  count now explicitly requires at least one CANDIDATE-status image in the set.
- **3.1.2** — Review page: filter order is now Approved / Ready for review / Initial
  inspection (left to right). Fixed the button-size reduction from 3.1.1, which never
  actually took effect — it used a single-class selector that lost the specificity fight
  against WordPress core's `.wp-core-ui .button` rule. Switched to WordPress's own
  `button-small` modifier class instead, applied to all toolbar buttons (filters, Clear
  filters, Generate pending crops, Apply pending watermarks, Publish to galleries).
- **3.1.1** — Review page: filtered galleries/cards are now selected server-side from
  the `filter` query param, so filtered reloads (after a cutoff/exclude-all/accept-all
  action) never flash the full unfiltered list before hiding non-matches. Reordered the
  filter buttons ("Approved" now leftmost, swapped with "Ready for review") and reduced
  their size.
- **3.1.0** — Review page: search-as-you-type gallery filter, "Approved" filter,
  "Clear filters" button, "Accept all" cutoff button (inverse of "Exclude all", clears
  a gallery's cutoff), set-count summary (approved / ready for review / untouched
  sets), and renamed filters ("Selections only" → "Ready for review", "Not yet
  reviewed" → "Initial inspection").
- **3.0.1** — Database Summary columns (Images/Excluded/Selected/Approved/Candidates),
  gallery picker filtering (excludes publish-target and default galleries), approval
  gating, legacy CSV cutoff migration, manual per-gallery/per-image cutoff controls,
  verbose scan output with skip reasons, Imagick-based dimension probing, search
  filtering and Review/Rescan links across admin screens.
- **3.0.0** — Rewrote wallpaper candidates around DB-backed images/selections/watermarks,
  2D crop controls, approval workflow, and Envira gallery sync.
- **1.0.0** — Initial release.
