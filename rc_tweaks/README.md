# rc_tweaks WordPress Plugin

## Description
The `rc_tweaks` plugin provides several features for WordPress sites using the Envira Gallery plugin:

1. **Envira RSS Feed:** Generates an XML/RSS feed for the last 10 published `envira` posts (limited to those in the "Members Gallery" album), sorted by publication date descending.
2. **Envira Gallery Table Page:** Automatically creates a page that displays a table of 3 random published `envira` galleries (excluding those in the "Dustrat" category), each showing up to 3 images, sorted by oldest publication date first.
3. **Custom Album Display Page:** A shortcode that renders a custom grid of all galleries in a given Envira album, with cover thumbnails, titles, photo counts, and optional category filtering via URL parameter.
4. **Envira Gallery Categories Widget:** Displays a bulleted list of Envira categories (with gallery counts) in the sidebar, only on single Envira gallery pages. Each category links to the Members Gallery album page filtered by that category.
5. **Envira Album Categories Widget:** Displays a hierarchical, collapsible category tree with gallery counts in the sidebar, visible on Envira album pages, album taxonomy pages (e.g. `/album/members-gallery`), and the `/members-gallery/` page.

## Installation
1. Download the `rc_tweaks` plugin files.
2. Upload the `rc_tweaks` folder to the `/wp-content/plugins/` directory of your WordPress installation.
3. Activate the plugin through the 'Plugins' menu in WordPress.

## Requirements
- WordPress 5.0 or higher
- PHP 7.0 or higher
- Envira Gallery plugin (with Albums addon for album features)

## Usage

### Envira RSS Feed
Once activated, the plugin automatically generates an RSS feed for `envira` posts in the "Members Gallery" album. Access it at:
```
http://yourdomain.com/?feed=envira-feed
```
Scheduled (future) galleries are excluded from the feed.

### Envira Gallery Table Page
- On activation, a page titled **Envira Gallery Table** is created at `/envira-gallery-table/`.
- This page displays a table of 3 random published Envira galleries, each with up to 3 images, sorted by oldest publication date first.
- Galleries in the "Dustrat" category are excluded.
- Use the `[rc_envira_gallery_table]` shortcode in any page or post to embed the same table elsewhere.

### Custom Album Display Page
The `[rincity_envira_album]` shortcode renders a custom grid layout for a given Envira album. It replaces the default Envira album display with a styled grid showing cover thumbnails, gallery titles (linked), and photo counts.

**Shortcode attributes:**

| Attribute | Required | Description |
|-----------|----------|-------------|
| `id` | Yes | The WordPress post ID of the Envira album to display |

**Example:**
```
[rincity_envira_album id="1411"]
```

**Category filtering:**

Galleries can be filtered by appending an `envira-category` query parameter to the page URL:
```
/members-gallery/?envira-category=envira-category-42
```
where `42` is the `term_id` of the desired category. The category widgets (see below) generate these URLs automatically.

Galleries are displayed in reverse chronological order (newest first). Cover thumbnails use a 144×180 px cropped image derived from the album's stored cover image metadata.

**Admin setting:**

A lazy load option for the album display can be toggled in the WordPress admin via the `rincity_envira_album_lazyload` option (defaults to enabled).

### Envira Gallery Categories Widget
- Go to **Appearance → Widgets** in your WordPress admin.
- Add the **Envira Gallery Categories** widget to your desired widget area.
- The widget appears **only on single Envira gallery pages** and displays a bulleted list of the categories assigned to the current gallery, with a count of galleries in each category.
- Each category name links to `/members-gallery/?envira-category=envira-category-{term_id}`, deep-linking into the filtered album view.
- The widget hides itself automatically if the current gallery has no categories.

### Envira Album Categories Widget
- Go to **Appearance → Widgets** in your WordPress admin.
- Add the **Envira Album Categories** widget to your desired widget area.
- The widget appears on:
  - Envira album post type pages
  - Envira album taxonomy pages (e.g. `/album/members-gallery`)
  - The `/members-gallery/` page
- It displays a hierarchical category tree showing all Envira categories, with a gallery count for each category (counting only galleries in the Members Gallery album, ID 1411).
- Top-level categories that have subcategories show a collapse/expand toggle (▶). Subcategory lists are hidden by default and revealed on click.
- An **All Categories** link at the top of the list clears any active filter and shows the full album.
- The widget hides itself if no categories are found.

## Support
For support, please open an issue on the plugin's repository or contact the developer directly.

## Changelog
- **1.20.5** - Envira category links in widgets now point to the Members Gallery album page with the correct query parameter for deep linking.
- **1.20.4** - Version bump and changelog update.
- **1.20.3** - Minor code cleanup and maintenance for Envira deeplink support.
- **1.20.2** - Improved Envira deeplink support: added delay and selector fallback for more reliable category filter activation via URL.
- **1.20.1** - Second pass at deeplinks.
- **1.20.0** - Initial pass at deeplink support.
- **1.19.3** - Fixed a critical error in the RSS feed by ensuring `$now_dt->format('c')` is used for debug output instead of the DateTime object; clarified and enforced UTC time comparisons for all scheduling logic.
- **1.19.2** - Fixed critical error in RSS feed by ensuring all time comparisons use UTC and consistent variable names; improved handling of schedule values as both string and integer.
- **1.19.1** - Fixed RSS feed to properly exclude scheduled galleries by comparing all times in UTC and handling both string and integer schedule values.
- **1.19.0** - Fixed rss feed showing scheduled galleries.
- **1.18.5** - Further fixes for rss feed.
- **1.18.3** - RSS feed now only includes galleries that are members of the "Members Gallery" album.
- **1.18.2** - Update the XML namespace config.
- **1.18.1** - RSS feed description now prepends the first image as an `<img src="...">` tag.
- **1.18.0** - Moved code into subfiles as rc_tweaks.php was getting bloated.
- **1.17.0** - Both Envira Gallery Categories and Envira Album Categories widgets now prefix each category name with a bullet character and a space.
- **1.16.0** - Envira Album Categories widget now hides empty categories and removes debug output.
- **1.15.0** - Improved Envira Album Categories widget: it now appears on album taxonomy pages (e.g. `/album/members-gallery`) as well as Envira album post type pages.
- **1.14.0** - Gallery table now sorts randomized results by oldest publication date first. Added a new widget that displays all Envira categories with their counts, visible only on Envira album pages.
- **1.13.0** - Gallery table now displays 3 random Envira galleries, but always sorts the random selection by publication date (newest first) before display.
- **1.12.0** - RSS feed excludes galleries not in the "Members Gallery" album and those scheduled for the future. Envira Gallery Categories widget now shows a total count of galleries in each category.
- **1.11.0** - Envira Gallery Categories widget now hides itself if no categories are found for the gallery, and displays categories as a bulleted list when present.
- **1.10.0** - Gallery table now includes a third column with a copyable text field containing the slugs of the Envira categories applied to each gallery in hashtag format. Galleries with the "Dustrat" category are excluded from the table.
- **1.9.0** - Envira Tags Widget now displays a clear error if the gallery post ID cannot be determined, and a separate error with details if there is an error retrieving tags for a gallery. Improved detection for Envira galleries, including support for standalone URLs like `/envira/gallery-slug/`.
- **1.8.0** - Improved Envira Tags Widget: now reliably detects the current Envira gallery post/page and its tags, including support for standalone URLs like `/envira/gallery-slug/`. Widget always displays, even if no tags are found.
- **1.7.0** - Gallery table now displays 3 random published Envira galleries (not just the latest), makes the gallery title a clickable link, and provides copyable links to each image and the gallery page. Envira Tags Widget now always displays on Envira posts, even if no tags are found.
- **1.6.0** - Gallery table now makes the gallery title a clickable link, and provides copyable links to each gallery page.
- **1.5.0** - Gallery table now displays 3 random published Envira galleries (not just the latest), and provides copyable links to each image. Fixed Envira Tags Widget to display on all Envira post types.
- **1.4.0** - Fixed Envira Tags Widget to display on all Envira post types, not just single gallery pages.
- **1.3.0** - Added support for Envira gallery preview image in the RSS feed and improved pretty printing of XML output.
- **1.2.0** - Added Envira tags widget for single gallery pages.
- **1.1.0** - Added Envira gallery table page and shortcode.
- **1.0.0** - Initial release
