from __future__ import annotations

import argparse
from pathlib import Path

from .config import load_config
from .db import DB
from .ingest import ingest
from .report import generate_weekly_report

def main() -> None:
    p = argparse.ArgumentParser(prog="emailexpert-news")
    sub = p.add_subparsers(dest="cmd", required=True)

    p_ingest = sub.add_parser("ingest", help="Fetch new items from configured sources and store in SQLite")
    p_ingest.add_argument("--config", required=True, help="Path to watchers.yml")

    p_report = sub.add_parser("report", help="Generate a Markdown report from stored items")
    p_report.add_argument("--config", required=True, help="Path to watchers.yml")
    p_report.add_argument("--days", type=int, default=7, help="How many days back to include")
    p_report.add_argument("--out", required=True, help="Output markdown path")

    args = p.parse_args()
    cfg = load_config(args.config)

    # Ensure DB path exists
    Path(cfg.db_path).parent.mkdir(parents=True, exist_ok=True)
    db = DB(cfg.db_path)
    db.init()

    if args.cmd == "ingest":
        stats = ingest(cfg, db)
        print(stats)
    elif args.cmd == "report":
        md = generate_weekly_report(cfg, db, days=args.days)
        out_path = Path(args.out)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(md, encoding="utf-8")
        print(f"Wrote {out_path}")
    else:
        raise SystemExit("unknown command")

if __name__ == "__main__":
    main()
