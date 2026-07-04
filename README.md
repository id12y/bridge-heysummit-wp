# emailexpert Events — HeySummit Connector for WordPress

> **⚠️ BETA software — use with care.** Licensed under the
> [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). As the GPL
> states (§11–12), it is provided **as is, without warranty of any kind and
> without any promises or protections of liability** — no fitness for a
> particular purpose, no guarantee against data loss. This plugin writes
> attendee data to a live event platform: test against a sandbox event
> first, keep backups, and read what each bridge does before enabling it.
> Currently distributed privately/directly to beta partners only.

Made with ❤️ by [emailexpert](https://emailexpert.com/) — the community for
the email industry — and [agency.cm](https://agency.cm/). If this plugin
helps your events, come say hello.

Connects WordPress to HeySummit in both directions:

- **Displays live event data beautifully** — session cards, agendas, hero
  banners, countdowns, speaker grids, ticket pricing tables, sponsor walls
  and spotlights, a live-now bar — as Gutenberg blocks, shortcodes and
  Elementor widgets sharing one renderer, so output is identical everywhere.
- **Registers people** — an in-page ticket panel with instant free-ticket
  registration, deep links into HeySummit checkout, and bridges that turn
  WooCommerce purchases, form submissions and site accounts into HeySummit
  attendees — always behind consent and a suppression list.
- **Optionally mirrors everything into WordPress content** — indexable
  event, session and speaker pages with full Schema.org markup, webhooks,
  attribution reporting and more (Full mode).

No front-end page load ever calls HeySummit from the browser; the API key
never leaves the server. Writes to HeySummit are restricted in code to two
allowlisted endpoints (attendee create, idempotent ticket attach) — anything
else throws. WordPress 6.4+, PHP 8.1+.

This repository **is** the plugin: its root is the plugin directory, ready
to install as `wp-content/plugins/emailexpert-events/`.

## Two operating modes

Chosen at install (wizard step 0), switchable any time under Settings →
emailexpert Events → Operating mode.

### Lite — live display, zero footprint

Lite is not a demo tier — it is the full display and registration
experience running straight off the HeySummit API, with almost no footprint
in WordPress: no post types, no posts or media, no sync cron, no custom
tables, no extra rewrite rules. Activation writes exactly one option.

Everything below works in Lite:

- **All display components** (the whole table further down): upcoming
  sessions and events, schedule, countdowns, speakers and speaker
  spotlight, featured talks, next-session hero (4 styles), ticket pricing
  table, events portfolio, live-now bar, sponsor wall and sponsor
  spotlight — all fed live from the API, server-side, cached.
- **The registration experience**: the slide-over ticket panel, in-page
  free-ticket registration, checkout deep links, external ticketing URLs.
- **The WooCommerce and Forms bridges, identically to Full** — consented
  purchases and form submissions push attendees either way.
- Per-session `.ics` downloads and Google Calendar links, inline Event
  JSON-LD on the listing components, UTM auto-tagging, settings
  export/import, the dashboard widget, a three-step setup wizard.

How Lite fetches: everything server-side at render time through the same
read-only client. Responses live in transients — a fresh copy (default 15
minutes) plus a 24-hour last-good copy. On API failure the page serves
last-good, failing that the component's empty state — never an error, never
a hung page. A hard budget of 2 cold fetches per page request (3-second
timeout, stampede-locked) means a cold page renders immediately and warms
on subsequent views. Empty results are never long-cached, so a blip cannot
pin "no sessions" to a page. "Flush live cache" clears everything.

What only Full adds (each settings location says so): local event / session
/ speaker **pages and archives — and the SEO/GEO indexable content they
carry** — the replays library and past-sessions components, the calendar
subscribe feed, webhooks + attribution + registration counter, the
MyListing bridge, the Accounts module, Elementor dynamic tags and Loop Grid
queries (plain Elementor widgets work in both modes), and the weekly
digest.

### Full — everything above plus a local mirror

Full syncs events, sessions and speakers into real WordPress content:
indexable pages with full Schema.org markup, editor-owned fields sync never
touches, orphan handling, webhooks with attribution reporting, and the
Accounts and MyListing modules.

Switching: **Lite → Full** runs the standard import wizard; nothing is
lost. **Full → Lite** asks whether to keep the synced content as a frozen,
readable archive or trash it (reversible via the bin). One shared
`Data\Repository` interface feeds every component from the local database
(Full) or the API cache (Lite) — same callbacks, same markup.

## Installation

1. Clone or download this repository into
   `wp-content/plugins/emailexpert-events/` (or symlink it) and activate
   **emailexpert Events**. No build step: the blocks are plain-JS dynamic
   blocks and Composer is only needed for development.
2. Activation is deliberately minimal: one settings option (plus post types
   and rewrites in Full). Tables, cron and the webhook secret are created
   on demand.
3. Follow the dismissible notice into the **setup wizard** (Settings → EEX
   Setup), or configure manually under Settings → emailexpert Events.

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

Different properties may live under different HeySummit accounts, so keys
are managed as a list of *connections*. With one connection the UI stays a
single key field. Keys are write-only (the UI shows only the last 4
characters) and are never logged or exposed over REST.

- API access requires the **HeySummit Business plan**.
- The first connection's key can be pinned in `wp-config.php`:
  `define( 'EEX_HEYSUMMIT_API_KEY', '…' );` — the field is then disabled.
- **Test connection** calls `events/` and, on success, runs the **discovery
  diagnostic**: the plugin samples each API resource, records field names
  and types (never values), and compares them against what the mappers
  expect (`src/Api/Shapes.php`, `docs/api-notes.md`). Also
  `wp eex discover`. Discovery reports are how live API changes become
  plugin features — anything under "unmapped fields" is a candidate.

### Sync (Full mode)

1. **Load events from HeySummit** per connection, then tick the events to
   sync.
2. Per event: independent toggles for sessions / speakers / categories,
   photo sideloading, import status (publish immediately or pending
   review), an optional category filter (include-only or exclude), and
   time scoping — future sessions all/none, past sessions all / none /
   most recent N / since a date. Scope is rolling; out-of-scope sessions
   are never created and orphan-drafted if previously synced.
3. Global: sync frequency (15 min – daily), **Sync now**, the sync log.

Worth knowing:

- Posts are matched by HeySummit ID; unchanged records (same sync hash)
  are skipped without any write.
- **Sync owns** titles, synced meta, category terms and speaker photos.
  **Editors own** `post_content`, venue fields, hero override, manual
  replay URL and event series — sync never touches them.
- Every synced post has a **Sync mode**: `synced`, `detached` (kept
  forever, never overwritten) or `excluded` (drafted and skipped).
- Records that disappear from HeySummit are drafted and flagged orphaned —
  never deleted (`wp eex orphans --list`).
- Speakers are deduplicated across events (ID, then email hash, then
  name + company).
- Consecutive sync failures raise an admin notice after 3 and email the
  admin after 6; **Tools → Site Health** gains a sync-health test;
  `wp eex status` reports the same.

### Webhooks (Full mode)

The receiver URL (`/wp-json/eex/v1/heysummit/<secret>`) is pasted into
HeySummit's outgoing webhooks for registration started, checkout complete
and talk added. Wrong secret → 404; rate-limited; deliveries deduplicated,
acknowledged immediately, processed asynchronously; payloads are untrusted
and state-changing actions re-fetch the attendee from the API. A **capture
mode** stores complete (email-redacted) payloads for one self-registration
so the parser can be verified against reality
(`wp eex webhooks:replay <log_id>`).

### Display settings

Event series colours (exposed as `--eex-series-<slug>` custom properties),
date-format override, per-type schema toggles, Open Graph fallback,
display-cache lifetime (1–1440 minutes; also how often random picks
reshuffle), live-cache lifetime (Lite), attribution retention and the
"delete all data on uninstall" switch.

## Display components

Every component is a dynamic Gutenberg block (category **emailexpert
Events**), a shortcode and an Elementor widget sharing one render callback —
identical output. Components are cached (flushed on sync, webhooks,
editorial saves and **automatically on every plugin update**) and always
render a configurable empty state rather than a blank void.

`event` accepts a HeySummit event ID, WP post ID or slug; with exactly one
configured event it can be omitted everywhere. In Elementor, events,
tickets, sponsors and sponsor categories are picked by **name** from
dropdowns — IDs are never typed by hand once the API has answered.

| Block | Shortcode | What it does |
|---|---|---|
| `eex/next-session` | `[eex_next_session]` | Hero for the single soonest session in 4 styles (`layout="panel\|banner\|spotlight\|minimal"`), with countdown, speakers, and the full two-button register experience below |
| `eex/upcoming-sessions` | `[eex_upcoming_sessions]` | Soonest first; layouts `cards\|list\|agenda\|compact`, column control, session images, venue/stage, status badges (In person / Open access / Replay soon), brand logos, calendar links, subscribe link |
| `eex/past-sessions` | `[eex_past_sessions]` (Full) | Newest first, paginated; replay CTA when a replay exists |
| `eex/schedule` | `[eex_schedule]` | Grouped by day in event-local time with timezone label |
| `eex/featured-talks` | `[eex_featured_talks ids=""]` | Hand-picked sessions, same layouts and register options as upcoming sessions |
| `eex/countdown` | `[eex_countdown event="" talk=""]` | Counts to a session or an event's next session; vanilla JS, text fallback |
| `eex/upcoming-events` / `eex/past-events` | `[eex_upcoming_events]` | Event cards or rows; evergreen events with open registrations always count as upcoming |
| `eex/events-portfolio` | `[eex_events_portfolio status="live\|evergreen\|archived\|all"]` | Every event on the account — a self-maintaining "Our events" page |
| `eex/pricing` | `[eex_pricing]` | Ticket pricing table straight from HeySummit: columns or rows, include/exclude ticket lists, featured ticket with ribbon, descriptions, coverage, remaining counts, sold-out handling, free/paid toggles |
| `eex/speakers` | `[eex_speakers]` | Grid or list, photo shapes, ordering incl. random, pagination, "view all" link, hub/local/no links |
| `eex/speaker-spotlight` | `[eex_speaker_spotlight]` | One featured speaker (or a rotating random pick) with photo, role, biography |
| `eex/sponsors` | `[eex_sponsors]` | The sponsor wall — see below |
| `eex/sponsor-spotlight` | `[eex_sponsor_spotlight]` | One sponsor, large — see below |
| `eex/live-now` | `[eex_live_now]` | Slim banner that appears only while a session is live, with a Join link |
| `eex/reg-counter` | `[eex_reg_counter threshold="50"]` (Full) | Live registration counter, hidden below the threshold, refreshed via REST so cached pages stay current |
| `eex/session-filter` | `[eex_session_filter]` (Full) | Category/speaker/text filter bar for the sessions library; works without JS as links + GET form |

### Registration buttons and the ticket panel

Sessions carry up to **two buttons**, each independently configurable
(`buttons="both|tickets|session"`, `register_text`, `session_text`):

- **Tickets** goes to the event's real ticketing: HeySummit's
  ticket-selection checkout by default, a per-widget external ticketing URL
  (`register_url`) when the event sells elsewhere, and a session's own
  `external_url` from HeySummit overrides both.
- **Session page** goes to the talk's landing page (or its external URL).
  While the session is live, this button becomes **Join now**.

`register_action="panel"` turns the tickets button into a **slide-over
ticket panel** rendered server-side: each ticket with price and
description; **free tickets register right there** (name, email, required
consent — posted to this site's own REST endpoint, which validates the
ticket is genuinely free, rate-limits, honours the suppression list and
creates the attendee server-side); paid tickets deep-link to checkout, or —
with `buy_on="woo"` — to the mapped WooCommerce product. `tickets` /
`exclude` control which tickets the panel offers.

### The sponsor wall and spotlight

Sponsors come **live from the HeySummit sponsors API** (categories
resolved to their real names, main-sponsor pins, per-surface visibility
flags, banners, intro videos, booking links), merged with optional manual
rows (Lite settings editor or Sponsor posts in Full; manual entries win on
name clashes).

- **Wall** (`eex/sponsors`): layouts `grid | list | compact` (chromeless
  logo grid) `| strip` (an accessible CSS-only scrolling marquee — pauses
  on hover, static under reduced-motion); grouped under real category
  headings or flat; ordered by HeySummit weight, alphabetically or random;
  filters for main-only, sponsor category, and where HeySummit shows them
  (landing / talks / categories / blog); cap, columns, logo size,
  name/blurb toggles, per-widget exclusions; logos link to the sponsor's
  website, their event-hub page, or nowhere.
- **Spotlight** (`eex/sponsor-spotlight`): one sponsor — named, or a
  random pick from a pool filtered by category, surface and (optionally)
  only sponsors with an intro video. Styles `card | banner | full`
  (banner + privacy-friendly video embed + full description). Every part
  toggles independently — logo, name, short description, banner, video,
  full description, website / booking / phone actions — descriptions can
  be capped to a character count (`blurb_length`, `description_length`),
  and the button labels are configurable (`website_text`, `books_text`)
  beyond the call to action the sponsor set in HeySummit.

### Layouts, toggles, time handling

Session listings accept `layout="cards|list|agenda|compact"`; events and
sponsors `grid|list` (+ the sponsor extras above); speakers add
`photo_shape`. Display toggles (`show_speakers`, `show_categories`,
`show_image`, `show_venue`, `show_ics`, `show_google`…) default sensibly
and are all in the block sidebar / Elementor panel.

Server HTML never bakes in time-relative state: times render as
`<time datetime="UTC">` with event-local fallback text, and one small JS
module converts to the visitor's timezone and computes upcoming /
starting-soon / live-now / past states client-side — correct even when the
cached HTML is hours old. Without JS, visitors see event-local times and no
live-state claims.

Calendar: every upcoming session offers an `.ics` download and Google
Calendar link; Full adds the subscribable `/feeds/eex/calendar.ics` feed
(RFC 5545, filterable by event and category).

## Templates and theming

Fallback templates ship for all singles, archives and taxonomies (Full).
Override any template by copying it into `yourtheme/emailexpert-events/`;
override just a card via `templates/parts/`. All plugin CSS uses `--eex-*`
custom properties (colours, spacing, radius, columns, drawer background) —
restyle by overriding variables, not files. Elementor style controls write
the same variables, scoped to the widget.

## Structured data (Full mode)

JSON-LD on event pages (`Event`/`BusinessEvent` with venue, offers while
registrations are open, performers), session pages (`Event` + `superEvent`,
`VideoObject` for replays) and speaker pages (`Person`). Nothing is emitted
when required fields are missing — no placeholders ever. With **Yoast** or
**Rank Math** active the pieces join their schema graphs; otherwise the
plugin outputs its own JSON-LD plus basic Open Graph/Twitter tags. In Lite,
the listing components emit inline Event JSON-LD themselves.

## Elementor (optional module)

Loads only when Elementor is active; every capability also works without
it.

- **Widgets**: one per component, in the *emailexpert Events* category.
  Content controls mirror the component attributes — with events, tickets,
  sponsors and sponsor categories offered as **name pickers**. Style
  controls (colours, radius, gap, button size presets) write the `--eex-*`
  variables. Editor previews are real server renders.
- **Theme Builder (Pro)**: matching theme templates make the plugin's
  template loader yield completely — no double headers.
- **Dynamic tags and Loop Grid query IDs (Pro, Full mode)**: session
  times, live status, register/replay/event URLs, venue and speaker
  fields; `eex_upcoming_sessions` / `eex_past_sessions` /
  `eex_event_sessions` as Loop Grid queries.
- **Elementor Pro Forms**: the forms bridge registers a "HeySummit
  registration" action — see below.

## Bridges: registrations into HeySummit

All bridges share one posture, enforced in code:

- **Write allowlist.** The API client can only ever POST to
  `events/<id>/attendees/` and `events/<id>/attendees/<pk>/tickets/`
  (anchored patterns in `Api\WriteEndpoints`); anything else throws.
- **Consent is a hard rule.** No person is pushed without a satisfied,
  recorded consent source. The suppression list (opt-outs, GDPR erasures,
  manual entries; emails stored as SHA-256 hashes) is checked before every
  push **and re-checked at delivery** — an opt-out between queueing and
  delivery wins.
- Queued async pushes, 3 retries with backoff, "attendee already exists"
  treated as success, terminal failures flagged with a retry path. Email
  addresses never reach the log.

### WooCommerce → HeySummit (both modes)

Sell tickets in WooCommerce; each consented, completed purchase becomes a
HeySummit attendee with the mapped ticket price assigned in the create
call.

- Map a product or variation on its edit screen: HeySummit tab →
  connection → event → ticket (enumerated live by name). Unmapped products
  are ignored entirely.
- Checkout gains a required consent checkbox (classic shortcode checkout,
  and the block checkout via the Additional Checkout Fields API on
  WooCommerce 8.9+). **No consent, no push** — the order is flagged
  instead. On older WooCommerce with the block checkout, consent cannot be
  captured; an admin warning explains and nothing pushes.
- One async job per mapped line item on completion (optionally
  `processing`); the local push record is the lock, so re-fired order
  hooks never double-push. Multi-quantity registers the purchaser and
  flags the rest for manual registration. Failures end in an orders-list
  notice, a **Push to HeySummit** button and `wp eex woo:push`.
- Refunds: attendee removal is outside the allowlist, so a full refund
  adds a manual-removal note and fires `eex_woo_refunded`.
- Mapped products can also serve as the **buy destination for paid
  tickets** in the pricing table and ticket panel (`buy_on="woo"`).

### Forms → HeySummit (both modes)

Register form submissions as attendees. Works with **Elementor Pro Forms**
(a "HeySummit registration" action in the form widget's Actions After
Submit), **Gravity Forms**, **WPForms** and **Fluent Forms** (matched by
form ID).

- Mappings live under Settings → EEX Bridges → Forms: source plugin, form
  ID, connection, event and ticket (name pickers wherever the API
  answers), plus the field IDs holding the email, name and consent — the
  IDs as the form plugin shows them.
- Consent: default mode requires the mapped checkbox ticked
  (`no`/`off`/`0`/empty never consent); "implied" mode — submitting the
  form is itself the registration — exists for forms whose stated purpose
  is registering and must be chosen per mapping.
- Optional question answers: map form fields to HeySummit registration
  question IDs; answers travel inside the same attendee-create call.
- Submissions queue for async push (the visitor's submit never waits on
  the API), deduped by email + event + mapping. Queue entries are deleted
  on success; addresses appear masked on screen and never in the log.
  Failed pushes have retry/clear buttons on the Bridges screen.

### Account registration rules (Full mode, optional)

Registers account holders as attendees under granular rules: gain an
account, a role, a published listing — get the right event ticket. A single
enable switch; **zero module code loads while off**.

- Triggers: account confirmed (on registration, first login, or the
  canonical `eex_user_confirmed` action — with shipped adapters for
  WooCommerce, MyListing and common verification plugins), role gained,
  listing published. Conditions: role allowlist, listing types, excluded
  roles/users.
- Consent sources: a registration checkbox (or the documented
  `eex_event_consent` user meta any form builder can set), or a
  deliberately worded operator assertion that the site's terms cover event
  registration — stored with who enabled it and when.
- One shared registration ledger (user + event) is the idempotency lock
  across all paths — overlapping rules, backfills and Woo purchases can
  never double-register.
- Backfill per rule, never automatic: dry run with exact counts →
  explicit confirmation → resumable batches. The dry run and the real run
  share the identical gate chain.
- A profile field ("Do not register me for events") suppresses
  immediately. Failures flag the user with a Users-screen row action and
  `wp eex accounts:push`.
- Plain limitations, stated honestly: one-way (email changes and account
  deletions do not propagate; hooks fire for site-specific handling), no
  revocation on role loss, and "confirmed" is only as strong as its
  trigger.

### MyListing bridge (Full mode, optional)

With the MyListing theme active, projects events, sessions and/or speakers
into listings — one way; the plugin's posts stay canonical as data. Listing
types and fields are detected from the installed theme at runtime (the
bridge disables itself with a notice if detection is unconfident, with a
manual-mapping fallback). Unmapped fields are never written; choose the
canonical side per source type (rel=canonical + schema follow it).

## Attribution and privacy (Full mode)

Checkout and registration-start webhooks land in the attribution table with
UTM parameters, referrer, affiliate and ticket data; Woo, forms and account
pushes join it tagged by source, deduped against HeySummit's own webhooks
by attendee ID. Emails are stored **only** as SHA-256 hashes. **Settings →
EEX Attribution** shows totals by source and status with CSV export.

The plugin registers with the WordPress personal data exporter and eraser;
erasure also adds the address to the suppression list (HeySummit-side
removal is stated as a manual step). Attribution retention is configurable
(default 24 months); logs keep 30 days.

## Operations extras

- **UTM auto-tagging**: set a source and medium once; every outbound
  register/event link gains `utm_source/utm_medium/utm_campaign`, campaign
  from the rendering page's slug.
- **Outbound relay** (Full): forward each verified webhook action as JSON
  to any number of URLs (n8n, Make, an ESP) with per-URL secrets, retries
  and logged deliveries. Attendee emails relay as hashes only.
- **Dashboard widget**: next sessions, 7-day registrations by source, last
  sync and cache status.
- **Settings export/import**: JSON with no keys or secrets; imports show a
  diff preview.
- **Cache purge integration** (off by default): WP Rocket, LiteSpeed, W3TC
  and Cloudflare purge hooks after syncs, scoped to affected URLs.
- **Weekly digest** (off by default; Full): Monday plain-text email with
  registrations, session activity and sync health.
- **Update self-flush**: every plugin update flushes rendered fragments and
  live caches automatically, so new templates and styles show immediately.

## Reference

### Filters

| Filter | Signature | Purpose |
|---|---|---|
| `eex_query_args` | `( array $args, string $kind, array $atts )` | Adjust component queries |
| `eex_card_html` | `( string $html, string $component, array $atts )` | Filter rendered component HTML |
| `eex_schema_data` | `( array $schema, string $kind, int $post_id )` | Adjust schema pieces |
| `eex_schema_suppress` | `( bool, int $post_id )` | Suppress schema on a view |
| `eex_template_yield` | `( bool $yield )` | Make the template loader stand down |
| `eex_checkout_path` | `( string $path, array $event )` | The HeySummit checkout path appended to event URLs |
| `eex_attendee_request` | `( array $request, array $purchase )` | Correct the attendee-create shape against the live API |
| `eex_ticket_sale_request` | `( array $request, … )` | Likewise for the ticket attach |
| `eex_ticket_assignment_method` | `( string $method, string $connection_id )` | Override the discovered assignment method |
| `eex_mylisting_meta_key` | `( string $meta_key, string $field_key )` | Listing field meta key mapping |
| `eex_max_pages` | `( int $max, string $path )` | API pagination safety cap |
| `eex_sync_time_budget` | `( int $seconds )` | Inline sync run budget |
| `eex_http_retry_delay` | `( int $seconds, int $attempt )` | API retry backoff |

### Actions

| Action | Payload | Fired |
|---|---|---|
| `eex_checkout_complete` | `array $attendee, int $event_post_id` | Verified checkout webhook (emails only as hashes) |
| `eex_registration_started` | `array $attendee, int $event_post_id` | Registration started |
| `eex_registration_abandoned` | `string $email_hash, string $event_hs_id` | 60 min after a start with no checkout |
| `eex_talk_signup` | `array $attendee, string $talk_hs_id, int $event_post_id` | Talk added to a schedule |
| `eex_webhook_processed` | `array $result` | After any webhook processes |
| `eex_sync_completed` | — | After a sync run |
| `eex_woo_pushed` | `int $order_id, int $item_id, array $attendee` | Woo purchase pushed |
| `eex_woo_multi_quantity` | `int $order_id, int $item_id, int $quantity` | Multi-quantity pushed (purchaser only) |
| `eex_woo_refunded` | `int $order_id` | Pushed order fully refunded |
| `eex_forms_pushed` | `string $mapping_id, string $event_hs_id, string $attendee_hs_id` | Form submission pushed |
| `eex_account_pushed` | `int $user_id, string $event_hs_id, string $attendee_hs_id` | Account rule registered a user |
| `eex_user_confirmed` *(fire it)* | `int $user_id` | Tell the Accounts module an account is confirmed |
| `eex_role_lost_after_registration` | `int $user_id, string[] $lost_roles` | Registered user lost a role |
| `eex_listing_unpublished_after_registration` | `int $owner_id, int $listing_id, string $type` | Registered user's listing unpublished |
| `eex_user_email_changed_after_registration` | `int $user_id, string $old, string $new` | Registered user changed email |
| `eex_cache_purged` | `string[] $urls, bool $allow_full` | After purge hooks fired |

### WP-CLI

```
wp eex sync [--event=<id>] [--force]
wp eex status
wp eex orphans --list
wp eex discover
wp eex webhooks:replay <log_id>
wp eex woo:push <order_id>
wp eex accounts:push <user_id>
wp eex accounts:backfill <rule_id> [--dry-run]
```

### REST

- `POST /wp-json/eex/v1/register` — the ticket panel's free-ticket
  registration (honeypot, consent, rate limit, free-only and
  suppression-guarded; both modes).
- `GET /wp-json/eex/v1/counter/<event_hs_id>` → `{ "count": n }` (Full).
- In Full, CPTs and sync-owned meta are exposed read-only via the standard
  WP REST API for other properties to consume.

## Uninstall

Deactivating unschedules cron and clears live caches. Deleting the plugin
removes options, custom tables and scheduled events; synced content is kept
unless "On uninstall, delete all data" was enabled.

## Repository layout

```
emailexpert-events.php   bootstrap
src/                     PSR-4 (Emailexpert\Events), no runtime Composer
  Api/                   HeySummit client, write allowlist, shapes, discovery
  Data/                  Repository interface, live + synced repositories
  Mappers/               request/response mapping (the only place shapes live)
  Sync/                  engine, scheduler, upsert, media, health (Full)
  Frontend/              components, blocks glue, schema, calendar, cache
  Rest/                  public endpoints (register, counter)
  Webhooks/              receiver, parser, processor, attribution, relay
  Accounts/              registration rules module (Full, opt-in)
  Forms/                 forms bridge (mappings, queue, pusher, adapters)
  WooCommerce/           Woo bridge (loads only with WooCommerce)
  MyListing/             listings bridge (loads only with MyListing)
  Elementor/             widgets, tags, queries (loads only with Elementor)
blocks/                  block editor script (plain JS, no build step)
templates/               overridable templates and parts
assets/                  one CSS file, two small JS files
tests/                   PHPUnit suite + WordPress stub layer + fixtures
docs/                    api-notes, decisions, progress, acceptance reports
```
