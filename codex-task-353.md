# Codex implementation report — task 353

**Date:** 2026-08-02  
**Repository:** `/home/morgan/git/rincity-tweaks`  
**Test host:** `rinling` / `test.rin-city.com`  
**Production host:** not contacted or modified  
**Odoo task:** #353 — “Replace shared s3-full-access-role with environment-specific instance roles” (`Rin-City.com`, project ID 1)

This implementation is recorded against Odoo task 353 because that task's
rinling-specific IAM role and scoped S3/CloudFront permissions are the Phase A
prerequisite for the media-sync replacement built and verified in this report.
The Odoo task remained in **Sprint in Progress** during this work; no Odoo fields,
stage, state, or chatter were modified.

## Scope and constraints followed

This work implemented Phases B, C, and D from
`/home/morgan/git/rincity-infra/plans/wpoml-replacement-media-sync-plugin.md`:

- Phase B: built the `rincity-media-sync` mu-plugin.
- Phase C: deployed and verified it on rinling only.
- Phase D: integrated `rincity-wallpaper-candidates` with the public media-sync
  action API and verified a complete crop/watermark/gallery-sync flow.

The following constraints were observed:

- No git write operations were performed. There was no branch, commit, push,
  pull request, merge, reset, checkout, or other git mutation.
- Read-only `git status`, `git diff`, and related inspection commands were used.
- Gelfling was never contacted or modified.
- Every command that ran on or affected rinling was preceded by a separate
  `ssh rinling "hostname"` check whose output was `rinling`.
- Every WordPress CLI command used `/home/morgan/bin/wp-lsphp` (normally written
  as `~/bin/wp-lsphp`), never plain `wp`.
- No script used `status` as a shell variable.
- Phase A was verified read-only and not redone.
- Phase E, Phase F, the parent plans, `rincity-infra/AGENTS.md`,
  `rincity-infra/CHANGELOG.md`, and `scripts/refresh_wordpress_test.sh` were not
  modified.
- Every attachment, draft gallery, generated image, temporary helper, local
  file, S3 object, and queue entry created for testing was cleaned up.

An unrelated pre-existing untracked `.serena/` directory remained untouched.

## Required source review

Before implementation, the following were read:

1. The complete `wpoml-replacement-media-sync-plugin.md` design, including
   Decisions 1–7, Phases B–D, Testing, and known risks.
2. The parent `cdn-enabler-wp-offload-cloudfront.md` plan for bucket topology,
   CDN Enabler behavior, the earlier WPOML query-count finding, and the Envira
   on-demand crop gap.
3. `rincity-tweaks/AGENTS.md` and the governing `rincity-infra/AGENTS.md`
   instructions.
4. The current `rincity-wallpaper-candidates` source, including the actual
   success guards in `RinCWC_Rest` and `RinCWC_Gallery_Sync`.
5. The current Makefile deploy patterns.

`RinCWC_Gallery_Sync::clear_gallery()` was specifically confirmed to call:

```php
wp_delete_post( (int) $att_id, true );
```

That routes attachment cleanup through WordPress's `delete_attachment` action,
so no separate wallpaper deletion hook was added.

## Working-tree changes

### New `rincity-media-sync` mu-plugin

The new plugin contains eight files and 667 lines:

| Mode | File | Lines | Purpose |
|---|---|---:|---|
| `0644` | `rincity-media-sync/rincity-media-sync.php` | 20 | Root mu-plugin loader and version header |
| `0644` | `rincity-media-sync/includes/class-config.php` | 59 | Compiled host map, option overrides, and kill-switch state |
| `0644` | `rincity-media-sync/includes/class-hooks.php` | 90 | Core write hooks, public actions, and WP-Cron registration |
| `0644` | `rincity-media-sync/includes/class-paths.php` | 78 | Pure absolute-path to S3-key conversion and guards |
| `0644` | `rincity-media-sync/includes/class-queue.php` | 75 | Atomic queue writes and detached worker spawn |
| `0755` | `rincity-media-sync/bin/rincity-media-sync-worker.sh` | 188 | Locked queue drain, S3 copy/delete, retries, and invalidation |
| `0644` | `rincity-media-sync/tests/test-paths.php` | 80 | Dependency-free path/config assertions |
| `0644` | `rincity-media-sync/README.md` | 77 | Behavior, configuration, deployment, tests, and changelog |

The loader is deployed to the root of `wp-content/mu-plugins/`; its
implementation lives in the `rincity-media-sync/` subdirectory.

### Hook implementation

The mu-plugin registers only write-path callbacks:

- `wp_handle_upload`, priority 10, two arguments:
  queues `$upload['file']` and returns the input array unchanged.
- `image_make_intermediate_size`:
  queues the completed derivative and returns `$filename` unchanged. The return
  line contains the required load-bearing comment because WordPress stores this
  result in attachment metadata.
- `delete_attachment`, priority 10, two arguments:
  enumerates the attached file, `original_image`, every registered metadata
  size, and `_wp_attachment_backup_sizes` while metadata still exists.

The public API is:

```php
do_action( 'rincity_media_sync_file', $absolute_path );
do_action( 'rincity_media_sync_delete', $absolute_path );
```

Both public actions use the same queue methods as the internal hooks.

### Configuration

The compiled map is:

| Host | Bucket | Distribution | Region |
|---|---|---|---|
| `gelfling` | `windsofstorm-rincity-s3` | `E2TEG3T8F8AC6S` | `us-east-1` |
| `rinling` | `windsofstorm-rincity-test-s3` | `EG4OSA4RQXBRS` | `us-east-1` |

Unknown hosts fail closed. The `rincity_media_sync` option supports `enabled`,
`bucket`, `distribution`, and `region`. No `wp-config.php` constant is used.

The hard kill switch is:

```text
/var/tmp/rincity-media-sync/DISABLED
```

While disabled, writes remain safely queued but the worker does not drain them.
This behavior supports the Phase C backlog-recovery acceptance test.

### Queue and asynchronous execution

Queue entries live under:

```text
/var/tmp/rincity-media-sync/queue/put/
/var/tmp/rincity-media-sync/queue/del/
```

Each entry is named `sha1(path)`, written to `.tmp`, then atomically renamed.
Only one shutdown spawn is registered per PHP request. The detached command uses
`/usr/bin/setsid`, and the worker takes a nonblocking exclusive `flock` on the
state lock file.

A recurring `rincity_media_sync_drain` WP-Cron event runs on the custom
five-minute schedule as a recovery backstop.

Failed items are renamed with an attempt suffix. After five attempts they move
to `/var/tmp/rincity-media-sync/failed/` and are logged at `user.err`.

### Path and S3-key rules

`RinMS_Paths::key_for()` implements:

- `realpath()` for put operations;
- lexical derivation for already-deleted paths;
- rejection of nonabsolute paths;
- rejection of explicit `..` path components;
- containment below `WP_CONTENT_DIR` after normalization;
- symlink traversal protection through the resolved path;
- rejection of the state directory;
- rejection of dot-prefixed path components; and
- `wp-content/`-prefixed, prefix-free S3 keys.

No file-extension allowlist is imposed.

### Worker behavior and invalidation

For puts, the worker runs `head-object` first, then `aws s3 cp`. An existing key
is accumulated for invalidation only after a successful copy. Deletes run
`aws s3 rm` and always accumulate the exact path for invalidation.

Paths are percent-encoded defensively, deduplicated, and sent to CloudFront in
batches of 500. New keys issue no invalidation. Invalidation failure is logged
but does not roll back or requeue a successful S3 operation.

### Makefile

`rincity-media-sync` was added to `.PHONY` and `all`. Its deploy target:

1. installs the root loader as `nobody:nogroup`, mode `0644`;
2. `rsync`s the implementation subdirectory with `--delete` while excluding the
   README, tests, and root loader;
3. sets the worker to `0755`; and
4. creates the state, put, delete, and failed directories as
   `nobody:nogroup`, mode `0755`.

The dependency-free suite is available through:

```bash
make test
```

The target uses `php` so it works both on the development workstation and on
rinling. This test is WordPress-free, so it does not conflict with the rule that
WordPress commands must use `wp-lsphp`.

### Documentation

- Added the plugin's README with a `1.0.0` changelog entry.
- Added `rincity-media-sync` to the `rincity-tweaks/AGENTS.md` plugin table as
  an mu-plugin.
- Updated the wallpaper README to version `3.11.6` with a one-line changelog
  entry.

### Wallpaper integration

`rincity-wallpaper-candidates` was bumped from `3.11.5` to `3.11.6`.

The three specified call sites now fire
`do_action('rincity_media_sync_file', $dst)` only after their existing success
checks:

1. `RinCWC_Rest::generate_selection_crop()` — after each raw tier crop exists.
2. `RinCWC_Rest::apply_watermark_to_row()` — in the `else` branch after the
   watermarked output exists.
3. `RinCWC_Gallery_Sync::generate_thumb()` — after the generated thumbnail
   exists.

The calls remain harmless on a host without the mu-plugin because an
unregistered WordPress action is a no-op.

## Local verification

### Dependency-free tests

```text
php rincity-media-sync/tests/test-paths.php
PASS: 12 media-sync path/config assertions
test-paths exit: 0
```

The assertions cover normal uploads, wallpaper crops, theme files, paths outside
`WP_CONTENT_DIR`, explicit traversal, symlink traversal, the state directory,
dotfile components, trailing-slash equivalence, unknown-host fail-closed
behavior, and equality of the PHP and shell host maps.

### ShellCheck

```text
shellcheck rincity-media-sync/bin/rincity-media-sync-worker.sh
shellcheck exit: 0
```

There were zero findings and no ShellCheck suppressions were introduced.

### PHP syntax and diff checks

Every PHP file in both affected plugin directories passed `php -l`.

```text
make test exit: 0
shellcheck exit: 0
PHP lint exit: 0
```

`git diff --check` produced no output.

Tracked-file numstat at the end of the work was:

```text
1  0  AGENTS.md
18 2  Makefile
2  1  rincity-wallpaper-candidates/README.md
4  0  rincity-wallpaper-candidates/includes/class-gallery-sync.php
5  0  rincity-wallpaper-candidates/includes/class-rest.php
1  1  rincity-wallpaper-candidates/rincity-wallpaper-candidates.php
```

The untracked new plugin accounts for the additional eight files and 667 lines
listed above.

## Rinling Phase A read-only verification

The instance identity was:

```json
{
    "UserId": "AROAZSI2LEZ6UB2O4LPGI:i-0db69c543ab2e8dda",
    "Account": "657722123901",
    "Arn": "arn:aws:sts::657722123901:assumed-role/rincity-rinling-instance-role/i-0db69c543ab2e8dda"
}
```

`head-bucket` confirmed `windsofstorm-rincity-test-s3` in `us-east-1`.
`ListInvalidations` succeeded for `EG4OSA4RQXBRS`.

An initial read probe used `cloudfront:GetDistribution`, which was denied:

```text
AccessDenied: ... is not authorized to perform: cloudfront:GetDistribution
```

This was not a Phase A failure. The narrow role intentionally grants
invalidation operations, not distribution administration. The relevant
read-only `ListInvalidations` call succeeded:

```text
I4QPOCF9CI72EOTJBA9W0SX0DA  Completed
```

No IAM changes were made.

## Deployment to rinling

Local working-tree files were copied directly into rinling's
`/home/morgan/rincity-tweaks/` checkout. No git command was run in that checkout.

The deployment commands were:

```bash
cd /home/morgan/rincity-tweaks
make rincity-media-sync
make rincity-wallpaper-candidates
```

The final deployed status was:

```text
name                        status    version
rincity-media-sync          must-use  1.0.0
rincity-wf-block-logger     must-use  1.0.0
rincity-wordfence-temp-allowlist must-use 1.0.1
rincity-zero-scheduled-seconds must-use 1.0.1
```

```text
name     rincity-wallpaper-candidates
version  3.11.6
status   active
```

The cron event was present:

```text
hook                        next_run_gmt          recurrence
rincity_media_sync_drain    2026-08-02 21:58:49   5 minutes
```

The final deployed-path modes were:

```text
drwxr-xr-x nobody nogroup rincity-media-sync
drwxr-xr-x nobody nogroup includes
-rw-r--r-- nobody nogroup class-config.php
```

The final site check returned:

```text
SITE HTTP 200
```

### Deployment defects found and corrected

Two defects were exposed during the first bootstrap and corrected in both the
local working tree and rinling's checkout/deployment:

1. The initial loader used `__DIR__ . '/'`, which resolved implementation
   includes relative to the root mu-plugin directory after deployment. The
   deployed bootstrap failed looking for:

   ```text
   /usr/local/lsws/wordpress/wp-content/mu-plugins/includes/class-config.php
   ```

   The loader was corrected to use:

   ```php
   __DIR__ . '/rincity-media-sync/'
   ```

2. Newly created local files initially inherited restrictive `0600`/`0700`
   modes. The first `rsync -a` preserved those modes, causing PHP permission
   errors. Source modes were corrected to `0644` for files, `0755` for
   directories and the worker, then redeployed. Final traversal and HTTP checks
   passed.

These errors caused a temporary bootstrap failure on rinling during the initial
deployment attempt. They were fixed before any media acceptance test began.

## Original upload and derivative verification

A 3200×2400 JPEG was generated at
`/tmp/rinms-phasec-20260802.jpg` and imported as attachment `21403`.

The first import attempt as `morgan` failed because the WordPress upload tree is
correctly writable by `nobody`, not `morgan`:

```text
Warning: Unable to import file '/tmp/rinms-phasec-20260802.jpg'.
Reason: The uploaded file could not be moved to wp-content/uploads/2026/08.
```

The import was rerun as the web-runtime identity:

```text
21403
```

Attachment metadata reported:

```json
{
    "attached": "2026/08/rinms-phasec-20260802-scaled.jpg",
    "width": 2560,
    "height": 1920,
    "original_image": "rinms-phasec-20260802.jpg"
}
```

The registered sizes were:

```text
medium                 rinms-phasec-20260802-400x300.jpg       3862
large                  rinms-phasec-20260802-1280x960.jpg      20585
thumbnail              rinms-phasec-20260802-200x400.jpg       2384
medium_large           rinms-phasec-20260802-768x576.jpg       9293
1536x1536              rinms-phasec-20260802-1536x1152.jpg     29478
2048x2048              rinms-phasec-20260802-2048x1536.jpg     49550
ashe-slider-grid       rinms-phasec-20260802-1140x540.jpg      11439
ashe-full              rinms-phasec-20260802-1140x855.jpg      17329
ashe-grid              rinms-phasec-20260802-500x330.jpg       4611
ashe-list              rinms-phasec-20260802-300x300.jpg       3016
ashe-navigation        rinms-phasec-20260802-75x75.jpg         571
```

The complete local file set was:

```text
rinms-phasec-20260802-1140x540.jpg 11439 bytes
rinms-phasec-20260802-1140x855.jpg 17329 bytes
rinms-phasec-20260802-1280x960.jpg 20585 bytes
rinms-phasec-20260802-1536x1152.jpg 29478 bytes
rinms-phasec-20260802-200x400.jpg 2384 bytes
rinms-phasec-20260802-2048x1536.jpg 49550 bytes
rinms-phasec-20260802-300x300.jpg 3016 bytes
rinms-phasec-20260802-400x300.jpg 3862 bytes
rinms-phasec-20260802-500x330.jpg 4611 bytes
rinms-phasec-20260802-75x75.jpg 571 bytes
rinms-phasec-20260802-768x576.jpg 9293 bytes
rinms-phasec-20260802-scaled.jpg 73207 bytes
rinms-phasec-20260802.jpg 126537 bytes
```

All 13 objects appeared in S3:

```text
2026-08-02 21:55:25  11439  wp-content/uploads/2026/08/rinms-phasec-20260802-1140x540.jpg
2026-08-02 21:55:31  17329  wp-content/uploads/2026/08/rinms-phasec-20260802-1140x855.jpg
2026-08-02 21:55:37  20585  wp-content/uploads/2026/08/rinms-phasec-20260802-1280x960.jpg
2026-08-02 21:55:22  29478  wp-content/uploads/2026/08/rinms-phasec-20260802-1536x1152.jpg
2026-08-02 21:55:43   2384  wp-content/uploads/2026/08/rinms-phasec-20260802-200x400.jpg
2026-08-02 21:55:40  49550  wp-content/uploads/2026/08/rinms-phasec-20260802-2048x1536.jpg
2026-08-02 21:55:55   3016  wp-content/uploads/2026/08/rinms-phasec-20260802-300x300.jpg
2026-08-02 21:55:58   3862  wp-content/uploads/2026/08/rinms-phasec-20260802-400x300.jpg
2026-08-02 21:55:46   4611  wp-content/uploads/2026/08/rinms-phasec-20260802-500x330.jpg
2026-08-02 21:55:49    571  wp-content/uploads/2026/08/rinms-phasec-20260802-75x75.jpg
2026-08-02 21:55:34   9293  wp-content/uploads/2026/08/rinms-phasec-20260802-768x576.jpg
2026-08-02 21:55:28  73207  wp-content/uploads/2026/08/rinms-phasec-20260802-scaled.jpg
2026-08-02 21:55:52 126537  wp-content/uploads/2026/08/rinms-phasec-20260802.jpg
```

The worker summary was:

```text
drain complete: put=13 del=0 invalidated=0 failures=0
```

No invalidation was issued because all keys were new.

## Local attachment URL over real HTTP

The public REST media response showed CDN URLs because CDN Enabler rewrote the
serialized HTTP output, so it could not prove the function's pre-buffer value.

A narrowly scoped temporary mu-plugin HTTP probe was installed. It called
`wp_get_attachment_url(21403)` and base64-encoded the result so CDN Enabler's
extension regex could not rewrite it.

The real HTTP result was:

```text
HTTP 200
Decoded: https://test.rin-city.com/wp-content/uploads/2026/08/rinms-phasec-20260802-scaled.jpg
```

The probe was removed from both rinling and the local working tree immediately
afterward.

## Envira on-demand crop regression verification

### Direct real Envira cropping path

The test called the real `envira_resize_image()` helper against attachment
`21403`:

```php
envira_resize_image(
    wp_get_attachment_url( 21403 ),
    320,
    400,
    true,
    'c',
    100,
    false,
    [ 'id' => 19285 ],
    false
);
```

This exercised `Envira\Utils\Cropping` and WordPress's image-editor save path;
it was not a synthetic file write.

Evidence:

```text
LOCAL /usr/local/lsws/wordpress/wp-content/uploads/2026/08/
      rinms-phasec-20260802-scaled-320x400_c.jpg
      23142 bytes
      2026-08-02 21:58:27.495003139 +0000
```

```text
S3 ContentLength: 23142
LastModified: 2026-08-02T21:58:31+00:00
ETag: "c9255709c60a532433bff8c31c46354e"
```

The S3 object appeared four seconds after the local save.

### Authenticated Alice page with a forced fresh crop

Alice is gallery `19285`, slug `alice`. The original serialized
`_eg_gallery_data` was saved before the test. Attachment `21403` was temporarily
appended, increasing the gallery from 77 to 78 entries. The existing
`320x400_c` crop was removed locally and all relevant Envira transients were
cleared.

An authenticated real HTTP load then regenerated the crop through Envira. It
also generated the gallery's other requested page-render sizes:

```text
rinms-phasec-20260802-scaled-320x400_c.jpg
rinms-phasec-20260802-scaled-640x800_c.jpg
rinms-phasec-20260802-scaled-60x80_c.jpg
```

The fresh `320x400_c` evidence was:

```text
LOCAL mtime: 2026-08-02 22:05:54.681835576 +0000
S3 LastModified: 2026-08-02T22:06:03+00:00
ContentLength: 23142
ETag: "c9255709c60a532433bff8c31c46354e"
```

Alice's exact saved metadata was restored after the request. The final gallery
count was confirmed as 77.

## Overwrite and exact-path CloudFront invalidation

Before the overwrite test, the distribution had one historical invalidation:

```text
count: 1
latest: I4QPOCF9CI72EOTJBA9W0SX0DA
created: 2026-08-02T20:28:11.454000+00:00
```

The existing Envira crop was re-enqueued through the public file action. After
the worker completed:

```text
count: 2
new ID: I281G0PHOT1I7XPLG250JSOFMH
created: 2026-08-02T21:59:12.825000+00:00
```

The new invalidation contained exactly one exact path:

```json
[
    1,
    [
        "/wp-content/uploads/2026/08/rinms-phasec-20260802-scaled-320x400_c.jpg"
    ],
    "Completed"
]
```

The corresponding worker summary was:

```text
drain complete: put=1 del=0 invalidated=1 failures=0
```

## Query-count measurement

The measurement helper read `DB_NAME`, `DB_USER`, `DB_PASSWORD`, and `DB_HOST`
directly from `/usr/local/lsws/wordpress/wp-config.php`. Secret values were not
printed. It ran `SHOW GLOBAL STATUS LIKE 'Questions'` immediately before and
after one authenticated request to Alice.

The first attempted measurement used curl with the Python cookie jar. Curl did
not accept that jar's session-cookie representation and measured the aMember
login page instead:

```text
HTTP 200 final=https://test.rin-city.com/amember/protect/new-rewrite?... redirects=1
Questions delta: 21
Envira item markers: 0
```

That result was rejected. The valid measurements used Python's
`MozillaCookieJar`, matching the repository's already-validated `am-fetch.py`
mechanism, and issued only the gallery request between the two DB reads.

### Existing crops

```text
HTTP 200 final=https://test.rin-city.com/envira/alice/ bytes=410204
Questions before: 386202
Questions after:  386246
Questions delta:  44
Envira item markers: 309
```

### Forced fresh on-demand crop

```text
HTTP 200 final=https://test.rin-city.com/envira/alice/ bytes=415236
Questions before: 386350
Questions after:  386428
Questions delta:  78
Envira item markers: 313
```

The results are lower than the previously recorded roughly 289-query WPOML-free
baseline, but are decisively nowhere near the 36,907-query WPOML-active result.
They are reported exactly rather than normalized or discarded.

## Kill-switch and backlog recovery

A 64×64 PNG was imported as attachment `21404` while
`/var/tmp/rincity-media-sync/DISABLED` existed.

Evidence while disabled:

```text
S3: absent while DISABLED
QUEUED:
/var/tmp/rincity-media-sync/queue/put/ad9b603923ac64164325a822fdc97e3739a2c148
/usr/local/lsws/wordpress/wp-content/uploads/2026/08/rinms-killswitch-20260802.png
FAILED:
ERROR LOGS:
-- No entries --
```

The sentinel was removed and the cron backstop was manually triggered:

```text
Executed the cron event 'rincity_media_sync_drain' in 0.01s.
Success: Executed a total of 1 cron event.
```

Recovered object evidence:

```text
ContentLength: 166
LastModified: 2026-08-02T22:00:35+00:00
ETag: "9f88958eb1d480cf1bcc376dfda62c36"
```

The queue and failed directories were empty afterward.

## Wallpaper crop → watermark → gallery-sync verification

### Test setup

Rinling had:

```json
{
    "approved_count": 0,
    "publish_galleries": {
        "4k": 0,
        "1440p": 0,
        "1080p": 0
    }
}
```

Calling gallery sync in that state would not exercise the successful
`generate_thumb()` path. Reusing the historical IDs from the plan would have
risked overwriting unrelated galleries because those IDs are no longer valid
publish targets.

The safe test procedure was therefore:

1. Snapshot candidate image row `2`, its selection row, and the exact raw
   `rincwc_settings` option.
2. Create three temporary draft Envira galleries:

   ```text
   4k:    21405
   1440p: 21406
   1080p: 21407
   ```

3. Temporarily configure those IDs as the publish galleries.
4. Run the actual REST callback methods with `WP_REST_Request` objects, matching
   the wp-admin flow.
5. Approve through the equivalent `RinCWC_Data::approve()` WP-CLI operation,
   since normal approve permissions were false for the CLI context.
6. Run the actual `sync_galleries` callback.

### Endpoint results

```text
select HTTP 200 {"ok":true,"status":"SELECTED"}
watermark HTTP 200 {"ok":true}
generate-crops HTTP 200 {"aid":21204,"status":"ok","files":3}
apply-watermarks HTTP 200 {"aid":21204,"status":"ok"}
approve WP-CLI equivalent OK
sync-galleries HTTP 200
```

Gallery sync summary:

```json
{
    "4k": {
        "gallery_id": 21405,
        "added": 1,
        "skipped": 0,
        "errors": []
    },
    "1440p": {
        "gallery_id": 21406,
        "added": 1,
        "skipped": 0,
        "errors": []
    },
    "1080p": {
        "gallery_id": 21407,
        "added": 1,
        "skipped": 0,
        "errors": []
    }
}
```

The created gallery attachment IDs were `21408`, `21409`, and `21410`.

### Explicit action output in S3

All 12 files directly associated with the three patched ImageMagick sites
appeared in S3:

```text
2026-08-02 22:10:08 854301 drop-shot_28_center_wm.jpg
2026-08-02 22:10:12 197364 drop-shot_21204_center_1080p.jpg
2026-08-02 22:10:47 406621 drop-shot_28_center_1440p_wm.jpg
2026-08-02 22:10:50 641591 drop-shot_21204_center.jpg
2026-08-02 22:10:53  28781 drop-shot_28_center_1440p_wm-320x180.jpg
2026-08-02 22:11:18 255487 drop-shot_28_center_1080p_wm.jpg
2026-08-02 22:11:27  19679 drop-shot_28_center_wm-128x72.jpg
2026-08-02 22:11:39  28775 drop-shot_28_center_wm-320x180.jpg
2026-08-02 22:11:42 310431 drop-shot_21204_center_1440p.jpg
2026-08-02 22:11:54  28796 drop-shot_28_center_1080p_wm-320x180.jpg
2026-08-02 22:12:07  19679 drop-shot_28_center_1440p_wm-128x72.jpg
2026-08-02 22:12:19  19678 drop-shot_28_center_1080p_wm-128x72.jpg
```

This proves all three integration sites:

- three raw selection crops;
- three watermarked images;
- three 320×180 review thumbnails; and
- three 128×72 filmstrip thumbnails.

WordPress also generated its normal attachment sizes, producing this complete
worker summary:

```text
drain complete: put=45 del=0 invalidated=2 failures=0
```

### Wallpaper cleanup and restoration

Every generated wallpaper file was queued through the public delete API before
local removal. The three temporary attachments and three temporary draft
galleries were deleted. The exact saved candidate and settings rows were then
restored.

Cleanup result:

```json
{
    "deleted_attachment_ids": [21408, 21409, 21410],
    "deleted_gallery_ids": [21405, 21406, 21407],
    "queued_file_deletes": 45,
    "row_restored": true,
    "settings_restored": true
}
```

The deletion drain reported:

```text
drain complete: put=0 del=45 invalidated=45 failures=0
```

Final state confirmation:

```json
{
    "row_status": "CANDIDATE",
    "selection_exists": false,
    "publish_galleries": {
        "4k": 0,
        "1440p": 0,
        "1080p": 0
    },
    "temp_posts_exist": [false, false, false],
    "temp_attachments_exist": [false, false, false]
}
```

```text
local-wallpaper-artifacts=0
s3-wallpaper-raw=0
s3-wallpaper-published=0
```

## Attachment delete verification

Before deletion, the bucket contained the original test attachment, its
metadata sizes, the kill-switch attachment, and three Envira page-render crops.

The attachments were deleted with:

```bash
~/bin/wp-lsphp --path=/usr/local/lsws/wordpress post delete 21403 21404 --force
```

Output:

```text
Success: Deleted post 21403.
Success: Deleted post 21404.
```

After the worker drained:

```text
queue-files=0
failed-files=0
```

The original, `-scaled` file, all 11 registered sizes, and the kill-switch PNG
were absent from S3. CloudFront invalidation
`I2K0MQIE7WG50N4G68GUFXR8FN` contained these 14 exact paths:

```json
[
    "/wp-content/uploads/2026/08/rinms-phasec-20260802.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-400x300.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-1280x960.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-500x330.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-scaled.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-1140x855.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-75x75.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-1140x540.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-768x576.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-2048x1536.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-1536x1152.jpg",
    "/wp-content/uploads/2026/08/rinms-killswitch-20260802.png",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-200x400.jpg",
    "/wp-content/uploads/2026/08/rinms-phasec-20260802-300x300.jpg"
]
```

The invalidation quantity was 14 and its status was `Completed`.

### Unregistered Envira derivative limitation

Three Envira page-render crops remained in S3 after attachment deletion:

```text
rinms-phasec-20260802-scaled-320x400_c.jpg
rinms-phasec-20260802-scaled-640x800_c.jpg
rinms-phasec-20260802-scaled-60x80_c.jpg
```

This is because Envira does not add these on-demand crops to
`_wp_attachment_metadata`. The plan's `delete_attachment` design deliberately
enumerates the original, scaled file, metadata sizes, and edit backups; it has
no record from which to discover arbitrary unregistered Envira crops.

No unplanned filesystem globbing behavior was added to the plugin. The three
test-only crop paths were explicitly queued through
`rincity_media_sync_delete`, yielding:

```text
drain complete: put=0 del=3 invalidated=3 failures=0
```

They were then confirmed absent. This limitation should be considered when the
plan's phrase “all derivatives” is interpreted; the implementation fulfills the
specified metadata enumeration but cannot automatically discover unregistered
third-party files.

## Journal evidence

The final `journalctl -t rincity-media-sync -n 20` output contained:

```text
Aug 02 21:55:57 rinling rincity-media-sync[34515]: drain complete: put=13 del=0 invalidated=0 failures=0
Aug 02 21:58:30 rinling rincity-media-sync[35918]: drain complete: put=1 del=0 invalidated=0 failures=0
Aug 02 21:59:13 rinling rincity-media-sync[36524]: drain complete: put=1 del=0 invalidated=1 failures=0
Aug 02 22:00:34 rinling rincity-media-sync[37502]: drain complete: put=1 del=0 invalidated=0 failures=0
Aug 02 22:06:07 rinling rincity-media-sync[40315]: drain complete: put=3 del=0 invalidated=1 failures=0
Aug 02 22:12:23 rinling rincity-media-sync[44614]: drain complete: put=45 del=0 invalidated=2 failures=0
Aug 02 22:14:33 rinling rincity-media-sync[46383]: drain complete: put=0 del=45 invalidated=45 failures=0
Aug 02 22:16:24 rinling rincity-media-sync[47379]: drain complete: put=0 del=14 invalidated=14 failures=0
Aug 02 22:18:09 rinling rincity-media-sync[48229]: drain complete: put=0 del=3 invalidated=3 failures=0
```

No failed queue entries or media-sync error-level entries were present.

## Final cleanup and health evidence

The final state was:

```text
queue-files=0
failed-files=0
kill-switch=absent
local-test-media=0
tmp-test-artifacts=0
s3-test-media-2026-08=0
s3-wallpaper-raw=0
s3-wallpaper-published=0
SITE HTTP 200
```

The final remote validation was:

```text
php rincity-media-sync/tests/test-paths.php
PASS: 12 media-sync path/config assertions

name                status    version
rincity-media-sync  must-use  1.0.0
```

Remote ShellCheck also completed with zero findings.

## Deviations, failures, and uncertainties

All observed deviations and failed first attempts are recorded here rather than
being omitted:

1. The first mu-plugin bootstrap failed due to the loader subdirectory path;
   fixed and redeployed.
2. The next bootstrap failed because restrictive source modes were preserved;
   source modes were corrected and redeployed.
3. The first media import ran as `morgan` and could not write the
   `nobody`-owned upload tree; the valid import ran as `nobody`.
4. A `GetDistribution` read probe was denied because the scoped role grants
   invalidation operations instead; the relevant `ListInvalidations` probe
   succeeded.
5. The first query-count attempt measured the login page because curl did not
   load the Python cookie jar compatibly; it was discarded and replaced with
   two verified authenticated measurements.
6. The measured query deltas, 44 and 78, were below the expected rough 289
   baseline. They are exact global counter deltas from authenticated gallery
   requests and were not altered.
7. Attachment deletion cannot discover the three unregistered Envira
   page-render crops. The public delete API removed these test artifacts; the
   implementation was not redesigned beyond the approved metadata enumeration.
8. The wallpaper environment had no configured publish galleries. Three
   isolated temporary draft galleries were used instead of risking unrelated
   content. All were deleted and all saved state was restored.

No remaining uncertainty was found in the final plugin load, queue drain, S3
copy/delete, exact invalidation, kill-switch recovery, query-cost comparison, or
wallpaper action integration results.

## Git and production handoff

The work ends with verified working-tree files and rinling deployment only.
There is no commit or pull request. Another operator must review the diff and
perform all requested git workflow steps later.

Production Phase E remains unperformed and requires explicit approval. Gelfling
was not contacted during this task.
