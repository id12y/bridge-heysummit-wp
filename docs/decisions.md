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
and stand unedited.
