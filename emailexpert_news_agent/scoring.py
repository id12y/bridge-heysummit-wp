from __future__ import annotations

from datetime import datetime, timezone
from typing import Dict, Optional

from .utils import clamp, now_utc

def recency_score(published_at: Optional[datetime], fetched_at: datetime) -> float:
    # 1.0 today, decays over ~14 days
    ref = published_at or fetched_at
    age_days = max(0.0, (fetched_at - ref).total_seconds() / 86400.0)
    # simple exponential-ish decay
    val = 2.0 ** (-age_days / 7.0)  # half-life ~7 days
    return clamp(val, 0.0, 1.0)

def match_score(keyword_hits_by_category: Dict[str, int]) -> float:
    # crude: more keyword hits = higher
    hits = sum(keyword_hits_by_category.values())
    if hits <= 0:
        return 0.0
    if hits >= 6:
        return 1.0
    return hits / 6.0

def overall_score(*, trust: float, recency: float, match: float) -> float:
    # weighted: trust + timeliness + match
    return clamp(0.45 * trust + 0.35 * recency + 0.20 * match, 0.0, 1.0)
