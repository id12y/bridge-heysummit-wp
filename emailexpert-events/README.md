# emailexpert Events — HeySummit Connector for WordPress

Syncs HeySummit event, session and speaker data into WordPress, renders it
with full Schema.org markup (the plugin's primary purpose: indexable GEO/SEO
content), and receives HeySummit registration webhooks for a live counter,
notifications and an attribution log.

- **Read-only against HeySummit**: the plugin never writes to the HeySummit
  API, and no front-end page load ever calls HeySummit.
- Works with **no API key, no Elementor and the API unreachable**: all
  rendering comes from the local database.
- WordPress 6.4+, PHP 8.1+.

## Installation

1. Copy the `emailexpert-events/` directory into `wp-content/plugins/` (or
   symlink it) and activate **emailexpert Events**.
2. Activation creates two tables (`{prefix}eex_log`,
   `{prefix}eex_attribution`), generates the webhook secret and schedules
   the sync cron.
3. Visit **Settings → Permalinks** once (or just save) so the
   `/events/`, `/sessions/`, `/speakers/`, `/sponsors/` and
   `/feeds/eex/calendar.ics` rewrites flush.

### Development

```bash
cd emailexpert-events
composer install          # dev tools only; the shipped plugin has no runtime dependencies
vendor/bin/phpunit        # unit suite (WordPress stub layer, no Docker needed)
vendor/bin/phpcs          # WordPress Coding Standards
npx wp-env start          # optional: full WordPress for integration testing
```

## Configuration

Everything lives under **Settings → emailexpert Events**.

### API connections

Different properties (the Member Hub, FORUM, Deliverability Summit) may live
under different HeySummit accounts, so keys are managed as a list of
*connections*. With one connection the UI stays a single key field; **Add
connection** reveals the list. Keys are write-only (the UI shows only the
last 4 characters) and are never logged or exposed over REST.

- API access requires the **HeySummit Business plan**.
- You can pin the first connection's key in `wp-config.php`:
  `define( 'EEX_HEYSUMMIT_API_KEY', '…' );` — the field is then disabled.
- **Test connection** calls `events/` and, on success, automatically runs
  the **discovery diagnostic**: the plugin samples each API resource,
  records field names and types (never values), and compares them against
  what the sync mappers expect. Review the *API discovery diagnostics*
  panel — anything listed under "expected but missing" or "type mismatches"
  means the live API differs from the assumed shapes in
  `src/Api/Shapes.php` (see `docs/api-notes.md`). Also available as
  `wp eex discover`.

### Sync

1. Press **Load events from HeySummit** per connection, then tick the
   events to sync.
2. Per event: independent toggles for **sessions**, **speakers** and
   **categories**; **photo sideloading**; **import status** for newly
   created posts (publish immediately, or pending review so editors approve
   before anything appears); and an optional **category filter** for
   session import — include-only or exclude, using the event's HeySummit
   categories (press *Refresh categories* to load them). Sessions removed
   by the filter are treated as if they do not exist: never created, and
   orphan-drafted if previously synced.
3. Global: sync frequency (15 min / hourly / twice daily / daily),
   **Sync now**, and the sync log link.

Sync behaviour worth knowing:

- Posts are matched by HeySummit ID (`_eex_heysummit_id`). Unchanged
  records (same `_eex_sync_hash`) are skipped without any write.
- **Sync owns** the title, description meta, synced meta, category terms
  and speaker photos. **Editors own** `post_content` (rendered below the
  synced description), venue fields, hero override, the manual replay URL
  and event series terms — sync never touches them.
- Every synced post has a **Sync mode** (meta box + quick edit):
  `synced` (default), `detached` (kept forever, never overwritten again)
  or `excluded` (drafted and skipped entirely, even if the record still
  exists in HeySummit). Modes are permanent until an editor changes them.
- Records that disappear from HeySummit are set to **draft** and flagged
  orphaned — never deleted. `wp eex orphans --list` shows them.
- Speakers are deduplicated across events: HeySummit ID, then email hash,
  then exact name + company.
- If Action Scheduler is installed (WooCommerce ships it), per-event jobs
  run through it; otherwise runs are time-budgeted (20 s) with queued
  continuations.

### Sync health

Consecutive failures raise a persistent admin notice after 3 and email the
admin after 6 (toggleable). **Tools → Site Health** gains a "HeySummit sync
health" test (last successful sync per event, webhook recency, cron
reliability). `wp eex status` reports the same.

### Webhooks

The **Webhooks** section shows the receiver URL:

```
https://your-site/wp-json/eex/v1/heysummit/<secret>
```

Paste it into HeySummit's outgoing webhooks for the three actions
(registration started, checkout complete, talk added to schedule). The
secret can be regenerated at any time; a wrong secret gets 404 and the
receiver rate-limits at 60 requests/minute/IP. Deliveries are deduplicated
(HeySummit retries every 15 minutes up to 3 times), acknowledged
immediately and processed asynchronously. Payloads are untrusted:
state-changing actions re-fetch the attendee from the API and use the
fetched record.

Per-action toggles control the behaviours; an optional admin email fires on
each completed checkout.

### Webhook payload capture (do this once after installation)

HeySummit does not document payload shapes, so verify the parser against
reality with one self-registration:

1. Turn on **Capture mode** (Settings → Webhooks) and save.
2. Make a **single self-registration** on the HeySummit event (or use a
   sandbox event) and, if testing checkout, complete it.
3. Each delivery is stored complete in the sync log flagged `capture`
   (Settings → EEX Sync Log; email addresses are redacted to hashes in the
   stored copy).
4. Replay each captured row through the parser without side effects:

   ```bash
   wp eex webhooks:replay <log_id>
   ```

   The command reports the detected action, attendee ID and event ID. If a
   payload is not recognised, open the log row and adjust
   `src/Webhooks/Parser.php` (the parser already accepts nested or flat
   attendees, string or integer IDs, and several action-key spellings).
5. Turn capture mode off and delete the self-registration in HeySummit.

### Display settings

Event series colours (exposed as `--eex-series-<slug>` CSS custom
properties), a PHP date-format override, per-type schema toggles
(Event / Person / VideoObject), the Open Graph fallback toggle, health
email, attribution retention (default 24 months) and the
"delete all data on uninstall" switch.

## Shortcode and block reference

Every component is both a dynamic Gutenberg block (category **emailexpert
Events**) and a shortcode, sharing one render callback — identical output.
Components render only from the local database, are cached for 5 minutes
(flushed on sync completion, webhook receipt and editorial saves) and always
render an empty state (`empty_text` attribute) rather than a blank void.

`event` accepts a HeySummit event ID, WP post ID or slug; with exactly one
synced event it can be omitted everywhere. `category` accepts a slug or
comma-separated slugs.

| Block | Shortcode | Key attributes |
|---|---|---|
| `eex/upcoming-sessions` | `[eex_upcoming_sessions category="" limit="6" show_subscribe="1"]` | Soonest first; card shows title, local time, category badge, speaker chips, register CTA, add-to-calendar links; `show_subscribe` adds the calendar-feed link |
| `eex/past-sessions` | `[eex_past_sessions category="" limit="12" paginate="1"]` | Newest first, paginated (`?eex_page=N`); replay CTA when a replay URL exists |
| `eex/upcoming-events` | `[eex_upcoming_events limit="3" series=""]` | Evergreen events with open registrations always count as upcoming |
| `eex/past-events` | `[eex_past_events]` | Newest first; evergreen events never appear |
| `eex/countdown` | `[eex_countdown event="" talk=""]` | Counts to a session or an event's next/first session; vanilla JS with a text fallback |
| `eex/schedule` | `[eex_schedule event="" category=""]` | Grouped by day in event-local time with timezone label |
| `eex/speakers` | `[eex_speakers event="" category="" columns="4"]` | Speakers with at least one session matching the filters |
| `eex/featured-talks` | `[eex_featured_talks ids="9001,9002"]` | Manual selection (HeySummit or post IDs) |
| `eex/sponsors` | `[eex_sponsors event=""]` | Grouped by tier in tier order; logos link out `rel="sponsored noopener"` |
| `eex/reg-counter` | `[eex_reg_counter event="" threshold="50"]` | Hidden below the threshold; refreshes via REST so cached pages stay current |

### Time handling and cache safety

Server HTML never bakes in time-relative state: times render as
`<time datetime="UTC">` with event-local fallback text, and one small JS
module converts them to the visitor's timezone and computes
upcoming / starting-soon / live-now / past states client-side — correct even
when the cached HTML is hours old. While live, cards show a live indicator
and the CTA becomes **Join now**; without JS, visitors see event-local times
and no live-state claims.

### Calendar

- Every upcoming session card and single offers an `.ics` download
  (`?eex_ics=<post_id>`) and a Google Calendar link.
- Subscribable feed: `/feeds/eex/calendar.ics`, filterable with `?event=`
  and `?category=`. RFC 5545 compliant (escaping + 75-octet folding),
  session URL and speaker names in the description.

## Templates and theming

Fallback templates ship for all singles, archives and the category/series
taxonomies; each synced category is an indexable page listing its upcoming
and past sessions, and speaker singles are genuine profiles with the
speaker's upcoming and past sessions (replay links included).

Override any template by copying it into `yourtheme/emailexpert-events/`;
override just a card by copying `templates/parts/<part>.php` to
`yourtheme/emailexpert-events/parts/`. Parts: `card-talk`, `card-event`,
`card-speaker`, `card-sponsor`, `speaker-chip`, `schedule-row`.

All plugin CSS uses `--eex-*` custom properties (colours, spacing, radius,
columns) — restyle by overriding variables, not files.

## Structured data

JSON-LD on event pages (`Event`/`BusinessEvent` with venue `PostalAddress`
or `VirtualLocation`, `offers` while registrations are open, `performer`
list), session pages (`Event` + `superEvent`, plus `VideoObject` for past
sessions with replays) and speaker pages (`Person` with `sameAs`). Nothing
is emitted when required fields are missing; no placeholders ever.

With **Yoast** or **Rank Math** active, the pieces are injected into their
schema graphs (never a duplicate standalone block). Without an SEO plugin,
the plugin outputs its own JSON-LD plus basic Open Graph/Twitter tags.
Per-type toggles in Display settings are the manual escape hatch.

## Filter and hook reference

The complete public API:

### Filters

| Filter | Signature | Purpose |
|---|---|---|
| `eex_query_args` | `( array $args, string $kind, array $atts )` | Adjust component queries (`talks` / `events`) |
| `eex_card_html` | `( string $html, string $component, array $atts )` | Filter rendered component HTML |
| `eex_schema_data` | `( array $schema, string $kind, int $post_id )` | Adjust schema pieces (`event`/`talk`/`video`/`speaker`) |
| `eex_template_yield` | `( bool $yield )` | Return true to make the template loader stand down |
| `eex_max_pages` | `( int $max, string $path )` | API pagination safety cap (default 50) |
| `eex_sync_time_budget` | `( int $seconds )` | Inline sync run budget (default 20) |
| `eex_http_retry_delay` | `( int $seconds, int $attempt )` | API retry backoff |

### Actions (webhook extension surface, Phase 3+ / ESP integration)

| Action | Payload | Fired |
|---|---|---|
| `eex_checkout_complete` | `array $attendee, int $event_post_id` | Checkout completed (attendee verified against the API where possible; email present only as `email_hash`) |
| `eex_registration_started` | `array $attendee, int $event_post_id` | Registration started |
| `eex_registration_abandoned` | `string $email_hash, string $event_hs_id` | 60 minutes after a start with no matching checkout. The plugin sends no attendee email; downstream automation owns that |
| `eex_talk_signup` | `array $attendee, string $talk_hs_id, int $event_post_id` | Talk added to an attendee's schedule |
| `eex_webhook_processed` | `array $result` | After any webhook is processed (used for cache flushing) |

The mapped `$attendee` array: `hs_id`, `email_hash` (never the raw email),
`name`, `registration_status`, `event_hs_id`, `created_at`, `utm_source`,
`utm_medium`, `utm_campaign`, `referer_domain`, `affiliate_email`,
`ticket_name`, `amount_gross`, `talk_hs_ids`.

### WP-CLI

```
wp eex sync [--event=<id>] [--force]   # --force ignores sync hashes
wp eex status
wp eex orphans --list
wp eex discover
wp eex webhooks:replay <log_id>
```

### REST (read-only, public)

- `GET /wp-json/eex/v1/counter/<event_hs_id>` → `{ "count": n }`
- All CPTs and sync-owned meta are exposed read-only via the standard WP
  REST API, so other properties (festivalofemail.com, sendersymposium.com)
  can consume the synced data with no extra work.

## Elementor guide (optional module)

The module loads only when Elementor is active (`src/Elementor/`, registered
on `elementor/init`); every capability also works without it, and shortcodes
work in Elementor's Shortcode widget regardless.

- **Widgets**: one per component in the *emailexpert Events* category.
  Content controls mirror the component attributes (event and category are
  selects populated from synced data). Style controls (colours, radius,
  gap) write the same `--eex-*` custom properties themes use, scoped to the
  widget. Output is identical to the block/shortcode; editor previews are
  real server renders.
- **Theme Builder (Pro)**: when a theme template's display conditions match
  an `eex_` single or archive, the plugin's template loader yields
  completely — no double headers, no leaked markup.
- **Dynamic tags (Pro)**: group *emailexpert Events* — session start/end
  (formatted, site or event timezone), category list, live status, register
  URL, replay URL, event URL, registration count, venue fields, speaker
  name/headline/company/photo/link. Tags return empty when data is missing.
- **Loop Grid query IDs (Pro)**: `eex_upcoming_sessions`,
  `eex_past_sessions`, `eex_event_sessions` — set one as the Query ID on a
  Loop Grid to get correctly ordered sessions without meta-date logic.

## Attribution and privacy

Checkout and registration-start webhooks land in the attribution table with
UTM parameters, referrer domain, affiliate and ticket data. Emails are
stored **only** as SHA-256 hashes. **Settings → EEX Attribution** shows the
report (totals by source and status, event/date filters, CSV export).

The plugin registers with the WordPress personal data exporter and eraser
(Tools → Export/Erase Personal Data): a requester's email is hashed the same
way and matching attribution rows and log entries are exported or removed.
Attribution retention is configurable (default 24 months, pruned daily);
logs are kept 30 days.

## Uninstall

Deactivating unschedules cron. Deleting the plugin removes options, custom
tables and scheduled events; synced content is kept unless "On uninstall,
delete all data" was enabled in Display settings.

## Repository layout

```
emailexpert-events.php   bootstrap
src/                     PSR-4 (Emailexpert\Events), no runtime Composer
  Api/                   HeySummit client, assumed shapes, discovery
  Mappers/               all response mapping (the only place shapes live)
  Sync/                  engine, scheduler, upsert, media, health
  Frontend/              components, blocks glue, schema, calendar, cache
  Webhooks/              receiver, parser, processor, attribution, privacy
  Elementor/             optional module (loads only with Elementor)
blocks/                  block editor script (plain JS, no build step)
templates/               overridable templates and parts
assets/                  one CSS file, two small JS files
tests/                   PHPUnit suite + WordPress stub layer + fixtures
docs/                    api-notes, decisions, progress, acceptance report
```
