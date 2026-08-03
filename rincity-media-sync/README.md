# rincity-media-sync

**Version:** 1.0.0  
**Deploy path:** `wp-content/mu-plugins/rincity-media-sync.php` and
`wp-content/mu-plugins/rincity-media-sync/`

Always-on infrastructure plugin that asynchronously mirrors WordPress media writes
and deletes to the environment's S3 bucket. It does not rewrite URLs and registers no
read-path callbacks.

## Behavior

- Queues original uploads from `wp_handle_upload`.
- Queues every WordPress or Envira image-editor derivative from
  `image_make_intermediate_size`.
- Removes attachment originals, generated sizes, original-image backups, and edit
  backups through `delete_attachment` while their metadata is still available.
- Exposes `rincity_media_sync_file` and `rincity_media_sync_delete` actions for files
  written by other plugins.
- Drains asynchronously through one detached, `flock`-guarded worker, with a recurring
  five-minute WP-Cron event as a recovery backstop.
- Invalidates exact CloudFront paths on overwrite and delete, deduplicated and batched
  in groups of 500. Brand-new objects need no invalidation.

The accepted wallpaper-pipeline limitation remains: `-128x72.jpg` filmstrip files are
not registered in attachment metadata, so WordPress does not delete them locally and
the sync plugin cannot discover them during `delete_attachment`; they can remain as
small S3 orphans after a gallery clear.

**This applies more broadly than just the wallpaper pipeline.** Envira's own
on-demand page-render crops (e.g. `*_c.jpg` grid/lightbox sizes generated the first
time a gallery page is viewed) are never added to `_wp_attachment_metadata` either —
confirmed during Phase C verification. `delete_attachment` can only enumerate what
WordPress itself tracks, so any Envira-generated crop for a deleted attachment
orphans in S3, exactly as it already orphans on local disk today. Not a regression;
covered by Phase 8's daily reconciliation as the eventual backstop, not by this
plugin's real-time delete path.

## Configuration

The compiled hostname map is fail-closed:

| Host | Bucket | CloudFront distribution | Region |
|---|---|---|---|
| `gelfling` | `windsofstorm-rincity-s3` | `E2TEG3T8F8AC6S` | `us-east-1` |
| `rinling` | `windsofstorm-rincity-test-s3` | `EG4OSA4RQXBRS` | `us-east-1` |

The `rincity_media_sync` option may contain `enabled`, `bucket`, `distribution`, or
`region` overrides. For example:

```bash
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress option patch update rincity_media_sync enabled 0
```

The hard emergency switch is `/var/tmp/rincity-media-sync/DISABLED`. While either
switch is disabled, writes remain queued but workers do not drain them; removing the
switch lets the next request shutdown or WP-Cron drain publish the backlog.

Queue failures retry across worker runs and move to
`/var/tmp/rincity-media-sync/failed/` after five attempts. Logs use the
`rincity-media-sync` syslog identifier.

## Deploy

```bash
make rincity-media-sync
```

The Makefile installs the root mu-plugin loader, synchronizes its implementation
directory, makes the worker executable, and creates the `nobody:nogroup` state tree.
No activation step is needed for an mu-plugin.

## Test

```bash
make test
shellcheck rincity-media-sync/bin/rincity-media-sync-worker.sh
```

The plugin enqueues no CSS or JavaScript, so its version is bookkeeping only; there
are no WordPress asset query strings to cache-bust.

## Changelog

- **1.0.0** — Initial release replacing WP Offload Media with asynchronous, write-path-only S3 synchronization and exact CloudFront invalidation (Odoo task #17).

