# rincity-envira-search-enhancements

**Version:** 1.3.0  
**Deploy path:** `wp-content/plugins/rincity-envira-search-enhancements/`

## Description

Extends the default WordPress search to include Envira galleries, and enhances gallery search results with a thumbnail and media count.

By default, WordPress search only covers `post` and `page` post types. This plugin adds `envira` to the searched post types and extends the SQL `WHERE` clause to also match galleries whose Envira categories or tags contain the search term.

## Features

### Gallery inclusion by taxonomy

Searches all registered Envira taxonomies (`envira-tag`, `envira_tags`, `envira-category`, `envira_categories`) for terms matching the query. Any gallery associated with a matching term is included in results even if the gallery title and content don't contain the search string.

### Search result thumbnail

On gallery entries in search results, a 400 px-wide thumbnail of the first gallery image is prepended to the post content.

### Media count

Below the thumbnail, a count line is shown: e.g. `80 photos`, `3 videos`, or `12 photos & 2 videos`. Only active gallery items are counted (items with `status !== 'active'` are skipped).

### Video detection

Video items are identified using `envira_video_get_video_type()` when the Envira Videos addon is active. Falls back to URL pattern matching for self-hosted `.mp4`/`.webm` files and YouTube/Vimeo links.

## Deploy

```bash
make rincity-envira-search-enhancements
```

## Changelog

- **1.3.0** — Current version.
- **1.0.0** — Initial release.
