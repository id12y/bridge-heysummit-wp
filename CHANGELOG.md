# Changelog

Notable changes per released version. Design reasoning lives in
[docs/decisions.md](docs/decisions.md); this file is the operator's view.

## 1.46.0
- **New toggle: "Load the image immediately".** On the featured session
  widget, for a card that leads a page. It drops the lazy hint and asks
  the browser for high priority instead, because a leading card's
  artwork is usually the Largest Contentful Paint and a lazy hint is
  what delays the number Lighthouse reports. Off by default — eager
  loading an image below the fold spends bandwidth for nothing — so
  every existing page is untouched.
- **Lighter stylesheets.** The comments added over 1.44.0–1.45.1 were
  duplicating reasoning that already lives in docs/decisions.md; they
  are now short pointers to it. The whole release since 1.43.0 now adds
  680 bytes gzipped to the delivered CSS and JS rather than 1,757.

## 1.45.1
- **The side-by-side card is exactly as it was again.** 1.45.0 changed
  its proportions and stopped it filling the column; both are reverted.
  The artwork there is a thumbnail sitting beside the detail, it fills
  its column tidily, and it crops to do so — which is the right trade
  when nobody is reading the small print in it.
- **Not cropping is now what the banner view is for.** Choose it from
  the widget's View control when the graphic itself is the content and
  every edge of it matters.
- Correcting 1.45.0's note: in a clean WordPress the previous CSS did
  not crop on its own — it produced a correct 16:9 box. The crop
  appears where the surrounding page gives the image a fixed height, as
  an Elementor stretched or equal-height column does. The old rule was
  vulnerable to that, not the cause of it.

## 1.45.0
- **Fixed: the featured session card cropped its own artwork.** A
  1920×1080 promo graphic lost roughly 4% of its height, split top and
  bottom — enough to slice the sponsor strip that runs to the bottom
  edge. The card's image rule set `height: 100%`, and once both width
  and height are set the aspect ratio is ignored, so the box took the
  grid row's height and `object-fit: cover` paid the difference out of
  the picture. The box now follows the artwork's ratio, and `contain`
  means it can never crop again.
- **New View choice: artwork full width above the detail.** The
  featured session widget's View control now offers a banner as well as
  the side-by-side card and the compact sidebar — one dropdown, so
  there is no dead control when the sidebar is chosen. A 1920×1080
  graphic renders about twice the size it did, which is what makes the
  type inside it readable.
- **The side-by-side card is now an even split.** At two parts in five
  the artwork was too small for its own contents to be read.
- Pages saved before this release keep the layout they had: `card`
  still means the side-by-side view.

## 1.44.2
- **Fixed: the whisper could render dark and the title in sans.** Two
  of the new tokens were read with a bare `var()` and no fallback. An
  undefined custom property there does not fall back — the whole
  declaration is dropped and the property inherits instead, giving a
  dark whisper and a sans-serif title. Both now carry their fallback.

## 1.44.1
- **Every value in the section heading is now overridable in one
  declaration.** Size, weight, line height, tracking and transform for
  both lines became `--eex-*` tokens, so a child theme, a block site or
  a shortcode site can retune the heading without having to outrank the
  plugin's own selectors. The Elementor Style tab already did this for
  widgets an editor opens; this gives the same reach site-wide.
- **Nothing moved.** Each token's fallback is the value that was there,
  so the heading renders exactly as it did in 1.44.0.
- This matters most for the two values that are not measurements: the
  title's fluid curve, which was reconstructed from a single viewport,
  and the space below the heading block, which was never measured.
  Both can now be corrected from a theme without a plugin change.

## 1.44.0
- **The editorial section heading now matches the site's own Featured
  News heading.** Sizing, weight, spacing and colour were read off
  emailexpert.com as computed styles and transcribed, rather than
  estimated from a screenshot: the whisper is a 0.78rem uppercase Inter
  at weight 750 in the site's brighter blue (#2864dc), the title a
  fluid Fraunces from 24px to 48px with the site's tight 1.02 line
  height and -0.035em tracking.
- **The Classic skin is untouched.** It goes on stating a section in
  its own plainer voice; only the editorial skin follows the site.
- **No webfont is loaded.** Fraunces is named, never fetched — where a
  theme already serves it the heading matches, and everywhere else the
  existing serif stack answers. There is no new network request and
  nothing new for Lighthouse to weigh.
- Every one of these values remains an Elementor control. The defaults
  moved; the ability to overrule any of them from the Style tab is
  exactly as it was.

## 1.43.0
- **A shared section heading on every widget that can carry one.** An
  optional whisper (the small uppercase label) over an optional title,
  matching the editorial pattern the site already uses elsewhere. One
  implementation registered once, not copied per widget: it arrives as
  a shortcode attribute, a block setting and an Elementor control set
  together.
- **Content controls**: show/hide switch, whisper, title, title HTML
  tag (h2/h3/h4/div — no h1, so a widget never competes with the
  page's own top-level heading) and alignment.
- **Style controls** in their own Elementor "Section heading" group:
  responsive alignment, space below the heading, maximum width,
  whisper colour and typography and spacing, title colour, typography
  and maximum width.
- **Nothing empty is ever output.** Either field alone is enough,
  neither requires the other, and with both blank — or the switch off
  — no wrapper renders at all, so there is no residual spacing.
- The switch defaults ON but both text fields default EMPTY, which is
  how existing pages stay exactly as they were: a heading with no
  content renders nothing.
- Uppercase is applied as styling, never baked into the rendered text,
  so an editor who sets Text Transform to None gets sentence case and
  a screen reader is never handed shouted text.
- The 1.38.1 `eyebrow` attribute is now the whisper. Saved values keep
  working and the element keeps its `eex-eyebrow` class alongside the
  new one, so existing custom CSS still applies.

## 1.42.0
- **Editorial is the new default skin, and Classic is a supported
  choice beside it** (Settings → Display → Skin). Exactly one is
  enqueued, under the same `eex-skin` handle, so choosing between them
  never costs a second stylesheet. Both read the same tokens, so
  Elementor style controls keep overriding either.
- **The session list is led by its date.** Day and date carry the
  weight, the clock and zone step back behind them, and the date
  column is narrower so long titles get the room.
- **A listing states its timezone once, above the rows**, instead of
  repeating it on every one — and the note is rewritten along with the
  times when the visitor's own zone is applied, so it never describes
  a zone the times are not in. Rows in genuinely different zones keep
  naming their own.
- **The format badge is an eyebrow above the title**, not a dark pill
  interrupting it: pale navy on a tint, 8.9:1 contrast.
- **One action looks like an action.** Register keeps the filled
  button; "View details" is a quiet text action with an arrow, still a
  44px touch target. Calendar links sit on their own line below.
- **Speakers appear once, in one place**, on a single metadata line
  under the title, so a session with two speakers has the same shape
  as one with a single speaker.
- Restrained hover and focus states: a pale row tint, a visible focus
  outline on whatever the keyboard landed on, no motion under
  prefers-reduced-motion.

## 1.41.0
- **The agenda item type is translated, not discarded.** A time marker
  is an in-person conference or summit; a schedule note is a meetup.
  1.39.2 dropped the field because it reads "marker" — but the fix for
  an internal word was to translate it, not to throw away the meaning
  it carried. Give each value its public wording under Settings →
  Display and the badge says what the session is. A value with no
  wording shows no badge; the raw enum never reaches a card.
- **Online is claimed on positive evidence.** A session HeySummit
  delivers itself carries a webinar delivery mode; agenda items do
  not. That presence is the signal — the value is an enum integer and
  says nothing readable. An absent in-person flag still proves
  nothing, which is the inference that once badged the in-person FORUM
  as Online. Add an `online = ...` line to reword it.
- The settings list now covers both kinds of value a session can be
  labelled by, tag IDs and agenda types, each with an example session.

## 1.40.3
- **The tag ID list is never silently absent.** It only rendered when
  it had something to show, so an empty list and a build without the
  feature looked identical on screen. The heading is always there now,
  and when there is nothing to list it says how many sessions were
  examined — which separates "nothing was fetched" from "nothing is
  tagged", and separates both from "this version does not have it".

## 1.40.2
- The tag labels field points at the list of IDs directly above it,
  rather than at Test connection — which is the route that came back
  empty on a live account and prompted the list in the first place.

## 1.40.1
- **Settings → Display now lists the tag IDs on your sessions**, each
  with an example session and whether it has been named yet, so the
  mapping field can be filled in without hunting. The Test connection
  list only covers whichever page it sampled; this reads the sessions
  the site actually displays.
- Diagnostics: `custom_tag` now reports when the sampled record's tag
  is null rather than omitting the line, since an absent tag is itself
  the finding.

## 1.40.0
- **Session tag labels (Settings → Display).** HeySummit sends a
  session's tag as a record ID and, on accounts like this one, names
  it nowhere the API exposes — so the badge had nothing true to print
  and showed nothing. The operator can now supply the wording, one
  mapping per line (`2 = Conference or Summit`). It wins over anything
  the API sends, so it also serves as a rename when the platform's
  wording is not what the site wants to say.
- **Test connection lists the tag IDs actually in use**, each with an
  example session, so it is clear which ID to name. "2" alone is not
  something anyone can act on.
- Tag resolution is tried on any reference shape, not only numeric
  ones, so a reference that is not an integer cannot be mistaken for
  the organiser's words.

## 1.39.4
- **The tag's words, resolved from the event.** A talk references its
  tag by ID and carries the wording nowhere; the event record lists
  its tags in full. Every event fetch now remembers those names, and
  a talk's tag ID is translated through them, so the badge reads
  "Conference or Summit" rather than nothing. An ID the site has
  never seen still shows nothing — the number is never a fallback.
- **The badge no longer claims "Online" for sessions nobody
  labelled.** It used to say Online whenever the in-person flag was
  unset, which stamped Online across every row of a listing,
  in-person events included: the flag lives on the talk record and an
  in-person event does not necessarily set it. An unset flag is
  silence, not a claim. Sessions with no format data now carry no
  format badge, and the in-person pill still fires on the real field.
- **Test connection reports more of what the account sends** — the
  raw `custom_tag`, `webinar_delivery_mode` and `inperson_available`
  on a talk, and the event's inline tag list with its first entry in
  full, so what the badge can say is visible rather than inferred.

## 1.39.3
- **Fixed: badges reading "2" or "1".** On this account HeySummit
  sends `custom_tag` as the tag's record ID rather than its text, so
  cards were badged with a bare number. An identifier is never a
  label: the tag is now ignored when it arrives as a number, and the
  badge falls back to the session's delivery mode, or to Online / In
  person. Tags written in words are unaffected, including ones that
  contain a digit ("Track 2"). The same guard already protected venue
  and category names; it now covers tags too, and the render path
  refuses numbers as well, so a Full-mode site shows the right badge
  before its next sync rewrites the stored value.
- **Test connection now prints the raw `custom_tag`** alongside the
  timestamp samples, so whether an account sends the tag's words or
  its ID is visible rather than inferred.

## 1.39.2
- **The badge now shows the organiser's tag, not HeySummit's internal
  type.** 1.39.0 read `agenda_item_type`, which is an internal enum:
  on the live account it reads "marker", so agenda sessions were
  badged "Marker" instead of "Conference or Summit". The label now
  comes from `custom_tag` (what the organiser typed, and what
  HeySummit's own hub displays), then the delivery mode. Internal
  enums are never used as public labels. A new
  `eex_session_format_label` filter remaps any label per site.
- **Fixed: a hidden category could delete the badge.** Badges are
  suppressed when they merely repeat a visible category term, but the
  check ran even when category badges were switched off, so a session
  whose category matched its format rendered no label at all. Only
  categories actually on screen can silence a badge now.

## 1.39.1
- **Fixed: the agenda layout labelled every session "Online".** The
  agenda row hard-coded that badge on every session, in-person ones
  included, and a test asserted it. It now shows the real format, and
  only when the widget asks. This predates 1.39.0 but is the same
  mistake the badge work exists to avoid: a claim not backed by data.
- **Fixed: the format toggle was missing or inert in three places.**
  The featured session card honoured the setting but never offered it,
  so the badge could not be switched on for the very widget most
  likely to want it; and the schedule, list and compact layouts
  offered the setting while rendering nothing for it. All four now
  offer it and render it. A test now asserts that every widget
  exposing the toggle actually draws a badge, so the setting and the
  rendering cannot drift apart again.

## 1.39.0
- **Sessions can show their format badge.** A new "Show the format
  badge" setting (default off) renders the same label HeySummit's own
  hub shows beside the time: "ONLINE" for a webinar, the agenda item's
  own type for things like "Conference or Summit". The label is the
  platform's, read from `webinar_delivery_mode` and `agenda_item_type`
  — two fields the plugin had never mapped, which is why nothing
  appeared before. Sessions the account never labelled fall back to
  In person / Online from the explicit `inperson_available` flag, and
  a session with no evidence either way gets no badge rather than a
  guessed one. The badge no longer rides on "Show category badges", so
  the two are independent, and duplicate labels are collapsed: a
  format of "In person" will not also draw the built-in pill.
- **Fixed: Full mode described sessions differently from Lite.** Lite
  has read `custom_tag` since it shipped; the Full sync mapper and
  upserter never stored it, nor the new format, so the same session
  badged differently depending on the operator's mode. Both now carry
  the same fields. Full-mode sites need one sync pass to backfill.
- **Diagnostics now show the raw timezone fields.** HeySummit sends
  `date_timezone_offset` and `date_localised` alongside the bare
  timestamps the plugin currently parses as event-local. Both are now
  displayed verbatim, with their JSON type, in the discovery panel's
  time samples. They are deliberately still unmapped: an offset
  guessed as hours when it is minutes moves every session by a working
  day, so the mapping will be written from the observed value.

## 1.38.3
- **Fixed: dead space below widgets on mobile.** v1.38.0 made every widget
  root a CSS size container. Containment also stops child margins
  collapsing out of the root, so the last child's bottom margin was
  trapped inside it and rendered as blank space under the widget,
  compounding down a page of stacked widgets (measured in an Elementor
  column at 390px: 206px of content rendering 222px tall). Container-width
  behaviour is now opt-in: add the class `eex-adaptive` to a section,
  column or widget wrapper to get it, and inside that scope the edge
  margins are neutralised so it costs no extra height. Everything else
  lays out exactly as it did in 1.37.x.
- **Listings can skip the first N.** Upcoming sessions and Upcoming events
  gain a "Skip the first N" setting, so a listing placed under a featured
  card can start at the second session instead of repeating the one above
  it. Blank/0 (the default) is the previous behaviour. It stays a number
  rather than a "hide featured" switch because the two widgets need not
  agree on what is featured, and more than one card may sit above.

## 1.38.2
- **Widgets can now sit on dark page sections.** Add the CSS class
  `eex-dark` to a section, column or widget wrapper (Elementor:
  Advanced, then CSS Classes) and every widget inside it switches to
  a dark-context palette: near-white ink, a lighter accent blue with
  dark button text, and translucent surfaces that work over any dark
  background colour rather than one assumed shade. The ticket drawer
  and the sticky register bar deliberately keep the light palette —
  they overlay the page, not the section they are coded inside.
  Pure CSS in the skin stylesheet; nothing changes anywhere until
  the class is added.

## 1.38.1
- **Every widget can carry an eyebrow label.** A new optional Eyebrow
  setting on each content widget renders the small uppercase label
  above the section ("UP NEXT", "OUR SPONSORS") that editorial layouts
  pair with a heading. Blank by default — no existing page changes.
  The next-session hero's hardcoded "Up next" kicker becomes the same
  setting: blank keeps the current text, typing rewords it, and a
  show/hide switch (on by default) can remove it — previously
  impossible without a template override. Chips, the sticky bar and
  the search box are skipped; a section label has no surface there.

## 1.38.0
- **Every widget now shares one visual language.** A new, deletable
  skin stylesheet (eex-skin.css) gives all widgets the emailexpert
  look: one token set (ink, muted, navy accent, surface, line), a
  five-step fluid type scale replacing sixteen ad-hoc font sizes,
  serif display headings, unified buttons and badges, and exactly two
  shadows. The base stylesheet was rewired so every hard-coded value
  became a token with the old literal as its fallback — dequeue the
  skin (filter `eex_skin_enabled` to false) and every widget renders
  exactly as it did in 1.37.x. Elementor style controls keep working
  and still win: they write the same tokens at page scope.
- **Widgets now adapt to their container, not the viewport.** A
  widget in a 340px sidebar on a desktop screen used to render its
  desktop three-column grid and overflow its column; grids and the
  feature card now use container queries, so layout follows the space
  the widget actually has. Roots containing the ticket drawer or the
  register bar are excluded from containment (containment would trap
  their fixed-position panels); the old viewport rules remain as a
  fallback for browsers without container query support.

## 1.37.2
- **Communication channels now follow their meaning, not just their
  shape.** The live schema's four boolean channels are not all
  marketing: offers and speaker-offers follow the optional marketing
  checkbox, while talk reminders and conference info are service
  emails about the registration itself — covered by the required
  disclosure ("will email me about this registration") and kept ON
  even when marketing is declined. v1.37.1's blanket fan-out would
  have silently switched off session reminders for every registrant
  who declined marketing — people missing sessions they signed up
  for. Unrecognised channels follow the marketing checkbox; the
  eex_communication_preferences_value filter overrides everything.

## 1.37.1
- **The consent wiring now handles the real field shape.** The live
  write:attendees schema types communication_preferences as a NESTED
  OBJECT, which v1.37.0 correctly refused to guess at. Discovery now
  captures one level of a nested object's children (names, types,
  choice vocabularies) and the diagnostics display them — and when
  every child is a boolean, the marketing checkbox fans out to all of
  them (one consent question, one answer across the platform's
  channels). Mixed-type children still stay off the wire with the
  vocabulary displayed and the filter as the mapping seam. Re-run
  Test connection once after updating; if the row shows all-boolean
  children, consent is on the wire from that moment.

## 1.37.0
- **Marketing consent reaches the attendee record — schema-safely.**
  HeySummit confirmed communication_preferences is honoured on API
  creates. The plugin sends the visitor's marketing checkbox when the
  stored discovery schema types the field as a boolean; any other
  shape stays off the wire (never a guess that could 400
  registrations) with the eex_communication_preferences_value filter
  as the mapping seam. agreed_terms is deliberately never sent —
  HeySummit stamps its own terms timestamp and ignores the field.
- **API discovery now records and displays the full write schema.**
  The diagnostics table shows each write field's type, whether it is
  required, and its choice vocabulary (previously discarded) — so
  "the field exists" becomes "here is exactly what to send". Re-run
  Test connection to refresh.
- **Paid checkout links can carry the session.** Where a widget
  presents one known session (the featured session card and the
  next-session hero), the ticket panel's paid links are now generated
  session-scoped: HeySummit preselects the session, adds it to the
  schedule after checkout — and, per their July fix, recognises
  already-registered members and takes them straight to their attendee
  area. Generated lazily and cached like coupon links.
- **New setting: Session-added email on/off.** HeySummit now ships its
  own configurable "Schedule Updated" email (unpublished template on
  existing events). Choose one sender: keep ours (with the .ics, from
  your own mail infrastructure) or publish theirs (also covers
  self-service adds in their hub) and untick ours in Settings →
  Registration & consent. The eex_session_rsvp hook fires either way.

## 1.36.2
- **Fixed (field-reported): the WordPress 6.7 "_load_textdomain_just_
  in_time called incorrectly" notice.** Options::defaults() translated
  the WooCommerce consent default while settings are read from
  plugins_loaded (the upgrade check) — before init, where WordPress
  now forbids loading translations. No translated strings live in
  defaults() or connections() any more (a source-level test keeps it
  that way); the consent wording default resolves lazily at render
  time via Options::woo_consent_text(), used by the checkout field,
  the accounts consent screen and the settings form alike.

## 1.36.1
- **Fixed (field-reported): externally ticketed sessions never offer
  the quick RSVP form.** A session carrying its own external URL (or a
  widget with the external-ticketing override) is sold elsewhere — the
  in-place form would have registered visitors onto a HeySummit free
  ticket for it. Such sessions now behave like paid tickets: the
  button follows the external link, per session, so other sessions in
  the same widget keep their forms. Full mode also gains the
  external_url mapping (synced, displayed, and honoured by both CTAs)
  that previously existed only in Lite.

## 1.36.0
- **GDPR-correct registration forms.** The single bundled checkbox
  becomes unbundled choices: a required disclosure that names the free
  account being created on the events platform, a separate optional and
  never pre-ticked marketing opt-in, and a privacy policy link (from
  the WordPress privacy page). All wording is editable under Settings →
  Registration & consent, and the EXACT wording each visitor saw is
  stored with their consent receipt (rolling log, hashed emails — no
  new PII store).
- **Confirmed opt-in.** Unknown addresses no longer reach HeySummit on
  submission: the registration is held (48 hours, name and email
  encrypted at rest, token hashed and single-use) and a confirmation
  email is sent — one click completes the registration and the session
  add, then lands back on the originating page with a banner. Logged-in
  members keep instant one-step RSVP; a "Standard" mode also trusts
  addresses a CRM reports as double-opt-in confirmed (via the
  eex_email_is_verified filter), with "Strict" and "Off" available.
  This also closes third-party registration (subscription bombing) and
  schedule tampering — nothing happens until the mailbox owner clicks.
- **Session-added email with calendar attachment.** When a session is
  added to an existing attendee's schedule (where HeySummit sends
  nothing), the plugin now emails "You're registered: {session}" with
  the .ics attached. Transactional; sent through wp_mail(), so a mail
  plugin/CRM (SES etc.) routes it automatically.
- **CRM-friendly, CRM-independent.** Three actions
  (eex_registration_pending / eex_registration_confirmed /
  eex_session_rsvp) and three filters (eex_email_is_verified /
  eex_email_is_suppressed / eex_should_send) are the whole integration
  surface — see docs/crm-integration.md. No CRM present: everything
  still works.
- All anonymous submissions now answer with one identical body
  regardless of outcome (held, instant, honeypot, cooldown) — the
  enumeration guarantee extended to the new flow. Logged-in members
  still get real statuses about themselves.
- **Registration-abuse caps.** The "known RSVP" chip's escape hatch is
  reworded to "Use a different email" and capped at two uses per
  browser; server-side (the layer that can't be cleared), one IP may
  trigger confirmation emails to at most three distinct addresses per
  hour (`eex_confirm_ip_budget` filter) on top of the existing
  per-address cooldown and per-IP request limit. Over-budget attempts
  get the same neutral answer and send nothing.
- Fixed (field-reported): the "You're going" confirmation chip broke
  list-layout action rows (block element inside the inline actions
  container pushed buttons out of the card). The chip now renders as
  its own block below the action row everywhere, and the text button
  is hardened against theme button styling.

## 1.35.0
- **Security (field-raised): registration state can no longer be probed
  by email.** The register endpoint used to answer differently for a
  new registration ("registered") and an existing one ("already") —
  a public oracle that let anyone test which email addresses are
  registered. Both cases now return byte-identical responses (this
  hole predates v1.34; it shipped with the drawer's registration).
  The visitor-facing message is accurate either way: "You're
  registered — this session is on your schedule."
- **New: logged-in visitors get zero-click detection from the server.**
  A new self-only endpoint (`my-schedule`) reports whether the
  *authenticated* user is registered and which sessions are on their
  schedule — the email always comes from their WordPress login, never
  from the request, so it cannot be pointed at anyone else. Widgets
  use it to show "You're going" with no clicks even in a fresh
  browser, and RSVP forms prefill from the account. Results are cached
  per user for ten minutes.
- Fixed (test-infra): the WordPress test stub for add_query_arg()
  encoded query values where real WordPress does not, hiding a
  double-encoding in attendee-by-email lookups from the tests and
  masking a raw slash in Google Calendar links (now canonically
  encoded).

## 1.34.0
- **New: "RSVP form" register-button behaviour, selectable per widget.**
  Every widget with a register button (upcoming sessions, featured
  talks, next-session hero, agenda, sticky register bar, and the
  featured session card) now offers a third behaviour alongside the
  link and the ticket panel: the button expands a compact name+email
  form in place. It registers the visitor on this site using the
  event's free ticket and puts the clicked session on their HeySummit
  schedule — and a returning member is recognised ("already
  registered") and simply gets the session added to their schedule.
  Nobody is sent into HeySummit's checkout wizard, which dead-ends
  members who already hold the membership ticket ("select at least 1
  membership" + "Already Purchased").
- The form needs a free ticket: if the event has none, the button
  quietly follows the ticket link instead and administrators see an
  inline note explaining why. Paid tickets always check out on the
  platform. Without JavaScript the RSVP button is a normal link to the
  ticket page.
- The featured session card also gains the ticket panel option, with
  the session context carried into the panel like the other widgets.
- Elementor: register-behaviour dropdowns now carry help text
  explaining the free-ticket requirement and fallback; definitions can
  ship help text on any select/switch control.
- Success messages are session-aware: an RSVP against a session
  confirms "this session is on your schedule", not just "registered".
- **Return visits are zero-click.** After a successful RSVP the browser
  remembers it (localStorage — nothing leaves the visitor's machine):
  next time the page loads, the RSVP button for that session is
  already a quiet "You're going — this session is on your schedule"
  confirmation, with a "Not you? RSVP someone else" escape hatch, and
  future forms prefill the name and email. Registered-state is never
  looked up server-side for anonymous visitors — that would make
  registrations probeable by email — so the memory covers RSVPs made
  through this site's own widgets, in the same browser.

## 1.33.0
- **Fixed (field-reported): every upcoming session vanished after a
  session without a date was added on HeySummit.** On oldest-first
  accounts the newest sessions live on the last pages, and the sweep
  walks backwards from the end to find them — but a session saved with
  no date sorts to the very end, made the last page date-less, and the
  walk read that one page and stopped, concluding everything further
  back was older. Date-less pages are now treated as inconclusive: the
  walk carries on past them and stops only at a page whose dated
  sessions are all in the past. The Live status harvest line showed
  the whole story (pages read, per-page date spans, HeySummit's own
  first/last session dates) — worth a look whenever a widget surprises.
- **A truncated sweep can no longer make "next session" jump to the
  far future.** A budget-capped backwards walk reads the far end of
  the collection first, so its partial result can know a 2029 session
  but not next week's. The cached last-good copy now wins whenever it
  carries a sooner upcoming session than the partial; only a complete
  sweep replaces it outright (so sessions cancelled on HeySummit still
  drop out).
- The unordered-collection fallback sweep now reads high pages first —
  both production failure shapes kept recent content near the end.

## 1.32.0
- **Fixed (field-reported): the homepage session widget could show its
  empty state right after a plugin update.** Every version change
  flushed the entire live cache, including the 24-hour last-good
  copies — so the first visitor after a deploy triggered a shallow
  budget-capped re-sweep with nothing to fall back on, and on large
  events whose upcoming sessions sit on middle pages the widget
  rendered "New sessions are announced soon" until someone opened
  wp-admin. The upgrade flush is now soft: fresh copies are
  invalidated (the new build refetches promptly) but the last-good
  tier survives, so pages keep showing the last complete data through
  a deploy. The Settings-page **Flush live cache** button still wipes
  everything, deliberately.

## 1.31.0
- **Pickers fill themselves in the editor.** Empty sponsor-category and
  ticket dropdowns now fetch their names from your configured events
  the moment the Elementor editor asks for them — no more "view the
  widget once, then reload the editor" dance. Editor-only; front-end
  page loads are untouched.
- **An empty category-filtered sponsors wall now explains itself
  visibly.** Administrators (and only administrators) see a small
  inline note naming the filter value and every category the site
  knows — in the editor preview and on the page — instead of a
  view-source HTML comment.

## 1.30.0
- **Fixed (field-reported): the sponsor category filter "did not
  actually work".** Three compounding causes: the editor dropdown had
  been polluted with raw category IDs by older builds, new category
  names could be blocked for 15 minutes by a failed categories fetch,
  and the filter itself only matched names — so a stored ID matched
  nothing and the wall showed its empty state. Now: the filter accepts
  a category name, a tier name or a category ID (so every previously
  stored value works); the dropdown memory drops numeric pollution on
  read and also learns names straight from sponsor rows; and a failed
  categories fetch is retried within two minutes instead of a quarter
  hour.
- **An empty category-filtered wall explains itself**: administrators
  see an HTML comment naming the filter value and every category name
  the site knows — on the wall and the sponsor spotlight alike.
- **Every category picker now fills in Lite mode.** Session category
  dropdowns (sessions, schedule, speakers, featured talks, replay
  gallery, session filter) previously read taxonomy terms that only
  exist in Full mode; category names are now remembered from live
  fetches — the same pattern as event and ticket names — so custom
  categories appear in every dropdown after any page using them has
  rendered once.

## 1.29.0
- **Fixed (field-reported): venue details showed nothing on some
  accounts.** HeySummit serialises the venue relation three different
  ways — a name, an object, or a bare record ID. The mappers now read
  the name (and address, when present) out of venue objects on talks
  AND events, in both modes. Accounts that only send an ID still have
  no readable venue — so the venue card now also accepts the details
  typed straight into the widget (name, street, city, postcode,
  country), which override or stand in for API data everywhere,
  including in Lite mode where the card was previously unavailable.
- **Venue card granular controls**: the name, the address, the
  Directions link and a venue image are now individually toggleable.
- **When the venue card is empty, administrators see why** — an
  HTML-comment note names the three data sources so "ticked but
  nothing displayed" diagnoses itself.
- **Updates no longer need a manual cache flush.** The display and
  live caches were already flushed automatically on every plugin
  update; now the ticket and coupon-link caches are version-keyed too,
  so a deploy starts fully fresh with no operator action. (Page caches
  at the host/CDN level remain outside the plugin.)
- **Fixed: "Replay available soon" now works in Full mode** — the
  replay_planned flag is synced (`_eex_replay_soon`), so the badge no
  longer works only in Lite.

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
