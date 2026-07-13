# Changelog

Notable changes per released version. Design reasoning lives in
[docs/decisions.md](docs/decisions.md); this file is the operator's view.

## 1.28.0
Front-end review release: every widget was rendered with hostile realistic
data and exercised in a real browser (desktop and 375px mobile, keyboard,
success/failure/empty states). Fixes, all verified in-browser:
- **Fixed: the ticket panel could overflow narrow screens.** The plugin now
  owns its own box model instead of relying on the theme's CSS reset — on
  reset-less themes the drawer was wider than the viewport and clipped
  content off-screen at phone sizes.
- **The ticket panel names the session.** Opening it from a session's
  button shows "Registering for: <session>" — the session that joins the
  attendee's schedule is now visible, not silent. Event-level buttons
  (register bar) show no line, exactly as before.
- **The registration form explains itself.** The standalone widget now
  names the event and the free ticket it registers for (suppressed when
  you set your own heading), and on paid-only events the checkout button
  carries a sentence saying why there is no form.
- **Comfortable touch targets.** All buttons, the panel close, and form
  fields now meet the 44px touch minimum (overridable via
  --eex-cta-min-height); the free-form toggle is a proper disclosure
  (aria-expanded, hides once the form is open instead of stacking two
  primary buttons).
- **New: Currency symbol control.** Prices arrive from HeySummit as bare
  numbers ("499"); a new Currency setting on ticket-bearing widgets
  prefixes them ("€499"). Empty by default — nothing changes until set;
  "Free" never gets a symbol.

## 1.27.0
- **New: Events health page (Settings → Events health).** One button runs
  the whole integration through its paces and reports each check in plain
  sentences: configuration, PHP/WordPress versions, whether caches
  actually persist (a broken object cache silently disables every
  guarantee), version bookkeeping, the write allowlist, the registration
  endpoint, cron/webhooks (Full mode), the live display pipeline, and
  live probes of every HeySummit surface — events, tickets (with checkout
  links), coupons, and the checkout-link generator (exercised with a real
  coupon; generate-only, nothing is modified). Results are timestamped
  and kept for the next visit.
- **Site Health now covers Lite mode.** WordPress's own Site Health screen
  gains an "emailexpert Events integration" test in both modes (Lite
  previously had none), using the cheap checks only — no API calls on a
  passive page view.
- **New: `wp eex health`** runs the same full check from the command line
  and exits non-zero when anything fails, so a cron job or uptime monitor
  can alert on it.
- Hardening from the pre-release review: adding a session to a schedule
  can never turn a successful registration into an error response (IDs
  are validated numeric and the attach is fully contained); a session
  that could not be attached is now always named in the log; the
  duplicate-registration lookup uses a short timeout so a returning
  visitor is never kept waiting on a slow API.

## 1.26.0
- **Free registration now signs people up for the session they clicked,
  not just the event.** When a visitor registers through the ticket
  panel's free form after opening it from a session, that session is
  added to their HeySummit schedule (with the usual reminders) — a
  first-party version of the talk-landing-page workaround. Works for
  returning attendees too. HeySummit added the endpoints for this on
  11 Jul 2026; the plugin validates the session belongs to the event
  before calling, and a hiccup adding the session never blocks the
  registration itself.
- Note: baking the session into *paid* checkout links (so a bought
  ticket also preselects the session) is intentionally not in this
  release — doing it at page-render time would breach the plugin's
  strict per-page API-call budget. It belongs in an on-click link
  generator and will come separately; paid buttons keep the event
  checkout for now.

## 1.25.0
- **Pick a coupon from a dropdown instead of typing the code.** In the
  Elementor editor, the Coupon field on the pricing table and the
  ticket-panel session widgets is now a dropdown of the event's live
  coupons (pulled straight from HeySummit) — choose one by name and its
  code is baked into every buy button exactly as before. Shortcodes,
  blocks and manual entry keep the plain text field, and the dropdown
  falls back to it until the connection has loaded the event's coupons.
  Coupons with no code, or marked inactive, are hidden. Needs
  HeySummit's coupons API (enabled July 2026).

## 1.24.0
- **Choose how much speaker detail session cards show.** A new Speaker
  detail option on session lists, the schedule and the featured session
  card: names only (the default, unchanged), names and job titles, or
  photos, names and job titles — alongside the existing show/hide
  toggle.
- **In-person sessions can carry the venue address.** Turn on "Show the
  event venue address on in-person sessions" and any session detected
  as in-person gains the event venue's address and a Directions link on
  its card — virtual sessions stay clean.
- **Stats strip customisation.** Rename any stat with a colon
  (`speakers:Experts`), count this site's registered users with
  `members` (narrow it via the `eex_stats_members` filter), or add your
  own figure (`1200:Newsletter subscribers`).
- **Fixed (field-reported): the "In person" pill no longer doubles up**
  on accounts that also have an "In Person" category — a category
  saying the same thing (case and punctuation ignored) silences the
  built-in badge, on session cards and the featured session card alike.
- **Fixed (field-reported): numeric venue IDs no longer render.** When
  HeySummit serialises a stage/venue relation as a bare record ID, it
  is dropped from the location line instead of displayed. The same
  guard covers Lite category badges and the event venue name.
- More of the same polish: a speaker's company line yields when their
  headline already names the company; the featured card's address
  drops its first line when the venue line already names the venue;
  the register bar's countdown carries no duplicate title; speaker
  chips without a link are no longer empty-href anchors.

## 1.23.0
- **Six new widgets** (every one also a shortcode and a block, with the
  full Style tab in Elementor):
  - **Sticky register bar** — a slim Get-tickets bar that pins to the top
    or bottom of the screen once the visitor scrolls, with an optional
    countdown, a "Join now" flip while a session is live, and a
    dismissal that sticks for the browsing session. Opens the ticket
    panel or deep-links to checkout (coupons included).
  - **Registration form** — the ticket panel's free-registration form as
    a standalone widget for heroes, footers and sidebars. Paid-only
    events show a checkout button instead of a dead form.
  - **Featured session card** — one session, hand-picked or the next
    upcoming, with its physical location given equal billing: stage and
    venue line, "In person" badge, the event venue's address and a
    Directions link. A wide feature-card view and a compact sidebar
    view; all the design presets apply.
  - **Event stats strip** — "40 speakers · 30 sessions · 2 days" social
    proof from numbers the plugin already has, with an optional
    count-up animation. Zero stats stay hidden.
  - **Replay gallery** — past sessions that have replays, as cards with
    a play overlay; sessions still awaiting their replay can show a
    "Replay available soon" badge.
  - **Venue card** — the event venue's name and address (Full mode's
    venue fields) with a Directions link.
- **Schedule**: optional jump-to-day links and a your-time/event-time
  toggle (both off by default; existing schedules are unchanged).
- **Speakers**: optional social/web link chips on grid, list and
  spotlight (off by default). Session cards can now show stage/venue
  and In-person badges in Full mode too (synced from HeySummit), and
  session images work in Full mode via featured images.
- **Elementor**: every widget has its own icon, and the event picker now
  works in Lite mode (event titles are remembered from live fetches).

## 1.22.0
- **Coupon codes bake into your buy buttons.** Create a coupon in
  HeySummit (Revenue area) as usual, then set `coupon="CODE"` on any
  pricing table or ticket-panel widget — shortcode, block or Elementor.
  Every buy button in that widget becomes a discounted deep link:
  visitors land on checkout with the ticket preselected and the
  discount already applied, no code to type. Build campaign landing
  pages around it — your UTM tags still ride along, so the attribution
  report shows what each couponed page sold (sponsor and partner codes
  become measurable). If a coupon expires or the link can't be
  generated, buttons quietly fall back to the normal full-price
  checkout link — never a broken page. Generated links are reused for
  12 hours (`eex_coupon_link_ttl` filter).

## 1.21.0
- **Buy buttons now land straight on each ticket's own checkout.**
  HeySummit added a dedicated per-ticket checkout link to its API (the
  same link as the dashboard's Generate Checkout Link), and the pricing
  table and ticket drawer now use it — visitors arrive at checkout with
  the ticket already selected instead of on the pick-a-ticket page.
  Your UTM tags ride along as before. Accounts whose API does not
  return the link yet keep the previous select-tickets destination, as
  do WooCommerce-mapped tickets (buy_on) and external ticketing URLs —
  those still take precedence. Takes effect on the first ticket fetch
  after updating. New `eex_ticket_checkout_link` filter to adjust the
  link per ticket if you need to.

## 1.20.4
- **Fixed: upcoming sessions vanished overnight and came back only after
  a Flush live cache.** On an account whose talks are not ordered by date,
  the upcoming sessions can sit on a middle page of a long collection. A
  deep admin-budget sweep (40 pages) reaches them; a shallow front-end
  sweep (12 pages) stops short and finds nothing upcoming. Because both
  share one cache, the shallow "nothing upcoming" was overwriting the
  deep sweep's saved copy — so the sessions disappeared until the next
  flush restored them. A budget-truncated sweep that finds nothing
  upcoming now keeps the last complete result instead of erasing it, so
  once the sessions are found they stay put. The Live status row says
  when this is happening ("this sweep was truncated by its page budget…").
  If you want the front end itself to sweep the whole collection, raise
  `eex_live_max_pages`.

## 1.20.0 – 1.20.3
- **Style pass for every Elementor widget**: design presets (boxed /
  outlined / soft / chromeless / inverted), typography groups for
  Headings and Descriptions (the hero title, wall/category headings,
  spotlight name and sponsor names were previously uncontrollable),
  description colour, content alignment, a free sponsor-logo size
  slider, and logo hover treatments (greyscale/dimmed until hover).
  Rounded out with card shadows and hover effects (lift/shadow),
  card border width, heading spacing, session-image aspect ratios,
  sponsor-strip scroll speed, and secondary buttons joining the button
  typography.
- **Fixed: ragged sponsor wall rows.** Cards now stretch to equal
  height and every logo occupies a fixed zone, so a sponsor whose name
  wraps to two lines no longer knocks its whole row out of alignment.
- More formatting staples: heading alignment (left/centre/right,
  per device), button hover colours, button corner radius, full-width
  buttons in cards, and a logo tile background for transparent logos
  on tinted or dark pages.

## 1.19.3 – 1.19.4
- Discovery report shows raw timestamp samples with an offset verdict.
- Timezone fix hardened: Lite reads all timezone field spellings, and
  the site's timezone stands in when an event payload omits its own.

## 1.19.2
- **Fixed: session times shown one hour off in summer.** HeySummit sends
  timestamps without a UTC offset, in the event's timezone; they were
  being read as UTC. Bare timestamps are now parsed in the event's
  timezone, correcting the hero, cards, countdowns, calendar files, the
  feed and the live-now window in both modes.

## 1.19.1
- Public-repo readiness: LICENSE (GPL-2.0), SECURITY.md, CONTRIBUTING.md,
  this changelog. Series terms are no longer pre-seeded with fixed brand
  names — operators create their own (or seed via the new
  `eex_seed_series_terms` filter).

## 1.19.0
- Lite mode grows up: the past-sessions archive and replay library, the
  calendar subscribe feed (`/?eex_feed=calendar`) and the session filter
  bar all work in Lite now. Fixed the subscribe link 404ing in Lite.

## 1.18.0
- `hide_empty` on every component with an empty state (visitors see
  nothing; admins get an explanatory comment). Sponsor links can open in a
  new tab and opt into UTM tagging; the wall gains a blurb length cap, an
  optional heading and configurable heading levels.

## 1.17.0
- Sponsor spotlight fine control: logo/name/blurb toggles, description
  character caps, configurable button labels. Licence set to
  GPL-2.0-or-later.

## 1.16.0 – 1.16.1
- **Forms bridge**: Elementor Pro Forms, Gravity Forms, WPForms and Fluent
  Forms submissions become HeySummit attendees — explicit field mappings,
  consent required, suppression checked twice, queued with retries.
  Registration-question answers ride in the attendee-create call. README
  restructured by capability.

## 1.13.0 – 1.15.1
- Sessions gained imagery, venue/stage lines, status badges, brand logos
  and the external-URL rule (a talk's external URL outranks all buttons).
- Sponsor ecosystem completed: live sponsors API with real category
  headings, wall layouts (grid/list/compact/scrolling strip), ordering,
  caps, link modes with verified hub URLs, video-capable sponsor
  spotlight, and name-based pickers throughout Elementor.

## 1.10.0 – 1.12.1
- Sponsor wall reads the live sponsors API (manual rows remain as
  extras); spotlight component; wall filters and controls; Woo-mapped
  tickets can sell on-site (opt-in per widget, never the default).

## 1.9.0
- Free tickets register inside the ticket panel via the plugin's own REST
  endpoint (honeypot, consent, rate limits, free-only guarantee,
  suppression honoured). Removed all undocumented HeySummit URLs.

## 1.8.0
- Ticket panel (slide-over) register experience; sponsors API adopted.

## 1.7.x
- Update self-flush (a version change clears caches itself), Lite talk
  links, discovery staleness warnings, drawer polish.

## 1.5.0 – 1.6.x
- Attribute schema drives the block editor and Elementor controls; talk
  layouts (cards/list/agenda/compact); display pack (pricing table,
  next-session hero with 4 styles, speaker spotlight, events portfolio,
  live-now bar); serve-stale cache guardrails.

## Earlier (1.0 – 1.4)
- Core connector: read-only HeySummit client with runtime shape discovery,
  sync engine with editor-owned fields and orphan handling, display
  components as blocks + shortcodes + Elementor widgets, Schema.org
  output, webhooks with attribution and privacy tooling, WooCommerce
  bridge with the two-endpoint write allowlist, MyListing bridge, accounts
  module, setup wizard, and Lite mode (live display with near-zero
  footprint).
