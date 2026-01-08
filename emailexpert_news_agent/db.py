from __future__ import annotations

import json
import sqlite3
from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional, Tuple

from .utils import isoformat, now_utc

SCHEMA = '''
PRAGMA journal_mode=WAL;

CREATE TABLE IF NOT EXISTS items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id TEXT NOT NULL,
    source_name TEXT NOT NULL,
    item_type TEXT NOT NULL, -- article | page_update
    title TEXT NOT NULL,
    url TEXT NOT NULL,
    canonical_url TEXT NOT NULL UNIQUE,
    published_at TEXT,
    fetched_at TEXT NOT NULL,
    content_text TEXT,
    content_hash TEXT,
    categories_json TEXT,
    score REAL,
    trust REAL,
    metadata_json TEXT
);

CREATE INDEX IF NOT EXISTS idx_items_fetched_at ON items(fetched_at);
CREATE INDEX IF NOT EXISTS idx_items_source_id ON items(source_id);

CREATE TABLE IF NOT EXISTS snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id TEXT NOT NULL,
    url TEXT NOT NULL,
    fetched_at TEXT NOT NULL,
    content_hash TEXT NOT NULL,
    content_text TEXT NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_snapshots_source_id ON snapshots(source_id, fetched_at);
'''

class DB:
    def __init__(self, path: str):
        self.path = path
        self.conn = sqlite3.connect(path)
        self.conn.row_factory = sqlite3.Row

    def init(self) -> None:
        self.conn.executescript(SCHEMA)
        self.conn.commit()

    def close(self) -> None:
        self.conn.close()

    def insert_item(
        self,
        *,
        source_id: str,
        source_name: str,
        item_type: str,
        title: str,
        url: str,
        canonical_url: str,
        published_at: Optional[str],
        fetched_at: str,
        content_text: Optional[str],
        content_hash: Optional[str],
        categories: List[str],
        score: float,
        trust: float,
        metadata: Dict[str, Any],
    ) -> bool:
        try:
            self.conn.execute(
                '''
                INSERT INTO items(
                    source_id, source_name, item_type, title, url, canonical_url,
                    published_at, fetched_at, content_text, content_hash,
                    categories_json, score, trust, metadata_json
                )
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ''',
                (
                    source_id,
                    source_name,
                    item_type,
                    title,
                    url,
                    canonical_url,
                    published_at,
                    fetched_at,
                    content_text,
                    content_hash,
                    json.dumps(categories, ensure_ascii=False),
                    float(score),
                    float(trust),
                    json.dumps(metadata, ensure_ascii=False),
                ),
            )
            self.conn.commit()
            return True
        except sqlite3.IntegrityError:
            # canonical_url already exists
            return False

    def latest_snapshot(self, source_id: str, url: str) -> Optional[sqlite3.Row]:
        cur = self.conn.execute(
            '''
            SELECT * FROM snapshots
            WHERE source_id = ? AND url = ?
            ORDER BY fetched_at DESC
            LIMIT 1
            ''',
            (source_id, url),
        )
        row = cur.fetchone()
        return row

    def insert_snapshot(self, *, source_id: str, url: str, fetched_at: str, content_hash: str, content_text: str) -> None:
        self.conn.execute(
            '''
            INSERT INTO snapshots(source_id, url, fetched_at, content_hash, content_text)
            VALUES(?,?,?,?,?)
            ''',
            (source_id, url, fetched_at, content_hash, content_text),
        )
        self.conn.commit()

    def items_since(self, iso_since: str) -> List[sqlite3.Row]:
        cur = self.conn.execute(
            '''
            SELECT * FROM items
            WHERE fetched_at >= ?
            ORDER BY score DESC, fetched_at DESC
            ''',
            (iso_since,),
        )
        return cur.fetchall()
