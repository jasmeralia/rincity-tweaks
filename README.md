# rincity-tweaks

A collection of tools and plugins for managing [Rin City](https://rin-city.com) Envira Gallery content, from WordPress customization through automated social media posting.

## Components

### [rc_tweaks](rc_tweaks/)

A WordPress plugin (v2.1.12) that extends Envira Gallery with:

- RSS feed of the latest galleries (Members Gallery album only)
- Gallery table page with random gallery display (`[rc_envira_gallery_table]` shortcode)
- Custom album display page (`[rincity_envira_album id="..."]` shortcode) — renders a styled grid of gallery cover thumbnails with titles and photo counts, with URL-based category filtering
- **Envira Gallery Categories widget** — shows categories for the current gallery on single gallery pages, linking to the filtered album view
- **Envira Album Categories widget** — shows a collapsible hierarchical category tree with gallery counts on album and Members Gallery pages

See [rc_tweaks/README.md](rc_tweaks/README.md) for full documentation.

### [rincity-envira-covers](rincity-envira-covers/)

A WP-CLI PHP script that scans Envira galleries and generates a JSON manifest of cover images. The manifest is consumed by the throwback posting tool. Runs on a cron schedule via `run_envira_covers.sh`.

### [rincity-throwback-posts](rincity-throwback-posts/)

A Python 3 application that reads the Envira cover manifest and posts throwback gallery content to X/Twitter and Bluesky. Features include history tracking to avoid repeat posts, Jinja2 templates for post text, and dry-run mode. Runs on a cron schedule via `run_throwback.sh`.

See [rincity-throwback-posts/README.md](rincity-throwback-posts/README.md) for full documentation.

### [rincity-envira-exif](rincity-envira-exif/)

A WordPress plugin (v1.0.1) that appends a single representative camera EXIF line (camera, aperture, shutter speed, ISO, focal length, lens) to the bottom of Envira gallery pages that belong to the main photo album. Renders nothing if no camera EXIF is present or the gallery isn't in the main album. See [rincity-envira-exif/README.md](rincity-envira-exif/README.md) for full documentation.

### [rincity-envira-search-enhancements](rincity-envira-search-enhancements/)

A WordPress plugin (v1.5.0) that extends the default WordPress search to include Envira galleries. Features:

- Galleries matching the search term by **Envira category or tag** are included in results alongside posts and pages
- Each gallery result is prefixed with a thumbnail (400 px wide, first image in the gallery) and a media count line (e.g. "80 photos & 2 videos")
- Photo/video detection uses `envira_video_get_video_type()` when available, with a fallback to URL pattern matching for `.mp4`/`.webm` and YouTube/Vimeo links
- Only active gallery items are counted
- Envira gallery entries in search results show a "View Set" button instead of the theme's default "Read More"

### [rincity-envira-zoom](rincity-envira-zoom/)

A WordPress plugin (v0.6.22) that replaces Envira's built-in ElevateZoom addon with [Panzoom](https://github.com/timmywil/panzoom) (`@panzoom/panzoom` v4.6.2). Provides scroll-wheel zoom, drag-to-pan, and double-click zoom-to-cursor on lightbox images, plus background-loading the pre-scale original for higher-resolution zoom (with an HD status indicator) and an image info overlay showing resolution. Panzoom is bundled locally (`assets/panzoom.min.js`) — no CDN dependency. See [rincity-envira-zoom/README.md](rincity-envira-zoom/README.md) for full documentation.

### [rincity-gallery-favorites](rincity-gallery-favorites/)

A WordPress plugin (v1.0.3) providing a member-only favorites/bookmarks system for Envira gallery pages. Logged-in aMember members can favorite galleries, add private notes, and view their full favorites list via a shortcode-driven management page, backed by a REST API and a custom database table. See [rincity-gallery-favorites/README.md](rincity-gallery-favorites/README.md) for full documentation.

### [rincity-image-size-control](rincity-image-size-control/)

A WordPress plugin (v0.1.1) that suppresses unused WordPress core and Ashe Pro intermediate image sizes for Envira gallery attachments, reducing CPU time and disk use during uploads. See [rincity-image-size-control/README.md](rincity-image-size-control/README.md) for full documentation.

### [rincity-news-widget](rincity-news-widget/)

A WordPress plugin (v1.2.0) that registers a **RinCity Recent Updates** sidebar widget. Shows a unified date-sorted feed of recent posts and Envira galleries with:

- Color-coded badges: **NEW POST** (blue) for standard posts, **NEW GALLERY** (green outline) for Envira galleries
- Photo/video counts on galleries (e.g. "Bloomlight (80 photos)")
- Author and publish date on each item
- All titles linked to their respective pages
- Dark-card visual style with RSS icon in the header

Widget settings: title (default "Recent Updates") and item count (default 5, max 20).

### [rincity-nav-tweaks](rincity-nav-tweaks/)

A WordPress plugin (v1.1.3) that fixes mobile submenu expand/collapse on the Ashe Pro theme, and prevents scroll-to-top on tapping desktop parent nav items on touch devices. The theme's `.sub-menu-btn` overlay is dead (covered by the anchor's `z-index: 5`), leaving the chevron icon with no handler. This plugin:

- Wires `.sub-menu-btn-icon` chevron clicks and parent-anchor clicks to `slideToggle` the sibling `.sub-menu` at all nesting depths (mobile nav)
- Strips the `href="#"` attribute from desktop parent nav items via `nav_menu_link_attributes`, so tapping them has no default browser action (no scroll-to-top); desktop submenus continue to open via the theme's native hover behavior
- Leaves real-link parents (About, Spoil Me!) navigable via their anchor

### [rincity-wf-block-logger](rincity-wf-block-logger/)

A WordPress must-use plugin (v1.0.0) that logs Wordfence IP ban events (rate/flood blocks, WAF triggers, manual and automatic-permanent IP blocks) as JSON to the systemd journal, for ingestion by Vector into the `ip-blocks-*` OpenSearch index. See [rincity-wf-block-logger/README.md](rincity-wf-block-logger/README.md) for full documentation.

### [rincity-wordfence-temp-allowlist](rincity-wordfence-temp-allowlist/)

A WordPress must-use plugin (v1.0.1) that automatically adds Rin's current client IP to the Wordfence IP allowlist for 6 hours after she successfully logs in. Removes any prior temporary entry on each login and expires the allowlist entry via WP-Cron. Wordfence remains fully active for all other traffic.

**Deploy path:** `wp-content/mu-plugins/rincity-wordfence-temp-allowlist.php`

**Required constants in `wp-config.php`:**
```php
define('RINCITY_WF_TEMP_ALLOWLIST_USER_IDS', '3,50'); // Rin's WP user IDs
define('RINCITY_WF_TEMP_ALLOWLIST_TTL', 21600);       // 6 hours in seconds
```

**WP-CLI:**
```bash
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-wf-temp-allowlist status
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-wf-temp-allowlist clear
```

### [rincity-zero-scheduled-seconds](rincity-zero-scheduled-seconds/)

A WordPress must-use plugin (v1.0.1) that strips sub-minute precision from scheduled post times. WordPress records the wall-clock seconds when a post is saved (e.g. `07:00:25 UTC`), which causes posts scheduled for "midnight" to briefly show "missed schedule" and publish up to a minute late. This filter zeroes the seconds on every `future`-status save so the stored time is exactly `07:00:00 UTC`.

**Deploy path:** `wp-content/mu-plugins/rincity-zero-scheduled-seconds.php`

## Workflow

```
WordPress Envira Galleries
        │
        ▼
rincity-envira-covers  →  cover manifest (JSON)
                                  │
                                  ▼
                    rincity-throwback-posts  →  X/Twitter, Bluesky
```

## Requirements

| Component | Requirement |
|-----------|-------------|
| rc_tweaks | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-envira-covers | WP-CLI, PHP 7.0+ |
| rincity-throwback-posts | Python 3.10+, tweepy 4.16.0+, jinja2 3.1.6+ |
| rincity-envira-exif | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-envira-search-enhancements | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-envira-zoom | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-gallery-favorites | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin, aMember |
| rincity-image-size-control | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-nav-tweaks | WordPress 5.0+, PHP 7.0+ |
| rincity-news-widget | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
| rincity-wf-block-logger | WordPress 5.0+, PHP 7.0+, Wordfence plugin |
| rincity-wordfence-temp-allowlist | WordPress 5.0+, PHP 7.0+, Wordfence plugin |
| rincity-zero-scheduled-seconds | WordPress 5.0+, PHP 5.3+ |
