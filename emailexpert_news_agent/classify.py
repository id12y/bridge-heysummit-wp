from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Dict, List, Tuple

from .config import AppConfig

def classify_text(cfg: AppConfig, *, title: str, text: str, category_hints: List[str]) -> Tuple[List[str], Dict[str, int]]:
    hay = f"{title}\n{text}".lower()
    scores: Dict[str, int] = {cid: 0 for cid in cfg.categories.keys()}

    for cid, cat in cfg.categories.items():
        for kw in cat.keywords:
            if not kw:
                continue
            # simple contains; treat as lowercase; allow partials
            k = str(kw).lower()
            if k in hay:
                scores[cid] += 1

    # multi-label: include all with at least 1 hit, plus hints
    labels = set(category_hints or [])
    for cid, n in scores.items():
        if n > 0:
            labels.add(cid)

    # stable ordering
    labels_list = sorted(labels)
    return labels_list, scores
