from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

import yaml

@dataclass
class FetchConfig:
    user_agent: str = "emailexpert-news-agent/0.1"
    timeout_seconds: int = 25
    max_items_per_source: int = 40
    per_domain_delay_seconds: float = 1.0

@dataclass
class ReportConfig:
    top_n_per_category: int = 7
    include_uncategorized: bool = True

@dataclass
class CategoryConfig:
    id: str
    keywords: List[str] = field(default_factory=list)

@dataclass
class SourceConfig:
    id: str
    name: str
    type: str  # rss | web_list | web_snapshot | google_news_rss
    trust: float = 0.5
    category_hints: List[str] = field(default_factory=list)

    # rss
    url: Optional[str] = None

    # google_news_rss
    query: Optional[str] = None
    hl: str = "en-US"
    gl: str = "US"
    ceid: str = "US:en"

    # web_list
    start_urls: List[str] = field(default_factory=list)
    allow_domains: List[str] = field(default_factory=list)
    item_url_regex: Optional[str] = None
    fetch_full_text: bool = True

    # web_snapshot
    # uses url

@dataclass
class AppConfig:
    db_path: str = "data/news.db"
    fetch: FetchConfig = field(default_factory=FetchConfig)
    report: ReportConfig = field(default_factory=ReportConfig)
    categories: Dict[str, CategoryConfig] = field(default_factory=dict)
    sources: List[SourceConfig] = field(default_factory=list)

def load_config(path: str) -> AppConfig:
    with open(path, "r", encoding="utf-8") as f:
        raw = yaml.safe_load(f)

    cfg = AppConfig()
    cfg.db_path = raw.get("db_path", cfg.db_path)

    fetch_raw = raw.get("fetch", {}) or {}
    cfg.fetch = FetchConfig(
        user_agent=fetch_raw.get("user_agent", cfg.fetch.user_agent),
        timeout_seconds=int(fetch_raw.get("timeout_seconds", cfg.fetch.timeout_seconds)),
        max_items_per_source=int(fetch_raw.get("max_items_per_source", cfg.fetch.max_items_per_source)),
        per_domain_delay_seconds=float(fetch_raw.get("per_domain_delay_seconds", cfg.fetch.per_domain_delay_seconds)),
    )

    report_raw = raw.get("report", {}) or {}
    cfg.report = ReportConfig(
        top_n_per_category=int(report_raw.get("top_n_per_category", cfg.report.top_n_per_category)),
        include_uncategorized=bool(report_raw.get("include_uncategorized", cfg.report.include_uncategorized)),
    )

    categories_raw = raw.get("categories", {}) or {}
    for cat_id, cat_obj in categories_raw.items():
        cfg.categories[cat_id] = CategoryConfig(
            id=cat_id,
            keywords=list(cat_obj.get("keywords", []) or []),
        )

    sources_raw = raw.get("sources", []) or []
    for s in sources_raw:
        sc = SourceConfig(
            id=str(s["id"]),
            name=str(s.get("name", s["id"])),
            type=str(s["type"]),
            trust=float(s.get("trust", 0.5)),
            category_hints=list(s.get("category_hints", []) or []),
            url=s.get("url"),
            query=s.get("query"),
            hl=s.get("hl", "en-US"),
            gl=s.get("gl", "US"),
            ceid=s.get("ceid", "US:en"),
            start_urls=list(s.get("start_urls", []) or []),
            allow_domains=list(s.get("allow_domains", []) or []),
            item_url_regex=s.get("item_url_regex"),
            fetch_full_text=bool(s.get("fetch_full_text", True)),
        )
        cfg.sources.append(sc)

    return cfg
