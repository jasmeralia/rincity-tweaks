# rincity-zero-scheduled-seconds

**Version:** 1.0.1  
**Deploy path:** `wp-content/mu-plugins/rincity-zero-scheduled-seconds.php`  
**Type:** Must-use plugin (single PHP file, no directory)

## Description

Strips sub-minute precision from scheduled post times so WP-Cron fires on the exact intended minute.

## Problem

When a post is saved with a `future` status, WordPress records the wall-clock time including seconds — e.g. `2026-06-21 07:00:25 UTC` rather than `2026-06-21 07:00:00 UTC`. WP-Cron checks every minute on the minute. A post saved at `07:00:25` won't be published until the `07:01:00` cron tick, which causes:

1. A brief **"Missed schedule"** error displayed on the post between `07:00:00` and `07:01:00`
2. The post publishing up to 59 seconds later than the intended time shown to the editor

## Solution

A `wp_insert_post_data` filter zeroes the seconds field on every save where `post_status === 'future'`, storing `07:00:00` instead. The next cron tick at `07:01:00` is then the first opportunity after the intended time, eliminating both the missed-schedule flash and the late-publish delay.

## Deploy

```bash
sudo install -o nobody -g nogroup -m 644 \
  rincity-zero-scheduled-seconds/rincity-zero-scheduled-seconds.php \
  /usr/local/lsws/wordpress/wp-content/mu-plugins/rincity-zero-scheduled-seconds.php
```

Or via the Makefile:

```bash
make rincity-zero-scheduled-seconds
```

## Changelog

- **1.0.1** — Current version.
- **1.0.0** — Initial release.
