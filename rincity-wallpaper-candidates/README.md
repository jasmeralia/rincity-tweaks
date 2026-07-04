# rincity-wallpaper-candidates

**Version:** 3.0.0  
**Deploy path:** `wp-content/plugins/rincity-wallpaper-candidates/`

## Description

Admin-only WordPress plugin for scanning Envira galleries, selecting wallpaper crops,
applying watermarks, approving publish-ready images, and syncing the wallpaper download
galleries.

## Admin pages

- **Wallpaper** — scan a single Envira gallery, dry-run results, and commit candidates to the v3 database.
- **Review** — choose preset or custom 2D crops, set watermark corners, approve/unapprove, generate crops, apply watermarks, and publish approved images.
- **Watermarks** — upload/register watermark PNGs, set the default watermark, delete unused files, and set per-gallery overrides.
- **Settings** — configure 4K/1440p/1080p target galleries, scanner cutoffs, and the test approval toggle.

## Storage

Version 3 stores state in `wp_rincwc_*` tables. CSV files from v2 are only read once
during migration.

## Deploy

```bash
make rincity-wallpaper-candidates
```

## Changelog

- **3.0.0** — Rewrote wallpaper candidates around DB-backed images/selections/watermarks, 2D crop controls, approval workflow, and Envira gallery sync.
- **1.0.0** — Initial release.
