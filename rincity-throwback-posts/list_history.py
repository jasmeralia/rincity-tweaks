#!/usr/bin/env python3
"""
list_history.py

Companion to rin_throwback_post.py. Reads post_history.json and prints every
recorded throwback post with the date it went out and which platform(s) it
was posted to.

Read-only: does not post anywhere or modify post_history.json.

Default file (matches rin_throwback_post.py):
  - history/state: ./post_history.json
"""

from __future__ import annotations

import argparse
import datetime as dt
import html
import json
import signal
import sys
from typing import Any

import rin_throwback_post as rtb


def _entry_posted_at(h: dict[str, Any]) -> dt.datetime | None:
    ts = h.get("posted_at") or h.get("tweeted_at")
    if not ts:
        return None
    try:
        return dt.datetime.fromisoformat(ts)
    except Exception:
        return None


def _entry_platforms(h: dict[str, Any]) -> list[str]:
    platforms: list[str] = []
    if h.get("twitter_post_id") or h.get("tweet_id"):
        platforms.append("Twitter")
    if h.get("bluesky_uri") or h.get("bluesky_url"):
        platforms.append("Bluesky")
    return platforms


def _entry_twitter_url(h: dict[str, Any]) -> str | None:
    post_id = h.get("twitter_post_id") or h.get("tweet_id")
    return f"https://x.com/i/web/status/{post_id}" if post_id else None


def _entry_bluesky_url(h: dict[str, Any]) -> str | None:
    stored = h.get("bluesky_url")
    if stored:
        return stored
    uri = h.get("bluesky_uri")
    if not uri or not uri.startswith("at://"):
        return None
    # No profile handle is stored in history, but the AT URI's own DID is a
    # valid (if less readable) profile reference for a bsky.app URL.
    did = uri[len("at://") :].split("/", 1)[0]
    return rtb._bluesky_web_url_from_at_uri(uri, did)


def _display_name(raw: str) -> str:
    return rtb._normalize_quotes(html.unescape((raw or "").strip()))


def main() -> int:
    p = argparse.ArgumentParser(
        description="List recorded throwback posts from post_history.json, newest first by default."
    )
    p.add_argument("--history", default="post_history.json", help="State file to read")
    p.add_argument(
        "--sort",
        choices=["recent", "oldest", "name"],
        default="recent",
        help="recent (default): most recently posted first; oldest: least recently posted first; "
        "name: alphabetical by set name",
    )
    p.add_argument("--set-name", default=None, help="Only show history entries for this set (case-insensitive)")
    p.add_argument("--limit", type=int, default=0, help="Only show the first N rows (0 = all)")
    p.add_argument("--json", action="store_true", help="Print machine-readable JSON instead of a table")
    args = p.parse_args()

    history_path = rtb._resolve_history_path(args.history)
    if not history_path.exists():
        print(f"ERROR: history file not found: {history_path}", file=sys.stderr)
        return 2

    history = rtb._load_history(history_path)

    rows: list[dict[str, Any]] = []
    skipped = 0
    for h in history:
        posted_at = _entry_posted_at(h)
        if posted_at is None:
            skipped += 1
            continue
        rows.append(
            {
                "set_name": _display_name(str(h.get("set_name") or "")),
                "set_url": (h.get("set_url") or "").strip(),
                "posted_at": posted_at,
                "platforms": _entry_platforms(h),
                "twitter_url": _entry_twitter_url(h),
                "bluesky_url": _entry_bluesky_url(h),
            }
        )

    if skipped:
        noun = "entry" if skipped == 1 else "entries"
        print(f"WARNING: skipped {skipped} history {noun} with an unparseable/missing posted_at.", file=sys.stderr)

    if args.set_name:
        target = rtb._normalized_set_name_for_match(args.set_name)
        rows = [r for r in rows if rtb._normalized_set_name_for_match(r["set_name"]) == target]

    if args.sort == "oldest":
        rows.sort(key=lambda r: r["posted_at"])
    elif args.sort == "name":
        rows.sort(key=lambda r: rtb._normalized_set_name_for_match(r["set_name"]))
    else:
        rows.sort(key=lambda r: r["posted_at"], reverse=True)

    if args.limit > 0:
        rows = rows[: args.limit]

    if args.json:
        out = [
            {
                "set_name": r["set_name"],
                "set_url": r["set_url"],
                "posted_at": r["posted_at"].isoformat(),
                "platforms": r["platforms"],
                "twitter_url": r["twitter_url"],
                "bluesky_url": r["bluesky_url"],
            }
            for r in rows
        ]
        print(json.dumps(out, indent=2))
        return 0

    print(f"{len(rows)} of {len(history)} recorded throwback post(s)")
    if not rows:
        return 0

    print()
    name_width = max(len("Set Name"), *(len(r["set_name"]) for r in rows))
    header = f"{'Posted At':<16}  {'Set Name':<{name_width}}  {'Platforms':<15}  URL"
    print(header)
    print("-" * len(header))
    for r in rows:
        posted = r["posted_at"].strftime("%Y-%m-%d %H:%M")
        platforms = "+".join(r["platforms"]) if r["platforms"] else "(none recorded)"
        print(f"{posted:<16}  {r['set_name']:<{name_width}}  {platforms:<15}  {r['set_url']}")

    return 0


if __name__ == "__main__":
    # Let the OS kill us normally on a closed pipe (e.g. `| head`) instead of
    # raising BrokenPipeError - see https://docs.python.org/3/library/signal.html#note-on-sigpipe
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    raise SystemExit(main())
