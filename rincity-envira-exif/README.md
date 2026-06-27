# rincity-envira-exif

**Version:** 1.0.0  
**Deploy path:** `wp-content/plugins/rincity-envira-exif/`

## Description

Appends a single representative camera EXIF line to each Envira gallery page, positioned at the bottom of `.post-content` — visually between the gallery grid and `footer.post-footer` (author / social share area). All images in a set are typically shot with the same camera, so one representative sample per page is sufficient.

Renders nothing if no camera EXIF is present (e.g. galleries exported through Photoshop, which strips Make/Model).

## Data sources

1. **`_envira_exif` post meta** (primary) — populated by the Envira EXIF plugin on first parse. Richer: includes separate Make/Model, pre-formatted ShutterSpeed, and Lens.
2. **`_wp_attachment_metadata['image_meta']`** (fallback) — set by WordPress on upload for all JPEG files. Available even without the Envira EXIF plugin. Provides camera, aperture, shutter\_speed, iso, focal\_length (no Lens).

The first gallery image with a non-empty `Make` or `Model` (primary) or `camera` (fallback) is used.

## Output

```html
<div class="rincity-gallery-exif">
  Canon EOS R10 · f/5.6 · 1/100s · ISO 4000 · 24mm · EF-S24mm f/2.8 STM
</div>
```

Fields included only if non-empty, joined with `·`. Order: camera, aperture, shutter speed, ISO, focal length, lens (lens only from `_envira_exif`).

## Assets

| File | Description |
|------|-------------|
| `rincity-envira-exif.php` | Plugin main file |
| `assets/rincity-envira-exif.css` | Block styling |

## Deploy

```bash
make rincity-envira-exif
```

Then activate the plugin in WP Admin → Plugins.

## Changelog

- **1.0.0** — Initial release. Per-gallery EXIF block on `is_singular('envira')` pages via `the_content` filter.
