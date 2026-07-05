# Rin Throwback Poster (X + Bluesky)

Automates a random throwback post from an Envira `manifest.json`.

Default behavior posts to **both X/Twitter and Bluesky**.

## Features

- Randomly selects an eligible set from `manifest.json`
- Avoids repeats using `post_history.json`
- Renders Twitter post text with Jinja template (`twitter_template.j2`)
- Uploads image and posts to:
  - X/Twitter (`--platform twitter`)
  - Bluesky (`--platform bluesky`)
  - Both (`--platform both`)
- Supports `--dry-run`
- Sends an HTML summary email (with the cover image embedded inline) after every run, dry or live

## Requirements

- Python 3.10+
- Dependencies:
  - `tweepy`
  - `jinja2`
- Optional but recommended for oversized images:
  - ImageMagick (`magick` or `convert`)

Install Python deps:

```bash
pip install -r requirements.txt
```

## Quick Start

From `rin_throwback_post/`:

```bash
python3 rin_throwback_post.py --dry-run
```

Default files:

- Manifest: `Rin_Covers/manifest.json`
- Images dir: `Rin_Covers/`
- Twitter auth: `twitter_auth.json`
- Bluesky auth: `bluesky_auth.json`
- History: `post_history.json`
- Twitter template: `twitter_template.j2`
- Bluesky template: `bluesky_template.j2`

## Usage

Both platforms (default):

```bash
python3 rin_throwback_post.py
```

Explicit Twitter:

```bash
python3 rin_throwback_post.py --platform twitter
```

Bluesky only:

```bash
python3 rin_throwback_post.py --platform bluesky --bluesky-auth bluesky_auth.json --bluesky-template bluesky_template.j2
```

Retry a specific set on one platform (ignores history threshold filtering):

```bash
python3 rin_throwback_post.py --platform bluesky --set-name "Set Title Here" --bluesky-auth bluesky_auth.json
```

Both platforms:

```bash
python3 rin_throwback_post.py --platform both --twitter-auth twitter_auth.json --bluesky-auth bluesky_auth.json
```

Dry run with deterministic selection:

```bash
python3 rin_throwback_post.py --dry-run --seed 123
```

## Authentication Files

### Twitter auth JSON

Create `twitter_auth.json`:

```json
{
  "api_key": "...",
  "api_secret": "...",
  "access_token": "...",
  "access_token_secret": "...",
  "bearer_token": "..."
}
```

Required keys:

- `api_key`
- `api_secret`
- `access_token`
- `access_token_secret`

`bearer_token` is optional in this script.

### Bluesky auth JSON

Create `bluesky_auth.json`:

```json
{
  "identifier": "your-handle.bsky.social",
  "app_password": "xxxx-xxxx-xxxx-xxxx",
  "service": "https://bsky.social"
}
```

Required keys:

- `identifier` (your handle or account identifier)
- `app_password` (recommended instead of your main account password)

Optional key:

- `service` (defaults to `https://bsky.social`)

## How To Obtain Credentials

### X/Twitter credentials

1. Create/sign in to your developer account in the X Developer Portal.
2. Create a Project and App (or select an existing App).
3. In your App, open **Keys and tokens**.
4. Copy/generate:
   - API Key + API Secret
   - Access Token + Access Token Secret (user context)
   - Bearer Token (optional for this script)
5. Put them in `twitter_auth.json`.

Official docs:

- API quickstart: <https://docs.x.com/x-api/getting-started/quickstart>
- Access levels and plans: <https://docs.x.com/x-api/fundamentals/access>
- OAuth 1.0a user context flow (for user access tokens): <https://docs.x.com/resources/fundamentals/authentication/oauth-1-0a/obtaining-user-access-tokens>

Notes:

- If you change App permissions, regenerate tokens.
- X API limits/pricing can change by tier.

### Bluesky credentials

1. Sign in to Bluesky and open app passwords settings:
   - <https://bsky.app/settings/app-passwords>
2. Create an app password for this script and copy it.
3. Use your handle as `identifier` and the generated app password as `app_password` in `bluesky_auth.json`.

Official docs:

- Get started (create session + post): <https://docs.bsky.app/docs/get-started>
- API endpoint reference for creating app passwords: <https://docs.bsky.app/docs/api/com-atproto-server-create-app-password>
- Post creation tutorial (record structure and embeds): <https://docs.bsky.app/docs/tutorials/creating-a-post>

## CLI Options

```text
--manifest PATH
--images-dir PATH
--auth PATH                  # legacy Twitter auth flag
--twitter-auth PATH          # preferred Twitter auth flag
--bluesky-auth PATH
--history PATH
--threshold-days INT
--set-name "SET NAME"
--seed VALUE
--template PATH               # Twitter template
--bluesky-template PATH
--max-image-mb INT
--platform {twitter,bluesky,both}
--dry-run
--record-dry-run
--email-to EMAIL               # default: morgan@windsofstorm.net
--no-email
```

## Email Notifications

After every run — dry or live — an HTML summary email is sent to `--email-to`
(default `morgan@windsofstorm.net`), unless `--no-email` is passed. The email
includes:

- The cover image, resized to 600px wide and embedded inline
- Set name, published date, and the rendered post text
- A "View Gallery" button linking to the gallery page
- X/Twitter and Bluesky post links (labelled "(mock)" on dry runs)
- A red **DRY RUN** banner when the run did not actually post to social media
- Subject line prefixed with `[rin-city.com]`

Credentials and sender config are read from a `.env` file (gitignored) next to
the script, not from CLI flags:

```
FROM_EMAIL=...
REPLY_TO_EMAIL=...       # optional, defaults to FROM_EMAIL
SMTP_HOST=smtp.gmail.com # optional, this is the default
SMTP_PORT=465            # optional, this is the default
SMTP_USER=...            # optional, defaults to FROM_EMAIL
SMTP_PASS=...            # Gmail app password
```

Sending is delivered via SMTP over SSL (`smtplib.SMTP_SSL`). A failed email send
prints a warning but does not fail the run — the throwback post itself (or dry
run) already completed.

## Notes

- Default platform is `both`.
- Bluesky posts include link facets so URLs become clickable links in-app.
- For Bluesky posting, the script constrains image uploads to a conservative size for compatibility.
- History is shared across platforms in `post_history.json`.
- Legacy `post_template.j2` and `tweet_template.j2` are auto-detected for template backward compatibility.
- Legacy `tweet_history.json` is still auto-detected for history backward compatibility.
