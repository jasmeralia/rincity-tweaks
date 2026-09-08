# rincity-plugin-update-email-recipient

**Version:** 1.0.0  
**Deploy path:** `wp-content/mu-plugins/rincity-plugin-update-email-recipient.php`  
**Type:** Must-use plugin (single PHP file, no directory)

## Description

Reroutes WordPress's background plugin/theme auto-update summary email to one site-specific recipient without changing `admin_email`, so core-update summaries, comment moderation notices, and other administrative email continue using the site's normal recipient.

## Required constant (`wp-config.php`)

```php
define( 'RINCITY_PLUGIN_UPDATE_EMAIL_RECIPIENT', 'recipient@example.com' );
```

Set `RINCITY_PLUGIN_UPDATE_EMAIL_RECIPIENT` to a string email address. The recipient is site-specific and intentionally not committed to this public repository, for the same reason that `RINCITY_WF_TEMP_ALLOWLIST_USER_IDS` is kept in `wp-config.php`. If the constant is missing or empty, WordPress retains its original recipient.

## Changelog

- **1.0.0** — Initial release.
