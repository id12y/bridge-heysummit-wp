from __future__ import annotations

import time
from dataclasses import dataclass
from typing import Dict, Optional
from urllib.parse import urlparse

import requests

@dataclass
class Fetcher:
    user_agent: str
    timeout_seconds: int = 25
    per_domain_delay_seconds: float = 1.0

    def __post_init__(self) -> None:
        self.session = requests.Session()
        self.session.headers.update({"User-Agent": self.user_agent})
        self._last_fetch_by_domain: Dict[str, float] = {}

    def get(self, url: str) -> requests.Response:
        domain = urlparse(url).netloc.lower()
        last = self._last_fetch_by_domain.get(domain)
        if last is not None and self.per_domain_delay_seconds > 0:
            delta = time.time() - last
            if delta < self.per_domain_delay_seconds:
                time.sleep(self.per_domain_delay_seconds - delta)

        resp = self.session.get(url, timeout=self.timeout_seconds, allow_redirects=True)
        self._last_fetch_by_domain[domain] = time.time()
        resp.raise_for_status()
        return resp
