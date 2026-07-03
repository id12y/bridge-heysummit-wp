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
