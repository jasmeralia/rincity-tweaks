# rincity-image-size-control WordPress Plugin

## Description

Suppresses unused WordPress core and Ashe Pro intermediate image sizes for attachments
that belong to Envira galleries, reducing CPU time and disk use during gallery uploads.

WordPress's `-scaled` file and all Envira-generated crops are intentionally retained.
The desired lightbox/zoom path is:

1. WordPress generates the `-scaled` image; Envira renders it as the initial lightbox image.
2. `rincity-envira-zoom` background-loads the pre-scale original.
3. After decode, the original is swapped in for high-resolution zoom.

## Suppressed sizes (Envira gallery attachments only)

| Size | Source |
|---|---|
| `medium_large` | WordPress core |
| `large` | WordPress core |
| `1536x1536` | WordPress core |
| `2048x2048` | WordPress core |
| `ashe-slider-grid-thumbnail` | Ashe Pro |
| `ashe-full-thumbnail` | Ashe Pro |
| `ashe-grid-thumbnail` | Ashe Pro |
| `ashe-list-thumbnail` | Ashe Pro |
| `ashe-single-navigation` | Ashe Pro |
| `rincity-thumb` | rc_tweaks (registration removed in rc_tweaks 2.1.6) |

## Retained sizes (all attachments)

- `thumbnail` — used in admin/media library views
- `medium` — used in admin/editor views
- WordPress `-scaled` full file (controlled by `big_image_size_threshold`)
- All Envira-generated crop files (`320x400_c`, `60x80_c`, `75x50_c`, etc.)

## Non-Envira attachments

The filter is a no-op for attachments whose parent post is not of type `envira`.
All registered sizes continue to be generated for theme post/page featured images
and other non-gallery uploads.

## Rollback

Deactivate the plugin. New uploads will resume generating all registered sizes.
Existing attachments are unaffected either way.

## Changelog

- **0.1.0** — Initial release.
