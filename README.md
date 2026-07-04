# emailexpert Events — HeySummit Connector for WordPress

Syncs HeySummit event, session and speaker data into WordPress, renders it
with full Schema.org markup (the plugin's primary purpose: indexable GEO/SEO
content), and receives HeySummit registration webhooks for a live counter,
notifications and an attribution log.

- **Read-only against HeySummit** apart from the WooCommerce bridge's two
  allowlisted endpoints (attendee create, idempotent ticket attach) —
  enforced in code, see the v2 section. No front-end page load ever calls
  HeySummit.
- Works with **no API key, no Elementor and the API unreachable**: all
  rendering comes from the local database.
- WordPress 6.4+, PHP 8.1+.

This repository **is** the plugin: its root is the plugin directory, ready
to install as `wp-content/plugins/emailexpert-events/`.

## Installation

1. Clone or download this repository into
   `wp-content/plugins/emailexpert-events/` (or symlink it) and activate
   **emailexpert Events**. No build step is needed: the blocks are plain-JS
   dynamic blocks and Composer is only required for development (tests and
   linting), not at runtime.
2. Activation is deliberately minimal (v2): it registers the post types,
   seeds the fixed terms, flushes rewrites and writes one settings option.
   Tables, cron and the webhook secret are all created on demand — the log
   table on first sync, the attribution table when webhooks are enabled,
   the sync cron when the first event is enabled.
3. Follow the dismissible notice into the **setup wizard** (Settings →
   EEX Setup), or configure manually under Settings → emailexpert Events.

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
| `eex/upcoming-sessions` | `[eex_upcoming_sessions category="" layout="cards" limit="6" show_subscribe="1"]` | Soonest first; `limit` = number to show; `layout` and `show_*` toggles below; `show_subscribe` adds the calendar-feed link |
| `eex/past-sessions` | `[eex_past_sessions category="" layout="cards" limit="12" paginate="1"]` | Newest first, paginated (`?eex_page=N`); replay CTA when a replay URL exists |
| `eex/upcoming-events` | `[eex_upcoming_events layout="grid" limit="3" series=""]` | Evergreen events with open registrations always count as upcoming; `layout="list"` for rows |
| `eex/past-events` | `[eex_past_events layout="grid"]` | Newest first; evergreen events never appear |
| `eex/countdown` | `[eex_countdown event="" talk=""]` | Counts to a session or an event's next/first session; vanilla JS with a text fallback |
| `eex/schedule` | `[eex_schedule event="" category="" show_speakers="1" show_categories="1"]` | Grouped by day in event-local time with timezone label |
| `eex/speakers` | `[eex_speakers event="" category="" layout="grid" columns="4" photo_shape="rounded" order="name" paginate="0" all_url=""]` | Speakers with at least one session matching the filters; `order="name\|name-desc\|random"` (random picks `limit` speakers afresh each cache refresh and disables pagination); `all_url`/`all_text` add a "View all speakers" link; paginates on `?eex_speaker_page=N` when `paginate="1"` and `limit` > 0 |
| `eex/featured-talks` | `[eex_featured_talks ids="9001,9002" layout="cards"]` | Manual selection (HeySummit or post IDs) |
| `eex/sponsors` | `[eex_sponsors event="" layout="grid"]` | Grouped by tier in tier order; logos link out `rel="sponsored noopener"`. Sponsors are manual data — HeySummit's API exposes no sponsors endpoint. In Lite, manage them (rows or CSV bulk import; logos by URL or media-library ID) under Settings → Live display → Sponsors; in Full they are Sponsor entries |
| `eex/reg-counter` | `[eex_reg_counter event="" threshold="50"]` | Hidden below the threshold; refreshes via REST so cached pages stay current |
| `eex/next-session` | `[eex_next_session event="" show_countdown="1"]` | Hero banner for the single soonest session: big title, local time, countdown, speakers, register CTA |
| `eex/pricing` | `[eex_pricing event="" layout="columns" tickets="" exclude="" featured="" show_remaining="1"]` | Ticket pricing table straight from HeySummit: prices, ribbon, scarcity, coverage. Granular control: `tickets`/`exclude` (comma-separated ticket IDs), `featured` (hero ticket ID — emphasised card + ribbon), `ribbon_text`, `columns`, and `show_free`/`show_paid`/`hide_soldout`/`show_covers` toggles |
| `eex/speaker-spotlight` | `[eex_speaker_spotlight speaker="" show_bio="1"]` | One featured speaker with photo, role and biography; empty `speaker` = a random pick that rotates each cache refresh |
| `eex/events-portfolio` | `[eex_events_portfolio status="live" layout="grid"]` | Every event on the account (live/evergreen/archived/all) — a self-maintaining "Our events" page |
| `eex/live-now` | `[eex_live_now limit="3"]` | Slim banner that appears only while a session is live, with a Join link; renders hidden otherwise |

### Layouts and display toggles

Session listings (`upcoming-sessions`, `past-sessions`, `featured-talks`)
accept `layout="cards|list|agenda|compact"`:

- **cards** — the default responsive card grid (`columns="1-6"` overrides the
  column count; `0` leaves it to CSS or the Elementor responsive control).
- **list** — bordered rows: time, title, speakers, actions.
- **agenda** — sessions grouped under day headings ("7 July 2026") with time,
  an Online badge, speaker photo and role, and a prominent register action.
- **compact** — one line per session: time and title.

Events and sponsors accept `layout="grid|list"`. Speakers accept
`layout="grid|list"` plus `photo_shape="rounded|circle|square"`.

The rendered-fragment cache lifetime is a setting (**Display cache
lifetime**, 1–1440 minutes, default 5) — it also controls how often random
speaker selections reshuffle. In Lite, the separate **Live cache lifetime**
governs how often the underlying HeySummit data refreshes.

Speaker links: `speaker_link="default|hub|none"` on the speakers grid and
spotlight — link to this site's speaker pages, to the speaker's page on the
HeySummit hub (best effort: the hub's name-based slug), or nowhere.
`register_text` relabels the register button on any listing.

Display toggles on session listings and the schedule (all default on):
`show_speakers`, `show_categories`, `show_ics` (the .ics link) and
`show_google` (the Google Calendar link). Every layout keeps the live-state
and filter-bar data attributes, so the countdown, live badges and session
filter keep working.

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

---

# Version 2 additions

## Lazy footprint

Activation now creates no tables and schedules no cron: the log table
appears on the first log write, the attribution table when webhooks are
enabled, the sync cron only while at least one event is enabled, and the
webhook secret on first use. `eex_settings` is the single autoloaded
option. A front-end page with no components runs no plugin queries and
loads no plugin assets.

## Setup wizard and time-scoped imports

A dismissible activation notice offers the five-step wizard (Settings →
EEX Setup, re-runnable any time): connect and test the key with an inline
discovery summary; choose events (title, dates, session counts); scope each
event; preview exact import counts from a GET-only dry run that updates as
you adjust; confirm and watch the initial sync from the log. The wizard
writes only the standard settings.

Per event (also in the main Sync settings): **future sessions** all/none
and **past sessions** all / none / most recent N / since a date. Scope is
rolling — evaluated on every run — and out-of-scope sessions behave exactly
like category-filtered ones: never created, orphan-drafted if previously
synced.

## MyListing bridge (optional)

With the MyListing theme active, Settings → EEX Bridges projects events,
sessions and/or speakers into listings — one way; the `eex_` posts stay
canonical as data. Listing types and fields are detected from the installed
theme at runtime (never hardcoded; the bridge disables itself with a notice
if detection is unconfident). Map source fields to listing fields per type;
unmapped fields are never written. Sync modes, import status and orphans
mirror through. Choose the canonical side per source type: the other side
carries rel=canonical, schema is emitted only on the canonical side, and an
optional "listings only" mode noindexes the plugin pages.

## WooCommerce → HeySummit bridge (optional)

Sell tickets in WooCommerce; each consented, completed purchase becomes a
HeySummit attendee with the mapped ticket price assigned in the create
call (the v2 API has no off-platform sale import — amounts stay on the
order; formerly described here as an external ticket sale, the API's
off-platform flow, avoiding HeySummit's transaction fee).

- Map a product (or variation) on its edit screen: HeySummit tab →
  connection → event → ticket (tickets enumerated live). Unmapped products
  are ignored entirely.
- Checkout gains a required consent checkbox (text configurable) whenever
  the cart holds mapped products; the consent timestamp is stored on the
  order. **No consent, no push** — the order is flagged instead.
- On order completion (optionally `processing`), one async job per mapped
  line item: attendee create → ticket sale import → attendee ID stored on
  the item → order note → `eex_woo_pushed`. The local push record is the
  lock, so repeated hooks can never double-push. Quantity above 1 registers
  the purchaser and adds a prominent note that additional attendees need
  manual registration (`eex_woo_multi_quantity`).
- Failures retry 3 times with backoff, then flag the order with an
  orders-list notice, a **Push to HeySummit** button on the order screen
  and `wp eex woo:push <order_id>`.
- Refunds: attendee removal is outside the write allowlist, so a full
  refund adds a manual-removal note/notice and fires `eex_woo_refunded`.
- **Write allowlist**: the client can only ever POST to
  `events/<id>/attendees/` and `events/<id>/attendees/<pk>/tickets/`
  (anchored patterns, defined once in
  `Api\WriteEndpoints`); anything else throws. Discovery verifies both
  write shapes via OPTIONS — nothing is created during discovery.
- Attribution rows from Woo pushes carry the order ID and dedupe against
  HeySummit's own checkout-complete webhook by attendee ID.

## Forms → HeySummit bridge (optional)

Register form submissions as HeySummit attendees. Works with **Elementor
Pro Forms** (a proper "HeySummit registration" action in the form widget's
Actions After Submit), **Gravity Forms**, **WPForms** and **Fluent Forms**
(matched by form ID); works in both operating modes.

- Define mappings under Settings → EEX Bridges → Forms: label, source
  plugin, form ID, connection, event and ticket price (both offered as
  name pickers wherever the API answers), plus the field IDs holding the
  email, name and consent — the IDs as the form plugin shows them
  (Elementor custom field IDs, Gravity Forms field numbers with dots for
  name parts, WPForms field numbers, Fluent Forms input names).
- **Consent is a hard rule.** Default mode requires the mapped consent
  checkbox to be ticked; "implied" mode (submitting the form is itself the
  registration consent) exists for forms whose stated purpose is
  registering, and must be chosen per mapping. Suppressed addresses are
  never pushed — checked at capture and again at delivery, so an opt-out
  between the two wins.
- Optional question answers: map form fields to HeySummit registration
  question IDs; answers travel inside the same attendee-create call.
- Submissions queue for async push (the visitor's submit never waits on
  the API), deduped by email + event + mapping so double submissions and
  re-fired plugin hooks produce one attendee. "Already exists" counts as
  success. Failures retry 3 times with backoff, then flag a notice with
  retry/clear buttons on the Bridges screen. Queue entries are deleted on
  success; addresses never reach the log and appear masked on screen.
- Same write allowlist as every other bridge — attendee-create only,
  nothing new opened.

## UTM auto-tagging

Display settings → UTM: set a source (e.g. `emailexpert.com`) and medium;
every register/event link the plugin outputs (blocks, shortcodes, Elementor
tags, calendar entries, projected listings) gains
`utm_source/utm_medium/utm_campaign`, with the campaign taken from the
rendering page's slug (`_eex_utm_campaign` meta overrides per page).
Together with the attribution report this shows which page produced which
registration.

## Outbound relay

Settings → Webhooks → Outbound relay: forward each processed, verified
action (checkout complete / registration started / talk added) as JSON to
any number of URLs (n8n, Make, an ESP) with a per-URL `X-Eex-Secret`
header, 3 retries and logged deliveries, plus a per-URL "send test payload"
button. Attendee emails are relayed as hashes only.

## Session library filter bar

`eex/session-filter` block / `[eex_session_filter]` pairs with the
past-sessions block: category and speaker links plus text search. Without
JS everything works as links and a GET form (`?eex_q=`, filtered
server-side); with JS the rendered list filters instantly.

## Operations extras

- **Dashboard widget**: next three sessions, 7-day registrations by source,
  last sync and health, quick links.
- **Settings export/import** (bottom of the settings page): JSON export
  containing no API keys or secrets; import shows a diff preview before
  applying and always preserves local keys.
- **Cache purge integration** (off by default): fires WP Rocket, LiteSpeed,
  W3TC and Cloudflare purge hooks after syncs and counter changes, scoped
  to affected URLs where supported.
- **Weekly digest** (off by default): Monday plain-text email with
  registrations by source, session activity, upcoming sessions and sync
  health.

## New hooks (v2)

| Hook | Payload | Fired |
|---|---|---|
| `eex_sync_completed` | — | After a sync run finishes |
| `eex_woo_pushed` | `int $order_id, int $item_id, array $attendee` | Woo purchase pushed to HeySummit |
| `eex_woo_multi_quantity` | `int $order_id, int $item_id, int $quantity` | Multi-quantity item pushed (purchaser only) |
| `eex_woo_refunded` | `int $order_id` | Pushed order fully refunded (manual removal needed) |
| `eex_cache_purged` | `string[] $urls, bool $allow_full` | After purge hooks fired |
| `eex_schema_suppress` (filter) | `bool, int $post_id` | Suppress schema on a view (canonical control) |
| `eex_mylisting_meta_key` (filter) | `string $meta_key, string $field_key` | Listing field meta key mapping |
| `eex_attendee_request` / `eex_ticket_sale_request` (filters) | request array | Correct write shapes against the live API |

---

# Version 3 additions: account registration rules

An optional module (Settings → EEX Bridges → Account registration; a single
enable switch until turned on, and **zero module code loads while off**)
that registers account holders as HeySummit attendees under granular rules:
gain an account, a role, a published listing or an entitlement on the site,
get the right event ticket.

## Rules

Each rule: a trigger, conditions, a target (connection + event + ticket), a
consent source and notes.

- **Account confirmed** — the trigger point is configurable per rule,
  honestly: *on registration* (core WordPress performs **no** email
  verification, so this means "form submitted"), *on first login*, or *on
  the canonical `eex_user_confirmed` action*. Shipped adapters fire that
  action for WooCommerce account creation, MyListing registration and
  common email-verification plugins; any plugin can fire it directly:
  `do_action( 'eex_user_confirmed', $user_id );`
- **Role gained** — fires on `set_user_role`/`add_user_role` for the rule's
  roles, at registration or later. This is how "product purchase grants
  membership" chains through to a ticket without coupling.
- **Listing published** (shown only with the MyListing bridge active) — a
  member whose listing of the chosen type(s) goes live gets event access.
- Product purchases stay on the v2 WooCommerce path; the shared
  registration ledger dedupes the two automatically.

Conditions per rule: role allowlist, listing type(s), excluded roles and
excluded user IDs.

**Idempotency**: one shared registration ledger (user + event) is the lock
across all paths — repeated role changes, overlapping rules, backfills and
Woo purchases can never register the same user twice for the same event,
and an "attendee already exists" API response is recorded as success, never
an error.

## Consent and suppression (hard rules)

No user is ever pushed without a satisfied consent source:

- **Registration checkbox** — rendered on the core WordPress and
  WooCommerce registration forms; any form builder can set the documented
  user meta key `eex_event_consent` (a timestamp) instead.
- **Site terms assertion** — a deliberately worded operator setting
  confirming the site's registration terms cover event registration and
  event-related email, stored with who enabled it and when, displayed with
  a plain warning about what enabling it means.

A profile field ("Do not register me for events") suppresses immediately.
The suppression list (email-hash + event pairs; populated by opt-outs,
erasure requests and manual entries) is checked before every push — rule,
backfill, retry or manual — and re-checked at delivery time. Every push
records which rule, trigger and consent source justified it.

## Backfill

Per rule and never automatic: **dry run** (exact count and a sample of
matched users) → explicit confirmation → queued batches of 20 with progress
in the sync log, resumable from persisted state. The dry run and the
confirmed run share the identical gate chain, so the counts always match.

## Failure handling

Queued async pushes, 3 retries with backoff, then the user is flagged with
an admin notice, a **Push to HeySummit** row action on the Users screen
(which also shows per-event push status), `wp eex accounts:push <user_id>`
and `wp eex accounts:backfill <rule_id> [--dry-run]`. Registrations join
the attribution table (source `account-rule`), the dashboard widget's
weekly numbers and the digest.

## Ticket assignment

Resolved at runtime from the discovery diagnostic (see
`docs/api-notes.md`): ticket in the create body when the API supports it,
otherwise the documented-idempotent ticket attach, otherwise the attendee
is registered without a ticket and a warning names the intended ticket in
the log and diagnostics panel. No new write endpoints: the v2 allowlist
(attendee create + ticket import) is unchanged and still enforced in code.

## Plain limitations

- **One-way.** WordPress email changes do **not** propagate to HeySummit
  (`eex_user_email_changed_after_registration` fires; the change is
  logged). Account deletion does not remove the attendee. GDPR erasure adds
  the address to the suppression list and the eraser output states that
  HeySummit-side removal is a manual step.
- **No revocation.** Role loss and listing unpublish do not remove event
  access; `eex_role_lost_after_registration` and
  `eex_listing_unpublished_after_registration` fire for site-specific
  handling.
- **"Confirmed" is as strong as its trigger.** On-registration means no
  verification at all; first-login is a weak proxy; the
  `eex_user_confirmed` action is only as good as whatever fires it.

## New hooks (v3)

| Hook | Payload | Fired |
|---|---|---|
| `eex_user_confirmed` (action to fire) | `int $user_id` | Tell the module an account is confirmed |
| `eex_account_pushed` | `int $user_id, string $event_hs_id, string $attendee_hs_id` | A rule registered a user |
| `eex_role_lost_after_registration` | `int $user_id, string[] $lost_roles` | Registered user lost a role |
| `eex_listing_unpublished_after_registration` | `int $owner_id, int $listing_id, string $type` | Registered user's listing unpublished |
| `eex_user_email_changed_after_registration` | `int $user_id, string $old, string $new` | Registered user changed email |
| `eex_ticket_assignment_method` (filter) | `string $method, string $connection_id` | Override the discovered assignment method |

---

# v4: Lite mode

A second operating mode, chosen at install (wizard step 0) and switchable
in Settings → emailexpert Events → Operating mode. **Full** is everything
above. **Lite** displays live HeySummit data without turning any of it
into WordPress content: no post types or taxonomies, no posts or media,
no sync cron, no custom tables, no extra rewrite rules. Lite suits a site
that wants a live feed of the next sessions or events, not a mirror.

## What Lite keeps and gives up

Kept: the live display components (upcoming sessions, upcoming events,
countdown, schedule, speaker grid, featured talks, sponsors wall),
per-session `.ics` downloads and Google Calendar links, inline Event
JSON-LD emitted by the blocks themselves, UTM auto-tagging, a three-step
setup wizard (connect, pick events, done), settings export/import, the
dashboard widget (next sessions and cache status) — and the **WooCommerce
bridge, exactly as in Full**: mapped purchases push attendee and ticket
sale identically in both modes, and the attribution table is created only
when the first push happens.

Given up (each settings location says "available in Full mode"): local
event/session/speaker pages and archives — and therefore the SEO/GEO
content they carry — the replays library, past-sessions archive
(Lite is forward-looking), calendar subscribe feed, webhooks and
attribution, the registration counter, MyListing bridge, Accounts module,
Elementor dynamic tags and Loop Grid queries (plain Elementor widgets
still work), and the weekly digest.

## How Lite fetches data

Everything is fetched **server-side at render time** through the same
read-only client — the browser never contacts HeySummit and the API key
never leaves the server. Responses live in transients: a fresh copy
(configurable, default 15 minutes) plus a 24-hour last-good copy. On API
failure or timeout the page serves last-good, failing that the
component's empty state — never an error, never a hung page. A hard
budget of 2 cold fetches per page request (3-second timeout each, with a
stampede lock so concurrent visitors trigger one fetch) means a cold page
renders immediately and the cache warms on subsequent views. "Flush live
cache" in settings (and on the dashboard widget) clears everything;
deactivation does too.

## One code path, two repositories

Every component render callback reads through the `Data\Repository`
interface: `SyncedRepository` (Full, the local database) or
`LiveRepository` (Lite, the API cache). Blocks, shortcodes and Elementor
widgets share the same callbacks unforked; the only rendering differences
in Lite are link targets (HeySummit URLs, UTM-tagged, instead of local
pages) and the inline JSON-LD block.

## Switching modes

- **Lite → Full** runs the standard import wizard; nothing is lost.
- **Full → Lite** shows a confirmation screen: keep the synced content
  (the "frozen archive" — posts stay published and readable, post types
  stay registered, sync stays stopped) or trash it (reversible, via the
  bin). Sync cron is unscheduled either way.

## Footprint in Lite

Activation writes exactly one option (the settings option). No tables
unless the WooCommerce bridge pushes (then only the attribution table);
no cron unless those pushes need retries; logging goes to a 20-entry
self-expiring ring buffer instead of the log table. Uninstall removes the
options and transients — nothing else exists.

## Lite sponsors

Sponsors were always manual data. In Lite they live inside the settings
option (up to 60 lean rows: name, link, external logo URL, tier, blurb)
with a simplified editor on the settings page — no posts, no media
sideloading.

## Checkout compatibility note

The registration-consent checkbox renders on the classic
`[woocommerce_checkout]` shortcode checkout (required there when the cart
contains mapped products) and on the block checkout via WooCommerce's
Additional Checkout Fields API (WooCommerce 8.9+, where it appears on
every checkout and is optional). Either way the rule is the same: no
recorded consent, no push. On older WooCommerce versions using the block
checkout, consent cannot be captured — the plugin shows an admin warning
and purchases will not push until WooCommerce is updated or the classic
checkout is used.
