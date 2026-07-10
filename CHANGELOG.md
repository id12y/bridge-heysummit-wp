# Changelog

Notable changes per released version. Design reasoning lives in
[docs/decisions.md](docs/decisions.md); this file is the operator's view.

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
