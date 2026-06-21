# rincity-news-widget

**Version:** 1.2.0  
**Deploy path:** `wp-content/plugins/rincity-news-widget/`

## Description

A WordPress sidebar widget that shows a unified date-sorted feed of recent posts and Envira galleries. Posts and galleries are interleaved by publish date. Envira galleries are filtered to only those belonging to at least one album — ungrouped galleries are excluded.

## Widget

Registered as **RinCity Recent Updates** (id base: `rincity_news_widget`). Place it via Appearance → Widgets.

### Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Title | `Recent Updates` | Widget heading text |
| Number of items | `5` | How many items to show (max 20) |

### Display format

Each item shows:
- A color-coded badge: **New Post** (gold `#be9e5e` border) or **New Gallery** (green `#5aa87a` border)
- The title, linked to the post/gallery page
- For galleries: a media count in parentheses, e.g. `(80 photos)` or `(12 photos & 2 videos)`
- A meta line: `Month D, YYYY by Display Name`

### Styling

CSS inherits the Ashe Pro theme's dark color scheme (`#111111` background, `#ffffff` titles, `#9e9e9e` meta, `#383838` dotted item dividers). The widget title is rendered via `before_title`/`after_title` so it picks up the theme's standard uppercase `h2` with horizontal-rule flanks.

## Album filtering

On each widget render, the plugin queries all published `envira_album` posts and collects gallery IDs from their `_eg_album_data['galleryIDs']` meta. Only galleries whose post ID appears in that set are eligible to show. Galleries with `post_status != publish` are also excluded by the query.

## Photo/video counts

Gallery media counts are read from the `_eg_gallery_data` post meta. Only items with `status === 'active'` are counted. Video detection uses `envira_video_get_video_type()` when the Envira Videos addon is active; falls back to URL pattern matching for `.mp4`/`.webm` and YouTube/Vimeo links.

## Cache busting

The enqueued CSS URL includes a `?ver=` query string set to the plugin version constant (`RINCITY_NEWS_WIDGET_VERSION`). Increment the constant and redeploy when the CSS changes.

## Deploy

```bash
make rincity-news-widget
```

## Changelog

- **1.2.0** — Filter galleries to album members only; split into two separate queries and merge by timestamp.
- **1.1.0** — Switch to inline badge layout (no flex) to fix wrapped-title indentation; add colored borders to badges; version-based CSS cache busting.
- **1.0.0** — Initial release.
