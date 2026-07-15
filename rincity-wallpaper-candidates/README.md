# rincity-wallpaper-candidates

**Version:** 3.11.3
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
  A **Scan All Galleries** button scans and commits every target gallery in one go —
  one REST call per gallery from the client, with live progress, so a server with
  hundreds of galleries (e.g. a fresh install with no scan history) can't hit PHP's
  `max_execution_time` in a single request; the page reloads once every gallery's done.
- **Review** — the main workflow page. Galleries are grouped into cards, newest first,
  each showing any `envira-category` tags starting with `Model:` or `Dustrat` next to
  the title/publish date. Toolbar is two rows: filters + search on the first, batch
  actions on the second.
  - Search-as-you-type filter on gallery name.
  - Filters: **Approved**, **Ready for review** (has a selection), **Initial inspection**
    (untouched — no selection and no cutoff set yet; disables itself once nothing is
    left untouched, and the set-count summary reads "All sets initially inspected"
    at that point), **Exclusions** (a cutoff is set and/or the set is fully excluded),
    **Comments** (any image with at least one comment, including excluded ones — a
    genuinely good image can still be commented-and-excluded, e.g. for nudity), and
    **Clear filters** to reset search + filter state. Every filter button (and Clear
    filters) reloads the page rather than toggling client-side, since PHP renders a
    different row/gallery subset per filter value.
  - A summary line of sets with an approved image / ready for review / passed initial
    inspection / excluded / untouched.
  - Per-gallery **Exclude all** and **Accept all** cutoff buttons, plus a per-image
    "Set cutoff here" button that excludes that image and everything after it in the
    set, and an **Include through here** button on already-excluded images that raises
    the cutoff back past that position (includes it and everything before it — works the
    same whether the set was partially or fully excluded). No confirmation dialogs on
    any of these; all are one click to undo. Cutoffs use an explicit sentinel: -1 =
    entire gallery excluded, 0 = no cutoff, >0 = exclude from that position onward.
    A gallery with zero visible images shows a "Fully excluded" badge in its header and,
    under the Exclusions filter, its excluded images are shown (each marked with an
    "Excluded" badge) so the decision can be reviewed or undone.
  - A "↑ Top" link at the bottom-right of each set jumps back to the top of the page.
  - Per-image crop selection: five preset crops (top/center-top/center/center-bottom/
    bottom) or a custom 2D crop overlay with a zoom slider, X/Y sliders, scroll-to-zoom
    (cursor-anchored), and touch pan/pinch support.
  - Thumbnail lightbox supports arrow-key or on-screen prev/next navigation between a
    set's images. Never shows the small grid thumbnail — always the true full-resolution
    original or the 4K crop/watermark, since this is detail review, not browsing.
    Resolution-variant download links (4K/1440p/1080p) still open in the same lightbox
    but standalone, with no set navigation. A **Compare to original** link (once a crop
    is selected) opens a side-by-side "Original" / "Selection" overlay, each pane scaled
    independently to make full use of its half of the screen.
  - Watermark corner selection per image, batch "Generate pending crops" and "Apply
    pending watermarks" actions — each processes one image per request and reports
    live progress ("Generating crop 3/57...", with a spinner) instead of one long
    request that hangs until everything's done.
  - Approve/Unapprove per card, gated to the `rincity`/`rincity_member` accounts or the
    "Allow test approve" setting. Approval is a hard invariant: an image can't reach
    APPROVED without an applied watermark, and any later change that invalidates the
    watermark automatically demotes it back to SELECTED.
  - Per-image threaded comments (add/edit/delete own comments).
  - "Publish to galleries" batch action to sync approved+watermarked images out.
  - Expand all / Collapse all, and deep links to a specific gallery
    (`#rincwc-gallery-{id}`) that auto-expand that gallery and collapse the rest.
- **Watermarks** — upload/register watermark PNGs (or PSD/PSB, auto-flattened to a PNG
  via Imagick with the original discarded), set the default watermark, delete unused
  files, and set per-gallery overrides (with its own search-as-you-type filter).
- **Export/Import** — its own admin page that exports all portable review state as
  JSON and imports it through a dry-run diff, opt-in conflict resolution, best-effort
  DB apply, and client-driven crop/watermark regeneration with restored approval
  provenance.
- **Settings** — configure the 4K/1440p/1080p target Envira galleries, the test-approve
  toggle, and per-gallery scanner cutoffs (only galleries with at least one scanned
  candidate row are listed), with a search-as-you-type filter over the cutoffs table
  and a "Fully excluded" badge — and a `-1` displayed value — on any gallery with zero
  visible images, regardless of what its stored cutoff setting actually says.

## Storage

Version 3 stores state in `wp_rincwc_*` tables (images, selections, watermarks,
gallery_wm, comments). The v2 CSV migration has been removed. JSON export/import uses
`gallery_id` + `attach_id` for images, PNG SHA-256 (with name fallback) for watermarks,
and image + author login + creation time for comments. Exported JSON embeds watermark
PNGs but references source gallery images; generated crop/watermark JPEGs are rebuilt
through the existing per-image REST pipeline after import.

## REST API

All routes use the `rincity/v1` namespace and require an authenticated administrator.
Review routes include `POST /wpc/select`, `/wpc/deselect`, `/wpc/approve`,
`/wpc/unapprove`, `/wpc/watermark`, `/wpc/crop-custom`, `/wpc/crop-offset`,
`/wpc/generate-crops`, `/wpc/apply-watermarks`, `/wpc/sync-galleries`, and
`/wpc/set-gallery-cutoff`; queue discovery uses `GET /wpc/pending-crops` and
`GET /wpc/pending-watermarks`. Comments use `GET`/`POST /wpc/comments` and
`PUT`/`DELETE /wpc/comments/{id}`. Watermark records use `GET`/`POST
`/wpc/watermarks`, `DELETE /wpc/watermarks/{id}`, and `POST /wpc/gallery-wm`.
Portable state uses `GET /wpc/export`, `POST /wpc/import/dry-run`, and `POST
/wpc/import/apply`. The apply response supplies per-image regeneration work; the admin
client drives `generate-crops`, `apply-watermarks`, and `approve` sequentially.

## Deploy

```bash
make rincity-wallpaper-candidates
```

## Changelog

- **3.11.3** — Added a "Scan All Galleries" button to the Wallpaper admin page. New
  `POST /wpc/scan-gallery` REST endpoint scans and commits a single gallery (mirroring
  the existing `generate-crops`/`apply-watermarks` per-item pattern); the client loops
  over every target gallery, one REST call each, with a live progress bar, and reloads
  the page once done. Previously `RinCity_Wallpaper_Scanner::scan_all()` existed but
  had no UI or REST entry point — only reachable via `wp eval`. Added to unblock the
  JSON import feature on any server that's never had the scanner run before (e.g.
  gelfling, which currently has zero rows in `wp_rincwc_images`).
- **3.11.2** — Moved the export/import UI off the Watermarks page onto its own
  dedicated "Export/Import" admin page (`RinCWC_Export_Import_Page`); no behavior
  change, just a clearer home for a feature that isn't watermark-specific.
- **3.11.1** — Scoped import regeneration to selections whose own effective watermark actually changes, preserving explicit per-selection watermark pins and unaffected gallery overrides; dry-run watermark rows now warn when an imported PNG matched locally by name but has different content.
- **3.11.0** — Added complete admin-only JSON export/import for review-state images, selections, approval provenance, comments, cutoffs, per-gallery watermark overrides, and embedded watermark PNGs: imports now preview a per-row/per-field diff with ID-mismatch protection and comment provenance/conflict choices, apply database changes best-effort without a transaction, fall back missing users to the importing admin with summary notes, then regenerate crops/watermarks and restore approvals one image at a time through the existing REST endpoints.
- **3.10.0** — Renamed the "N sets passed initial inspection" set-count segment to
  "N sets initially inspected" — the old wording read as a near-duplicate of the
  **Initial inspection** filter button, when the two are actually opposite ends of the
  pipeline (the filter shows *untouched* sets; the count was showing *already-reviewed*
  sets). "Generate pending crops" and "Apply pending watermarks" now report live
  per-item progress ("Generating crop 3/57...", with a spinner) instead of one long
  synchronous request that hangs until the whole batch finishes. New `GET
  /pending-crops` and `GET /pending-watermarks` endpoints list the eligible image IDs;
  `POST /generate-crops` and `POST /apply-watermarks` now take a single `image_id` and
  process just that one image, so the client can loop and update the message between
  each — this also removes the previous risk of a large gallery's batch job hitting
  PHP's `max_execution_time` mid-loop.
- **3.9.1** — Fixed a real bug in the custom 2D crop overlay, introduced by 3.9.0's
  `scaledUrl`/`originalUrl` split: the crop overlay's preview `<img>` was still loading
  `cfg.scaledUrl`, which for an already-selected-and-watermarked image now points at the
  already-cropped 4K file instead of the full original. Since the overlay's box/slider
  math (`displayScales()`) assumes the loaded image spans the full `origW x origH`
  canvas, loading an already-cropped 3840×2160 file there silently misaligned every
  on-screen crop-box calculation against a smaller, already-zoomed-in image — the drawn
  box no longer corresponded to where the crop would actually land. Re-opening the
  overlay for an existing selection now always loads `cfg.originalUrl` (the true
  original) instead. "View original" and "Compare to original" now sit on one line
  separated by `·`, rather than stacked.
- **3.9.0** — The lightbox (and arrow-navigation between a set's images) never shows
  the small grid thumbnail anymore — it's admin-only detail review, so quality matters
  more than load time. It now always uses either the true full-resolution original
  (`wp_get_original_image_url()`, not `wp_get_attachment_url()`/`'large'`, both of which
  return WordPress's auto-scaled "-scaled.jpg" derivative) or the **4K** crop/watermark
  (previously the 1080p file meant for the grid thumbnail leaked into the lightbox
  too). Resolution-variant download links are unaffected — they already opened their
  own specific file. Added a **Compare to original** link (shown once a crop is
  selected, even before its watermark is applied, as long as a crop file exists) that
  opens a new side-by-side overlay: "Original" and "Selection" panes, each scaled
  independently via `object-fit: contain` to make full use of its half of the screen
  regardless of the two images' differing resolutions/aspect ratios.
- **3.8.0** — Once an image is selected and its watermark applied, the thumbnail and
  the lightbox (opened by clicking it, and when arrow-navigating between a set's
  images) now both show that cropped+watermarked version instead of the lightbox
  showing something different from what the thumbnail displays — previously the
  thumbnail already switched to the watermarked crop but clicking it opened the
  original in the lightbox anyway. A new **View original** link appears under the
  filename for these cards specifically, opening the true original standalone (not
  part of the set's arrow navigation). Ordinary not-yet-selected candidates are
  unaffected: the lightbox still opens WordPress's bigger "large" attachment size
  (not the small grid thumbnail), same as before.
- **3.7.1** — Per-set summary line (the collapsed `<summary>` text) reworked: "X
  selected, Y (other) candidates, Z excluded · best Wpx", replacing the old "other"
  with "candidates" (or "other candidates" once there's at least one selection, since
  at that point the rest are "other" relative to what's picked). Zero-count segments
  are omitted. These counts are now always computed from the set's full row list, not
  whatever subset the active filter is currently rendering — e.g. under the Exclusions
  filter a set still shows its true "X selected, Y candidates, Z excluded" breakdown,
  not just the excluded count, even though only the excluded cards are actually shown
  in the grid below. "Best Wpx" now reflects the same full-set data for consistency.
- **3.7.0** — Watermark upload accepts `.psd`/`.psb` in addition to `.png`. Since
  `wp_handle_upload()`'s image-content check (`getimagesize()`/`exif_imagetype()`)
  doesn't recognize Photoshop files, PSD/PSB uploads are handled directly and read via
  Imagick instead: frame `[0]` of a PSD is Photoshop's own merged/flattened composite
  (no layer picking needed), read against a transparent background (not ImageMagick's
  default white) and written out as `png32` so the alpha channel survives intact. The
  original PSD/PSB is discarded; only the derived PNG is stored.
- **3.6.1** — Two Settings page fixes: the Scanner Cutoffs table now only lists
  galleries with at least one candidate row (`RinCWC_Data::scanned_gallery_ids()`),
  instead of every Envira gallery on the site (most had zero rows, especially after
  the sub-4K cleanup). And it now shows `-1` for any gallery flagged "Fully excluded"
  by the row data, instead of trusting the stored setting alone — four galleries were
  excluded via the old "Exclude all" button before the `-1` sentinel existed, leaving
  their setting absent (reads as `0`) despite every row actually being excluded. Their
  stored value has been backfilled to `-1` to match reality, and the display now falls
  back to the row-based truth for any future case like it.
- **3.6.0** — Renamed "Include from here" to **Include through here** (the old name
  read as symmetric with "Set cutoff here", which cuts forward; this one restores
  backward toward the current cutoff, so the wording was misleading). When there are
  zero untouched sets left, the set-count summary now reads "All sets passed initial
  inspection" instead of "0 sets", and the Initial Inspection filter button disables
  itself (nothing to show there). Added a **Comments** filter — surfaces images with at
  least one comment, including excluded ones (comments can exist on an image that was
  later excluded, e.g. a genuinely good shot flagged as unusable for wallpaper use).
  Each set now has a "↑ Top" link at its bottom-right to jump back to the top of the
  page without scrolling back up through a long set.
- **3.5.3** — 3.5.2 went too far: dropping the "Accept all" vs "never touched"
  distinction entirely removed a needed signal, not just a fragile implementation of
  one. Restored it the way the original (pre-3.5.0) code did it — "Accept all" sends a
  cutoff position far beyond any real gallery size (largest sets are under 200 images,
  so 999 has enormous headroom), which flows through the ordinary `>0` partial-cutoff
  code path with no special-casing: nothing gets excluded, but the stored cutoff value
  itself (998) is what marks the set as reviewed and lands it in "passed initial
  inspection" instead of "untouched" or "Initial inspection". No new sentinel or
  key-presence tracking needed — it's just a number, exactly as robust for a future
  JSON export/import as -1 already is.
- **3.5.2** — Reverted 3.5.1's `has_cutoff_decision()` distinction between "Accept all"
  and "never touched" — on reflection, encoding that via settings-key presence (rather
  than the stored value) is exactly the kind of thing a future JSON export/import round
  trip or hand edit would silently drop, and the 3.5.1 regression it was fixing is
  itself evidence of how easy that distinction is to get wrong. Back to a pure 3-value
  scheme: -1 = fully excluded, 0 = no cutoff (whether never reviewed or reviewed and
  accepted — not distinguished), >0 = partial cutoff. "Accept all" sets collapse back
  into the "untouched" bucket and the Initial Inspection filter. Also fixed the Settings
  page's cutoff `<input min="0">`, which would have blocked saving the form (or let the
  browser silently coerce the value) for any fully-excluded gallery now that -1 is a
  real, valid stored value; changed to `min="-1"`.
- **3.5.1** — Fixed a regression from 3.5.0's cutoff sentinel change: "Accept all" now
  sends `position: 0`, and the code used to `unset()` the settings key for that case —
  making an Accept-All'd set indistinguishable from one that had simply never been
  looked at (both read as `get_cutoff() === 0`). "Passed initial inspection" and
  "untouched" collapsed into each other. Fixed by storing `0` explicitly instead of
  unsetting, and adding `RinCWC_Data::has_cutoff_decision()` (checks whether the settings
  key exists at all, independent of its value) for anywhere that needs to know "has this
  set been reviewed" rather than "what's its cutoff position". The set-count summary and
  the Initial Inspection filter now use that instead of `cutoff === 0` / `cutoff > 0`.
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
