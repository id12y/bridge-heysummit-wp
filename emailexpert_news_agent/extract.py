from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Optional, Tuple

from bs4 import BeautifulSoup

try:
    import trafilatura
except Exception:  # pragma: no cover
    trafilatura = None

def extract_title(html: str) -> str:
    soup = BeautifulSoup(html, "html.parser")
    # Prefer og:title
    og = soup.find("meta", attrs={"property": "og:title"})
    if og and og.get("content"):
        return og["content"].strip()
    h1 = soup.find("h1")
    if h1 and h1.get_text(strip=True):
        return h1.get_text(" ", strip=True)
    if soup.title and soup.title.get_text(strip=True):
        return soup.title.get_text(" ", strip=True)
    return "(untitled)"

def extract_text(html: str) -> str:
    # Prefer trafilatura when available
    if trafilatura is not None:
        try:
            downloaded = html
            txt = trafilatura.extract(downloaded, include_comments=False, include_tables=False)
            if txt and txt.strip():
                return normalize_whitespace(txt)
        except Exception:
            pass

    soup = BeautifulSoup(html, "html.parser")
    # remove script/style
    for tag in soup(["script", "style", "noscript"]):
        tag.decompose()
    text = soup.get_text(" ", strip=True)
    return normalize_whitespace(text)

def normalize_whitespace(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "")).strip()

def extract_published(html: str) -> Optional[str]:
    soup = BeautifulSoup(html, "html.parser")
    # Common meta fields
    candidates = []
    for attr in [
        ("meta", {"property": "article:published_time"}, "content"),
        ("meta", {"name": "article:published_time"}, "content"),
        ("meta", {"name": "pubdate"}, "content"),
        ("meta", {"name": "publish_date"}, "content"),
        ("meta", {"name": "date"}, "content"),
        ("meta", {"property": "og:updated_time"}, "content"),
    ]:
        tag = soup.find(attr[0], attrs=attr[1])
        if tag and tag.get(attr[2]):
            candidates.append(tag.get(attr[2]))
    # <time datetime="...">
    time_tag = soup.find("time")
    if time_tag:
        if time_tag.get("datetime"):
            candidates.append(time_tag.get("datetime"))
        else:
            t = time_tag.get_text(" ", strip=True)
            if t:
                candidates.append(t)
    # return raw; parsing happens later
    for c in candidates:
        if c and str(c).strip():
            return str(c).strip()
    return None
