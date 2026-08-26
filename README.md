# emailexpert Events — HeySummit Connector for WordPress

> **⚠️ BETA software — use with care.** Licensed under the
> [GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). As the GPL
> states (§11–12), it is provided **as is, without warranty of any kind and
> without any promises or protections of liability** — no fitness for a
> particular purpose, no guarantee against data loss. This plugin writes
> attendee data to a live event platform: test against a sandbox event
> first, keep backups, and read what each bridge does before enabling it.
> This is BETA software under active development — expect breaking changes
> between versions. See [SECURITY.md](SECURITY.md) for how to report
> vulnerabilities.

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

Lite also has the **past-sessions archive and replay library** (the same
bounded harvest that feeds upcoming sessions serves the other side of the
clock — newest first, replay CTAs, pagination and search), the **session
filter bar**, and the **calendar subscribe feed** (at
`/?eex_feed=calendar`, since Lite adds no rewrite rules).

What only Full adds (each settings location says so): local event / session
/ speaker **pages and archives — and the SEO/GEO indexable content they
carry** — past events, webhooks + attribution + registration counter, the
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
render a configurable empty state rather than a blank void — or, per
widget, nothing at all (`hide_empty`, useful for sidebars and strips;
administrators still get an HTML comment explaining why, and the cache
guardrails behave exactly as with the visible empty state).

`event` accepts a HeySummit event ID, WP post ID or slug; with exactly one
configured event it can be omitted everywhere. In Elementor, events,
tickets, sponsors and sponsor categories are picked by **name** from
dropdowns — IDs are never typed by hand once the API has answered.

| Block | Shortcode | What it does |
|---|---|---|
| `eex/next-session` | `[eex_next_session]` | Hero for the single soonest session in 4 styles (`layout="panel\|banner\|spotlight\|minimal"`), with countdown, speakers, and the full two-button register experience below |
| `eex/upcoming-sessions` | `[eex_upcoming_sessions]` | Soonest first; layouts `cards\|list\|agenda\|compact`, column control, session images, venue/stage, status badges (In person / Open access / Replay soon), brand logos, calendar links, subscribe link |
| `eex/past-sessions` | `[eex_past_sessions]` | Newest first, paginated, searchable; replay CTA when a replay exists. In Lite it surfaces whatever the bounded live harvest fetched — the recent archive |
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
| `eex/session-filter` | `[eex_session_filter]` | Category/speaker/text filter bar for the sessions library; works without JS as links + GET form (in Lite the category links filter the list on the current page via `?eex_cat=`) |
| `eex/homepage-hero` | `[eex_homepage_hero]` | **Homepage Editorial Hero** — the whole top of an editorial homepage in one component: featured story, featured event or session, More Events, Latest News. See "The compositions" below |
| `eex/event-landing` | `[eex_event_landing]` | **Event Landing Page** — a complete event site in one component, transforming automatically after the event. See "The compositions" below |

### The compositions (v1.50.0)

Two opinionated page-level components, both first-class in Lite and Full,
both rendering through the shared server-side pipeline (the Gutenberg
block, the shortcode and the Elementor widget are editing surfaces only).
Layout presets change classes and `--eex-*` tokens, never the renderer.

#### Homepage Editorial Hero

The default follows the approved editorial design: a dominant featured
story (serif headline, standfirst, category/date/read time, capped image),
a prominent but compact featured event or session beside it (portraits,
real-text speaker names, lifecycle-aware CTAs, calendar action), compact
More Events rows, and a Latest News strip that stays inside the first
desktop viewport at 1440 × 900. Presets: `layout="editorial-split"`
(default) `| compact-split | news-led | event-led`.

**Featured event or session** (`featured_source`):

- `auto` (default) — the next eligible event, automatically rolling
  forward when it ends. Strategy `selection_strategy="chronological"`
  (default) or `"priority"` (Flagship, then Featured, then dates —
  promotion windows respected).
- `manual_event` / `manual_session` — pick by name in the editors. A
  featured session always resolves its owning event for deduplication,
  registration, tickets, schema and More Events.
- `none` — no featured-event area.

Selected events present as `event_presentation="auto"` (the next session
when one exists — the stronger card) `| event | next_session | session`.
Manual picks pin `pin_duration="until_end"` (default) `| until_date |
never`; after expiry `pin_expiry_action="auto"` (return to automatic,
default) `| keep | hide`. A missing manual selection follows the same
fallback and explains itself to editors.

**More Events** (`more_events`, default on, three rows, soonest first):
`more_events_mode="all_upcoming" | after_featured | same_series |
upcoming_sessions`. The event modes list distinct events, and the
featured event is always excluded by canonical identity (connection +
HeySummit event ID) *before* the limit, so the list refills — and a
manually featured future event never removes the wrong chronological
event. `upcoming_sessions` lists the next sessions across the displayed
events instead (the featured session excluded) — the mode for a calendar
that is one long-running event holding many sessions, where the event
modes have nothing left to list. Placement:
`more_events_placement="strip"` (default) `| event-column | hidden`.
Presentation (`more_events_presentation="events"` default `| speakers |
people | auto`): speakers adds one or two portraits and names per row
(`more_events_speakers` 1–3), people leads with the person (portrait,
name, session title · date, under "Coming up"), auto uses speakers where
good portraits exist — all from the session data the page already loads,
falling back to event-only rows where none exists. Arrangement
(`more_events_layout="auto" | vertical | horizontal | grid`) recomposes
any placement, and `more_events_whisper="none" | format | location` adds
a tiny-caps line per row (Online / In person, or city and country).

**Featured story** (`story_source="latest" | sticky | manual | none`,
manual picks are searchable by title in the editors with an ID fallback
in the shortcode): eyebrow, image position and fit, standfirst length,
category/date/read-time toggles, CTA text, and a fallback
(`story_fallback="latest" | none`) when a manual story is unavailable.
`story_count="2"` adds a secondary feature (`story2_source="auto" |
manual`, `story2_id`) that can never duplicate the lead, placed by
`story2_placement="auto"` (beneath the primary story) `| beneath | side |
hidden`; both features are excluded from Latest News before its limit.
**Latest News** (`news_show`, `news_count` 2–20, post types, include and
exclude categories, `news_order="latest" | balanced`): the featured
stories are always removed before the limit and the list refills;
balanced mode is deterministic — a different category per initial slot
where alternatives exist, chronological fill, no randomness.
`news_layout="auto"` is the **Front Page** (recommended): deterministic
editorial roles from the selection order — one lead (large image,
optional standfirst via `news_lead_standfirst`), up to three secondary
rail stories, a standard row from nine stories, and every later story as
a typography-led headline under a configurable label
(`news_dense_heading`, default "Latest"). Briefs build no image markup,
so 20 stories request at most eight images (`news_media_emphasis="auto"
| restrained | strong`). `newsroom` is denser, with a Latest side rail
on wide screens. `media` (equal illustrated cards — best at 4–8) and
`list` (the newswire) keep their flat treatment, where desktop columns
follow the item count (2→2, 3→3, 4→4, 6→3×2, 8→4×2, 12→4×3) and images
(`news_image_position="auto" | none | beside | above`,
`news_image_size="compact" | medium | large`) render at content width in
a stable ratio (no layout shift). Everything lazy-loads, a story without
an image aligns cleanly beside its neighbours, and switching layouts
never changes which stories are selected.

```text
[eex_homepage_hero]
[eex_homepage_hero featured_source="manual_event" featured_event="123456" more_events_mode="all_upcoming"]
[eex_homepage_hero featured_source="manual_session" featured_session="654321" pin_duration="until_date" pin_until="2026-11-17T10:30:00Z"]
```

`heading_context="embedded"` (default, lead heading H2) or `"page"` (the
featured story is the page's one H1). `mobile_order="story-first"`
(default) or `"event-first"` — the DOM order follows, so reading order and
visual order always agree. `eager_media="auto"` marks at most one leading
image `fetchpriority="high"`; everything else lazy-loads.

#### Event Landing Page

```text
[eex_event_landing]
[eex_event_landing event_source="manual" event="123456"]
```

`event_source="current"` (default: the event page being viewed, or a
session's owning event, falling back to the next eligible event) `| auto |
manual`. Sections render in one canonical order attribute —
`sections="hero,status,stats,intro,sessions,speakers,schedule,tickets,venue,sponsors,replays,more_events,final_cta"`
— sanitised server-side (unknown removed, duplicates collapsed, order
preserved; a live postponed/cancelled notice is re-appended if omitted).
Elementor edits the order as a drag-orderable repeater; Gutenberg as an
accessible checkbox-and-arrows control. Every section hides itself when it
has no usable data. The hero offers event-level, next-session or specific
session presentation, three styles (`hero_style="editorial" | dark |
compact`), media fallbacks, countdown, registration state and the full CTA
stack. In Full mode the introduction stacks the presentation intro, the
sync-owned description and the editor-owned `post_content` — nothing
overwrites anything.

**Post-event transformation** (`post_event="auto"`, default): once the
event ends, replays lead, tickets and the forward-looking programme drop
out, speakers and More Events remain, and the final CTA becomes "Watch
replays" — never a dead registration page. `"keep"` preserves the
original layout. While live, the programme leads.

**Full-mode single event pages**: Settings > Display > **Single event page
layout** switches the plugin's fallback `single-eex_event.php` to the
landing composition (the existing template stays the default; theme
overrides in `yourtheme/emailexpert-events/` and Elementor Pro Theme
Builder templates take precedence exactly as before).

#### Per-event presentation settings

**Homepage and landing-page presentation** — a meta box on event posts in
Full mode, per configured event under Settings > Live display in Lite,
one shared accessor either way: eligibility for the automatic feature and
for More Events, promotion level (Normal / Featured / Flagship / Do not
promote) with an optional window, a public status override (Automatic /
Scheduled / Postponed / Cancelled) with a message, and optional intro,
hero-media and CTA overrides. A cancelled event shows its state and no
registration language; postponed suppresses the countdown and misleading
registration wording. Saving flushes the display cache.

#### Time, caching and preview

Lifecycle (scheduled, registration open/not yet open, starting soon, live,
ended, replay available, postponed, cancelled, evergreen) resolves through
one injectable clock; a multi-day event stays current until its final end.
Cached composition fragments expire at the earliest relevant boundary —
the featured event ending, a pin or promotion window turning, an event
going live — never outliving their own truth (one-minute floor; normal
display-cache lifetime otherwise). Editors get **Preview as at**: render
the composition as though it were any moment, with an explanation of every
selection decision; it never touches public output or the public cache.

Theme overrides work as everywhere else: copy
`templates/parts/hero-story.php`, `hero-featured-event.php`,
`hero-news-item.php`, `compact-event-row.php`, `landing-hero.php`,
`landing-status.php` or `landing-cta.php` into
`yourtheme/emailexpert-events/parts/`. Composition tokens
(`--eex-page-max`, `--eex-composition-gap`, `--eex-composition-space`,
`--eex-hero-story-width`, `--eex-hero-event-width`,
`--eex-story-media-ratio`, `--eex-story-media-max`,
`--eex-event-media-ratio`, `--eex-compact-row-gap`, `--eex-news-columns`)
are overridable globally by themes and locally by the Elementor style
controls.

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

The two compositions (Homepage Editorial Hero, Event Landing Page) share
this exact system — the same form part, consent wording, REST endpoint,
panel and checkout routing — and add one mode of their own:
`register_action="auto"` (their default) asks it what to render: the
in-place RSVP form when the existing free-ticket rules apply, else the
ticket panel. Explicit `link | panel | form` keep their classic meaning
everywhere.

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
  name/blurb toggles with a `blurb_length` character cap, per-widget
  exclusions; logos link to the sponsor's website, their event-hub page,
  or nowhere. An optional `heading` renders above the wall (every layout)
  one level above the category headings, whose tag is configurable
  (`heading_level` h2–h4). `new_tab` opens sponsor links in a new tab;
  `utm_links` opts the sponsor's own website links into the site's UTM
  tagging (hub links are always tagged, never double-tagged).
- **Spotlight** (`eex/sponsor-spotlight`): one sponsor — named, or a
  random pick from a pool filtered by category, surface and (optionally)
  only sponsors with an intro video. Styles `card | banner | full`
  (banner + privacy-friendly video embed + full description). Every part
  toggles independently — logo, name, short description, banner, video,
  full description, website / booking / phone actions — descriptions can
  be capped to a character count (`blurb_length`, `description_length`),
  and the button labels are configurable (`website_text`, `books_text`)
  beyond the call to action the sponsor set in HeySummit. `new_tab` and
  `utm_links` work here exactly as on the wall.

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
Calendar link. The subscribable feed (RFC 5545, filterable by event and
category) lives at `/feeds/eex/calendar.ics` in Full and
`/?eex_feed=calendar` in Lite (no rewrite rules); the listings' subscribe
link always points at the right one.

## Templates and theming

Fallback templates ship for all singles, archives and taxonomies (Full).
Override any template by copying it into `yourtheme/emailexpert-events/`;
override just a card via `templates/parts/`. All plugin CSS uses `--eex-*`
custom properties (colours, spacing, radius, columns, drawer background) —
restyle by overriding variables, not files. Elementor style controls write
the same variables, scoped to the widget. Note: theme-overridden parts
predate newer template arguments (e.g. the sponsor parts' `new_tab` and
`blurb_length`) and silently ignore them until re-copied from the plugin.

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
| `eex_feature_candidates` | `( array $pool )` | The automatic event candidate pool for the compositions |
| `eex_feature_target` | `( ?array $target, array $atts )` | The final selected feature target |
| `eex_more_events` | `( array $rows, string $mode, ?array $featured )` | The final More Events rows (post-deduplication) |
| `eex_editorial_query_args` | `( array $args )` | The editorial (featured story / Latest News) query arguments |
| `eex_editorial_post_types` | `( string[] $types )` | Post types whose publish transitions flush the display cache |
| `eex_lifecycle_result` | `( array $reading )` | The final lifecycle reading for a composition target |
| `eex_cta_view_model` | `( array $model, array $target, array $atts )` | The final CTA view model |
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
