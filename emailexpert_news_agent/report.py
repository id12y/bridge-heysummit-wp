from __future__ import annotations

import json
from collections import defaultdict
from dataclasses import dataclass
from datetime import timedelta
from typing import Dict, List, Optional

from .config import AppConfig
from .db import DB
from .utils import first_sentences, now_utc, isoformat

def generate_weekly_report(cfg: AppConfig, db: DB, *, days: int = 7) -> str:
    since_dt = now_utc() - timedelta(days=days)
    since_iso = isoformat(since_dt)
    rows = db.items_since(since_iso)

    by_cat: Dict[str, List] = defaultdict(list)
    uncategorized: List = []

    for r in rows:
        cats = []
        try:
            cats = json.loads(r["categories_json"] or "[]")
        except Exception:
            cats = []
        if not cats:
            uncategorized.append(r)
        else:
            for c in cats:
                by_cat[c].append(r)

    # sort per category by score, then by publication date
    for c in list(by_cat.keys()):
        by_cat[c] = sorted(
            by_cat[c], 
            key=lambda x: (x["score"] or 0.0, x["published_at"] or x["fetched_at"]), 
            reverse=True
        )

    # report
    lines: List[str] = []
    lines.append(f"# Weekly EmailExpert News Brief ({days} days)")
    lines.append("")
    lines.append(f"Data window: {since_dt.date().isoformat()} to {now_utc().date().isoformat()} (UTC)")
    lines.append("")

    # Keep category ordering as config order
    for cat_id in cfg.categories.keys():
        items = by_cat.get(cat_id, [])
        if not items:
            continue
        lines.append(f"## {cat_id}")
        lines.append("")
        top = items[: cfg.report.top_n_per_category]
        for it in top:
            why = first_sentences(it["content_text"] or "", max_chars=240)
            src = it["source_name"]
            
            # Format date nicely - show just the date part
            pub_raw = it["published_at"] or it["fetched_at"]
            try:
                # Extract just the date (YYYY-MM-DD) from ISO format
                pub_date = pub_raw[:10] if pub_raw else "unknown"
            except:
                pub_date = pub_raw
            
            # Mark page updates differently from articles
            if it["item_type"] == "page_update":
                lines.append(f"- **[Updated]** [{it['title']}]({it['url']}) — *{src}* — {pub_date}")
            else:
                lines.append(f"- [{it['title']}]({it['url']}) — *{src}* — {pub_date}")
            
            if why:
                lines.append(f"  - {why}")
        lines.append("")

    if cfg.report.include_uncategorized and uncategorized:
        lines.append("## Uncategorized / needs review")
        lines.append("")
        for it in uncategorized[: cfg.report.top_n_per_category]:
            src = it["source_name"]
            pub_raw = it["published_at"] or it["fetched_at"]
            try:
                pub_date = pub_raw[:10] if pub_raw else "unknown"
            except:
                pub_date = pub_raw
            lines.append(f"- [{it['title']}]({it['url']}) — *{src}* — {pub_date}")
        lines.append("")

    return "\n".join(lines)
