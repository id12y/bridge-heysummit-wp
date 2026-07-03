# Progress

## M1 — Scaffold and client

- Plugin skeleton: bootstrap, PSR-4 autoloader (no Composer at runtime),
  service-based `Plugin` class, activation/deactivation with table creation
  (`eex_log`, `eex_attribution`), webhook secret generation and cron setup.
- `HeySummitClient`: GET-only, 15s timeout, 2 retries with backoff on
  5xx/timeouts, none on 4xx; DRF pagination with a filterable 50-page cap;
  refuses to follow pagination links off the API host; 401/403 mapped to a
  distinct auth error; every request logged (endpoint, status, duration),
  never the key.
- Runtime discovery diagnostic (`Api\Discovery` + `Api\Shapes` as the single
  source of assumed shapes): per-resource found/missing/unmapped/type-mismatch
  report, stored per connection, logged flagged `discovery`, rendered as a
  diagnostics panel on the settings page.
- Settings screen with connection list (write-only keys, last-4 display,
  `EEX_HEYSUMMIT_API_KEY` constant override), per-event sync rows (resource
  toggles, import status, category include/exclude filter), webhook section,
  display section; AJAX test-connection/load-events/load-categories/sync-now/
  regenerate-secret, all nonce- and capability-guarded.
- Sync log admin viewer with context/level filters; logger with recursive
  email redaction (SHA-256 local-part prefix) and 30-day pruning.
- Tests: 16 passing (client pagination/retry/auth, logger redaction,
  discovery reporting). docs/api-notes.md records every assumed shape.

## M2 — Data layer

- CPTs (eex_event /events/, eex_talk /sessions/, eex_speaker /speakers/,
  eex_sponsor /sponsors/), all public + REST + archives; taxonomies
  (eex_event_series with seeded FORUM/Deliverability Summit/Sender
  Symposium/Festival of Email terms, eex_category, eex_sponsor_tier with
  ordered Platinum/Gold/Silver/Partner terms).
- Full meta registration per spec 4.3 including reserved
  _eex_directory_listing_id; _eex_raw kept out of REST; speaker emails stored
  only as SHA-256 hashes.
- Mappers for events, talks, speakers, categories, attendees: null-safe,
  tolerant of string/int IDs, nested-or-flat related records, offset
  timestamps (normalised to UTC ISO 8601), candidate photo fields.
- Upserter: lookup by _eex_heysummit_id, hash-based skip (zero writes on
  unchanged payloads), detached/excluded never written, import status only at
  creation, manual meta untouched, orphan flag cleared on return.
- Sync-mode meta box + quick edit + list column; venue and replay-URL manual
  field boxes; excluding a post drafts it immediately.
- Tests: 37 passing (mappers against assumed-shape fixtures with variants,
  upsert idempotency/hash/mode/status behaviours). PHPCS clean.
