from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Any, Dict, List, Optional, Tuple
from urllib.parse import urljoin, urlparse, quote_plus

import feedparser
from bs4 import BeautifulSoup

from .config import AppConfig, SourceConfig
from .db import DB
from .extract import extract_published, extract_text, extract_title
from .fetch import Fetcher
from .classify import classify_text
from .scoring import recency_score, match_score, overall_score
from .utils import canonicalize_url, isoformat, now_utc, parse_datetime, sha256_text, unified_diff

def ingest(cfg: AppConfig, db: DB) -> Dict[str, Any]:
    fetcher = Fetcher(
        user_agent=cfg.fetch.user_agent,
        timeout_seconds=cfg.fetch.timeout_seconds,
        per_domain_delay_seconds=cfg.fetch.per_domain_delay_seconds,
    )

    stats = {"inserted": 0, "skipped_existing": 0, "errors": 0, "by_source": {}}

    for src in cfg.sources:
        inserted, skipped, errors = ingest_source(cfg, db, fetcher, src)
        stats["inserted"] += inserted
        stats["skipped_existing"] += skipped
        stats["errors"] += errors
        stats["by_source"][src.id] = {"inserted": inserted, "skipped_existing": skipped, "errors": errors}

    return stats

def ingest_source(cfg: AppConfig, db: DB, fetcher: Fetcher, src: SourceConfig) -> Tuple[int, int, int]:
    inserted = 0
    skipped = 0
    errors = 0

    try:
        if src.type == "rss":
            items = ingest_rss(cfg, fetcher, src)
        elif src.type == "google_news_rss":
            items = ingest_google_news_rss(cfg, fetcher, src)
        elif src.type == "web_list":
            items = ingest_web_list(cfg, fetcher, src)
        elif src.type == "web_snapshot":
            items = ingest_web_snapshot(cfg, db, fetcher, src)
        else:
            raise ValueError(f"Unknown source type: {src.type}")

        # store items
        for it in items[: cfg.fetch.max_items_per_source]:
            ok = db.insert_item(
                source_id=src.id,
                source_name=src.name,
                item_type=it["item_type"],
                title=it["title"],
                url=it["url"],
                canonical_url=it["canonical_url"],
                published_at=it.get("published_at"),
                fetched_at=it["fetched_at"],
                content_text=it.get("content_text"),
                content_hash=it.get("content_hash"),
                categories=it.get("categories", []),
                score=float(it.get("score", 0.0)),
                trust=float(src.trust),
                metadata=it.get("metadata", {}),
            )
            if ok:
                inserted += 1
            else:
                skipped += 1

    except Exception:
        errors += 1

    return inserted, skipped, errors

def ingest_rss(cfg: AppConfig, fetcher: Fetcher, src: SourceConfig) -> List[Dict[str, Any]]:
    assert src.url, "rss source requires url"
    feed = feedparser.parse(src.url)
    out: List[Dict[str, Any]] = []
    fetched_at_dt = now_utc()
    fetched_at = isoformat(fetched_at_dt)

    for entry in feed.entries:
        url = getattr(entry, "link", None) or ""
        if not url:
            continue
        title = getattr(entry, "title", None) or "(untitled)"
        published_raw = getattr(entry, "published", None) or getattr(entry, "updated", None)
        published_dt = parse_datetime(published_raw)
        published_at = isoformat(published_dt) if published_dt else None

        summary = getattr(entry, "summary", None) or ""
        text = summary

        # classify & score
        labels, hits = classify_text(cfg, title=title, text=text, category_hints=src.category_hints)
        rec = recency_score(published_dt, fetched_at_dt)
        m = match_score(hits)
        score = overall_score(trust=src.trust, recency=rec, match=m)

        out.append({
            "item_type": "article",
            "title": title,
            "url": url,
            "canonical_url": canonicalize_url(url),
            "published_at": published_at,
            "fetched_at": fetched_at,
            "content_text": text,
            "content_hash": sha256_text(text) if text else None,
            "categories": labels,
            "score": score,
            "metadata": {"feed_title": getattr(feed.feed, "title", None)},
        })
    return out

def ingest_google_news_rss(cfg: AppConfig, fetcher: Fetcher, src: SourceConfig) -> List[Dict[str, Any]]:
    assert src.query, "google_news_rss requires query"
    # NOTE: This is "best effort" and may change; treat as discovery.
    q = quote_plus(src.query)
    url = f"https://news.google.com/rss/search?q={q}&hl={src.hl}&gl={src.gl}&ceid={src.ceid}"
    # parse like rss
    tmp = SourceConfig(**{**src.__dict__})
    tmp.url = url
    return ingest_rss(cfg, fetcher, tmp)

def ingest_web_list(cfg: AppConfig, fetcher: Fetcher, src: SourceConfig) -> List[Dict[str, Any]]:
    if not src.start_urls:
        raise ValueError("web_list source requires start_urls")

    fetched_at_dt = now_utc()
    fetched_at = isoformat(fetched_at_dt)

    item_urls: List[str] = []
    regex = re.compile(src.item_url_regex) if src.item_url_regex else None

    for start_url in src.start_urls:
        resp = fetcher.get(start_url)
        html = resp.text
        soup = BeautifulSoup(html, "html.parser")
        for a in soup.find_all("a", href=True):
            href = a.get("href")
            if not href:
                continue
            abs_url = urljoin(start_url, href)
            parsed = urlparse(abs_url)
            if src.allow_domains and parsed.netloc.lower() not in [d.lower() for d in src.allow_domains]:
                continue
            if regex and not regex.search(abs_url):
                continue
            item_urls.append(abs_url)

    # de-dupe and cap
    seen = set()
    uniq = []
    for u in item_urls:
        cu = canonicalize_url(u)
        if cu in seen:
            continue
        seen.add(cu)
        uniq.append(u)

    out: List[Dict[str, Any]] = []
    for u in uniq[: cfg.fetch.max_items_per_source]:
        try:
            resp = fetcher.get(u)
            html = resp.text
            title = extract_title(html)
            published_raw = extract_published(html)
            published_dt = parse_datetime(published_raw)
            published_at = isoformat(published_dt) if published_dt else None
            text = extract_text(html) if src.fetch_full_text else ""

            labels, hits = classify_text(cfg, title=title, text=text, category_hints=src.category_hints)
            rec = recency_score(published_dt, fetched_at_dt)
            m = match_score(hits)
            score = overall_score(trust=src.trust, recency=rec, match=m)

            out.append({
                "item_type": "article",
                "title": title,
                "url": u,
                "canonical_url": canonicalize_url(u),
                "published_at": published_at,
                "fetched_at": fetched_at,
                "content_text": text,
                "content_hash": sha256_text(text) if text else None,
                "categories": labels,
                "score": score,
                "metadata": {"list_source": src.start_urls},
            })
        except Exception:
            # ignore per-item errors; keep crawling
            continue

    return out

def ingest_web_snapshot(cfg: AppConfig, db: DB, fetcher: Fetcher, src: SourceConfig) -> List[Dict[str, Any]]:
    assert src.url, "web_snapshot requires url"
    fetched_at_dt = now_utc()
    fetched_at = isoformat(fetched_at_dt)

    resp = fetcher.get(src.url)
    html = resp.text
    title = extract_title(html)
    text = extract_text(html)
    h = sha256_text(text)

    prev = db.latest_snapshot(src.id, src.url)
    db.insert_snapshot(source_id=src.id, url=src.url, fetched_at=fetched_at, content_hash=h, content_text=text)

    if prev is None or prev["content_hash"] != h:
        old_text = prev["content_text"] if prev is not None else ""
        diff = unified_diff(old_text, text)
        # classify & score: for page updates, rely on source hints + keyword hits
        labels, hits = classify_text(cfg, title=title, text=text, category_hints=src.category_hints)
        rec = 1.0  # page updates are inherently "now"
        m = match_score(hits)
        score = overall_score(trust=src.trust, recency=rec, match=m)

        return [{
            "item_type": "page_update",
            "title": f"Updated: {src.name}",
            "url": src.url,
            "canonical_url": canonicalize_url(src.url),
            "published_at": fetched_at,
            "fetched_at": fetched_at,
            "content_text": text,
            "content_hash": h,
            "categories": labels,
            "score": score,
            "metadata": {"page_title": title, "diff": diff},
        }]

    return []
