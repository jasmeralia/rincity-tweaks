# rincity-wallpaper-candidates

**Version:** 1.0.0  
**Deploy path:** `wp-content/plugins/rincity-wallpaper-candidates/`

## Description

An admin-only WordPress plugin that scans all published Envira galleries and identifies landscape images suitable for use as 16:9 desktop wallpaper. Results are displayed in a WP Admin page with filtering and sorting options.

## Admin page

**Location:** WP Admin → Wallpaper (menu icon: images, position 58)

**Required capability:** `manage_options`

The page lists wallpaper candidates grouped by gallery, showing the image, its dimensions, which resolution tier it qualifies for, and a link to the full-size file.

### Sort options

| Sort | Description |
|------|-------------|
| `newest` | Most recently published galleries first (default) |
| `oldest` | Oldest galleries first |
| `most` | Galleries with the most candidates first |

### Actions

- **Re-scan** — clears the object cache and runs a fresh scan immediately
- **Save settings** — saves scanner settings, clears cache, and rescans

## Scanner

### Resolution tiers

A landscape image qualifies if it meets the minimum dimension for at least one tier:

| Tier | Minimum dimensions |
|------|--------------------|
| 4K | 3840 × 2160 |
| 1440p | 2560 × 1440 |
| 1080p | 1920 × 1080 |

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Minimum tier (`rincwc_min_tier`) | `1080p` | Lowest tier to include in results |
| Tail exclusion (`rincwc_tail_exclude_pct`) | `33` | Skip the last N% of images in each gallery (end-of-set images tend to be lower quality) |

### Caching

Scan results are stored in the WordPress object cache (`rincwc` group). On sites with a persistent cache (Redis/Memcached), results persist until explicitly cleared via Re-scan or Save settings.

## Deploy

```bash
make rincity-wallpaper-candidates
```

## Changelog

- **1.0.0** — Initial release.
