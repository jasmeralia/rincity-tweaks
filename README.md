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
