# rincity-envira-zoom

**Version:** 0.6.10  
**Deploy path:** `wp-content/plugins/rincity-envira-zoom/`

## Description

Replaces Envira Gallery's built-in ElevateZoom addon with [Panzoom](https://github.com/timmywil/panzoom) (`@panzoom/panzoom` v4.6.2) in the lightbox. Provides smooth scroll-wheel zoom, drag-to-pan, and double-click zoom-to-cursor on lightbox images. Panzoom is bundled locally — no CDN dependency.

## Features

- **Scroll-wheel zoom** — zoom in/out with the mouse wheel while the lightbox is open
- **Drag-to-pan** — click and drag to pan a zoomed image
- **Double-click zoom** — double-click to zoom in, double-click again to reset
- **HD background-load** — while the lightbox is open, the pre-scale original image is fetched in the background; once decoded, it is swapped in for higher-resolution zoom
- **HD indicator** — a small `HD` badge in the zoom controls shows loading state (`…` while pending, `HD` when ready); removed if no original is available
- **Clean teardown** — zoom state is destroyed when the lightbox closes or the user navigates to another slide, preventing state leaking between images

## Assets

| File | Description |
|------|-------------|
| `assets/panzoom.min.js` | Bundled Panzoom v4.6.2 library |
| `assets/rincity-envira-zoom.js` | Integration layer: hooks Envira lightbox events, manages Panzoom lifecycle and HD swap |
| `assets/rincity-envira-zoom.css` | Zoom controls styling |

## Zoom path

1. Envira lightbox opens with the `-scaled` image (WordPress's `big_image_size_threshold` output).
2. `rincity-envira-zoom` activates Panzoom on the lightbox image.
3. Concurrently, the pre-scale original is fetched in the background via a detached `Image()`.
4. Once the original finishes decoding, the `src` is swapped in and the HD indicator turns green.

This pairs with `rincity-image-size-control`, which suppresses unnecessary intermediate crops for Envira gallery attachments (but retains the `-scaled` file that feeds step 1).

## Deploy

```bash
make rincity-envira-zoom
```

## Changelog

- **0.6.10** — Current version.
- **0.1.0** — Initial release.
