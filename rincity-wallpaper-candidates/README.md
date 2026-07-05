# rincity-wallpaper-candidates

**Version:** 3.1.0
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
  with:
  - Search-as-you-type filter on gallery name.
  - Filters: **Ready for review** (has a selection), **Initial inspection** (untouched —
    no selection and no cutoff set yet), **Approved** (status = APPROVED), and
    **Clear filters** to reset search + filter state.
  - A summary line of sets with an approved image / ready for review / untouched.
  - Per-gallery **Exclude all** and **Accept all** cutoff buttons, plus a per-image
    "Set cutoff here" button that excludes that image and everything after it in the
    set. Cutoffs are stored per gallery (`excluded_after` position) and applied
    retroactively to already-scanned rows.
  - Per-image crop selection: five preset crops (top/center-top/center/center-bottom/
    bottom) or a custom 2D crop overlay with a zoom slider, X/Y sliders, scroll-to-zoom
    (cursor-anchored), and touch pan/pinch support.
  - Watermark corner selection per image, batch "Generate pending crops" and "Apply
    pending watermarks" actions.
  - Approve/Unapprove per card, gated to the `rincity`/`rincity_member` accounts or the
    "Allow test approve" setting.
  - Per-image threaded comments (add/edit/delete own comments).
  - "Publish to galleries" batch action to sync approved+watermarked images out.
  - Expand all / Collapse all, and deep links to a specific gallery
    (`#rincwc-gallery-{id}`) that auto-expand that gallery and collapse the rest.
- **Watermarks** — upload/register watermark PNGs, set the default watermark, delete
  unused files, and set per-gallery overrides (with its own search-as-you-type filter).
- **Settings** — configure the 4K/1440p/1080p target Envira galleries, the test-approve
  toggle, and per-gallery scanner cutoffs.

## Storage

Version 3 stores state in `wp_rincwc_*` tables (images, selections, watermarks,
gallery_wm, comments). CSV files from v2 are only read once during migration.

## Deploy

```bash
make rincity-wallpaper-candidates
```

## Changelog

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
