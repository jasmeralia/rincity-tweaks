# RinCity Envira Gallery Favorites – Design Plan

**Date:** 2026-06-13  
**Target site:** rincity.com WordPress install  
**Primary goal:** Add authenticated member-only favorites/bookmarks for Envira Standalone gallery pages, controlled from a sidebar widget, with a full favorites management page.

---

## 1. Problem Statement

RinCity uses Envira Gallery with the Standalone option enabled, so individual galleries have their own gallery URLs rather than only being embedded on ordinary WordPress pages.

The desired feature is intentionally narrow:

- Let an authenticated aMember user favorite the current Envira standalone gallery.
- Let that user optionally add a private note for that favorite.
- Let that user view, search, sort, edit notes, and remove favorites from a full management page.
- Do not rely on WordPress login status as the authority, because there is currently a known discrepancy between aMember authenticated status and WordPress authenticated status.
- Avoid broad bookmark/collection/profile plugins that solve too much and increase attack surface.

This should be implemented as a small, defensive custom WordPress plugin.

---

## 2. Non-Goals

This feature should **not** implement:

- Public bookmarks.
- Social sharing.
- User-created collections.
- Favorite folders.
- Ratings.
- Comments.
- Cross-user discovery.
- Admin analytics beyond what is necessary for debugging.
- WordPress user-profile features.
- Dependency on Ultimate Member, BuddyPress, WooCommerce, or other membership/profile plugins.

The feature should remain narrowly scoped to:

> “This aMember-authenticated user has favorited this Envira gallery, optionally with a private note.”

---

## 3. High-Level Architecture

Create a small custom plugin:

```text
rincity-gallery-favorites/
  rincity-gallery-favorites.php
  includes/
    class-activator.php
    class-amember-session.php
    class-current-gallery.php
    class-repository.php
    class-rest-controller.php
    class-sidebar-widget.php
    class-shortcodes.php
    class-assets.php
  assets/
    favorites-widget.js
    favorites-table.js
    favorites.css
```

The plugin provides:

1. **Sidebar widget**
   - Visible only when an aMember user is authenticated.
   - Only renders a meaningful control on Envira standalone gallery pages.
   - Shows current favorite state.
   - Lets the user favorite/unfavorite the current gallery.
   - Lets the user add or edit a private note.
   - Links to the full favorites page.

2. **Full favorites page**
   - Implemented with a shortcode, for example:
     ```text
     [rincity_gallery_favorites_page]
     ```
   - Shows a searchable, AJAX-backed, sortable table.
   - Includes:
     - Gallery title
     - Gallery permalink
     - Gallery published date
     - Favorite added date
     - Private note
     - Edit note action
     - Remove favorite action

3. **Data layer**
   - Uses a custom table rather than user meta.
   - Tracks:
     - aMember user ID
     - Envira gallery post ID
     - when the favorite was added
     - when the note was last updated
     - private note text

4. **Authentication layer**
   - Uses aMember session/user identity as the authority.
   - Does not rely on `is_user_logged_in()` or `get_current_user_id()` for access decisions.
   - WordPress user ID may be stored optionally for diagnostics only, but it must not be the primary user key.

---

## 4. Authentication Model

### 4.1 Source of Truth

The feature must treat **aMember login status** as the source of truth.

This matters because the site currently has an active discrepancy where aMember may consider a visitor logged in while WordPress does not, or vice versa.

The plugin should have an abstraction layer:

```php
final class RinCity_Amember_Session {
    public function is_logged_in(): bool;
    public function get_member_id(): ?int;
    public function get_member_email(): ?string;
    public function get_member_display_name(): ?string;
}
```

All feature gates should call this service.

Do **not** scatter direct aMember checks throughout the plugin.

### 4.2 Integration Options

The exact aMember integration should be confirmed against the current site implementation, but likely approaches are:

1. Use the existing aMember WordPress integration plugin, if it exposes a reliable current-member object or helper.
2. Bootstrap aMember’s API/session layer directly if already done elsewhere on the site.
3. Read aMember-provided session state through an officially supported method.
4. As a fallback only, detect a valid aMember session cookie and validate it server-side through aMember, not by trusting cookie contents directly.

### 4.3 Hard Rule

Never accept `member_id` from the browser.

The browser may send a request like:

```json
{
  "gallery_id": 123,
  "note": "private note"
}
```

But the server must derive the member identity from the current aMember-authenticated session.

---

## 5. Envira Standalone Gallery Detection

The sidebar widget needs to know whether the current page is an Envira standalone gallery.

Implement a resolver:

```php
final class RinCity_Current_Gallery {
    public function get_current_gallery_id(): ?int;
}
```

Possible detection strategy:

1. Use `get_queried_object_id()`.
2. Confirm the queried object is an Envira gallery post type.
3. Confirm the post exists, is published, and is publicly viewable to the current member.
4. Return the gallery post ID.

The exact Envira custom post type slug should be verified on staging. Likely candidates may include `envira`, `envira_gallery`, or similar depending on Envira’s implementation/version.

Do not rely only on URL path parsing like `/envira/foo/` unless no better post object is available.

---

## 6. Database Design

Use a custom table. This gives cleaner querying, sorting, migration, and future analytics than a serialized user meta array.

Suggested table:

```sql
CREATE TABLE {$wpdb->prefix}rincity_gallery_favorites (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  amember_user_id BIGINT UNSIGNED NOT NULL,
  gallery_id BIGINT UNSIGNED NOT NULL,
  note TEXT NULL,
  note_updated_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_member_gallery (amember_user_id, gallery_id),
  KEY idx_member_created (amember_user_id, created_at),
  KEY idx_gallery (gallery_id),
  KEY idx_member_gallery (amember_user_id, gallery_id)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.1 Field Semantics

| Field | Meaning |
|---|---|
| `id` | Internal row ID |
| `amember_user_id` | aMember user ID, source of truth |
| `gallery_id` | WordPress post ID for the Envira gallery |
| `note` | Private note visible only to that aMember user |
| `note_updated_at` | Last time the note was changed |
| `created_at` | Time the gallery was added as a favorite |
| `updated_at` | Last row update time |

### 6.2 Optional Diagnostic Columns

Optionally add:

```sql
wp_user_id BIGINT UNSIGNED NULL,
amember_email_hash CHAR(64) NULL
```

These should be considered diagnostic only.

Do not use them as authorization keys.

If storing email-derived data, prefer a one-way hash instead of raw email unless there is a specific operational need.

---

## 7. Sidebar Widget Behavior

### 7.1 Visibility

The widget should render only when:

- The visitor is authenticated through aMember.
- The current view is an Envira standalone gallery.
- The gallery is published.
- The current member has access to the gallery page under the existing site rules.

If any check fails, the widget should render nothing or a minimal non-sensitive placeholder.

Suggested behavior:

| State | Widget Output |
|---|---|
| Not aMember authenticated | Nothing |
| Authenticated, not Envira gallery | Optional link to “My Favorite Galleries” only |
| Authenticated, Envira gallery, not favorited | “☆ Favorite this gallery” |
| Authenticated, Envira gallery, favorited | “★ Favorited” + note editor + remove button |
| AJAX failure | Non-verbose error message |

### 7.2 UI Copy

Example sidebar widget:

```text
Gallery Tools

☆ Favorite this gallery

Private note
[textarea]

[Save note]

View my favorite galleries
```

When already favorited:

```text
Gallery Tools

★ Favorited

Private note
[textarea]

[Save note] [Remove favorite]

View my favorite galleries
```

### 7.3 Widget Placement

Preferred approach:

- Register a WordPress widget or block-compatible widget.
- Site admin places it in the sidebar shown on Envira standalone pages.
- If the Envira standalone template does not include the desired sidebar, edit the standalone template once to include that widget area.

This keeps the gallery rendering itself minimally touched.

---

## 8. Full Favorites Management Page

Create a WordPress page, for example:

```text
/my-favorite-galleries/
```

Page content:

```text
[rincity_gallery_favorites_page]
```

### 8.1 Authentication

If the visitor is not authenticated through aMember:

- Do not show the table.
- Show a short login-required message.
- Link to the existing aMember login page.

Do not use WordPress login state to decide access.

### 8.2 Table Columns

The page should show:

| Column | Source |
|---|---|
| Gallery title | `wp_posts.post_title` |
| Gallery URL | `get_permalink(gallery_id)` |
| Gallery published date | `wp_posts.post_date` |
| Added as favorite | `rincity_gallery_favorites.created_at` |
| Private note | `rincity_gallery_favorites.note` |
| Actions | Edit note, remove favorite |

Optional additional columns:

| Column | Source |
|---|---|
| Last note update | `rincity_gallery_favorites.note_updated_at` |
| Gallery status | `wp_posts.post_status` |

### 8.3 Table Loading, Sorting, and Searching

Because the current gallery catalog is only in the hundreds, the management table should **not** use SQL-backed search/sort/pagination in v1.

Instead:

1. Server returns all favorites for the current aMember user.
2. Browser stores that small result set in memory.
3. Browser handles search, sort, and filtering locally.

This avoids risky and tedious SQL search/sort boilerplate while still satisfying the requirement for an interactive searchable/sortable table.

Search should cover:

- Gallery title
- Private note

Sortable columns:

```text
title
gallery_published_at
favorite_added_at
note_updated_at
```

Allowed sort directions:

```text
asc
desc
```

Privacy still depends on server-side scoping. The server must always return only favorites belonging to the current aMember user.

Server-side filtering must always include:

```sql
WHERE favorites.amember_user_id = current_amember_user_id
```

Client-side search/sort is acceptable only because the server has already returned a scoped, owner-only result set.

### 8.4 Table Library

Use a conservative approach.

Preferred:

1. Lightweight custom JavaScript table.
2. Load all current member favorites once.
3. Search/sort/filter the in-memory array.
4. Re-render rows safely using `textContent` and textarea `.value`.

Avoid DataTables or large table frameworks unless they are already approved elsewhere on the site.

Avoid pulling in a large admin/table framework just for this.

---

## 9. Request Handling Design

There is no inherent security advantage to AJAX/REST over classic PHP form handling. The security comes from the same server-side rules either way:

- derive the aMember user from the server-side session;
- validate the gallery ID server-side;
- use nonces/CSRF protection;
- sanitize note input;
- use prepared SQL;
- escape output;
- never trust browser-supplied user IDs.

AJAX is recommended for usability, especially for the sidebar toggle and sortable/searchable management table, but it is mostly additional boilerplate rather than a security requirement.

Acceptable approaches:

1. **Classic PHP POST handlers** for favorite, unfavorite, and save-note actions.
2. **WordPress `admin-post.php` handlers** with redirects back to the page.
3. **WordPress `admin-ajax.php` handlers** for partial updates.
4. **WordPress REST API routes** for a cleaner JavaScript interface.

For v1, a pragmatic split is recommended:

- Sidebar favorite/note controls: AJAX, so the widget updates without a page reload.
- Management table: AJAX, because sortable/searchable columns are a requirement.
- Non-JavaScript fallback: optional classic POST handling for favorite/remove/save-note.

If minimizing boilerplate is more important than live updates, classic POST handlers are acceptable for the sidebar. The management table will still need some server-side endpoint if columns are AJAX sortable/searchable.

Suggested endpoint style:

Use WordPress REST API routes or `admin-ajax.php`.

Preferred for simplicity in WordPress plugin development: `admin-ajax.php`.
Preferred for cleaner API structure: REST API with explicit permission callbacks.

If REST is used, namespace:

```text
/rincity-gallery-favorites/v1
```

If `admin-ajax.php` is used, equivalent actions can be:

```text
rincity_gallery_favorites_status
rincity_gallery_favorites_add
rincity_gallery_favorites_remove
rincity_gallery_favorites_update_note
rincity_gallery_favorites_list
```

Routes or actions:

```text
GET    /status?gallery_id=123
POST   /favorite
DELETE /favorite
PATCH  /note
GET    /favorites
```

### 9.1 GET /status

Returns favorite state for the current gallery.

Request:

```json
{
  "gallery_id": 123
}
```

Response:

```json
{
  "is_favorited": true,
  "gallery_id": 123,
  "created_at": "2026-06-13 10:30:00",
  "note": "Private user note"
}
```

### 9.2 POST /favorite

Adds the current gallery as a favorite for the current aMember user.

Request:

```json
{
  "gallery_id": 123,
  "note": "Optional private note"
}
```

Server behavior:

- Derive aMember user ID from the session.
- Validate gallery ID.
- Sanitize note.
- Insert or update favorite row.
- Return current state.

### 9.3 DELETE /favorite

Removes the favorite.

Request:

```json
{
  "gallery_id": 123
}
```

Server behavior:

- Derive aMember user ID from the session.
- Delete only the row matching both member ID and gallery ID.

### 9.4 PATCH /note

Updates the note for an existing favorite.

Request:

```json
{
  "gallery_id": 123,
  "note": "Updated private note"
}
```

Server behavior:

- Derive aMember user ID from the session.
- Validate note.
- Update only the row matching both member ID and gallery ID.

### 9.5 GET /favorites

Returns paged, sorted, searched favorites for the management page.

Query parameters:

```text
page=1
per_page=25
sort=favorite_added_at
direction=desc
search=term
```

Response:

```json
{
  "page": 1,
  "per_page": 25,
  "total": 43,
  "rows": [
    {
      "gallery_id": 123,
      "title": "Gallery Title",
      "url": "https://rincity.com/envira/gallery-title/",
      "gallery_published_at": "2025-11-02 14:22:00",
      "favorite_added_at": "2026-06-13 10:30:00",
      "note": "Private note",
      "note_updated_at": "2026-06-13 10:35:00"
    }
  ]
}
```

---

## 10. Defensive Security Design

WordPress installs are frequent targets, so design this as hostile-input software.

### 10.1 Authorization

Every mutating request must verify:

- aMember authenticated session exists.
- Current aMember member ID is available.
- Gallery ID is valid.
- The requested favorite row belongs to the current aMember user.
- The current member is allowed to view the target gallery.

Never trust:

- Browser-supplied user IDs.
- Hidden fields containing member IDs.
- WordPress login state alone.
- Client-side checks.

### 10.2 CSRF Protection

All AJAX/REST mutations must require a nonce.

For REST API:

- Use `wp_create_nonce('wp_rest')`.
- Validate through WordPress REST nonce handling.
- Still verify aMember session server-side.

For `admin-ajax.php`:

- Use `check_ajax_referer()` with a plugin-specific action.

Because the source of truth is aMember, nonce validation is necessary but not sufficient.

### 10.3 Note Field Safety

The private note field is the most obvious attack surface.

Rules:

- Treat notes as plain text only.
- Do not allow HTML.
- Do not allow Markdown rendering.
- Do not allow shortcodes.
- Do not allow oEmbed.
- Do not allow scriptable content.
- Store sanitized text.
- Escape again on output.

Recommended input handling:

```php
$note = wp_unslash($_POST['note'] ?? '');
$note = sanitize_textarea_field($note);
$note = mb_substr($note, 0, 2000);
```

Recommended output handling:

```php
echo esc_textarea($note); // inside textarea
echo esc_html($note);     // plain display
```

If the note is included in JSON:

```php
return [
    'note' => $note,
];
```

The JavaScript must insert it with `textContent` or textarea `.value`, never `innerHTML`.

### 10.4 SQL Safety

Use `$wpdb->prepare()` for every query with dynamic values.

Never concatenate:

- Search terms.
- Sort fields.
- Sort directions.
- Gallery IDs.
- Member IDs.

For v1, do not accept SQL sort/search parameters from the browser. Search and sort happen in browser memory after the server returns the current member’s scoped favorites list.

If future scale requires SQL-backed search/sort, sort fields must be allowlisted:

```php
$allowed_sort = [
  'title' => 'p.post_title',
  'gallery_published_at' => 'p.post_date',
  'favorite_added_at' => 'f.created_at',
  'note_updated_at' => 'f.note_updated_at',
];
```

Sort direction must be normalized:

```php
$direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';
```

Search should use `$wpdb->esc_like()` and prepared placeholders.

### 10.5 XSS Safety

Escape all output according to context:

| Context | Function |
|---|---|
| HTML text | `esc_html()` |
| Attribute | `esc_attr()` |
| URL | `esc_url()` |
| Textarea | `esc_textarea()` |
| JavaScript data | `wp_json_encode()` |

Never render note contents with raw `echo`.

Never insert API results into the DOM with `innerHTML`.

### 10.6 Rate Limiting / Abuse Control

Add lightweight server-side throttling for note writes and favorite toggles.

Example:

- Limit favorite toggle to 30 per minute per aMember user.
- Limit note saves to 20 per minute per aMember user.
- Store counters in transients keyed by a hash of aMember user ID.

This prevents accidental loops and low-effort abuse.

### 10.7 Data Privacy

Private notes are private to the owning aMember user.

Requirements:

- Admins should not need a front-end UI to browse notes.
- Do not expose notes through public REST responses.
- Do not include notes in page source except for the authenticated owner.
- Do not cache note-bearing responses globally.
- Do not log note contents.
- If errors occur, log row IDs and member IDs, not note text.

### 10.9 Favorite Count and Scale

Do not enforce a visible/user-facing favorite cap in v1.

The current gallery catalog is approximately 370 galleries, so even a user favoriting every gallery is comfortably small for a load-all management table.

Recommended behavior:

- Allow users to favorite as many galleries as exist.
- Keep the note field capped, preferably at 2,000 characters.
- Use an internal defensive `LIMIT 2000` when loading the management list to prevent runaway behavior if the catalog grows or data becomes corrupted.
- Treat the `LIMIT 2000` as a circuit-breaker, not as a product rule shown to users.

If the catalog ever grows into the thousands and users commonly have over 1,000 favorites, revisit server-side pagination/search/sort.

### 10.8 Capability and Direct Access Protections

Every PHP file should start with:

```php
defined('ABSPATH') || exit;
```

REST routes must use permission callbacks.

Activation/deactivation should be limited to administrators through normal WordPress plugin controls.

Do not expose unauthenticated AJAX actions for this feature.

---

## 11. Data Access Methods

Repository class:

```php
final class RinCity_Gallery_Favorites_Repository {
    public function get_favorite(int $amember_user_id, int $gallery_id): ?array;
    public function add_favorite(int $amember_user_id, int $gallery_id, string $note = ''): array;
    public function remove_favorite(int $amember_user_id, int $gallery_id): bool;
    public function update_note(int $amember_user_id, int $gallery_id, string $note): array;
    public function list_favorites(int $amember_user_id, array $args): array;
}
```

All methods must require `amember_user_id`.

No method should use the current global user implicitly.

---

## 12. Front-End JavaScript Behavior

### 12.1 Sidebar Widget JS

Responsibilities:

- Read gallery ID from a `data-gallery-id` attribute.
- Fetch current favorite state.
- Toggle favorite.
- Save note.
- Remove favorite.
- Update the widget state without a full page reload.

Do not include user ID in the DOM.

Example markup:

```html
<div
  class="rin-gallery-favorite-widget"
  data-gallery-id="123"
  data-rest-base="/wp-json/rincity-gallery-favorites/v1"
>
  <button type="button" data-action="toggle-favorite">
    Favorite this gallery
  </button>

  <label>
    Private note
    <textarea maxlength="2000"></textarea>
  </label>

  <button type="button" data-action="save-note">
    Save note
  </button>

  <a href="/my-favorite-galleries/">View my favorite galleries</a>
</div>
```

### 12.2 Management Page JS

Responsibilities:

- Load all favorites for the current aMember user.
- Store the returned rows in a local JavaScript array.
- Search favorites in memory.
- Sort columns in memory.
- Edit notes.
- Remove favorites.
- Re-render table safely.

DOM safety:

- Use `textContent` for table cells.
- Use `setAttribute()` for safe attributes.
- Use textarea `.value` for notes.
- Avoid `innerHTML`.

---

## 13. Gallery Published Date

The management table should join favorites to the Envira gallery post.

Use:

```php
p.post_date
```

or, if preferred for consistency with public display:

```php
get_the_date('', $gallery_id)
```

For sortable AJAX queries, use `p.post_date` at the SQL layer and format after retrieval.

Only include published galleries by default:

```sql
AND p.post_status = 'publish'
```

Consider whether to show “unpublished/removed” favorites. If desired, display them as unavailable and allow removal. For the initial version, hiding unpublished galleries is simpler, but it may strand rows. A better user experience is:

- Include favorite rows even if gallery is no longer published.
- Show title as “Unavailable gallery”.
- Hide the URL.
- Allow removing the favorite.

---

## 14. Caching Notes

The user has stated there is no page caching, specifically to avoid member-state issues.

Still design defensively:

- Do not emit cacheable REST responses containing notes.
- Send no-cache headers for REST responses that include favorite state or notes.
- Do not use transients to cache per-user note data.
- If object cache is present, key per-user data by aMember user ID and keep TTL short.

---

## 15. Admin Settings

A minimal settings page is optional.

Suggested settings:

| Setting | Default |
|---|---|
| Envira post type slug | Auto-detect |
| Favorites page URL | `/my-favorite-galleries/` |
| Max note length | `2000` |
| User-facing favorite cap | None |
| Internal management-list safety limit | `2000` |
| Enable rate limiting | `true` |
| Debug logging | `false` |

Avoid adding settings unless needed.

Hardcoded constants are acceptable for v1 if documented.

---

## 16. Migration Considerations

This feature is likely temporary or transitional if RinCity later migrates from WordPress to Directus/Astro.

Because of that:

- Use a clean custom table.
- Keep aMember user ID explicit.
- Keep Envira gallery ID explicit.
- Store timestamps in normal SQL datetime fields.
- Avoid serialized arrays.
- Avoid WordPress user meta as primary storage.
- Avoid dependency on third-party bookmark plugin schemas.

This will make later export straightforward:

```sql
SELECT
  amember_user_id,
  gallery_id,
  note,
  created_at,
  note_updated_at
FROM wp_rincity_gallery_favorites;
```

A later migration can map:

```text
Envira gallery ID -> Directus gallery ID
aMember user ID -> aMember/member identity
```

---

## 17. Implementation Phases

### Phase 1 – Foundation

- Create plugin skeleton.
- Add activation hook to create custom table.
- Implement aMember session abstraction.
- Implement current Envira gallery resolver.
- Implement repository class.
- Add basic logging with note contents excluded.

### Phase 2 – Sidebar Widget

- Register sidebar widget.
- Render only for aMember-authenticated users.
- Render only on Envira standalone gallery pages.
- Add favorite/unfavorite support.
- Add private note support.
- Add nonce-protected AJAX/REST calls.

### Phase 3 – Favorites Management Page

- Add `[rincity_gallery_favorites_page]` shortcode.
- Add endpoint that returns all favorites for the current aMember user.
- Implement in-memory JavaScript search.
- Implement in-memory JavaScript sorting.
- Add edit-note and remove actions.
- Keep an internal defensive server-side result limit, default `2000`.

### Phase 4 – Hardening

- Review all escaping.
- Review all SQL queries.
- Add rate limiting.
- Confirm no user ID is accepted from the browser.
- Confirm notes never render as HTML.
- Confirm unauthenticated requests fail.
- Confirm WordPress-authenticated-but-not-aMember-authenticated requests fail.
- Confirm aMember-authenticated-but-not-WordPress-authenticated requests work if that is the desired production behavior.

### Phase 5 – Staging QA

Test cases:

| Test | Expected Result |
|---|---|
| Anonymous visitor on gallery page | Widget hidden |
| WordPress logged-in but not aMember logged-in | Widget hidden |
| aMember logged-in but WordPress not logged-in | Widget works |
| aMember logged-in on non-gallery page | Optional favorites-page link only or hidden |
| Favorite gallery | Row created |
| Favorite same gallery twice | No duplicate row |
| Remove favorite | Row removed |
| Add note with HTML/script | Stored/displayed as plain text |
| Search by title | Matching own favorites only; search performed in browser memory |
| Search by note | Matching own favorites only; search performed in browser memory |
| Sort by published date | Correct order; sort performed in browser memory |
| Sort by added date | Correct order; sort performed in browser memory |
| Favorite all available galleries | Works without user-facing cap |
| Attempt request with another member ID | Ignored or rejected |
| Attempt request for another user’s favorite row | Rejected |
| Deleted/unpublished gallery | Does not expose broken private data; user can remove row |

---

## 18. Specific Defensive Requirements for Codex

When implementing, Codex should follow these rules:

1. Do not use WordPress login status as the authorization source.
2. Do not accept user/member IDs from the client.
3. Do not render notes as HTML.
4. Do not use `innerHTML` for note content.
5. Do not build SQL strings from untrusted input; v1 should not accept SQL search/sort parameters at all.
6. Do not support anonymous favorites.
7. Do not expose public REST routes that reveal favorite or note data.
8. Do not log private notes.
9. Do not add unrelated collection/profile/social features.
10. Keep this as a small, auditable plugin.

---

## 19. Open Questions to Confirm in Staging

1. What is the exact Envira gallery custom post type slug?
2. Does the Envira Standalone template currently include the sidebar/widget area?
3. What is the supported and reliable way to read the current aMember session from WordPress?
4. Should unavailable/unpublished galleries remain visible in the favorites table as removable rows?
5. What is the preferred aMember login URL for unauthenticated users?
6. Should the note length be 1000, 2000, or 4000 characters?
7. Should the favorites page be excluded from any future caching/CDN rules explicitly?

---

## 20. Recommended Defaults

Unless staging reveals a reason to change them:

```text
Storage:
  Custom table

Authentication:
  aMember session only

Widget:
  Sidebar widget

Management page:
  /my-favorite-galleries/

Note field:
  Plain text only
  2000 character max
  No HTML
  No Markdown
  No shortcodes

Table:
  Load all current member favorites
  Browser in-memory search
  Browser in-memory sorting
  No user-facing favorite cap
  Internal safety limit of 2000 rows

Security:
  REST nonce
  aMember session validation
  SQL prepared statements
  Output escaping by context
  Lightweight rate limiting
```

---

## 21. Summary

The right implementation is a narrow custom plugin:

- aMember-authenticated member identity is the authority.
- The sidebar widget controls the current Envira standalone gallery favorite state.
- A custom table stores member ID, gallery ID, note, favorite-created timestamp, and note-updated timestamp.
- A full favorites page provides a sortable/searchable AJAX table and lets the user edit notes or remove favorites.
- The note field is treated as hostile input and rendered only as escaped plain text.
- The design avoids broad third-party bookmark plugins and keeps the attack surface small.
