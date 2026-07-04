# rincity-wf-block-logger

**Version:** 1.0.0  
**Deploy path:** `wp-content/mu-plugins/rincity-wf-block-logger.php`  
**Type:** Must-use plugin (single PHP file, no directory)

## Description

Logs Wordfence IP ban events to the systemd journal as JSON, for ingestion by
Vector into the `ip-blocks-*` OpenSearch index. The daily error summary script
queries this index to suppress 5xx errors from IPs that were banned in the last
24 hours.

## Hooks

| Hook | Block types covered |
|------|-------------------|
| `wordfence_security_event` | Rate/flood blocks, WAF rule triggers |
| `wordfence_created_ip_pattern_block` | Manual IP blocks, automatic-permanent blocks |

Login lockouts (`createLockout`) have no Wordfence hook and are not captured —
they produce 401/403 responses, not 5xx errors, so they are not needed for
the suppression use case.

## Journal output

Events are written under syslog identifier `rincity-wf-block-logger`:

```json
{"action":"ban","ip":"1.2.3.4","reason":"Your IP was blocked...","source":"wordfence"}
```

Verify:

```bash
journalctl -t rincity-wf-block-logger -f
journalctl -t rincity-wf-block-logger --since "1 hour ago" -o json
```

## Deploy

```bash
sudo install -o nobody -g nogroup -m 644 \
  rincity-wf-block-logger/rincity-wf-block-logger.php \
  /usr/local/lsws/wordpress/wp-content/mu-plugins/rincity-wf-block-logger.php
```

Or via the Makefile:

```bash
make rincity-wf-block-logger
```

## See also

`rincity-infra/plans/ip-block-event-ingestion.md`

## Changelog

- **1.0.0** — Initial release.
