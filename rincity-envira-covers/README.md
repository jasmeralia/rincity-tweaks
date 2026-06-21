# rincity-envira-covers

## Description

A WP-CLI `eval-file` script that scans all published Envira galleries and writes a JSON manifest of cover images to disk. The manifest is consumed by `rincity-throwback-posts` to generate throwback content for X/Twitter and Bluesky.

Not a WordPress plugin — runs as a standalone CLI script via `run_envira_covers.sh`.

## Files

| File | Purpose |
|------|---------|
| `rin_envira_covers.php` | WP-CLI eval-file script; scans galleries and writes the manifest |
| `run_envira_covers.sh` | Wrapper shell script for cron; suppresses output on no-op runs |
| `cron.log` | Appended to by `run_envira_covers.sh` on every run (not committed) |

## Output

The script writes cover images to a flat directory and a `manifest.json` alongside them:

**Default output directory:** `<wp-uploads>/Rin_Covers/`

**Filename format:** `YYYY-MM-DD-<set-name>.<ext>` — prefixed with publish date so files sort chronologically.

**`manifest.json`** — array of objects, one per gallery, sorted newest-first:

```json
[
  {
    "post_id": 3740,
    "title": "Bloomlight",
    "date": "2026-05-30",
    "slug": "bloomlight",
    "cover_file": "2026-05-30-bloomlight.jpg",
    "cover_path": "/path/to/Rin_Covers/2026-05-30-bloomlight.jpg"
  }
]
```

## Environment variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RIN_OUT` | `<wp-uploads>/Rin_Covers` | Output directory for cover images and manifest |
| `RIN_DRY_RUN` | _(unset)_ | Set to any value to skip writing files |
| `RIN_DEBUG` | _(unset)_ | Set to any value for verbose per-gallery logging |
| `RIN_LIMIT` | `0` (all) | Limit to N most-recent galleries |

## Exclusions

Galleries tagged with the Envira term `dustrat` are skipped.

## Cron usage

`run_envira_covers.sh` is designed for cron. It:
- Runs the PHP script and captures stdout/stderr to a temp file
- Appends all output to `cron.log`
- Only emits output to stdout if the manifest changed or the script failed — so cron sends no email on no-op runs

Example crontab entry:
```
0 4 * * * /home/morgan/rincity-tweaks/rincity-envira-covers/run_envira_covers.sh
```

## Manual run

```bash
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress eval-file \
  /home/morgan/rincity-tweaks/rincity-envira-covers/rin_envira_covers.php

# Dry run
RIN_DRY_RUN=1 ~/bin/wp-lsphp --path=/usr/local/lsws/wordpress eval-file \
  /home/morgan/rincity-tweaks/rincity-envira-covers/rin_envira_covers.php
```

## Changelog

- **Current** — Output filenames prefixed with publish date (`YYYY-MM-DD-`) for chronological sorting.
