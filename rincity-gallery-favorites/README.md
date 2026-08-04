# rincity-gallery-favorites

**Version:** 1.0.3  
**Deploy path:** `wp-content/plugins/rincity-gallery-favorites/`

## Description

Member-only favorites/bookmarks system for Envira standalone gallery pages. Logged-in aMember members can favorite galleries, add private notes, and view their full favorites list on a dedicated page. Unfavorited galleries and the management page are not shown to guests or non-members.

## Features

### Sidebar widget

A **Gallery Tools** widget (id: `rincgf_widget`) renders in the sidebar on Envira gallery pages. For logged-in members it shows a favorite toggle button and note field for the current gallery, plus a link to their favorites page. On non-gallery pages it shows only the favorites page link. Hidden entirely for non-members.

### Favorites page

The `[rincity_gallery_favorites_page]` shortcode renders a member's full favorites list — gallery title, cover thumbnail, and personal note. Place this shortcode on a members-only page at `/my-favorite-galleries/`.

### REST API

All state changes go through a JSON REST API. Endpoints require aMember session authentication.

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/wp-json/rincity-gallery-favorites/v1/status?gallery_id=N` | Favorite status for one gallery |
| `POST` | `/wp-json/rincity-gallery-favorites/v1/favorite` | Add a favorite (optional `note` body param) |
| `DELETE` | `/wp-json/rincity-gallery-favorites/v1/favorite?gallery_id=N` | Remove a favorite |
| `PATCH` | `/wp-json/rincity-gallery-favorites/v1/note` | Update the note on an existing favorite |
| `GET` | `/wp-json/rincity-gallery-favorites/v1/favorites` | List all favorites for the current member |

Notes are capped at 2000 characters (`RINCGF_NOTE_MAX`).

### Admin columns

The Envira gallery list in WP Admin gains a **Favorites** column showing how many members have favorited each gallery.

### Database

On plugin activation, a custom table `{prefix}rincity_gallery_favorites` is created:

| Column | Type | Description |
|--------|------|-------------|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT` | Primary key |
| `amember_user_id` | `BIGINT UNSIGNED` | aMember member ID |
| `gallery_id` | `BIGINT UNSIGNED` | Envira gallery post ID |
| `note` | `TEXT` | Member's private note |
| `created_at` | `DATETIME` | When first favorited |
| `updated_at` | `DATETIME` | When note was last changed |

Unique constraint on `(amember_user_id, gallery_id)`.

## aMember integration

Session detection uses `RinCity_Amember_Session`, which tries `am4PluginsManager` and `Am_Lite` in order. WP-CLI has no HTTP session, so session resolution always returns false in CLI context — use a browser session to test live behavior.

## Deploy

```bash
make rincity-gallery-favorites
```

## WP-CLI

```bash
# Check aMember session detection availability
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-gallery-favorites amember-check

# Show database table stats (row count, distinct member count)
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-gallery-favorites db-status
```

## Changelog
- **1.0.4** — `get_cover_image_url()` now uses the shared cover crop resolver from `rc_tweaks` (`rincity_resolve_gallery_cover_url()`) instead of its own copy of the brittle `-scaled.<ext>`-only transformation, fixing the same full-size-original fallback bug as the album shortcode for non-scaled cover sources. (Odoo #402)
- **1.0.3** — Add Categories column to the favorites management table (comma-separated deep links to `envira-category` archive pages; included in the search filter); add a sortable Favorites count column to the WP Admin Envira Galleries list; fix the mobile virtual keyboard dismissing on each search keypress by splitting rendering into a one-time shell init and a table-only update on input.
- **1.0.0** — Initial release: sidebar widget, favorites page shortcode, REST API, aMember session integration.
