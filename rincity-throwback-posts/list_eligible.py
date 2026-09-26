#!/usr/bin/env python3
"""
list_eligible.py

Companion to rin_throwback_post.py. Applies the same exclude list, min-age,
and repeat-threshold filtering the poster uses for random selection, then
prints every set still in the eligible pool instead of picking one.

Read-only: does not post anywhere, touch post_history.json, or send email.

Default files (matches rin_throwback_post.py):
  - manifest:      ./Rin_Covers/manifest.json
  - history/state: ./post_history.json
  - exclude list:  ./excludes.json
"""

from __future__ import annotations

import argparse
import datetime as dt
import json
import signal
import sys
from pathlib import Path
from typing import Any

import rin_throwback_post as rtb


def _row_sort_key(row: dict[str, Any]) -> tuple[int, dt.datetime]:
    # Never-posted sets first (oldest published first), then previously-posted
    # sets that are eligible again (longest since last posted first).
    if row["last_posted"] is None:
        return (0, row["published_at"])
    return (1, row["last_posted"])


def main() -> int:
    p = argparse.ArgumentParser(
        description="List manifest sets still eligible for the next throwback post."
    )
    p.add_argument("--manifest", default="Rin_Covers/manifest.json", help="Path to manifest.json")
    p.add_argument("--history", default="post_history.json", help="State file to avoid repeats")
    p.add_argument("--exclude-file", default="excludes.json", help="JSON list of set names/post IDs to skip")
    p.add_argument(
        "--threshold-days",
        type=int,
        default=rtb.DEFAULT_THRESHOLD_DAYS,
        help=f"Do not repeat a set within this many days (default: {rtb.DEFAULT_THRESHOLD_DAYS})",
    )
    p.add_argument(
        "--min-age-days",
        type=int,
        default=rtb.DEFAULT_MIN_AGE_DAYS,
        help="Only consider galleries originally published at least this many days ago "
        f"(default: {rtb.DEFAULT_MIN_AGE_DAYS})",
    )
    p.add_argument(
        "--sort",
        choices=["priority", "name", "published"],
        default="priority",
        help="priority (default): never-posted oldest-first, then longest-since-last-posted; "
        "name: alphabetical; published: original publish date, oldest first",
    )
    p.add_argument("--limit", type=int, default=0, help="Only show the first N rows (0 = all)")
    p.add_argument("--json", action="store_true", help="Print machine-readable JSON instead of a table")
    args = p.parse_args()

    manifest_path = Path(args.manifest)
    history_path = rtb._resolve_history_path(args.history)
    exclude_path = Path(args.exclude_file)

    if not manifest_path.exists():
        print(f"ERROR: manifest not found: {manifest_path}", file=sys.stderr)
        return 2

    manifest = rtb._load_json(manifest_path)
    if not isinstance(manifest, list):
        print("ERROR: manifest.json must be a list of entries", file=sys.stderr)
        return 2

    try:
        excluded_names, excluded_ids = rtb._load_excludes(exclude_path)
    except Exception as e:
        print(f"ERROR: failed to load exclude file {exclude_path}: {e}", file=sys.stderr)
        return 2
    total = len(manifest)
    manifest = rtb._filter_excluded(manifest, excluded_names, excluded_ids)
    excluded_count = total - len(manifest)

    history = rtb._load_history(history_path)
    last_posted = rtb._last_posted_map(history)
    now = dt.datetime.now(dt.timezone.utc)

    eligible = rtb._eligible_entries(manifest, history, args.threshold_days, args.min_age_days, now)

    already_posted_count = sum(
        1 for e in manifest if ((e.get("set_url") or "").strip() or (e.get("set_name") or "").strip()) in last_posted
    )

    rows: list[dict[str, Any]] = []
    for e in eligible:
        set_url = (e.get("set_url") or "").strip()
        set_name = (e.get("set_name") or "").strip()
        key = set_url or set_name
        published_at = rtb._parse_iso8601((e.get("date_published") or "").strip())
        rows.append(
            {
                "set_name": set_name,
                "set_url": set_url,
                "post_id": e.get("post_id"),
                "published_at": published_at,
                "published": rtb._fmt_publish_date(e.get("date_published") or ""),
                "last_posted": last_posted.get(key),
            }
        )

    if args.sort == "name":
        rows.sort(key=lambda r: rtb._normalized_set_name_for_match(r["set_name"]))
    elif args.sort == "published":
        rows.sort(key=lambda r: r["published_at"])
    else:
        rows.sort(key=_row_sort_key)

    if args.limit > 0:
        rows = rows[: args.limit]

    if args.json:
        out = [
            {
                "set_name": r["set_name"],
                "set_url": r["set_url"],
                "post_id": r["post_id"],
                "published": r["published_at"].isoformat(),
                "last_posted": r["last_posted"].isoformat() if r["last_posted"] else None,
            }
            for r in rows
        ]
        print(json.dumps(out, indent=2))
        return 0

    print(
        f"{len(eligible)} of {total} sets eligible "
        f"({excluded_count} excluded, {already_posted_count} already posted at least once, "
        f"threshold_days={args.threshold_days}, min_age_days={args.min_age_days})"
    )
    if not rows:
        return 0

    print()
    name_width = max(len("Set Name"), *(len(r["set_name"]) for r in rows))
    header = f"{'Set Name':<{name_width}}  {'Published':<11}  {'Last Posted':<11}  URL"
    print(header)
    print("-" * len(header))
    for r in rows:
        last = _days_ago_label(r["last_posted"], now)
        print(f"{r['set_name']:<{name_width}}  {r['published']:<11}  {last:<11}  {r['set_url']}")

    return 0


def _days_ago_label(when: dt.datetime | None, now: dt.datetime) -> str:
    if when is None:
        return "never"
    return f"{rtb._days_ago(when, now)}d ago"


if __name__ == "__main__":
    # Let the OS kill us normally on a closed pipe (e.g. `| head`) instead of
    # raising BrokenPipeError - see https://docs.python.org/3/library/signal.html#note-on-sigpipe
    signal.signal(signal.SIGPIPE, signal.SIG_DFL)
    raise SystemExit(main())
