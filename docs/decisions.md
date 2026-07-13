# Decisions

Deviation log: where the specification was ambiguous or the environment
forced a choice, the decision and its reasoning are recorded here, most
recent last.

## D1. Plugin lives in `emailexpert-events/`, not the repo root

The spec says "repo root is the plugin", but the target repository
(`id12y/emailexpert-news-scout`) already contains an unrelated Python
project. Deleting or displacing it would be destructive, so the plugin is
self-contained in `emailexpert-events/` and that directory follows all the
repo-root conventions (bootstrap file, `src/`, `docs/`, …). The CI workflow
lives at the repo-level `.github/workflows/plugin-ci.yml` with
`working-directory: emailexpert-events`. To deploy, copy or symlink the
`emailexpert-events/` directory into `wp-content/plugins/`.

## D2. Unit tests run on a lightweight WordPress stub layer

The spec asks for PHPUnit via wp-env. wp-env needs Docker-in-Docker plus
network image pulls that are not reliable in this build environment, so the
suite is written as pure unit tests against `tests/wp-stubs.php` (an
in-memory implementation of the narrow WordPress surface the plugin uses,
every stub `function_exists`-guarded so real WordPress wins when present).
This keeps the suite fast, deterministic and runnable in CI without Docker.
`.wp-env.json` is still shipped and working for local integration testing.

## D3. Blocks ship as plain-JS dynamic blocks, no build step

All blocks are dynamic (server-rendered) and their editor scripts are
plain JavaScript using the `wp.*` globals (`registerBlockType` +
`ServerSideRender`), so the repository needs no Node build to produce a
working plugin. `@wordpress/scripts` remains the documented path if JSX is
ever wanted. This satisfies "no block stores data in post content" and keeps
the shipped artefact dependency-free.

## D4. Talk→event filter parameter is negotiated at runtime

The correct query parameter for "talks belonging to event X" is unknown
(docs unreadable). `SyncEngine` tries `?event=<id>`; if results include
talks whose own `event` field points elsewhere, it falls back to
`?event_id=<id>`, and finally to client-side filtering of the full
collection. The discovery diagnostic records what the talks resource really
looks like so the operator can confirm.

## D5. Speaker photo field name is a candidate list

`avatar` (string or `{url: …}`) then `photo_url` then `photo` then
`avatar_url`. First match wins; recorded in `_eex_photo_source_url` so a
changed URL triggers a re-download.

## D6. Sync writes descriptions to meta, never post_content

Spec 4.4 offers this as the "simplest compliant approach" and it is taken:
synced descriptions land in `_eex_description`, templates render from meta,
and `post_content` belongs entirely to editors. The
`<!-- eex:synced-content -->` marker is therefore unnecessary, but sync also
never touches post_content, so any editor content is safe by construction.

## D7. Webhook dedupe bucket is 15 minutes

Spec 8.1: dedupe on a hash of (action, attendee identifier, timestamp
bucket). HeySummit retries failed deliveries every 15 minutes up to 3
times, so the bucket is 15 minutes and the dedupe transient lives 2 hours —
long enough to cover the full retry window, short enough that a genuine
re-registration days later still counts.

## D8. "Evergreen" detection

An event is treated as evergreen when `is_evergreen` is truthy, or when the
API omits both `first_talk_at` and `last_talk_at`. Evergreen events never
appear in past-events lists and count as upcoming while
`is_open_for_registrations` is true.

## D9. Attribution `amount_gross` stored as string

Ticket price shape is unverified (currency, cents vs decimal). The column
is a varchar holding whatever the attendee record exposes, avoiding a wrong
numeric normalisation; the report sums only when values parse as numeric.

## D10. Elementor widgets registered from one definition table

Rather than 10 hand-written widget classes, a single `Widget` base class is
parameterised by the same component definition table that drives blocks and
shortcodes (`Frontend\Components::definitions()`). Guarantees the
"identical output" acceptance criterion by construction and keeps the
module a thin control-mapping layer, per spec 7.7.

## D11. PHPCS ruleset exclusions

WordPress-Extra with three exclusions: short array syntax allowed, PSR-4
file naming (src/ClassName.php) instead of class-*.php, and short ternaries
allowed. All are style-only; security sniffs all active.

## D12. `wp-env` phpVersion pinned to 8.1

Matches the minimum supported PHP so local integration testing catches
8.1-incompatible syntax; CI runs the unit suite on 8.1 and 8.3.

## D13. Replay URL: two keys, manual wins

Spec 4.3 names one key `_eex_replay_url` sourced from the API but editor-
owned with manual precedence. Two writes into one key cannot express "manual
always wins", so the synced value lands in `_eex_replay_url_synced` and the
meta box writes `_eex_replay_url`; every render path (cards, singles,
VideoObject schema, dynamic tags) resolves manual ?: synced.

## D14. BusinessEvent when a venue exists

Spec 7.3 says "use BusinessEvent for FORUM-style conferences" without
defining FORUM-style machine-readably. Chosen signal: an event with manual
venue meta filled in (an in-person conference) emits `BusinessEvent`;
venue-less (online/evergreen hub) events emit `Event`. Deterministic,
editor-controllable, and matches the examples given.

## D15. Webhook verification failure: attribution logged, counter not

Spec 8.1 requires state-mutating actions to verify by re-fetching the
attendee and to use fetched data. When verification fails (API unreachable
or no key configured), the registration counter — public-facing state — is
NOT incremented, and a warning is logged. The attribution row IS still
inserted from the payload (email already hashed): attribution is an internal
report where a flagged-in-log unverified row is more useful than a silently
dropped signal. A later verified retry of the same delivery is deduplicated,
so no double rows.

## D16. Webhook processing queue

Receipt returns 200 immediately and processing runs via
wp_schedule_single_event + spawn_cron (a queued single event per spec 8.2).
Dedupe happens at receipt time so HeySummit's 15-minute retries do not queue
duplicate jobs even if processing is slow.

# v2 extension run

## D17. Lazy initialisation model

Activation now does only: CPT/taxonomy registration, term seeding, rewrite
flush, the single autoloaded `eex_settings` option, and a dismissible
wizard-offer option. The log table is created on first log write and the
attribution table when webhooks are enabled (belt-and-braces guard at
insert), both guarded by a stored schema version (`eex_schema_versions`)
plus a per-request static — creation is never attempted per request. The
sync cron exists only while at least one event is enabled; the daily
maintenance cron is scheduled with the first table. The webhook secret is
generated the first time the Webhooks settings section renders rather than
at activation. `eex_settings` is the one autoloaded option; everything
bulky (discovery snapshots, availability caches, schema versions) stays
non-autoloaded. Term seeding moved from per-request init to activation.
Upgrades from v1 are safe: dbDelta reconciles existing tables on the first
guarded write and the schema option starts tracking from there.

## D18. Untimed talks count as upcoming in the time scope

The time scope splits on `starts_at` versus now. Talks the API returns with
no start time cannot be "past", so they ride with the future bucket:
"future: none" drops them, past modes never touch them. Consistent with the
front end, where untimed talks appear in neither upcoming nor past lists.

## D19. Wizard step 2 session counts via DRF pagination

Per-event session counts come from `talks/?event=<id>&page_size=1`, reading
the DRF `count` field — one cheap GET per event at wizard time instead of
paging whole collections. When the field is absent the count is simply
omitted from the listing (never guessed).

## D20. Wizard state is the standard settings

Step 1 writes the connection, step 2 enables/disables events in
`eex_synced_events`, steps 3–4 merge scope into the same rows, and
confirmation kicks the ordinary async sync and cron scheduling. Abandoning
the wizard mid-way leaves a valid partial configuration and no orphaned
state; the only wizard-specific options are the dismissible notice flag and
a started-at timestamp used by the progress poll.

## D21. MyListing detection strategy and disable-on-doubt

Listing types are enumerated as `case27-listing-type` posts, fields first
through the theme's own `\MyListing\Src\Listing_Type` API (guarded
try/catch), then from the stored listing-type configuration JSON under
candidate meta keys. Results are cached non-autoloaded, salted with the
theme version, and logged flagged `discovery`. If neither path yields
usable fields, `confident` is false and the bridge disables itself with an
admin notice — it never guesses. The two pieces of near-stable MyListing
convention the projector does rely on — listing values under
underscore-prefixed meta keys and the `_case27_listing_type` type link —
come out of the detection result and are filterable
(`eex_mylisting_meta_key`), not hardcoded at call sites.

## D22. MyListing module gating

Themes load after plugins, so presence is checked on `after_setup_theme`
with an inline class/constant check in Plugin (plus an
`eex_mylisting_present` filter for tests); nothing in `src/MyListing/`
loads when the theme is absent. Detection unconfidence then gates the rest.

## D23. Write posture: no client retries, job-level retry with a local lock

`HeySummitClient::post()` never retries at transport level: a duplicated
attendee-create is worse than a failed one, and the API's idempotency is
unverified. The push job owns retrying (3 attempts, 5/15/45-minute
backoff), and the order-item push record is written *before* the first
attempt so re-fired WooCommerce order hooks can never enqueue a second
chain for the same item.

## D24. Refunds: manual removal path only

The spec asks to implement attendee removal on refund if discovery shows
the API supports it, but the amended hard rule allowlists only attendee
create and external ticket sale import — removal would require a third
write endpoint. The hard rule wins: full refunds of pushed orders produce
an order note, an admin notice and `eex_woo_refunded` for downstream
automation; removal stays manual in HeySummit. If the allowlist is ever
consciously extended, `WriteEndpoints::ALLOWLIST` is the single place.

## D25. Woo attribution rows and webhook dedupe

A pushed sale inserts a completed attribution row tagged with the order ID
(schema v2 `order_id` column) and the HeySummit attendee ID from the
create response. `Attribution::insert()` now dedupes completed rows on
attendee ID + event, so the checkout-complete webhook HeySummit emits for
the imported sale never double-counts, whichever side lands first.

## D26. UTM tagging semantics

Tagging is active only when enabled AND a source is configured ("on by
default once configured"). Existing utm_* parameters on a URL are never
overwritten. Campaign comes from the per-page `_eex_utm_campaign` meta,
falling back to the rendering page's slug; calendar entries use the fixed
campaign `calendar` (tagged from the raw event URL so the page context
never leaks into feeds). Because rendered HTML now varies by rendering
page, the component cache key includes the campaign context. Schema
`offers` URLs stay untagged: structured data should carry the canonical
registration URL.

## D27. Relay privacy and layout

The relay forwards the plugin's mapped attendee, which never contains a raw
email (hash only) — the raw key is additionally stripped defensively before
dispatch. Shared secrets ride in an `X-Eex-Secret` header, are never
logged, and never leave the site in settings exports.

## D28. Export/import boundaries

Exports carry eex_settings (whitelisted keys only on import),
eex_synced_events, the MyListing bridge config, connections as id+label,
and relay URLs without secrets. Import preserves every local key and
secret, merging by connection ID / relay URL, and re-evaluates the sync
cron state after applying. Woo product mappings are post meta and
deliberately do not travel (product IDs differ between sites).

# v3 extension run

## D29. The shared registration ledger generalises the v2 push record

The canonical "is this person registered for this event" store is now user
meta `_eex_hs_registrations` (Registrations class), written by the accounts
engine (pending-first: the record is the lock, exactly the v2 pattern) and
by the Woo pusher on success when the billing email matches a WP account.
The v2 order-item record remains the per-item job lock. Cross-path dedupe
additionally consults the attribution table (Attribution::has_completed),
which covers guest purchases and webhook-recorded registrations from before
the module existed; a match is copied into the ledger so later checks are
cheap.

## D30. Suppression stores hashes, not addresses

Suppression entries hold the SHA-256 of the lowercased email (the same
construction as the attribution table) plus the domain for admin
recognisability. Matching happens at push time when the raw address is in
hand from the user account. Unticking the profile opt-out deliberately does
NOT clear the suppression entry: re-enabling automatic registration is a
conscious operator action on the suppression list.

## D31. Ticket assignment is resolved from discovery, defaulting to import

The attendee-create body's ticket support is unverified. The resolver reads
the OPTIONS-based write-shape snapshots: a ticket field on write:attendees
→ ticket in the create body; otherwise a usable
write:external-ticket-sales → zero-amount ticket import (the documented
off-platform flow, already allowlisted — also the default when no discovery
data exists yet); both present but unusable → register without a ticket,
warn naming the intended ticket in the log (flagged discovery) and surface
it in the diagnostics panel. Already-existing attendees never get a ticket
import: their ticket state is unknown and a duplicate import is worse than
none. Overridable via the eex_ticket_assignment_method filter.

## D32. Manual pushes waive trigger matching, never gates

The users-screen row action and `wp eex accounts:push` evaluate every
enabled rule with trigger matching waived (the operator is the trigger),
but conditions, exclusions, suppression, consent and idempotency all still
apply — consent remains a hard rule even for manual pushes. Failed records
are retried on the same action.

## D33. v4: components consume data arrays through a Repository interface

Render callbacks, blocks, shortcodes and Elementor widget wrappers were
already one code path; v4 moves their data access behind
`Data\Repository` (upcoming/past sessions, events, event summary, talk,
speakers, categories, sponsors) so Lite mode can swap the source without
forking any rendering. `SyncedRepository` reassembles the exact reads the
templates previously made inline, and the card templates now take data
arrays (with a back-compat branch for theme overrides passing post IDs) —
proven by the pre-existing rendering tests passing unchanged. Talk data
gained hs_id, event_hs_id, raw_event_url, ics_ref and published keys so
the ICS builder is a pure data function shared by both modes.

## D34. v4: a fresh activation is Full-shaped until wizard step 0 answers

Activation cannot know the mode the operator will choose, so a fresh
install takes the existing Full-shaped activation (terms seeded, rewrites
flushed, wizard offered) and wizard step 0 undoes it when Lite is chosen:
seeded terms deleted (only when no content exists), stray options removed,
rewrites re-flushed. Once the stored mode IS lite, activation touches
exactly one option — the settings option (version tracking moves inside
it) — and registers nothing. This keeps one activation code path while
meeting the Lite footprint rule; a switch to an existing Full site with
synced content is refused at step 0 and routed through the settings
confirmation screen, because keep-or-trash must be an explicit decision.

## D35. v4: the frozen archive keeps only the reading surface

Full → Lite with "keep content" registers post types, taxonomies, meta,
the template loader and single-page schema so kept posts stay readable and
indexable — but sync, webhooks, feeds and every other Full service stays
off, and the display components render live data regardless. Trashing uses
wp_trash_post (reversible), not deletion. Lite-visible settings save only
Lite-relevant keys so Full-mode toggles survive a round trip.

## D36. v4: live fetching is budgeted, locked and never trusted to be up

LiveRepository fetches at render time through the existing client with a
3-second timeout and zero transport retries. Every resource sits behind
LiveCache: a fresh transient (lite_ttl minutes, default 15) plus a 24-hour
last-good copy; failure order is fresh → last-good → the component's empty
state — never a fatal, never a hung page. Cold fetches are capped at 2 per
request (`eex_live_budget` filter) and guarded by an add_option-based
stampede lock (an INSERT, so exactly one concurrent visitor fetches; stale
locks are stolen after 30 seconds). Flushing bumps a generation counter —
O(1), orphans expire by TTL. Collections use single-page GETs (roughly the
first 50 records): display components are limited anyway, and unbounded
pagination in a render path would defeat the budget. In Lite the logger
writes to a 20-entry transient ring buffer instead of creating the log
table; a table left over from a Full period keeps working.

## D37. v4: Lite hides Full-only surface, and blocks carry their own schema

Full-only components (past sessions, past events, registration counter,
session filter bar) are absent in Lite, not greyed out: they are excluded
from block/shortcode/widget registration via
`Components::available_definitions()` and render nothing if invoked
directly. Elementor keeps its plain widgets in Lite; dynamic tags, Loop
Grid queries and Theme Builder need local content and stay Full-only. In
Lite the session and event components append one inline JSON-LD
`<script>` per render (Google Event shape: name + startDate required,
VirtualLocation for the online URL) — structured data survives without
local pages. Sponsors move into the settings option (max 60 lean rows,
external logo URLs, no media library); the per-session .ics endpoint
resolves HeySummit talk IDs through the live repository under the same
cache and budget as any render.

## D38. Production review fixes: cache keys, URL schemes, abuse guards, block checkout

Four findings from the pre-launch review, each closed structurally:
(1) the past-sessions page number became a component attribute (via the
existing from_get mechanism) so the fragment cache keys on it;
(2) `BaseMapper::url_of()` now enforces an http/https allowlist +
esc_url_raw for every URL that arrives from the API — the single point
all mappers and the live repository flow through — and eex-time.js
re-checks the scheme before assigning `data-eex-join` to an href;
(3) visitor-controlled values no longer mint cache entries: searches
render uncached, the calendar feed caches only known-entity parameters,
and the Lite .ics endpoint resolves talk IDs via the new
`Repository::known_talk()` (cached collections only, never a per-ID
fetch) behind a 60/min per-IP rate limit (`RateLimiter`);
(4) the Woo consent checkbox reaches the block checkout through the
Additional Checkout Fields API (WC 8.9+), writing the same `_eex_consent`
meta via `woocommerce_set_additional_field_value` so the pusher has one
path; the block field is optional and shown on every checkout (the
Blocks API cannot condition on cart contents), and older WooCommerce
with a block checkout gets an admin warning naming the consequence.

## D39. Errors carry enough context to debug from the message alone

Every client error now names the method and endpoint, the HTTP status,
the API's own reason (DRF detail/message/first field error, tag-stripped
and truncated to 200 characters) and a sync-log row reference — and the
same structured facts ride in the WP_Error data (status, endpoint,
detail, log_id) so callers never re-parse messages. Because push
failures, sync health notices, wizard/AJAX results and order notes all
display the client's message, the enrichment reaches every operator
surface automatically. In Lite, LiveCache records the reason and
resource key of the last failed fetch: the dashboard widget shows it
(with the ring-buffer tail), and degraded pages append an HTML comment
for administrators only — added outside the cached fragment so it can
never leak to visitors — turning "the block looks empty" into a
self-explaining report. Raw API bodies still never reach non-admin
output, and the logger's email redaction applies unchanged.

## D40. The repository is the plugin (supersedes D1's subdirectory layout)

D1 placed the plugin in an `emailexpert-events/` subdirectory because the
repository then hosted an unrelated Python project. That project has been
removed at the owner's request, and the plugin now lives at the repository
root — installable directly as `wp-content/plugins/emailexpert-events/`.
CI dropped its working-directory indirection and path filters; the wizard's
documentation links point at the root README. Historical decisions and
progress entries that mention the subdirectory are records of their time
and stand unedited. The repository was subsequently renamed to
`id12y/bridge-heysummit-wp`; the wizard links follow it (old GitHub URLs
redirect, but only until the old name is ever reused).

## D41. MyListing detection failure is a guided path, not a dead end

The bridge was already opt-in per source (projection checkboxes default
off), but the UI never said so — it now does, with a status pill naming
how many listing types were detected. When automatic detection cannot
read the theme's structure, the bridges page no longer just announces
the bridge is disabled: it offers a one-click detection retry and a
manual mapping form (listing post type, listing-type meta key, type
lines "slug | Label", optional field lines "key | Label"). A stored
manual mapping is treated as a confident detection result tagged
source=manual — the operator knows their site — and always wins over
the automatic cache until explicitly discarded. Manual and automatic
results flow through the identical projection code; nothing is forked.
The admin stylesheet was rebuilt at the same time (white sheet per
screen, separated sections, card rows, status pills, a numbered wizard
stepper) and now loads on the bridges page, which previously enqueued
nothing.

## D42. Sweep for first-use traps: dead buttons, stale wizard state, cron leftovers

A contract audit (JS selectors ↔ markup, AJAX actions ↔ handlers, form
actions ↔ admin_post registrations, settings reads ↔ defaults, scheduled
hooks ↔ cleared hooks) found three real faults, all fixed:
(1) the product editor's "Load tickets" buttons were dead — eex-admin.js
and its nonce were only enqueued on the plugin's own screens; a shared
`Admin\AdminAssets::enqueue()` now serves every screen with eex buttons,
including product edit;
(2) a successful wizard connection test didn't reveal the server-rendered
discovery summary and Continue button — the wizard now reloads on
success, which also refreshes the form's stale hidden connection ID;
(3) queued jobs were never fully cleared: wp_clear_scheduled_hook()
without args skips arg-carrying events (nearly all of ours), and four job
hooks were missing from the lists entirely. `Install\Cron` now holds the
single inventory of every scheduled hook; deactivation, uninstall and the
Lite switch clear through wp_unschedule_hook(), and the Lite switch
deliberately keeps Woo push jobs (the bridge runs in both modes).

## D43. Empty Lite components diagnose themselves

The reported field failure: Lite's upcoming-sessions block rendered the
polite empty state with no way to tell why. Three causes closed:
(1) switching Full → Lite never populated the display list — it now
seeds `lite_events` from the events that were enabled for sync;
(2) two live-API assumptions made robust: a configured event missing
from the first collection page gets a targeted cached `events/<id>/`
fetch, and the talks filter tries `?event=` then falls back to
`?event_id=` (the sync engine's order), detecting unfiltered responses;
(3) `LiveRepository::diagnose()` walks the pipeline (no events chosen →
no keyed connection → event unfetchable → no sessions → none upcoming)
and reports the first gap — shown on the dashboard widget and appended
to empty components as the admin-only, uncached HTML comment. Visitors
always keep the plain empty state.


## D44. Routes are negotiated and remembered per connection

Live verification (docs/api-notes.md) found an account where every
top-level collection route except `events/` answers 403, with the data
served nested under the event instead, and the open-registrations flag
spelt `_is_open_for_registrations`. Rather than hardcoding the newly
observed variant (other accounts may serve the original shape), the
talk/ticket fetchers try all known route styles and `Api\PathStyles`
remembers the working one per connection, so steady-state traffic never
burns calls on refused routes. `boolish()` accepts candidate key lists
for the underscore variant, and the discovery panel samples the nested
route before reporting a top-level 403 as an error.


## D45. The write allowlist follows the real API: create + idempotent attach

The published OpenAPI spec shows the v2 API has no external-ticket-sales
endpoint and no top-level attendees route: attendee create is
POST events/<id>/attendees/ (with optional ticket_price_id in the body)
and ticket assignment for an existing attendee is the
documented-idempotent POST events/<id>/attendees/<pk>/tickets/. The
allowlist now holds exactly those two anchored patterns — the same two
sanctioned operations as the v2 hard rule (attendee create + ticket
assignment), at their real addresses — and rejects everything else the
spec exposes, including event/talk/speaker/category create-update-delete
and webhook-subscription management. Consequences: one POST per push on
the happy path; "already exists" recovers by finding the attendee via the
documented ?email= filter and attaching the ticket idempotently
(supersedes D31's no-assignment caution, which applied to the sale
import); order amounts cannot be recorded on HeySummit and stay on the
WooCommerce order; the mapped value is a ticket PRICE id and the UI says
so. Talks use `date` with no end time; inactive talks/speakers are
respected; webhook actions are inferred from documented payload shapes
when no action key travels.

## D46. The live diagnosis lives where events are configured

The pipeline diagnosis (`LiveRepository::diagnose()`) was only visible on
the dashboard widget, which nobody looks at while configuring the Live
display section. The settings section now leads with a Live status row:
a Working pill with the upcoming-session count when the pipeline is
healthy, or the diagnosis with the last fetch timestamps, the last error
and the plugin version when it is not. The "none upcoming" verdict now
distinguishes sessions with no date on HeySummit from sessions that are
all in the past (naming the most recent date), because the operator's
fix is different in each case. The version constant is surfaced so an
operator can confirm which build a site is actually running.

## D47. Live talk fetches walk the whole paginated collection

Found on the production account: the talks collection paginates 10 per
page, oldest first, with no date or upcoming filter (the v1 reference's
example shows `count: 62` with a `next` link). The Lite render path read
page 1 only, so a summit running since 2020 reported "10 sessions, most
recent 2020" while its 2026 sessions sat on the last pages. The live
fetch now uses `get_all()` (which sync always used), passing its short
timeouts through and capping at 20 pages via `eex_live_max_pages`. The
walk lives inside the single cached fetch, so the cost is once per cache
lifetime. A page cap that truncates would drop the newest talks (they
are at the end), which is why the cap is generous and filterable rather
than tight.

## D48. Talk harvesting jumps to the end, it does not walk forward

D47's full forward walk was still wrong for the production account: 500+
past talks means 50+ pages, so any forward page walk either burns dozens
of render-path requests or gets capped before it ever reaches the newest
talks — which sit at the END of the oldest-first collection. The live
harvest now reads page 1, computes the last page from the response's
`count`, jumps straight to it and walks backwards until a page contains
nothing upcoming; a symmetric forward walk from page 1 covers
newest-first accounts, and the two merge with de-duplication by talk id.
Cost is a handful of requests (page 1, the last page, one boundary page)
regardless of history depth, capped by `eex_live_max_pages` (default 12
fetches). The sync-side `get_all()` cap rose from 50 to 300 pages —
runaway protection only, since Full mode genuinely wants the whole
history and 50 pages silently truncated exactly the newest talks.

## D49. Admin views warm the live cache with patient requests

Production showed the talks endpoint on a 500+-talk account sometimes
taking longer than the 3-second render timeout (cURL error 28, 0 bytes),
and after a cache flush there is no last-good copy to hide behind, so
the pipeline reported empty. Front-end fetches stay impatient (5 seconds,
no retries — a slow API must never hang a visitor's page); admin fetches
(the dashboard widget and the settings Live status row) wait 15 seconds
and retry once, because an admin looking at the status page is the
natural moment to warm the cache for everyone else. Both timeouts are
filterable via eex_live_timeout. diagnose() now distinguishes "the
sessions request failed" (surfacing the transport error verbatim) from
"HeySummit returned no sessions" — a timeout is not an empty summit and
the operator fix differs.

## D50. The harvest shows its working

The production site kept reporting exactly one page of sessions with no
recorded failure, which two code-correct-looking builds could not
explain from the outside. Two silent failure modes were possible: a
deep-page request timing out inside the harvest (per-page errors used to
end the walk without a trace), or a response without the next link the
walk keyed on. Both are now closed: page failures are recorded and
skipped (deep offsets are the slowest queries on big accounts, and one
timeout must not hide every upcoming session), the jump trusts the
reported count even when the next link is absent, a wall-clock deadline
(front end 8s, admin 25s, eex_live_deadline) bounds the whole harvest,
and every harvest records count/pages/read/failed per event
(eex_harvest_* transients, 24h). Session-related diagnoses append that
record — "Event X: HeySummit reports N session(s) across P page(s);
pages read: …; pages that failed: …" — so the Live status row states
what actually happened on the wire instead of leaving the operator (and
us) guessing. Version 1.2.0 so builds are tellable apart.

## D51. "No upcoming sessions" names the event it checked and its siblings

The full v2 reference confirmed each Event carries its own
first_talk_at/last_talk_at, is_evergreen, is_live and
_is_open_for_registrations. That makes the commonest cause of a
persistent "none upcoming" — the configured event is an old summit and
the upcoming sessions belong to a different event on the same account —
provable from data already in the cache. Session-related diagnoses now
append HeySummit's own record for each configured event ("sessions from
2020-05-01 to 2020-12-10") and list unconfigured sibling events with
their last-session dates, pointing at Choose events. No extra requests:
it reads the cached page-1 events collection. Also from the reference:
Ticket.prices is a string (JSON) — matching the existing json_decode
expansion; talks/attendees/categories/speakers gained PATCH/DELETE
routes we deliberately do not use (the write allowlist stays create +
ticket attach); outbound webhook payloads are the four documented shapes
the parser already infers.

## D52. The harvest speaks the route's own paging dialect

v1.2.0's harvest record from production was conclusive: event 181590
reports 273 sessions across 28 pages, pages read "1, 28" — yet only 10
sessions surfaced, because "page 28" was the first page echoed back.
The talks route ignores ?page= entirely (the spec documents a page
parameter only for events and webhooks); its own next link carries the
real parameters (limit/offset). The harvest now parses the next link's
query string and pages the way the route itself does — limit/offset
when the link says so, ?page= otherwise — and if a route echoes the
first page back regardless, that is recorded as a failed page
("the route ignored the paging parameters and returned the first page
again"), the walk stops immediately, and the Live status row says it in
plain words. Version 1.3.0.

## D53. Flush live cache resets the harvest record too

The harvest record transients were keyed outside the cache generation,
so Flush live cache cleared the data but left yesterday's diagnostics —
after an update, the status row could keep showing the pre-update
harvest and read as "nothing changed". Harvest records now carry the
generation in their key, exactly like every other live transient, so a
flush restarts the diagnostics with the pipeline. Version 1.3.1.

## D54. The status row shows the wire, not our interpretation of it

v1.3.1 on production: a fresh harvest still counted 10 sessions while
the API reports 273, page 28 read successfully with no echo and no
failure — so the deep rows exist, differ from page 1, and either carry
dates we misread or fall to the display filters (inactive /
other-event). Rather than infer a fourth time, the harvest record now
carries the raw evidence: the paging dialect actually used, each read
page's date span as returned, rows fetched vs rows excluded (with the
exclusion reasons counted), and a sample session from the deepest page
as literal field=value pairs. The status row prints all of it, so the
next screenshot is the API's own words. Version 1.4.0.

## D55. When the ends are old but the count says more, sweep the middle

The talks list documents no ordering and no query parameters, so its
order cannot be assumed chronological. If it is not, upcoming sessions
sit on MIDDLE pages and the end-jump legitimately finds old sessions at
both ends and stops — matching production exactly (10 old sessions from
pages 1 and 28 of 273). The harvest now falls back to a full sweep of
the remaining pages whenever the ends contain nothing upcoming yet the
reported count exceeds the rows read — skipped when the route echoes
pages back. Admin views carry a 40-page budget (front end stays at 12)
so one look at the Live status row reads a whole 28-page collection,
caches it, and the front end serves from the cache.

## D56. Layouts are attributes; styling is CSS; the schema drives all three surfaces

The v5 display work splits cleanly along the cache boundary. Anything
that changes markup — the layout variants (cards/list/agenda/compact
for session listings, grid/list elsewhere), the photo shape, the
show_speakers/show_categories/show_ics/show_google toggles, speaker
pagination — is a component attribute: it joins the fragment cache key
automatically and travels identically through blocks, shortcodes and
Elementor. Anything that only restyles — colours, typography, spacing,
responsive columns — is CSS (custom properties or Elementor-scoped
rules) and deliberately not cache-keyed. The attribute schema gained
options/flag/label keys so one definition lights up a select control
in Elementor, a SelectControl in the block editor, an enum on the
block attribute, and a sanitise_atts whitelist. Text/title/heading/
link colour controls write direct color properties instead of custom
properties: an always-on rule consuming an unset variable computes as
inherit and would silently override theme colours on untouched pages.
Speakers paginate on their own query var (eex_speaker_page) so a page
can host both paginated components. The agenda layout reuses the
schedule's day-grouping via a shared helper; the schedule's output is
unchanged and test-guarded. The Elementor widget remains a thin,
untested mapping layer — every branch it maps to lives in Components,
where the test suite covers it.


## D57. Random display order lives inside the cached fragment

The speakers component gains order = name | name-desc | random. Random
shuffles the full (unpaged) speaker set at render time and slices to the
limit; because the shuffled HTML is fragment-cached, the selection stays
stable for one cache lifetime and reshuffles on refresh — the requested
behaviour with zero extra queries and no session state. Pagination is
deliberately disabled under random order (a random sample has no stable
pages). The fragment cache lifetime itself became a setting (cache_ttl,
1-1440 minutes, default unchanged at 5) shown in both modes, since it now
has a user-visible effect beyond staleness; sync/webhook/editorial
flushes still apply immediately. A "View all speakers" link (all_url /
all_text attributes) renders after the grid when configured.


## D58. The display pack reads API surface we already trusted

Five components close the gap between what the API offers and what the
site can show: next-session hero, ticket pricing table, speaker
spotlight, events portfolio and a live-now bar. Tickets are commerce
data and are never synced content — Data\Tickets is one cached fetch
(15-minute transient, nested-route fallback) shared by the WooCommerce
picker, the pricing component and, next, the forms bridge. The
portfolio sorts in PHP because a meta_key-ordered query silently drops
events with no first-session date. The live-now bar renders hidden and
is revealed only by the session-state JS (cached HTML never claims live
state); in Lite, "current" sessions are those started within six hours,
since Lite keeps no past data. Speaker hub links are best-effort
(the read API exposes no slug, so the hub's name-based slug is
reconstructed) and optional per widget: this site's pages, the hub, or
no link.


## D59. Sponsors stay manual, but stop being mysterious

"The sponsor wall finds nothing" has one root cause: HeySummit's v2 API
exposes no sponsors endpoint at all, so there is nothing to import from
the account — the wall renders whatever the operator enters. Three
changes make that workable: the empty wall now explains itself to
administrators (the admin-only note names the exact settings location
and the API constraint); the Lite sponsors editor gains a CSV bulk
import (Name, URL, Logo URL or media ID, Tier, Tier order, Blurb —
quoted commas honoured, parsing unit-tested); and the logo field
accepts a media-library attachment ID as well as a URL (numeric value →
logo_id, rendered via wp_get_attachment_image like Full-mode sponsor
posts). If HeySummit ever adds a sponsors read endpoint, Data-layer
import slots in behind the same rows.


## D60. Updates flush their own leftovers (Install\Upgrade)

Plugin files are replaced without activation ever re-running (zip
overwrite, git deploy, auto-update), yet the fragment cache kept
serving markup from the previous build for up to the display TTL, and
assets versioned with an unchanged EEX_VERSION kept old CSS/JS pinned
in browser caches. Root cause of a week of "I still see the old
layout" reports: three CSS/JS-changing releases shipped under the same
1.6.0 version string. Two fixes: every release now bumps EEX_VERSION
(assets cache-bust themselves), and Install\Upgrade::check() runs on
every load — the version stored in the autoloaded settings option costs
no extra query; a mismatch flushes the display cache and the live cache
once and stores the new version.

## D61. Empty states are never cached for the full display TTL

A cold or failed fetch renders the (correct) empty state — but caching
it for the operator-set display TTL (up to 24 hours) pins "New sessions
are announced soon." next to widgets that fetched fine a second later.
Frontend\Cache::set() caps the TTL at one minute whenever the fragment
contains the eex-empty marker: real emptiness re-renders identically a
minute later for pennies, transient failure self-heals.

## D62. Register buttons land on ticketing, not the lobby (register_link)

The API exposes only the event's public page URL; Register buttons sent
people to that landing page, one more click away from tickets. Sessions,
featured talks, the hero and the pricing table now share a
register_link attribute: 'checkout' (default) rewrites the event URL to
its HeySummit checkout page, preserving UTM query args; 'event' keeps
the old landing-page behaviour; 'custom' takes the operator's own URL
(register_url) for events sold through an external ticketing provider.
Pricing buttons append ?ticket=<id> in checkout mode — HeySummit
preselects the ticket when it recognises the parameter and ignores it
otherwise, so the deep link degrades safely.

## D63. The ticket drawer is server-rendered (register_action=panel)

"Ticketing as a slider/popup" could not become a client-side API call
(hard rule: the key never leaves the server). Instead the component
renderer builds the whole drawer — tickets, prices, per-ticket checkout
links — as hidden HTML inside the same cached fragment, and Register
buttons carry data-eex-drawer pointing at it. eex-time.js supplies
dialog behaviour (focus in, Tab trapped, Escape/backdrop close, focus
returned, background scroll locked, reduced-motion honoured); without
JS the buttons stay ordinary links to the same destination. Opt-in per
widget on sessions, featured talks and the hero.

## D64. Hero styles are one enum attribute, not four components

The next-session hero gained a layout attribute (panel | banner |
spotlight | minimal) because the four looks differ structurally (where
the countdown and actions live), which makes them markup — so they must
key the fragment cache and travel through the one schema, giving every
surface (shortcode, block, Elementor) the same dropdown for free.
Panel is the new default: countdown pill and actions grouped in a
tinted right-hand column, replacing the floating lone button.

## D65. A failed refetch serves the last good fragment (serve-stale)

The reported sequence: a widget renders fine, its fragment expires, the
refetch hits the API during a cold or throttled moment, and visitors see
"no upcoming sessions" where there were sessions a minute ago. When the
data source is fallible (Lite mode, and ticket fetches in either mode),
Cache::keep() stores every non-empty fragment a second time under a
six-hour, non-generation-scoped key; a fresh render that comes back
empty serves that copy instead. Full-mode local queries cannot fail, so
their empties remain authoritative and are never masked. Trade-off: in
Lite a genuinely emptied schedule can linger up to six hours — the time
module already marks aged sessions as past client-side, and the
admin-only debug note keeps reporting the true fetch state underneath.

## D66. Session buttons register for the session (register_link=talk)

Field correction to D62: sending every per-session Register button to the
event-wide checkout "makes no sense" — each talk has its own landing
page on HeySummit, which is where registering for a specific session
starts. Talk components (sessions, featured talks, the hero) therefore
default to 'talk' (the session's landing page, falling back to the
event URL when a talk has none); 'checkout', 'event' and 'custom'
remain selectable. The pricing table keeps 'checkout' as its default —
tickets are event-level commerce and a per-session destination is
meaningless there, so its dropdown simply omits 'talk'. Inside the
ticket drawer, per-ticket buttons always deep-link the checkout
regardless of what the opening button does (custom external URLs
excepted).

## D67. Two buttons, not one button with four destinations

Second field correction (supersedes D62/D66's enum): a session has two
meaningful actions, and they are different buttons. The tickets button
goes to that EVENT's ticketing — HeySummit hosts one checkout per event,
or the operator's external ticketing URL (register_url) replaces it.
The session button goes to the talk's own landing page (event page
fallback). A 'buttons' attribute shows both (default on sessions,
featured talks and the hero), or either alone. The live "Join now" swap
targets the session button (the tickets button keeps it only when it is
the sole button). Labels default to "Get tickets" / "View session",
both operator-editable. The pricing table keeps checkout + external
override; components without the attribute (past sessions, schedule
rows) default to the session button alone.

## D68. The sponsors endpoint exists now — reads wired, writes declined

HeySummit shipped a sponsors API (reads, inserts, updates, deletes)
long after the rest, which is why D59 made the wall manual-only. Reads
are now first-class: Data\Sponsors mirrors the tickets fetcher
(15-minute cache, top-level route with the nested events/<id>/sponsors/
fallback, PathStyles memory) and maps defensively across the API's
likely field spellings (name/title/company_name, url/website/link,
logo/logo_url/image, tier as string or object, order variants) since
the endpoint went live after this code was written — discovery samples
it and will report the real shape on the next Test connection. Both
repositories put API sponsors on the wall first; the operator's manual
rows (and CSV import) remain as supplements, de-duplicated by name so
a hand-tuned entry never doubles an API one. Inserts/updates/deletes
are deliberately NOT wired: the write allowlist stays attendee-only
until a real workflow needs sponsor writes, per the standing rule that
every write endpoint must be explicitly allowlisted and justified.

## D69. The drawer registers people itself; invented URLs are dead

Live verification killed the URL synthesis: /checkout/ paths and
?ticket=/?talk= preselect parameters all produced error pages on the
hub. Rule reaffirmed the hard way — never invent URLs the platform has
not documented. Tickets buttons now land on the event page (the one URL
the API guarantees), with the external-ticketing override unchanged.

The journey the operator actually wanted — ticketing inside the
slide-over — runs through the plugin's own write path instead: free
tickets render a name/email/consent form in the drawer, POSTed to
/eex/v1/register, which calls the allowlisted events/<id>/attendees/
create with the ticket's own price ID. Guard rails in order: honeypot
(silent fake success), consent required, email validation, five
attempts per IP per ten minutes, the event must be one this site is
configured for (the client never picks a connection), the ticket must
exist on that event and be genuinely free (a claimed price ID is only
accepted if it belongs to the ticket — a paid price cannot be smuggled
onto a free registration), and suppression is honoured indistinguishably
from success. Paid tickets link out — payment can only happen on the
platform (the WooCommerce bridge remains the paid-in-WordPress path).
The drawer components gained the pricing table's tickets/exclude
filters (with the same name dropdowns in Elementor) so the operator
chooses exactly what the hero offers.

## D70. Sponsors mapped to the live schema; the wall filters on it

The operator supplied the real sponsor schema from discovery (v1.9.0
report). Corrections and gains over the defensive guesses: the display
name is `title`; the blurb is `short_description` (falling back to
`long_description`); the tier-style grouping is `sponsor_categories`
(strings or objects, first one becomes the wall's tier heading);
`is_main_sponsor` pins a sponsor to the top (tier "Main sponsor" when
uncategorised); the per-surface `show_on_*` flags are respected. The
sponsors component gained the requested filters — main_only, shown_on
(landing/talks/categories/blog) and sponsor_category — with manual rows
passing visibility filters (typed in on purpose) but never counting as
main. Discovery's expected shape now matches the live schema so the
"unmapped" column stops shouting. Fields noted but not yet surfaced:
promo_banner, page_header_graphic, intro_video_*, booth_*, books_url,
phone_number — a sponsor-spotlight component could use them next.

## D71. IDs never face the operator (event + sponsor-category pickers)

Raw HeySummit ID boxes are hostile to site managers. In Elementor, the
`event` attribute on every component is now a dropdown of the site's
actual events ("Title (ID)", from the same cached account listing both
repositories already expose), `sponsor_category` is a dropdown of names
seen on any sponsor fetch (remembered like ticket titles), and the
ticket pickers already show names. Every picker degrades to a guided
text field before its first fetch.

## D72. Paid tickets sell on this site when a Woo mapping exists

Live click-test verdict: HeySummit's select-tickets page ignores the
?ticket= parameter entirely — selection is a CSRF-protected cart POST
their own JavaScript makes, which no external link can perform. The
parameter stays on our links (harmless, echo-confirmed, lights up if
HeySummit ever honours it), but the real never-leaves-the-site path for
paid tickets is the WooCommerce bridge that has existed since v2: when
a ticket price is mapped to a Woo product (_eex_hs_ticket meta), the
pricing table and drawer buy buttons CAN link to that local product
page instead of HeySummit — purchase on this site, pushed to HeySummit
by the existing bridge. Operator correction (v1.10.3): this is opt-in
per widget via the buy_on attribute — HeySummit checkout stays the
default even when a mapping exists. Unmapped tickets always keep the
select-tickets link. Free tickets already register inside the drawer.

## D73. Sponsor categories resolve to names; the wall becomes configurable

The live wall exposed the last schema surprise: sponsor rows carry BARE
CATEGORY IDS in sponsor_categories, so headings rendered as "6708". A
sponsor-categories endpoint resolves them: Sponsors::categories_map()
(same route dialect, cached, cached-empty when missing) feeds heading
names, the category filter, and the editor dropdown; an unresolvable
numeric ID is dropped rather than displayed — a number must never be a
heading — with the sponsor falling back to the Partner group. The wall
also gained the operator controls it lacked: group_by (category
headings | one flat wall), show_names, show_blurb (off by default —
walls are logos first), and logo_size (small/medium/large via the
--eex-sponsor-logo variable). Cards centre their logo with the name
beneath; logo-only walls keep the link and an aria-label on the logo.

## D74. Wall ordering, cap and columns; the weight-sort bug

The sponsors component gained order (weight | alphabetical | reverse |
random — cache-stable like random speakers), a total cap (limit,
applied after ordering so random+limit is a random sample), and a
columns control. Building "grouped by the weight we provided" exposed
a latent bug in my own grouping: tier keys were string-sorted, so a
weight of 100 would have sorted before 99 — keys are now zero-padded.
Ordering applies within each category group when grouped, or to the
whole wall when flat.

## D75. Sponsor spotlight uses the fields the wall has no room for

The discovery report showed the sponsor serializer's depth going unused
(promo_banner, page_header_graphic, intro video fields, long_description,
link_title, books_url, phone_number, booth flags). A sponsor-spotlight
component now uses them: one sponsor — picked by name from a dropdown,
or a cache-stable random rotation — in three styles (card, banner with
the logo card overlapping the promo image, full with video and long
description). Videos embed privacy-friendly (youtube-nocookie / Vimeo
DNT-style player / Wistia; unknown providers render nothing) with
autoplay muted per browser policy; the long description passes through
wp_kses_post; the website button uses the sponsor's own link_title;
booking link and phone are opt-in toggles. Booth links are NOT
reconstructed — no hub URL convention is documented for them (the
never-invent-URLs rule).

## D76. The talks serializer grew — banked wins

The same report showed talks now carrying slug, url, external_url,
broadcast_duration_mins, description_short/long, images, stage/venue
and cancellation flags. Banked immediately: real end times from
broadcast_duration_mins (live-state accuracy no longer leans on the
one-hour default) and richer descriptions; the existing url/slug
candidates now pick up the real values, retiring title-derived talk
links wherever the API provides them. Still unused and noted for next:
primary_image/custom_promo_image_primary (session card imagery),
stage/inperson_venue (venue display), talk_cancelled/is_open_access
badges, and event feature_image/company_name on the portfolio.

## D77. The talks serializer's depth reaches the cards (v1.13.0)

Banked from the expanded serializer: session images
(custom_promo_image_primary/primary_image) behind a show_image toggle
(off by default — layouts must not jump on update); venue lines built
from stage + inperson_venue + area behind show_venue (on); status
badges riding the existing badge row (In person, Open access, and the
organiser's custom_tag); brand logos replacing speaker chips when
show_brand_logo_instead_of_speakers says so; cancelled sessions
excluded from every listing unconditionally. Operator rule for
external_url: an externally hosted session replaces BOTH buttons'
destinations (session page and tickets) and its title link — the
session lives elsewhere, so everything points there, ahead of even the
per-widget external-ticketing override.

## D78. Sponsors get canvases, not just a wall (v1.14.0)

Sponsors fund the operation; one grid did not do them justice. The wall
gained two more layouts for different canvases: 'strip' — a flat
scrolling logo marquee (CSS-only animation, track doubled with the
duplicate copy aria-hidden, pauses on hover, collapses to a static
wrapped row under prefers-reduced-motion; edge-masked) for sidebars,
footers and full-width bands — and 'compact', a dense chromeless logo
grid for big canvases. Both walls and the spotlight gained
sponsor_link: 'website' (default), 'hub' — the sponsor's page on the
event hub, built from the REAL slug the API now provides (nothing
reconstructed; one live click-test wanted) with website fallback for
slugless sponsors — or 'none'. Combined with the earlier controls
(category filter, main-only, shown-on, order, cap, columns, logo size,
grouping, spotlight styles) a sponsor can now headline a homepage,
scroll in a footer, sit quietly in a rail, or take a full feature
section — each widget independently configured.

## D79. Video spotlights draw only from sponsors with video (v1.15.0)

require_video on the spotlight filters the pool to sponsors whose intro
video actually embeds (known provider + ID) before the pick — a random
video spotlight can never land on a videoless sponsor, and naming a
videoless sponsor while requiring video yields the empty state rather
than a hole. The wall gained exclude (hide individual sponsors without
touching HeySummit), with an Elementor SELECT2 of sponsor names; the
generic ticket-picker branch is now gated to ticket-bearing components
so the two 'exclude' fields cannot collide. Operator verified the
sponsor hub URL convention live — the api-notes caveat is closed.

## D80. Forms bridge: capture-gate-queue-push, consent twice, emails transient (v1.16.0)

Form plugins (Elementor Pro Forms via a proper form action, Gravity
Forms, WPForms and Fluent Forms via their submission hooks) can now
register submitters as HeySummit attendees — through the exact posture
the Woo and accounts bridges established, and with NO write-allowlist
change: attendee-create is the only endpoint used, and question answers
ride inside that same call (the builder gained an additive `questions`
key; malformed or empty answers are dropped, never sent).

Decisions that matter:

- **Explicit field-ID mapping, not guessing.** The operator maps which
  field is the email, the name, the consent checkbox and any question
  answers, using the IDs the form plugin itself shows (Elementor custom
  IDs, GF dotted numbers, WPForms numbers, Fluent input names).
  Heuristics on field labels would break silently in other languages.
- **Consent is checked twice and configured deliberately.** Default
  mode requires a mapped checkbox to be ticked ('no'/'off'/'0' do not
  consent); 'implied' mode exists only for forms whose stated purpose
  is registering, and the operator must choose it per mapping.
  Suppression is checked at capture AND re-checked at delivery — an
  opt-out between the two wins and deletes the queued entry.
- **The queue is a buffer, not a ledger.** Entries are keyed by
  sha256(email|event|mapping) so double submissions and re-fired plugin
  hooks dedupe to one push; the entry (and the address in it) is
  deleted the moment the push succeeds. A 500-entry cap refuses (and
  loudly logs) rather than growing unbounded. Failed entries keep the
  address so the Bridges-screen retry works; clearing failed entries
  discards it. Addresses never reach the log and appear masked
  (l…@example.org) in the queue table.
- **"Already exists" is success, no ticket attach.** Unlike the Woo
  bridge (a paid sale must land its ticket), a form lead who is already
  an attendee needs nothing further — attaching would risk overwriting
  a paid ticket with a lead-magnet one.
- **Both modes, like Woo.** eex_forms_push joined Cron::JOBS but not
  FULL_ONLY; pushing a lead needs no synced content. The Elementor Pro
  action class is only autoloaded behind a class_exists guard on its
  parent, so sites without Elementor Pro never load it (BootSmokeTest
  exempts it the same way as the Elementor module).

## D81. README restructured by capability; licence stated as proprietary BETA (v1.16.1)

The README no longer reads as a changelog ("Version 2 additions",
"Version 3", "v4") — history lives in this file; the README is organised
by what the plugin does. Two deliberate framing changes:

- **Lite mode is presented as a first-class way to run the plugin**, not
  an appendix. The old intro declared indexable GEO/SEO content "the
  plugin's primary purpose", which is untrue in Lite — where the full
  display and registration experience (all components, the ticket panel,
  in-page free registration, the Woo and forms bridges) runs straight off
  the API with near-zero WordPress footprint. The modes section now leads,
  and the capability split is stated per mode instead of burying Lite's
  strengths under Full-mode assumptions.
- **Licensing corrected to reality.** The plugin header and composer.json
  claimed GPL-2.0-or-later boilerplate; the software is in fact
  distributed only privately/directly as a BETA. All three places
  (README banner, plugin header, composer.json) now state: proprietary,
  all rights reserved, no warranty, no promises or protections of
  liability, private BETA — be cautious. If it is ever publicly
  distributed through WordPress.org channels this must be revisited
  (that channel requires GPL compatibility).

## D82. GPL after all, with the warning in plain words; spotlight fine control (v1.17.0)

Licensing reversed from D81's all-rights-reserved position on the
operator's decision: the plugin is **GPL-2.0-or-later** (header,
composer.json and README agree again), with the README carrying a plain
BETA warning — as-is, no warranty, no liability promises, GPL §11–12 —
plus links back to emailexpert.com and agency.cm. Distribution remains
private/direct to beta partners for now; the GPL simply makes the terms
honest for whoever receives it.

Sponsor spotlight grew the fine control the operator asked for:

- show_logo / show_name / show_blurb toggles (all default on — existing
  renders unchanged) alongside the earlier banner/video/description/
  action toggles, so a spotlight can be anything from a bare logo to the
  full feature block.
- blurb_length and description_length character caps. Truncation breaks
  on a word boundary with an ellipsis (shared Components::truncate()).
  A capped FULL description deliberately renders as plain text: cutting
  HTML at a character count risks unbalanced tags, so the cap trades
  formatting for safety — uncapped keeps the sponsor's markup via
  wp_kses_post as before.
- website_text and books_text label overrides. Precedence for the
  website button: operator's text > the sponsor's own link_title CTA
  from HeySummit > "Visit website". The operator always wins on their
  own site.

All markup-affecting, so all component attributes (cache-keyed), and all
surfaced automatically in the block sidebar and Elementor panel via the
shared definitions schema.

## D83. No per-sponsor URL overrides; the QoL pack instead (v1.18.0)

The operator asked whether sponsors' link destinations could be set
per sponsor. Answer: they already can, the honest way — a manual sponsor
row (Lite editor) or Sponsor post (Full) with the same name shadows the
API sponsor via the name dedupe and carries whatever URL/logo/blurb the
operator wants. A dedicated override table was offered and declined:
overridden data silently drifts from what HeySummit shows on the hub,
and the shadowing pattern covers the rare genuine need without a second
source of truth.

What shipped instead — the fine-tuning that was actually missing:

- **hide_empty** on every component that has a visible empty state
  (injected once in definitions() keyed on the presence of empty_text,
  so components that already suppress their own empties never grow a
  dead toggle). The rule that mattered: hiding happens strictly AFTER
  caching, at the render return sites. Passing '' into Cache::keep()
  would have cached the blank for the full display TTL and clobbered
  the last-good copy — destroying both guardrails D-series decisions
  built. The real empty fragment stays cached at ≤60s; visitors get
  nothing; administrators get an HTML comment naming the setting so a
  blank sidebar stays debuggable.
- **new_tab** on the wall (grid/list/compact/strip) and spotlight:
  target="_blank" on sponsor anchors, rel="sponsored noopener" already
  present; the spotlight's tel: link never gets one.
- **Wall blurb_length** (same truncate() as the spotlight) and an
  optional **wall heading** rendered on every layout, one level above
  the **configurable category-heading tag** (heading_level h2–h4,
  string-typed: an integer att with options would pair a number block
  attribute with a string enum and break the editor control).
- **utm_links** (opt-in): sponsor website URLs pass through Utm::tag()
  at render; hub URLs are tagged at mapping time and Utm::tag() never
  double-tags, so hub mode composes safely. Off, sponsor links stay
  exactly as the sponsor entered them.

Known cost, accepted: new atts change every last-good cache key, so
this deploy discards existing last-good copies once (a Lite site whose
API is down right at upgrade time shows empty states until one fetch
succeeds).

## D84. Lite grows up: past sessions, the calendar feed and the filter bar (v1.19.0)

Asked "which 3 features would you check or add in Lite", the honest
answer started with a check that failed: a live registration counter is
NOT buildable — the events serializer exposes no attendee/registration
count and Full's counter is fed by verified webhooks, which Lite gives
up. Added to the HeySummit ask list: an aggregate registration_count on
the events serializer would make social proof work API-only.

The three that shipped, each cheaper than it looks because the plumbing
existed:

- **Past sessions + replays.** The bounded talk harvest was already
  fetching past sessions and throwing them away — past_talks() was a
  hard stub with an outdated "unbounded queries" rationale (the harvest
  has had page budgets since V4). Un-stubbed: newest first, replay CTAs
  (replay_url/replay_planned were already mapped), pagination, search.
  Past EVENTS remain Full-only — no live archive worth chasing. Side
  effect accepted: Lite's schedule component now shows past sessions
  (Full parity).
- **Calendar subscribe feed.** Feeds moved to both modes; in Lite it
  skips add_rewrite_rule (the no-rewrites promise holds) and serves at
  /?eex_feed=calendar, building through Ics::calendar_from_data() from
  the live repository — the exact path the per-session download proved.
  should_cache() gained a Lite branch validating ?event=/?category=
  against the configured events and live category slugs (bounded sets;
  junk params still get a fresh-built response, never a transient).
  Fixed a latent bug: the subscribe link on listings was hardcoded to
  the pretty URL and 404'd in Lite — Feeds::url() now serves both.
- **Session filter bar.** Categories and speakers already aggregated
  correctly in Lite; the JS filter works on the shared card markup
  unchanged. The no-JS story needed one addition: Lite categories have
  no archive pages, so URL-less category badges link back to the
  current page as ?eex_cat=, which past-sessions now accepts via
  from_get — and GET-sourced category values are treated like search
  strings for caching (render fresh, never mint transient rows from
  visitor-controlled input).

## D85. Public-repo readiness (v1.19.1)

Preparing to open the repository. The audit found no secrets, no real
personal data and no live event data anywhere in the tree or the full
git history — the fixtures were always example.* addresses and the docs
reference only HeySummit's public API endpoints. What did need fixing:

- **LICENSE added** (canonical GPL-2.0 text): the licence was declared
  in three places but the grant text shipped nowhere, so GitHub could
  not detect it and the grant was technically ambiguous.
- **README notice** no longer says distribution is private — it is a
  public BETA with the same as-is/no-liability warning, now pointing at
  SECURITY.md.
- **No brand names baked in**: activation stopped seeding the series
  taxonomy with emailexpert's own event names (FORUM, Deliverability
  Summit, Sender Symposium, Festival of Email). Series are
  site-specific brands; operators create their own, or site code seeds
  a fixed set via the new eex_seed_series_terms filter (default empty).
  Existing installs keep any terms already created — the seed only ever
  ran at activation and never deletes. Generic tier terms
  (Platinum/Gold/Silver/Partner) still seed.
- **SECURITY.md** (private reporting route plus the plugin's standing
  security promises as researcher targets), **CONTRIBUTING.md** (the
  load-bearing ground rules, distilled from this file) and
  **CHANGELOG.md** (operator-view version history reconstructed from
  the release commits).

Left alone deliberately: docs/ (engineering history, nothing sensitive,
adds credibility), commit history (rewriting 125 commits would break
every merged PR reference), and the repository description/topics —
those live in GitHub settings, not the tree.

## D86. Bare API timestamps are event-local, not UTC (v1.19.2)

Operator report: the next-session hero showed a 7 July talk at 8pm to a
Madrid visitor while the HeySummit hub said 7:00 PM GMT+2 — exactly one
hour late. Root cause confirmed against the live event (timezone
Europe/London): HeySummit serialises starts_at/ends_at WITHOUT a UTC
offset, as wall-clock time in the event's timezone. Our shared
BaseMapper::datetime() handed such strings to DateTimeImmutable, which
falls back to PHP's default timezone — pinned to UTC by WordPress — so
6pm London was stamped 18:00Z instead of 17:00Z. Every consumer of that
instant (the <time datetime> the visitor JS converts, countdowns, .ics,
the feed, the live-now window, upcoming/past splits) inherited the
error. The cruel part: in winter London is GMT+0, so the bug was
invisible when most of this plugin was built and tested.

Fix: datetime() takes the event's IANA timezone and uses it ONLY for
bare values — strings carrying Z or ±hh:mm are unambiguous and ignore
the hint, bare DATES (no wall clock) are left alone, and an unknown
zone string falls back to the old UTC assumption rather than erroring.
Threaded through both modes: LiveRepository (map_event, map_talk,
talk_ends), the sync TalkMapper/EventMapper, and SyncEngine's talk pass
(the mapper calls that only compare event IDs don't need it; DryRun
keeps the offset-less path — a one-hour skew at a scope boundary in a
preview count is acceptable). The version bump self-flush clears every
cached fragment and synced value re-maps on the next sync, so the
correction is complete without manual intervention.

## D87. The site timezone stands in when the event's is unknown (v1.19.4)

The v1.19.2 fix verified but did not engage on the live site (page
source still carried 18:00:00Z on plugin version 1.19.2). Two ways the
hint could silently miss: the live events payload may omit `timezone`
(or spell it differently — Lite's map_event read only the one key while
the Full EventMapper accepted timezone/time_zone/tz), leaving the hint
empty and the old UTC assumption in force. Hardened both: Lite now
reads the same three keys, and BaseMapper::datetime() falls back to
wp_timezone_string() when no event timezone is supplied — a bare
HeySummit timestamp is never meant as UTC, and an operator's site
almost always shares its events' timezone. UTC remains the last resort
(and the exact prior behaviour for sites whose WP timezone is UTC).

Still open: if the raw API value turns out to carry a misleading Z
(event-local time stamped as UTC), no timezone hint can help — the
parser trusts explicit offsets by design. The v1.19.3 discovery report
prints raw timestamp samples with an offset verdict precisely to
settle that; if confirmed, the next step is treating HeySummit's
offsets as decoration behind a filter.

## D88. The Style tab catches up with the components (v1.20.0)

Operator feedback: the sponsor wall "does not offer nearly the levels
of controls or pre designed templates I would like... no way to change
the size of the headline or font styling". The audit agreed and found
it was not sponsor-specific: the shared Style tab had typography groups
for titles/meta/buttons/body but NONE for headings, and the selector
lists predated the newer components — .eex-wall-heading,
.eex-spotlight-name, .eex-hero-title and the sponsor name classes
appeared in no control at all. Once markup outruns the style selectors,
whole components become unstyleable without custom CSS.

Added for every widget (one shared Style tab): a Design preset select
(boxed/outlined/soft/chromeless/inverted — bundles of the same CSS
variables the individual controls fine-tune, so presets are starting
points, not modes), Headings and Descriptions typography groups,
description colour, responsive content alignment, a free sponsor-logo
size slider (direct height/max-height so it beats the content-tab
preset's inline variable), and logo hover treatments (greyscale/dimmed
until hover — style-only, so a CSS variable pair consumed in eex.css
with hover/focus reset). Rule reaffirmed for future components: when a
template introduces a new text class, it must join the Style tab's
selector lists in the same change.

## D89. A budget-truncated sweep must not poison the last-good cache (v1.20.4)

Field report: upcoming sessions displayed at bedtime, were gone by
morning, and a Flush live cache brought them back — every night. The
harvest record told the story. Event 181590 reports 273 sessions across
28 pages, and this account's talks list is NOT ordered by date: its
upcoming sessions sit on a MIDDLE page, past both ends (the last page,
28, held only 2026-06 sessions while the event's own record ran to
2029). D55's middle sweep is the right tool for that — but it is bounded
by `eex_live_max_pages`, 40 in admin and 12 on the front end (a visitor
page must never make dozens of sequential API calls). So the deep admin
sweep reaches the upcoming sessions and the shallow front-end sweep,
capped at 12 of 28 pages, does not. Both fetches share one cache key.
The sequence: an admin view (or a flush, which reloads the admin page)
sweeps deep and caches the upcoming sessions as the fresh (15-minute)
AND last-good (24-hour) copies → events show; 15 minutes later the fresh
copy lapses, the next visitor triggers a shallow sweep that finds
nothing upcoming, and that partial overwrites BOTH copies, including the
24-hour last-good → events vanish until the next flush.

The two-tier cache exists precisely so a degraded fetch serves last-good
instead of blanking the page; a sweep truncated by its page budget or
wall-clock deadline is a degraded fetch for the "is anything upcoming?"
question, but the harvest was treating it as authoritative. Now the
harvest records whether it read the whole collection (`complete`), and a
sweep that is BOTH incomplete AND found nothing upcoming defers to the
last-good collection when that copy still carries upcoming sessions
(`LiveCache::peek_good()`, a read-only peek that spends no budget and
takes no lock) — so a shallow sweep can only preserve upcoming sessions,
never erase them. Once a deep sweep has cached them, every subsequent
shallow sweep re-serves and re-saves that copy, and the sessions stay
put without a flush. Consequences and bounds: the fix is one-directional
(a shallow sweep never adds or prunes upcoming sessions, it only keeps
the last complete answer), so refreshing the upcoming set still needs a
deep sweep — an admin view, a flush, or a raised `eex_live_max_pages`;
a genuinely-emptied summit therefore keeps showing its last upcoming
sessions on the front end until a deep sweep confirms the emptiness (the
same serve-stale trade-off as D65, and the client-side clock still ages
past sessions out of the list). The Live status row names the situation
("this sweep was truncated by its page budget before it reached the
upcoming sessions, so the last complete result is being shown"). The
cold-start case is unchanged: with no last-good yet, the first shallow
sweep still caches its partial, and the first admin view or flush seeds
the complete copy that the guard then protects.

## D90. Per-ticket buy buttons use the API's checkout_link (v1.21.0)

HeySummit added a dedicated per-ticket checkout URL after we raised
the deep-linking gap (founder-confirmed by email, 9 Jul 2026): every
ticket row in v2 API responses now carries `checkout_link`, the same
`<hub>/checkout/ticket/<pk>-<hash>/` link the dashboard's Generate
Checkout Link produces, and it genuinely preselects the ticket —
unlike the `?ticket=` parameter, which D72's live click-test proved
echo-only. Per-ticket CTAs (pricing table and drawer) now prefer it.

Precedence, unchanged around the new arrival: Woo mapping (buy_on=woo,
D72) still outranks everything; a widget's external ticketing override
still replaces HeySummit checkout wholesale; then the API
checkout_link when the row has one; then the constructed
select-tickets URL with the echo-only parameter, byte-for-byte as
before — so accounts whose serializer predates the field, and rows
served from a stale cache, degrade to exactly the old behaviour.
Event-level tickets buttons (session cards, hero, agenda) have no
single ticket to deep-link and keep select-tickets.

The API link arrives untagged, so `carry_query()` copies the tagged
event URL's query string onto it at render time — attribution stays
identical to what the constructed URL would have sent, and parameters
the link already carries are never clobbered (the Utm::tag rule). An
`eex_ticket_checkout_link` filter is the operator escape hatch.
The raw ticket transient key is versioned (v2|) so upgraded sites
fetch link-bearing rows immediately instead of serving the old cache
for up to 15 minutes; orphaned entries expire on their own.

HeySummit also shipped POST events/<id>/tickets/<pk>/checkout-link/
(optional coupon in the body). NOT allowlisted: the GET field already
covers display, and D45's write-surface rule — attendee create and
ticket attach only — stands until coupon-baked links are actually
wanted. If that day comes, the endpoint is generate-only (mints a URL,
mutates nothing), so the widening is defensible; it gets its own
decision entry then.

## D91. Coupon-baked checkout links; the write allowlist gains a generator (v1.22.0)

The other half of HeySummit's July 2026 checkout-link work (D90):
POST events/<id>/tickets/<pk>/checkout-link/ generates a per-ticket
checkout URL with an optional coupon baked in. A `coupon` attribute on
the ticket-bearing components (pricing plus the three drawer-capable
session widgets) now feeds that endpoint, and the generated link rides
the row's checkout_link key — so precedence (Woo mapping and the
external override still win), UTM carry-over and the hostile-scheme
guard all apply unchanged, and Components needed no new URL logic. The
operator journey: create the coupon in the HeySummit dashboard, set
coupon="CODE" on a widget, and every buy button on that page is a
discounted deep link — no code for the visitor to type, and campaign
pages pair with UTM attribution to make sponsor/partner codes
measurable.

The write allowlist (D45) gains its third entry for this. The rule
stands — no event or content writes, ever — and the addition is
defensible because the endpoint is generate-only: it mints a URL and
mutates no attendee, ticket or event data; worst case is a wasted
link. Call volume is bounded: one POST per ticket+coupon, cached 12
hours on success (eex_coupon_link_ttl) and negative-cached 5 minutes
on failure, so an account without the endpoint or an expired coupon
cannot be re-POSTed on every render. Failure yields '' and the row
keeps its plain checkout_link — a bad coupon costs the visitor the
discount, never the button.

Deliberately excluded from this version: coupons from
visitor-controlled input (a ?coupon= page parameter would let anyone drive
authenticated POSTs and mint cache rows — if ever wanted it needs an
operator-approved coupon allowlist, the same reasoning as the D-series
cache-stuffing guards), and any coupon admin UI (the API's coupon
list/create abilities are unconfirmed — asked of HeySummit; a widget
dropdown of live codes could follow).

## D92. The widget release: six new components on the one render pipeline (v1.23.0)

A survey of the data layer against the 17 existing components found real
assets nothing displayed: replay URLs and replay_planned (both modes),
session promo images and stage/inperson_venue (Lite mapped them; sync
did not), the operator-owned event venue meta (schema-only until now),
speaker links (mapped, stored, never rendered), and the free-registration
REST that lived only inside the drawer. This release surfaces them as six
new components — sticky register bar, inline registration form, featured
session card, stats strip, replay gallery, venue card — plus opt-in
upgrades to schedule (day nav, timezone toggle) and speakers (link
chips). Everything rides the existing pipeline: a definitions() entry IS
the shortcode, the block and the Elementor widget, style presets
included; no component gained bespoke registration code.

Rules reaffirmed and applied: upgrades to existing components hide
behind flags that default off, so rendered pages change only when an
operator opts in (tested: schedule and speakers markup carries none of
the new markers without the flags). New data keys (talk venue/inperson
via _eex_talk_venue/_eex_inperson, talk_data image from the featured
image, speaker links in both repositories) are additive; sync writes
stay in sync-owned keys. The sticky bar is server-rendered in normal
flow and only pinned by JS — no-JS visitors see an ordinary banner where
it was placed, the Elementor editor keeps it selectable, dismissal is
sessionStorage, and reduced-motion visitors get no slide animation. The
drawer's form moved to a shared part (register-form) consumed by both
the drawer and the inline component — same fields, honeypot, consent
and aria-live status. Stats suppress zeroes ("0 speakers" sells no
tickets) and the count-up is opt-in, IntersectionObserver-driven and
disabled under prefers-reduced-motion. The replay gallery links to the
session page by default (keeping VideoObject schema pages in the
journey) with direct replay links as the explicit alternative; no
embedded players — component pages stay free of third-party requests,
which is also why venue/featured-session map links are links, never
iframes. The timezone toggle keeps both renderings (the server's
event-local markup and the JS visitor-local rewrite) and flips between
them, so no-JS output is untouched and the choice persists per session.
Venue is Full-only (the address meta lives on event posts) and joined
FULL_ONLY; the featured session card shows the Lite venue name line in
Lite. Elementor's event picker now works in Lite via eex_event_titles,
remembered on every live event map — the ticket-titles pattern.

## D93. Field report: presentation bugs come in classes — fix the class (v1.23.0)

First real-account render of the featured session card showed two bugs:
the built-in "In person" pill next to the account's own "In Person"
category, and a bare "12970" as the location line (HeySummit had
serialised the venue relation as a record ID). Each is an instance of a
class, so the fix swept the class across every widget rather than
patching the reported spot:

Duplicate information via two paths — status pills now dedupe against
category names with case and punctuation folded (status_badges(), used
by session cards and the featured card); a speaker's company line
yields when the headline already names the company; the featured card's
address drops its first line when the session's venue line already
names the venue (the maps query keeps the full address); the register
bar's countdown is label-free because the bar itself names the event.

Machine data leaking as display text — bare-numeric parts are dropped
from talk venue lines in BOTH mappers, from the Lite event venue, and
from Lite scalar category badges (an ID list must never become "9"
"12" pills).

Empty-value edges — speaker chips without a URL render as spans, not
href="" anchors that link to the current page.

The rule this reaffirms: display fields sourced from the API are
untrusted PRESENTATIONALLY as well as structurally — validated not
just for safety (schemes, escaping) but for whether they read as
human text.

## D94. The live coupon dropdown lands (v1.25.0)

D91 shipped coupon-baked checkout links but deliberately deferred one
piece — the operator still typed `coupon="CODE"` by hand, because the
API's coupon-list ability was then unconfirmed ("a widget dropdown of
live codes could follow"). HeySummit has since enabled
`GET events/<id>/coupons/`, so the dropdown lands.

A read-only `Data\Coupons` fetcher mirrors `Data\Tickets` (15-minute
transient; nested-route lead with a top-level `coupons/?event=`
fallback, since the spec documents coupons only under the event) and
exposes `code_options()`: one row per redeemable coupon — active, with a
`coupon_code` — shaped `{ id, code, title }`. The Elementor coupon
control builds a dropdown from it; the operator picks a coupon by title
and its code flows into the existing `coupon` attribute, which the buy
buttons already bake into checkout links via the generate-only
`checkout-link/` POST (unchanged). Codeless or inactive coupons are
dropped — they cannot mint a usable link. The control degrades to the
old text field before the connection has loaded any coupons, so manual
entry, blocks and shortcodes are untouched: this changes only how the
code is chosen, never how it is used.

The picker live-fetches at editor-build time (like the event picker's
`all_events()`), so there is no remembered store to pre-warm, and the
per-event fetches are cached so repeat editor loads cost nothing.
`coupons` joins `Shapes::RESOURCES` and the discovery nested-route
fallback, so Test connection samples the real shape and reports it.

Still no writes: the coupon create/update/delete the API exposes stay
outside the allowlist (D45), which keeps its three entries — attendee
create, ticket attach, and the generate-only checkout-link (D91).
Pick-by-name-never-by-ID is D71; this is its coupon instance.

## D95. Registering for a session, not just the event (v1.26.0)

Field report: a session's Register button sent people to the event
checkout, which registers them for the event but never for the session
they clicked — the operator had disabled buy buttons and leaned on the
talk landing page (which HeySummit uses to add the talk) as a stopgap.
HeySummit then shipped two ways to close the gap: an optional `talk_id`
on the generate-only `POST events/<id>/tickets/<pk>/checkout-link/`
(the checkout preselects the talk and schedules it on registration), and
an event-scoped, idempotent `POST events/<id>/attendees/<pk>/talks/<talk>/`
that adds an EXISTING attendee to a talk, respecting their ticket access
and the talk's capacity.

This release wires the second — the plugin's own free in-drawer
registration. The ticket drawer is event-level and shared across every
session card in a component (one cached fragment, not one per session),
so the clicked session travels client-side: each session card's
Register button carries `data-eex-talk`, and eex-time.js stamps it onto
every registration form in the drawer as it opens. `RegisterController`
gained a `talk` field; after a successful attendee-create it attaches
that talk (best-effort — the visitor is already registered, so a failed
attach only forgoes the session, never the registration), and a
returning "already exists" attendee is found by email and attached too,
so a repeat visitor adding a new session still works. The talk is
validated as a real talk of the event before any call
(`Repositories::known_talk()`) — a form-supplied ID is never trusted.
The allowlist gains its fourth entry, the talk attach; the hard rule
holds because it mutates no event or ticket data, only a schedule the
attendee is entitled to.

Deliberately NOT done here: baking `talk_id` into the paid/hosted
checkout link (endpoint #1). It reads clean but is not — a session card
has no single ticket, so a per-session paid link means resolving a
ticket AND generating a link per card at render, which is one POST per
session on top of the events/talks fetches: it breaks the "at most two
cold API calls per page" guarantee (D36) that keeps session listings
cheap, and no in-render gate fixes it without a multi-second warm-render
spike (and Full mode has no such budget at all). The correct home for
endpoint #1 is a lazy, on-click REST generator (zero render cost,
deterministic, cached) — its own change when wanted. Until then, free
sessions register in-drawer (this change) and paid tickets keep the
event-level checkout with the talk landing page carrying the session.

## D96. The integration tests itself in the admin (v1.27.0)

Operator ask, verbatim intent: comprehensive testing in the admin so
problems are detected and everything provably works. The pattern this
project keeps re-learning (D46, D50, D54: the harvest shows its working)
is that self-explaining state beats inference — but that principle only
covered the display pipeline. Registration, coupons, the generator, the
allowlist, caches and cron had no operator-visible verification at all,
and Site Health had no test in Lite mode — the mode production runs.

`Admin\SelfTest` is one engine with two tiers, because Site Health loads
its direct tests on every view of that screen. Cheap checks (no HTTP):
a keyed connection exists; events are chosen/enabled; PHP/WP versions;
**transients persist** (write-read-back — a broken object-cache drop-in
silently voids every cache, budget and stampede guarantee the plugin is
built on, and looks exactly like "it worked last night"); version
bookkeeping ran (D60); the **write allowlist still permits all four
sanctioned writes** (a refactor that broke a pattern would kill
registration silently); the /eex/v1/register route is registered; sync
cron and webhook secret (Full); live-cache degradation (Lite). API
probes (explicit Run button or `wp eex health` only): events per
connection with latency, tickets per event (warning when no row carries
a checkout_link), coupons (warning only — optional surface), the
display-pipeline diagnosis (`LiveRepository::diagnose()`, reused whole),
and the checkout-link generator exercised through the REAL production
path — `Tickets::couponed_checkout_link()` with the event's first live
coupon — never a guessed request body ({} vs [] matters to DRF), and
skipped honestly when there is no coupon to test with. Probes are
read-only except that one generate-only POST (D91's classification).

Surfaces: a health page (Settings → Events health) with a Run button
(admin_post + nonce + capability), stored timestamped results
(non-autoloaded option); a Site Health "direct" test in BOTH modes built
from the cheap tier, critical on any fail; `wp eex health` printing
every row and exiting non-zero on failure so cron/CI can alert — the
"detect problems without a human looking" half of the ask. Per-event
probes are bounded to the first three configured events so a many-event
account cannot turn the button into a minute-long stall. The engine is
pure enough to unit-test: SelfTestTest covers unconfigured-fail,
configured-pass, version-mismatch warn, allowlist coverage of all four
writes, probe pass/fail/warn (dead generator warns, unreachable API
fails with the transport reason), Site Health registration and
criticality, and result storage.

## D97. Widgets are reviewed in a browser, not inferred from code (v1.28.0)

The operator asked for a critical review of the rendered output of every
Elementor widget as both a demanding site owner and a subscriber. Since
the widgets are thin mappers over Components::render() (D10), the review
rendered every component through the real pipeline (wp-stubs + Lite
fixtures deliberately hostile: 120-character titles, five categories, no
speakers, paid-only, API-dead) into standalone pages carrying the shipped
CSS/JS, then exercised them in Chromium at 1180px and 375px — clicks,
keyboard, fetch-intercepted success/failure — rather than trusting code
reading. Kept as scratch tooling, not shipped: the fixtures would drift.

What the browser caught that code review had not: (1) the drawer panel
overflowed a 375px viewport on themes without a global border-box reset —
the plugin now owns its box model (.eex/.eex-drawer subtree box-sizing);
(2) the session silently attached by D95 was invisible — the drawer now
carries a context line ("Registering for: <session>") stamped by
eex-time.js from the opener's data-eex-talk-title, hidden for event-level
openers, so the write the visitor triggers is the write they can see;
(3) the standalone registration form never said what it registered for —
it now names the event and free ticket (unless the operator's own heading
does), and the paid-only fallback button explains why there is no form;
(4) sub-44px touch targets throughout (CTAs 32px, close 31px, secondary
links 15px, inputs 21px) — all controls now meet 44px, filterable via
--eex-cta-min-height, and .eex-cta moved to inline-flex so full-width
variants centre; (5) the free-form toggle stacked two identical primary
buttons — it is now a disclosure (aria-expanded) that yields to the form;
(6) bare "499" amounts — a currency attribute (default '', byte-identical
output until set) prefixes numeric amounts only, never the Free label.
Verified after the fixes by a scripted re-run: drawer fits at 375px with
zero clipped elements, context line correct, stamp/Escape/focus-trap
regressions green, currency renders, 44px measured. Not "fixed" because
unbroken: focus-visible outlines, dialog semantics, Escape/focus-return,
and empty-state honesty all passed the audit as shipped.
