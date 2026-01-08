from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Any, Iterable, Optional
from urllib.parse import urlparse, urlunparse, parse_qsl, urlencode

from dateutil import parser as date_parser

TRACKING_PARAMS = {
    "utm_source","utm_medium","utm_campaign","utm_term","utm_content",
    "gclid","fbclid","mc_cid","mc_eid","mkt_tok"
}

def now_utc() -> datetime:
    return datetime.now(timezone.utc)

def isoformat(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).isoformat()

def parse_datetime(value: Any) -> Optional[datetime]:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=timezone.utc)
    s = str(value).strip()
    if not s:
        return None
    try:
        dt = date_parser.parse(s)
        return dt if dt.tzinfo else dt.replace(tzinfo=timezone.utc)
    except Exception:
        return None

def sha256_text(text: str) -> str:
    h = hashlib.sha256()
    h.update(text.encode("utf-8", errors="ignore"))
    return h.hexdigest()

def canonicalize_url(url: str) -> str:
    # Remove fragments, normalize host, strip common tracking parameters
    try:
        p = urlparse(url)
    except Exception:
        return url.strip()

    scheme = (p.scheme or "https").lower()
    netloc = (p.netloc or "").lower()
    path = p.path or "/"

    # normalize: remove trailing slash (except root)
    if path != "/" and path.endswith("/"):
        path = path[:-1]

    # remove tracking params
    q = [(k, v) for (k, v) in parse_qsl(p.query, keep_blank_values=True) if k not in TRACKING_PARAMS]
    query = urlencode(q, doseq=True)

    return urlunparse((scheme, netloc, path, "", query, ""))

def clamp(x: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, x))

def first_sentences(text: str, max_chars: int = 280) -> str:
    text = re.sub(r"\s+", " ", (text or "")).strip()
    if not text:
        return ""
    # crude sentence split
    parts = re.split(r"(?<=[.!?])\s+", text)
    out = ""
    for part in parts:
        if not part:
            continue
        if not out:
            out = part
        else:
            if len(out) + 1 + len(part) > max_chars:
                break
            out += " " + part
        if len(out) >= max_chars:
            break
    if len(out) > max_chars:
        out = out[: max_chars - 1].rstrip() + "…"
    return out

def unified_diff(old: str, new: str, max_lines: int = 80) -> str:
    import difflib
    old_lines = (old or "").splitlines()
    new_lines = (new or "").splitlines()
    diff = list(difflib.unified_diff(old_lines, new_lines, lineterm=""))
    if len(diff) > max_lines:
        diff = diff[:max_lines] + ["… (diff truncated)"]
    return "\n".join(diff)
