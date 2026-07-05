# AGENTS.md — rincity-tweaks Plugin Conventions

AI agent reference for the `rincity-tweaks` repository. Read this before making
any changes to plugins here.

Also read `~/rincity-infra/AGENTS.md` — it is the primary reference for the
broader rin-city.com infrastructure and contains rules that govern all work on
the server, including git workflow, deployment, changelog requirements, and
production safety.

---

## How This Repo Is Used

Morgan keeps `rincity-infra` open as the primary persistent Claude Code session
on both gelfling (production) and rinling (test). Changes to this repo are made
**from within that session**, not by invoking Claude directly here. When you are
working on a plugin, you are almost certainly operating from a `rincity-infra`
session with this repo at `~/rincity-tweaks/`.

---

## Git Workflow

**Never commit directly to `master`.** All changes go through a feature branch
and pull request:

```bash
git checkout -b feature/<description>
# make changes, commit
git push -u origin feature/<description>
gh pr create ...
```

Merge only after review. The PR is the record of intent; the commit messages are
the record of what changed.

---

## Deploy

```bash
cd ~/rincity-tweaks && make <plugin-name>   # single plugin
cd ~/rincity-tweaks && make all             # all plugins
```

The Makefile deploys to `/usr/local/lsws/wordpress/wp-content/plugins/` with
`--chown=nobody:nogroup`. WordPress runs as `nobody`; files owned by anything
else will cause PHP include failures.

When adding a new plugin, add a target to `Makefile` following the existing
pattern, and add the target name to both `.PHONY` and `all`.

New plugins need to be activated once after first deploy:
```bash
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress plugin activate <slug>
```

---

## Plugin Structure

Each plugin follows this layout:

```
rincity-<name>/
  rincity-<name>.php     ← main file; plugin header + enqueue hooks
  assets/
    rincity-<name>.js    ← frontend JS (if any)
    rincity-<name>.css   ← frontend CSS (if any)
  README.md              ← description, features, changelog
```

`README.md` is excluded from deployment by `make` (`--exclude='README.md'`).

---

## Versioning and Asset Cache-Busting

WordPress appends `?ver=X.Y.Z` to enqueued asset URLs. This is the only
cache-busting mechanism in use — there is no build step or content-hash.

**Rules:**

1. Read the version from the plugin header using `get_file_data()` and store it
   as a constant:
   ```php
   $data = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
   define( 'RINCITY_MY_PLUGIN_VERSION', $data['Version'] );
   ```

2. Pass that constant as the `$ver` argument to every `wp_enqueue_style()` and
   `wp_enqueue_script()` call in the plugin.

3. **Bump the version in the plugin header (`* Version: X.Y.Z`) whenever JS or
   CSS changes.** Deploy without bumping = browsers serve stale files.

4. Add a changelog entry in `README.md` for every version bump. One line is
   enough; it should describe what changed and why.

Version format: `MAJOR.MINOR.PATCH` for established plugins; `1.0.0` for new
plugins at initial release.

---

### Documentation must reflect current state
Any code change must be accompanied by updates to every `.md` file that describes
the changed behavior (plugin `README.md`, `AGENTS.md`, etc.) in the same commit.
Do not defer doc updates to a later pass — stale docs are treated as a bug, not a
cleanup task. Before marking work done, re-read every doc file that mentions the
changed component and correct anything no longer accurate (version numbers,
feature lists, admin page names, endpoint names, workflow steps).

### Keep open PRs in sync with their own commits
If you push additional commits to a branch that already has an open pull request,
update that PR's title and description in the same step (`gh pr edit`) so they
describe the PR's current full contents — not just what was true when it was
opened. Do this every time the scope changes, not only right before merge.

---

## Changelog (README.md)

Each plugin's `README.md` has a `## Changelog` section. Add entries newest-first:

```markdown
- **1.2.3** — One-line description of what changed and the reason.
- **1.2.2** — ...
```

Keep entries on a single line. Reference Odoo task IDs where relevant.

---

## Plugins in This Repo

| Plugin | Deploy path | Notes |
|---|---|---|
| `rc_tweaks` | `plugins/rc_tweaks/` | General site tweaks; amember bridge, album page, feed generator |
| `rincity-envira-exif` | `plugins/rincity-envira-exif/` | Per-gallery EXIF summary block on gallery pages |
| `rincity-envira-zoom` | `plugins/rincity-envira-zoom/` | Panzoom lightbox zoom; HD background-load; image info overlay |
| `rincity-envira-search-enhancements` | `plugins/rincity-envira-search-enhancements/` | |
| `rincity-gallery-favorites` | `plugins/rincity-gallery-favorites/` | Member favorites system |
| `rincity-image-size-control` | `plugins/rincity-image-size-control/` | Suppresses unwanted intermediate image sizes on upload |
| `rincity-nav-tweaks` | `plugins/rincity-nav-tweaks/` | Fixes mobile submenu expand/collapse and desktop scroll-to-top on the Ashe Pro theme |
| `rincity-news-widget` | `plugins/rincity-news-widget/` | |
| `rincity-wallpaper-candidates` | `plugins/rincity-wallpaper-candidates/` | Wallpaper candidate tracking |
| `rincity-wf-block-logger` | **mu-plugins** | Logs Wordfence IP ban events to the systemd journal for Vector/OpenSearch ingestion |
| `rincity-wordfence-temp-allowlist` | **mu-plugins** | Auto-allowlist for Morgan's IP |
| `rincity-zero-scheduled-seconds` | **mu-plugins** | Forces scheduled post times to :00 seconds |
| `rincity-envira-covers` | _(cron script, not a WP plugin)_ | Indexes cover images nightly |
| `rincity-throwback-posts` | _(cron script, not a WP plugin)_ | Daily throwback post creator |

---

## Server Context

- **Web PHP:** `/usr/local/lsws/lsphp83/bin/php` (PHP 8.3, LiteSpeed SAPI)
- **WP-CLI:** always use `~/bin/wp-lsphp --path=/usr/local/lsws/wordpress`
- **WordPress root:** `/usr/local/lsws/wordpress`
- **Plugin directory:** `/usr/local/lsws/wordpress/wp-content/plugins/`
- **mu-plugin directory:** `/usr/local/lsws/wordpress/wp-content/mu-plugins/`

See `~/rincity-infra/AGENTS.md` for full server path reference, OLS/PHP workflow
rules, and production safety requirements.
