# rincity-envira-zoom

**Version:** 0.6.18  
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

- **0.6.18** — Fix image info placement: inject `rin-image-info` into `.envirabox-infobar__body` (replacing the native "X / Y" counter) instead of as a separate floating overlay, eliminating the duplicate counter. Falls back to floating overlay when the infobar is absent.
- **0.6.17** — Add image info overlay to lightbox: `(Image X of Y) [WxH]`. Shows scaled dimensions on open; updates to original dimensions once the HD background load completes (reads from `bgLoader.naturalWidth/Height` before src swap). Tears down with `destroyZoom()` alongside `zoomControls`.
- **0.6.16** — Fix toolbar hiding on short pan/drag at scale=1 (task 310): block *all* touch propagation from the shell unconditionally (previously allowed at scale=1 for swipe-nav), so Envira's guestures can no longer route a drag-end to `clickContent:'toggleControls'` and hide the +/−/HD/zoom controls. Reimplement horizontal swipe-nav ourselves at scale=1 via Envira's public `instance.next()`/`instance.previous()` API (40px threshold, horizontal-dominant, guarded on multi-image galleries). Side effect: tap-to-toggle-toolbar is intentionally disabled, so the toolbar now stays visible while the lightbox is open.
- **0.6.15** — Fix centering on first open: replace single-rAF guard with a MutationObserver that re-clears the wrap transform immediately whenever Envira writes it back, regardless of timing or frequency.
- **0.6.14** — Restore swipe-nav: initialize Panzoom with `disablePan:true` and toggle it via `panzoomzoom`/`panzoomreset` so Panzoom doesn't capture pointer events at scale=1 (previously caused the image to slide off-screen instead of Envira navigating); restore conditional touch propagation (allow at scale=1, block at scale>1 and multi-touch). Fix intermittent centering on first open: add a `requestAnimationFrame` second-clear of the wrap transform to catch Envira re-setting it asynchronously after `after_show`.
- **0.6.13** — Fix scale=1 touch bugs: stop all touch propagation from shell unconditionally (previously only blocked at scale>1, letting Envira's tap-to-hide-toolbar handler fire on drag-end); fix double-tap state corruption from drag-ending touchends by comparing against the touchstart position (drags reset lastTap instead of recording a phantom "first tap").
- **0.6.12** — Add zoom-level indicator ("2x", "3x" …) to the left of +/− buttons; hidden at 1x, updated live via the `panzoomzoom` event.
- **0.6.11** — Fix three mobile touch bugs: drag after zoom-in no longer resets zoom (touch events now blocked from Envira's swipe-nav handler when zoomed or pinching); tapping +/−/HD controls no longer hides the toolbar (touch events stopped on the controls span); double-tap to zoom now works (custom touchend handler, since `dblclick` is not synthesised when `touch-action:none` is set).
- **0.6.10** — Previous version.
- **0.1.0** — Initial release.
