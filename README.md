# EmailExpert News Agent (MVP)

A small, configurable crawler that:
- ingests **RSS/Atom** feeds
- scrapes **web pages** (list pages + full article pages)
- monitors **policy / guideline pages** for silent updates (snapshot + diff)
- classifies items into EmailExpert categories
- generates a weekly **Markdown briefing**

## What this is (and isn't)
- ✅ A pragmatic “agentic pipeline” built for *weekly editorial research*
- ✅ Designed around **topic watchers** that feed a **shared scoring/reporting system**
- ❌ Not a general-purpose web crawler (by design)
- ❌ Not an LLM-dependent system (LLM hooks are optional)

## Quick start

1) Create a virtualenv, then install dependencies:

```bash
pip install -r requirements.txt
```

2) Copy the example config and edit sources/keywords:

```bash
cp config/watchers.example.yml config/watchers.yml
```

3) Ingest new items:

```bash
python -m emailexpert_news_agent ingest --config config/watchers.yml
```

4) Generate a weekly report (last 7 days):

```bash
python -m emailexpert_news_agent report --config config/watchers.yml --days 7 --out reports/weekly.md
```

## Configuration

Edit `config/watchers.yml`:

- `categories`: multi-label taxonomy + keywords
- `sources`: RSS feeds, web list sources, and web snapshots

### Source types
- `rss`: parse RSS/Atom (feedparser)
- `google_news_rss`: Google News RSS search feed (query -> RSS). Treat as *discovery*; usually don't fetch full text.
- `web_list`: scrape a list page, extract links, fetch each linked page, extract article text
- `web_snapshot`: fetch a single page and detect content changes over time (stores snapshots + diff)

## Data storage
SQLite DB is stored at `data/news.db` by default.

## Operational safety
Be a good citizen:
- respect robots.txt and site terms
- rate-limit your fetches
- prefer RSS/official feeds over aggressive scraping

## Roadmap ideas
- embeddings-based clustering (vector DB)
- primary-source verification rules
- Slack/Email delivery of the weekly brief
- editorial feedback loop (what got published -> tune scoring)
