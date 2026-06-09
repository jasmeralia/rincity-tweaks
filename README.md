# rincity-tweaks

A collection of tools and plugins for managing [Rin City](https://rin-city.com) Envira Gallery content, from WordPress customization through automated social media posting.

## Components

### [rc_tweaks](rc_tweaks/)

A WordPress plugin (v2.1.5) that extends Envira Gallery with:

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

### [rincity-envira-zoom](rincity-envira-zoom/)

A WordPress plugin (v0.1.0) that replaces Envira's built-in ElevateZoom addon with [Panzoom](https://github.com/timmywil/panzoom) (`@panzoom/panzoom` v4.6.2). Provides scroll-wheel zoom, drag-to-pan, and double-click zoom-to-cursor on lightbox images. Panzoom is bundled locally (`assets/panzoom.min.js`) — no CDN dependency.

### [rincity-wordfence-temp-allowlist](rincity-wordfence-temp-allowlist/)

A WordPress must-use plugin (v1.0.0) that automatically adds Rin's current client IP to the Wordfence IP allowlist for 6 hours after she successfully logs in. Removes any prior temporary entry on each login and expires the allowlist entry via WP-Cron. Wordfence remains fully active for all other traffic.

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
| rincity-throwback-posts | Python 3.10+, tweepy 4.14.0+, jinja2 3.1.0+ |
| rincity-envira-zoom | WordPress 5.0+, PHP 7.0+, Envira Gallery plugin |
