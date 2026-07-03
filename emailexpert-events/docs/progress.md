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

## M3 — Sync engine

- SyncEngine: per-event flow (event detail → categories → talks → orphans →
  speakers → relationship resolution), resource toggles honoured (disabled
  types fully untouched, including talk→speaker relationships), category
  include/exclude filter with orphan-drafting of filtered talks, talk filter
  parameter negotiation with client-side fallback.
- Speaker dedup: HS ID → alternate IDs → email hash → exact name+company;
  cross-event matches preserve identity meta and record alternate IDs;
  identity fields excluded from the change hash to avoid write thrash.
- Orphan handling: draft + _eex_orphaned, never delete; detached/excluded and
  already-orphaned posts skipped; speakers still referenced by live talks
  spared.
- Scheduling: recurring cron (15min/hourly/twicedaily/daily), async "Sync
  now", 20s time budget with queued continuation, Action Scheduler fan-out
  when present.
- Media: speaker photos sideloaded once, re-downloaded only on source URL
  change, alt text set, featured image assigned.
- Health: consecutive-failure tracking (notice at 3, email at 6, reset on
  success), Site Health test, status shared with `wp eex status`.
- WP-CLI: `wp eex sync [--event] [--force]`, `status`, `orphans --list`,
  `discover`, `webhooks:replay <log_id>`.
- Tests: 46 passing including engine flow, filters, orphans, dedup,
  escalation. PHPCS clean.

## M4 — Display

- 10 components (upcoming/past sessions, upcoming/past events, countdown,
  schedule, speaker grid, featured talks, sponsors wall, registration
  counter) as one definition table with shared render callbacks; each ships
  as a dynamic block (plain-JS editor script + ServerSideRender previews)
  and a shortcode; empty states everywhere; eex_query_args and eex_card_html
  filters; transient cache (5 min, generation-counter flush on sync/webhook/
  editorial save).
- Cache-safe time handling: <time datetime=UTC> with event-local fallback,
  vanilla JS module localises to the visitor timezone and computes
  upcoming/soon/live/past states client-side; countdown with aria-live;
  register CTA becomes "Join now" only via JS; counter refreshes via public
  REST read.
- Calendar: per-session .ics download + Google Calendar links, subscribable
  /feeds/eex/calendar.ics filterable by event/category, RFC 5545 escaping
  and 75-octet folding (unit tested).
- Templates: singles for all four CPTs, archives, category and series
  taxonomy pages, all built from overridable parts (card-talk, card-event,
  card-speaker, card-sponsor, speaker-chip, schedule-row); theme override
  directory emailexpert-events/; speaker singles list upcoming + past
  sessions with replays; talk singles embed YouTube/Vimeo/oEmbed replays.
- Schema: Event/BusinessEvent with venue PostalAddress or VirtualLocation,
  offers URL-only while registrations open, performer array; talk Event with
  superEvent; VideoObject for past replays; Person for speakers; injected
  into Yoast (wpseo_schema_graph) or Rank Math (rank_math/json_ld) graphs
  when active, standalone JSON-LD + OG/Twitter fallback otherwise; per-type
  toggles; emits nothing when required fields missing.
- Assets: one stylesheet + one JS file, enqueued only where needed, all
  theming via --eex-* custom properties. Combined gzipped size ~4.5KB
  (budget 30KB).
- Tests: 81 passing. PHPCS clean.

## M5 — Elementor module

- All code in src/Elementor/, registered on elementor/init only; nothing
  loads when Elementor is absent; Pro detected separately
  (ELEMENTOR_PRO_VERSION) with Theme Builder yield, dynamic tags and Loop
  Grid queries degrading silently on free Elementor.
- Widgets: one parameterised ComponentWidget class instantiated per
  component (docs/decisions.md D10), content controls mapped 1:1 to
  component attributes (event/category selects populated from synced data,
  switchers for flags), style controls writing --eex-* custom properties
  scoped to {{WRAPPER}}; render() calls the shared component callback, so
  output matches blocks/shortcodes and editor previews are real server
  renders.
- Theme Builder: eex_template_yield returns true when a Pro theme template's
  conditions match our single/archive views (conditions manager queried
  defensively), plus a belt-and-braces guard in the template loader that
  never displaces an Elementor-resolved template.
- Dynamic tags: session start/end (format + site/event timezone), category
  list, live status, register/replay/event URLs, registration count, venue
  fields, speaker name/headline/company/photo/link — all return empty when
  data is missing.
- Loop Grid queries: eex_upcoming_sessions, eex_past_sessions,
  eex_event_sessions with component-identical ordering.
- Not runnable in this environment (no Elementor install): syntax-checked,
  logic covered indirectly via the shared component tests; flagged in the
  acceptance report for on-site verification.

## M6 — Webhooks, attribution, privacy

- Receiver POST /wp-json/eex/v1/heysummit/<secret>: hash_equals comparison,
  404 (rest_no_route) on a bad secret, 60/min/IP rate limit (429), receipt
  logged (capture mode stores full payloads flagged `capture`), 200
  immediately, processing via queued single event; dedupe on
  hash(action, attendee id, talk, 15-minute bucket) held 2 hours.
- Parser: fuzzy action detection across candidate keys, attendee accepted
  nested (attendee / data.attendee / data) or flat, string or integer IDs;
  unrecognised payloads log a warning and do nothing.
- Processor: per-action toggles; checkout complete verifies the attendee
  against the API and increments _eex_registration_count only from verified
  data (D15), inserts attribution, fires eex_checkout_complete, optional
  admin email; registration started inserts a `started` row, fires
  eex_registration_started, schedules the 60-minute abandonment check that
  fires eex_registration_abandoned when no checkout followed; talk added
  fires eex_talk_signup and logs only. eex_webhook_processed flushes the
  component cache.
- Attribution table writes/reads (emails as SHA-256 hashes only), admin
  report with totals by utm_source and status, event/date filters, CSV
  export (nonce + manage_options).
- Privacy: personal data exporter and eraser by email hash, including log
  rows carrying the redaction hash prefix; retention pruning already in
  daily maintenance.
- Public counter read GET /eex/v1/counter/<event> with no-store headers.
- Tests: 94 passing (secret, idempotency, verification fallback,
  abandonment, toggles, capture/replay, rate limit, privacy, counter REST).

## M7 — Hardening and delivery

- Security pass: no unserialise/eval anywhere; API key surfaces audited
  (write-only UI with last-4, header-only transmission, never in
  logs/REST/front end — covered by a regression test); all admin actions
  nonce + capability guarded; webhook secret compared with hash_equals and
  wrong secrets indistinguishable from a missing route.
- uninstall.php: drops tables, clears schedules, deletes all eex_* options
  and transients; content removed only when the opt-in setting was on.
- Boot smoke test: every non-Elementor class loads and Plugin::boot wires
  all services with zero side effects; Elementor module php -l checked in CI.
- CI: .github/workflows/plugin-ci.yml (repo root) — PHP 8.1 and 8.3 matrix,
  composer install, WPCS lint, syntax check of all PHP including the
  Elementor module, PHPUnit.
- README: installation, configuration, sync semantics, shortcode/block
  reference, filter/hook/CLI/REST reference, Elementor guide, webhook
  capture procedure, theming and privacy documentation.
- docs/acceptance-report.md: 14 pass / 0 fail / 8 not verifiable in this
  environment, each with the manual step that verifies it live.
- Final state: 96 tests, 384 assertions, PHPCS clean, front-end assets
  ~3.1KB gzipped.

# v2 extension run

## V2-WI1 — Footprint and lazy initialisation

- Minimal activation (no tables, no cron, no secret, no redirect); tables on
  demand via Install\Tables with stored schema versions; attribution schema
  v2 adds order_id for the Woo bridge; daily maintenance scheduled with the
  first table; sync cron follows enabled events (schedules on first enable,
  unschedules on last disable); deactivation clears every hook.
- eex_settings is now the single autoloaded option; term seeding runs at
  activation only; log and attribution admin screens handle absent tables;
  retention pruning skips absent tables.
- Tests: 103 passing including the new FootprintTest (7 tests).

## V2-WI2 — Time-scoped imports and setup wizard

- ScopeFilter (shared by engine and dry run): future all/none; past
  all/none/most-recent-N/since-date, composing with the category filter;
  rolling evaluation every run; out-of-scope talks treated exactly like
  category-excluded ones (not created, orphan-drafted). Scope controls in
  the standard sync settings; defaults all/all.
- DryRun: GET-only preview producing sessions/past/upcoming/speakers/images
  counts guaranteed (by shared filter) to match a confirmed import.
- Wizard: dismissible activation notice, re-runnable from settings, five
  steps (connect + inline discovery summary; choose events with dates and
  session counts; scope; live dry-run preview updating on change; confirm →
  async initial sync with log-fed progress and completion links, detecting
  Elementor/MyListing/WooCommerce). Writes only the standard options.
- Tests: 112 passing, including the acceptance-critical dry-run == import
  == most-recent-5 == scope-reduction-orphans chain.

## V2-WI3 — MyListing bridge

- One-way projection module (src/MyListing/): runtime detection of listing
  types/fields with theme-API-then-config fallback, cached and logged
  flagged discovery, bridge self-disables with a notice when unconfident.
- Projector: per source type (events/sessions/speakers) with target listing
  type and dropdown field mapping; unmapped fields never written; hash
  idempotent; honours sync modes (detached freezes the listing, excluded
  drafts it), mirrors import status and orphan drafts; reciprocal
  _eex_mylisting_id/_eex_source_post_id linkage; runs on eex_sync_completed
  and via Project now.
- Canonical control: required canonical side per source type (default eex),
  rel=canonical on the non-canonical side (core + Yoast/Rank Math filters +
  explicit link tag for listings), schema emitted only on the canonical
  side (eex_schema_suppress), optional listings-only noindex.
- Bridge settings page (Settings → EEX Bridges) with the mapping UI and an
  eex_bridge_sections hook for other modules.
- Tests: 123 passing (11 new bridge tests: gating, mapping, idempotency,
  modes, status mirroring, canonical both ways, discovery logging).

## V2-WI4 — WooCommerce → HeySummit bridge

- Amended hard rule enforced in code: Api\WriteEndpoints::ALLOWLIST
  (attendees/, external-ticket-sales/) is the single write definition;
  HeySummitClient::post() throws for anything else; OPTIONS-based write
  shape verification added to discovery (write:attendees,
  write:external-ticket-sales) plus a tickets read resource — nothing is
  ever created during discovery.
- Request builders isolated alongside the mappers
  (AttendeeRequestBuilder, TicketSaleRequestBuilder), filterable for live
  shape corrections.
- Module (src/WooCommerce/, loaded on woocommerce_loaded only): product and
  variation mapping UI (HeySummit tab; connection → event → ticket with
  API-enumerated tickets), checkout consent checkbox for carts with mapped
  products (timestamp in order meta; no consent = no push, flagged),
  push jobs per mapped line item with the push record as the dedupe lock,
  attendee-create → ticket-import → attendee ID in item meta → order note →
  eex_woo_pushed; multi-quantity registers the purchaser once with a
  prominent warning and eex_woo_multi_quantity (record schema
  multi-attendee-ready); 3 retries with backoff then order flagged with
  orders-list notice, manual push button and wp eex woo:push; refunds
  produce the manual-removal note/notice and eex_woo_refunded (D24);
  attribution rows tagged with order ID and deduped against webhooks by
  attendee ID.
- Tests: 132 passing (9 new: allowlist enforcement, single-push despite
  repeated hooks, unmapped = zero calls, consent gate, retry/flag/manual
  re-push, multi-quantity, attribution dedupe, refund paths).

## V2-WI5 — Extras

- UTM auto-tagging (Utm helper) across component register/event URLs,
  event cards and singles, Elementor URL tags, ICS descriptions (campaign
  `calendar`) and projected listings; per-page campaign override; component
  cache varies by campaign context; never clobbers existing parameters.
- Outbound relay: per-action forwarding of verified payloads (email hash
  only) to configured URLs with X-Eex-Secret header, 3 retries with
  backoff, logged deliveries, per-URL send-test button.
- Session filter bar (eex/session-filter + [eex_session_filter]):
  server-rendered category/speaker links and a GET search form that filters
  past-sessions server-side (?eex_q=); with JS, instant client-side
  filtering via data attributes on the session grid. Front-end assets still
  ~3.7KB gzipped combined.
- Dashboard widget: next three sessions, 7-day registrations with a source
  spark, last sync + health, quick links.
- Settings export/import: JSON export without any key/secret material,
  import with per-group diff preview and transient-stashed confirm; local
  keys and secrets always preserved.
- Cache purge integration (off by default): WP Rocket, LiteSpeed, W3TC,
  Cloudflare hooks scoped to affected URLs with full-purge fallback, plus
  eex_cache_purged for bespoke hosts.
- Weekly digest (off by default): Monday plain-text email with
  registrations by source, session counts, upcoming sessions and sync
  health; schedule follows the toggle.
- Tests: 146 passing (14 new extras tests).

## V2 — Completion

- README reconciled with v2 behaviour and extended (wizard, scope, bridges,
  UTM, relay, filter bar, operations extras, new hooks).
- docs/acceptance-report.md v2 section: 7 pass / 0 fail / 1 partially not
  verifiable (browser/live-host halves), each with manual steps.
- Final state: 146 tests, 575 assertions, PHPCS clean, all files php -l
  clean, front-end assets ~3.7KB gzipped combined.
