#!/usr/bin/env python3
"""
rin_throwback_post.py

Reads an Envira cover manifest.json, randomly selects an eligible set (not posted within a threshold),
and posts a "throwback" post with the cover image attached, linking to the set, mentioning original
publish date, and including tags.

Auth is stored in a separate JSON file so you can migrate credentials later.

Default files (override via CLI):
  - manifest:       ./Rin_Covers/manifest.json
  - images dir:     ./Rin_Covers
  - auth file:      ./twitter_auth.json
  - history/state:  ./post_history.json
  - Twitter template: ./twitter_template.j2

Requires:
  pip install tweepy

Notes:
- Twitter/X media upload uses the v1.1 endpoint; post creation uses v2.
- Bluesky posting uses AT Protocol HTTP endpoints directly.
"""

from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import mimetypes
import os
import random
import re
import shutil
import subprocess
import sys
import tempfile
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Optional

try:
    import tweepy  # type: ignore
except Exception:
    tweepy = None  # type: ignore
try:
    import jinja2  # type: ignore
except Exception:
    jinja2 = None  # type: ignore


def _load_json(path: Path) -> Any:
    with path.open("r", encoding="utf-8") as f:
        return json.load(f)


def _save_json(path: Path, data: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, indent=2)
        f.write("\n")
    tmp.replace(path)


def _parse_iso8601(s: str) -> dt.datetime:
    return dt.datetime.fromisoformat(s)


def _fmt_publish_date(iso: str) -> str:
    d = _parse_iso8601(iso).date()
    return d.strftime("%b %d, %Y")


def _days_ago(ts: dt.datetime, now: dt.datetime) -> int:
    return (now.date() - ts.date()).days


def _fit_tags(base: str, tags: str, max_len: int) -> str:
    tags = (tags or "").strip()
    if not tags:
        return ""
    candidate = f"{base}\n\n{tags}"
    if len(candidate) <= max_len:
        return tags
    tag_list = [t for t in tags.split() if t.startswith("#")]
    kept: List[str] = []
    for t in tag_list:
        next_len = len(f"{base}\n\n{' '.join(kept + [t])}")
        if next_len <= max_len:
            kept.append(t)
        else:
            break
    return " ".join(kept)


def _magick_cmd() -> Optional[str]:
    return shutil.which("magick") or shutil.which("convert")


def _prepare_image_for_upload(image_path: Path, max_bytes: int) -> tuple[Path, Optional[Path]]:
    if image_path.stat().st_size <= max_bytes:
        return image_path, None

    size_mb = image_path.stat().st_size / (1024 * 1024)
    limit_mb = max_bytes / (1024 * 1024)
    print(f"Resizing before upload: {image_path.name} ({size_mb:.2f} MB > {limit_mb:.2f} MB)")

    cmd = _magick_cmd()
    if not cmd:
        raise RuntimeError("ImageMagick is required to resize large images (magick/convert not found).")

    suffix = image_path.suffix.lower()
    tmp_fd, tmp_name = tempfile.mkstemp(suffix=suffix)
    os.close(tmp_fd)
    Path(tmp_name).unlink(missing_ok=True)
    tmp_path = Path(tmp_name)

    if suffix in {".jpg", ".jpeg"}:
        args = [
            cmd,
            str(image_path),
            "-strip",
            "-resize",
            "2048x2048>",
            "-define",
            f"jpeg:extent={max_bytes}B",
            "-quality",
            "92",
            str(tmp_path),
        ]
        subprocess.run(args, check=True)
    elif suffix == ".png":
        args = [
            cmd,
            str(image_path),
            "-strip",
            "-resize",
            "2048x2048>",
            "-define",
            "png:compression-level=9",
            str(tmp_path),
        ]
        subprocess.run(args, check=True)
        if tmp_path.stat().st_size > max_bytes:
            tmp_path.unlink(missing_ok=True)
            tmp_path = tmp_path.with_suffix(".jpg")
            args = [
                cmd,
                str(image_path),
                "-strip",
                "-resize",
                "2048x2048>",
                "-background",
                "white",
                "-alpha",
                "remove",
                "-alpha",
                "off",
                "-define",
                f"jpeg:extent={max_bytes}B",
                "-quality",
                "92",
                str(tmp_path),
            ]
            subprocess.run(args, check=True)
    else:
        raise RuntimeError(f"Unsupported image format for auto-resize: {image_path.suffix}")

    if tmp_path.stat().st_size > max_bytes:
        size_mb = tmp_path.stat().st_size / (1024 * 1024)
        limit_mb = max_bytes / (1024 * 1024)
        raise RuntimeError(f"Resized image still too large: {size_mb:.2f} MB > {limit_mb:.2f} MB")

    return tmp_path, tmp_path


def _render_post_text(template_path: Path, context: Dict[str, Any], max_len: int = 280) -> str:
    if jinja2 is None:
        raise RuntimeError("jinja2 is not installed. Run: pip install jinja2")
    if not template_path.exists():
        raise RuntimeError(f"Template file not found: {template_path}")
    text = template_path.read_text(encoding="utf-8")
    env = jinja2.Environment(autoescape=False, keep_trailing_newline=True)
    template = env.from_string(text)
    rendered = template.render(**context).strip()
    if len(rendered) > max_len:
        return rendered[:max_len]
    return rendered


def _render_template_text(template_path: Path, context: Dict[str, Any], max_len: int) -> str:
    return _render_post_text(template_path=template_path, context=context, max_len=max_len)


def _bluesky_link_facets(text: str) -> List[Dict[str, Any]]:
    facets: List[Dict[str, Any]] = []
    for match in re.finditer(r"https?://\S+", text):
        raw_url = match.group(0)
        url = raw_url.rstrip(".,;:!?)]}")
        if not url:
            continue
        start = match.start()
        end = start + len(url)
        byte_start = len(text[:start].encode("utf-8"))
        byte_end = len(text[:end].encode("utf-8"))
        facets.append(
            {
                "index": {"byteStart": byte_start, "byteEnd": byte_end},
                "features": [{"$type": "app.bsky.richtext.facet#link", "uri": url}],
            }
        )
    return facets


def _normalize_quotes(text: str) -> str:
    return (
        text.replace("“", '"')
        .replace("”", '"')
        .replace("„", '"')
        .replace("«", '"')
        .replace("»", '"')
        .replace("’", "'")
        .replace("‘", "'")
        .replace("‚", "'")
        .replace("`", "'")
    )


def _load_history(history_path: Path) -> List[Dict[str, Any]]:
    if not history_path.exists():
        return []
    data = _load_json(history_path)
    return data if isinstance(data, list) else []


def _eligible_entries(manifest: List[Dict[str, Any]], history: List[Dict[str, Any]], threshold_days: int, now: dt.datetime) -> List[Dict[str, Any]]:
    last: Dict[str, dt.datetime] = {}
    for h in history:
        key = (h.get("set_url") or h.get("set_name") or "").strip()
        ts = h.get("posted_at") or h.get("tweeted_at")
        if not key or not ts:
            continue
        try:
            when = dt.datetime.fromisoformat(ts)
        except Exception:
            continue
        if key not in last or when > last[key]:
            last[key] = when

    eligible: List[Dict[str, Any]] = []
    for e in manifest:
        set_url = (e.get("set_url") or "").strip()
        set_name = (e.get("set_name") or "").strip()
        key = set_url or set_name
        if not key:
            continue
        when = last.get(key)
        if when is None or _days_ago(when, now) >= threshold_days:
            eligible.append(e)
    return eligible


def _normalized_set_name_for_match(name: str) -> str:
    return _normalize_quotes(html.unescape((name or "").strip())).casefold()


def _load_auth(auth_path: Path) -> Dict[str, str]:
    auth = _load_json(auth_path)
    if not isinstance(auth, dict):
        raise RuntimeError("Auth file must be a JSON object.")
    required = ["api_key", "api_secret", "access_token", "access_token_secret"]
    missing = [k for k in required if not auth.get(k)]
    if missing:
        raise RuntimeError(f"Auth file missing required keys: {', '.join(missing)}")
    return {k: str(v) for k, v in auth.items()}


def _twitter_clients(auth: Dict[str, str]):
    if tweepy is None:
        raise RuntimeError("tweepy is not installed. Run: pip install tweepy")

    api_key = auth["api_key"]
    api_secret = auth["api_secret"]
    access_token = auth["access_token"]
    access_token_secret = auth["access_token_secret"]
    bearer = auth.get("bearer_token")  # optional but recommended

    oauth1 = tweepy.OAuth1UserHandler(api_key, api_secret, access_token, access_token_secret)
    api_v1 = tweepy.API(oauth1)

    client_v2 = tweepy.Client(
        bearer_token=bearer if bearer else None,
        consumer_key=api_key,
        consumer_secret=api_secret,
        access_token=access_token,
        access_token_secret=access_token_secret,
        wait_on_rate_limit=True,
    )
    return api_v1, client_v2


def _load_bluesky_auth(auth_path: Path) -> Dict[str, str]:
    auth = _load_json(auth_path)
    if not isinstance(auth, dict):
        raise RuntimeError("Bluesky auth file must be a JSON object.")
    required = ["identifier", "app_password"]
    missing = [k for k in required if not auth.get(k)]
    if missing:
        raise RuntimeError(f"Bluesky auth missing required keys: {', '.join(missing)}")
    out = {k: str(v) for k, v in auth.items()}
    out.setdefault("service", "https://bsky.social")
    return out


def _http_json(url: str, payload: Dict[str, Any], headers: Optional[Dict[str, str]] = None) -> Dict[str, Any]:
    body = json.dumps(payload).encode("utf-8")
    req_headers = {"Content-Type": "application/json"}
    if headers:
        req_headers.update(headers)
    req = urllib.request.Request(url=url, data=body, headers=req_headers, method="POST")
    try:
        with urllib.request.urlopen(req) as resp:
            raw = resp.read()
    except urllib.error.HTTPError as e:
        details = ""
        try:
            details = e.read().decode("utf-8", errors="replace")
        except Exception:
            pass
        raise RuntimeError(f"HTTP {e.code} calling {url}: {details or e.reason}") from e
    try:
        return json.loads(raw.decode("utf-8"))
    except Exception as e:
        raise RuntimeError(f"Invalid JSON response from {url}") from e


def _bluesky_login(auth: Dict[str, str]) -> Dict[str, str]:
    service = auth.get("service", "https://bsky.social").rstrip("/")
    session = _http_json(
        f"{service}/xrpc/com.atproto.server.createSession",
        {"identifier": auth["identifier"], "password": auth["app_password"]},
    )
    access_jwt = session.get("accessJwt")
    did = session.get("did")
    handle = session.get("handle")
    if not access_jwt or not did:
        raise RuntimeError("Bluesky session response missing accessJwt or did.")
    out = {"service": service, "access_jwt": str(access_jwt), "did": str(did)}
    if handle:
        out["handle"] = str(handle)
    return out


def _bluesky_web_url_from_at_uri(at_uri: str, profile_ref: str) -> Optional[str]:
    # Expected AT URI format:
    # at://did:plc:.../app.bsky.feed.post/<rkey>
    prefix = "at://"
    if not at_uri.startswith(prefix):
        return None
    remainder = at_uri[len(prefix) :]
    parts = remainder.split("/")
    if len(parts) != 3:
        return None
    _, collection, rkey = parts
    if collection != "app.bsky.feed.post" or not rkey:
        return None
    return f"https://bsky.app/profile/{profile_ref}/post/{rkey}"


def _bluesky_upload_blob(service: str, access_jwt: str, image_path: Path) -> Dict[str, Any]:
    mime_type, _ = mimetypes.guess_type(str(image_path))
    if not mime_type:
        mime_type = "application/octet-stream"
    data = image_path.read_bytes()
    req = urllib.request.Request(
        url=f"{service}/xrpc/com.atproto.repo.uploadBlob",
        data=data,
        headers={
            "Authorization": f"Bearer {access_jwt}",
            "Content-Type": mime_type,
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req) as resp:
            raw = resp.read()
    except urllib.error.HTTPError as e:
        details = ""
        try:
            details = e.read().decode("utf-8", errors="replace")
        except Exception:
            pass
        raise RuntimeError(f"Bluesky blob upload failed (HTTP {e.code}): {details or e.reason}") from e
    payload = json.loads(raw.decode("utf-8"))
    blob = payload.get("blob")
    if not blob:
        raise RuntimeError("Bluesky blob upload response missing blob.")
    return blob


def _bluesky_create_post(
    service: str,
    access_jwt: str,
    did: str,
    text: str,
    created_at: str,
    image_blob: Dict[str, Any],
    alt_text: str,
) -> Dict[str, Any]:
    record: Dict[str, Any] = {
        "$type": "app.bsky.feed.post",
        "text": text,
        "createdAt": created_at,
    }
    facets = _bluesky_link_facets(text)
    if facets:
        record["facets"] = facets
    record["embed"] = {
        "$type": "app.bsky.embed.images",
        "images": [
            {
                "alt": alt_text[:1000],
                "image": image_blob,
            }
        ],
    }
    return _http_json(
        f"{service}/xrpc/com.atproto.repo.createRecord",
        {"repo": did, "collection": "app.bsky.feed.post", "record": record},
        headers={"Authorization": f"Bearer {access_jwt}"},
    )


def main() -> int:
    p = argparse.ArgumentParser(description="Post a random throwback post from an Envira cover manifest.")
    p.add_argument("--manifest", default="Rin_Covers/manifest.json", help="Path to manifest.json")
    p.add_argument("--images-dir", default="Rin_Covers", help="Directory containing downloaded cover images")
    p.add_argument("--auth", default="twitter_auth.json", help="Twitter auth JSON file (legacy flag)")
    p.add_argument("--twitter-auth", default=None, help="Twitter auth JSON file (overrides --auth)")
    p.add_argument("--bluesky-auth", default="bluesky_auth.json", help="Bluesky auth JSON file")
    p.add_argument("--history", default="post_history.json", help="State file to avoid repeats")
    p.add_argument("--threshold-days", type=int, default=90, help="Do not repeat a set within this many days")
    p.add_argument(
        "--set-name",
        default=None,
        help="Force a specific set_name from manifest (exact match, ignores history threshold filtering)",
    )
    p.add_argument("--seed", default=None, help="Optional RNG seed for reproducible choice")
    p.add_argument("--template", default="twitter_template.j2", help="Path to Twitter template file")
    p.add_argument(
        "--bluesky-template",
        default="bluesky_template.j2",
        help="Path to Bluesky template file (falls back to --template if not found)",
    )
    p.add_argument("--max-image-mb", type=int, default=5, help="Max image size for upload (MB)")
    p.add_argument(
        "--platform",
        choices=["twitter", "bluesky", "both"],
        default="both",
        help="Where to post. Default is both.",
    )
    p.add_argument("--dry-run", action="store_true", help="Do not post; just print what would be posted")
    p.add_argument(
        "--record-dry-run",
        action="store_true",
        help="When used with --dry-run, record the selection in history without posting",
    )
    args = p.parse_args()

    manifest_path = Path(args.manifest)
    images_dir = Path(args.images_dir)
    twitter_auth_path = Path(args.twitter_auth) if args.twitter_auth else Path(args.auth)
    bluesky_auth_path = Path(args.bluesky_auth)
    history_path = Path(args.history)
    template_path = Path(args.template)
    bluesky_template_path = Path(args.bluesky_template)
    if args.history == "post_history.json" and not history_path.exists():
        legacy_history = Path("tweet_history.json")
        if legacy_history.exists():
            history_path = legacy_history
    if args.template == "twitter_template.j2" and not template_path.exists():
        for legacy_name in ("post_template.j2", "tweet_template.j2"):
            legacy_template = Path(legacy_name)
            if legacy_template.exists():
                template_path = legacy_template
                break

    if not manifest_path.exists():
        print(f"ERROR: manifest not found: {manifest_path}", file=sys.stderr)
        return 2

    manifest = _load_json(manifest_path)
    if not isinstance(manifest, list):
        print("ERROR: manifest.json must be a list of entries", file=sys.stderr)
        return 2

    history = _load_history(history_path)
    now = dt.datetime.now(dt.timezone.utc)

    rng = random.Random(args.seed) if args.seed is not None else random.Random()
    if args.set_name:
        target = _normalized_set_name_for_match(args.set_name)
        matches = [e for e in manifest if _normalized_set_name_for_match(str(e.get("set_name") or "")) == target]
        if not matches:
            print(f"ERROR: no manifest entry found for set_name: {args.set_name}", file=sys.stderr)
            return 3
        if len(matches) > 1:
            print(f"WARNING: multiple manifest entries matched set_name; using first match: {args.set_name}")
        chosen = matches[0]
    else:
        eligible = _eligible_entries(manifest, history, args.threshold_days, now)
        if not eligible:
            print(f"No eligible sets found (threshold_days={args.threshold_days}).", file=sys.stderr)
            return 3
        chosen = rng.choice(eligible)

    filename = (chosen.get("filename") or "").strip()
    set_name = html.unescape((chosen.get("set_name") or "").strip())
    set_name = _normalize_quotes(set_name)
    set_url = (chosen.get("set_url") or "").strip()
    date_published = (chosen.get("date_published") or "").strip()
    tags = (chosen.get("tags") or "").strip()
    if tags:
        tags = tags.replace("-", "")

    if not filename or not set_name or not set_url or not date_published:
        print(f"ERROR: chosen entry missing required fields: {chosen}", file=sys.stderr)
        return 4

    print(f"Selected set: {set_url}")

    image_path = images_dir / filename
    if not image_path.exists():
        print(f"ERROR: image file not found: {image_path}", file=sys.stderr)
        return 5

    published = _fmt_publish_date(date_published)
    template_context = {
        "set_name": set_name,
        "set_url": set_url,
        "date_published_iso": date_published,
        "published": published,
        "tags": tags,
        "max_len": 280,
        "fit_tags": _fit_tags,
    }
    try:
        post_text = _render_template_text(template_path=template_path, context=template_context, max_len=280)
    except Exception as e:
        print(f"ERROR: template render failed: {e}", file=sys.stderr)
        return 9

    if args.dry_run:
        print("DRY RUN - would post:\n")
        print(post_text)
        print("\nWith image:", str(image_path))
        if args.record_dry_run:
            record = {
                "twitter_post_id": None,
                "posted_at": now.isoformat(),
                "filename": filename,
                "set_name": set_name,
                "set_url": set_url,
            }
            history.append(record)
            _save_json(history_path, history)
            print(f"\nRecorded dry run in history: {history_path}")
        return 0

    post_to_twitter = args.platform in {"twitter", "both"}
    post_to_bluesky = args.platform in {"bluesky", "both"}

    twitter_post_id: Optional[str] = None
    bluesky_uri: Optional[str] = None
    bluesky_url: Optional[str] = None
    max_bytes_twitter = args.max_image_mb * 1024 * 1024
    max_bytes_bluesky = min(max_bytes_twitter, 1_000_000)
    bluesky_text = post_text

    if post_to_twitter:
        if not twitter_auth_path.exists():
            print(f"ERROR: Twitter auth file not found: {twitter_auth_path}", file=sys.stderr)
            return 6
        try:
            auth = _load_auth(twitter_auth_path)
            api_v1, client_v2 = _twitter_clients(auth)
        except Exception as e:
            print(f"ERROR: Twitter auth/init failed: {e}", file=sys.stderr)
            return 6

        upload_path = image_path
        tmp_path: Optional[Path] = None
        try:
            upload_path, tmp_path = _prepare_image_for_upload(image_path, max_bytes=max_bytes_twitter)
            media = api_v1.media_upload(filename=str(upload_path))
            media_id = media.media_id
        except Exception as e:
            print(f"ERROR: Twitter media upload failed: {e}", file=sys.stderr)
            return 7
        finally:
            if tmp_path:
                tmp_path.unlink(missing_ok=True)

        try:
            resp = client_v2.create_tweet(text=post_text, media_ids=[media_id])
            twitter_post_id = getattr(resp, "data", {}).get("id") if resp else None
        except Exception as e:
            print(f"ERROR: Twitter post create failed: {e}", file=sys.stderr)
            return 8

    if post_to_bluesky:
        if not bluesky_auth_path.exists():
            print(f"ERROR: Bluesky auth file not found: {bluesky_auth_path}", file=sys.stderr)
            return 10
        upload_path = image_path
        tmp_path = None
        try:
            bsky_template_to_use = bluesky_template_path if bluesky_template_path.exists() else template_path
            bluesky_text = _render_template_text(
                template_path=bsky_template_to_use,
                context={**template_context, "max_len": 300},
                max_len=300,
            )
            bsky_auth = _load_bluesky_auth(bluesky_auth_path)
            bsky_session = _bluesky_login(bsky_auth)
            upload_path, tmp_path = _prepare_image_for_upload(image_path, max_bytes=max_bytes_bluesky)
            blob = _bluesky_upload_blob(bsky_session["service"], bsky_session["access_jwt"], upload_path)
            created_at = now.isoformat().replace("+00:00", "Z")
            post = _bluesky_create_post(
                service=bsky_session["service"],
                access_jwt=bsky_session["access_jwt"],
                did=bsky_session["did"],
                text=bluesky_text,
                created_at=created_at,
                image_blob=blob,
                alt_text=f"Cover image for {set_name}",
            )
            bluesky_uri = str(post.get("uri")) if post.get("uri") else None
            if bluesky_uri:
                profile_ref = str(bsky_session.get("handle") or bsky_session["did"])
                bluesky_url = _bluesky_web_url_from_at_uri(bluesky_uri, profile_ref)
        except Exception as e:
            print(f"ERROR: Bluesky post failed: {e}", file=sys.stderr)
            return 11
        finally:
            if tmp_path:
                tmp_path.unlink(missing_ok=True)

    record = {
        "twitter_post_id": str(twitter_post_id) if twitter_post_id else None,
        "bluesky_uri": bluesky_uri,
        "bluesky_url": bluesky_url,
        "posted_at": now.isoformat(),
        "filename": filename,
        "set_name": set_name,
        "set_url": set_url,
    }
    history.append(record)
    _save_json(history_path, history)

    print(f"Posted throwback for set: {set_name}")
    if twitter_post_id:
        print(f"Twitter URL: https://x.com/i/web/status/{twitter_post_id}")
    if bluesky_url:
        print(f"Bluesky URL: {bluesky_url}")
    elif bluesky_uri:
        print(f"Bluesky URI: {bluesky_uri}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
