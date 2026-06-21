# rincity-wordfence-temp-allowlist

**Version:** 1.0.1  
**Deploy path:** `wp-content/mu-plugins/rincity-wordfence-temp-allowlist.php`  
**Type:** Must-use plugin (single PHP file, no directory)

## Description

Automatically adds the user's current client IP to the Wordfence IP allowlist for a configurable duration after a successful WordPress login. On each login, any previously tracked temporary entry is removed first, so only one temporary IP is tracked at a time. The entry expires via WP-Cron and a lazy-cleanup fallback on admin page loads.

## Required constants (`wp-config.php`)

```php
define( 'RINCITY_WF_TEMP_ALLOWLIST_USER_IDS', '3,50' ); // Comma-separated WP user IDs to cover
define( 'RINCITY_WF_TEMP_ALLOWLIST_TTL', 21600 );        // Duration in seconds (21600 = 6 hours)
```

Only users whose ID appears in `RINCITY_WF_TEMP_ALLOWLIST_USER_IDS` trigger the allowlist logic. All other logins are ignored.

## Behavior

1. **On login** — validates the client IP from `REMOTE_ADDR` (X-Forwarded-For is not trusted on this OLS stack), removes any previously tracked IP from Wordfence, adds the new IP, stores metadata in `wp_options`, and schedules a WP-Cron expiry event.
2. **On expiry (WP-Cron)** — removes the IP from Wordfence and deletes the stored metadata.
3. **Lazy cleanup** — on every admin page load, if the stored entry is past its expiry time and WP-Cron hasn't fired yet, the IP is removed and the metadata deleted. Guards against delayed or missed cron runs.
4. **WAF cache purge** — after any allowlist change, attempts to purge the Wordfence WAF IP block cache via `wfWAF::getInstance()->getStorageEngine()->purgeIPBlocks()`.

## Deploy

```bash
sudo install -o nobody -g nogroup -m 644 \
  rincity-wordfence-temp-allowlist/rincity-wordfence-temp-allowlist.php \
  /usr/local/lsws/wordpress/wp-content/mu-plugins/rincity-wordfence-temp-allowlist.php
```

Or via the Makefile:

```bash
make rincity-wordfence-temp-allowlist
```

## WP-CLI

```bash
# Show current status
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-wf-temp-allowlist status

# Manually clear the temporary entry
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress rincity-wf-temp-allowlist clear
```

`status` output includes the tracked IP, when it was added, when it expires (and whether it's already stale), and whether the IP is currently present in Wordfence.

## Audit log

All actions are logged via `error_log` with the prefix `[rincity-wf-temp-allowlist]`, including skipped cases (missing constants, invalid IP, Wordfence inactive).

## Changelog

- **1.0.1** — Current version.
- **1.0.0** — Initial release.
