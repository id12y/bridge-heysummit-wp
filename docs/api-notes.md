# HeySummit API notes

## Status of these notes

No API key was available during the build, and the public v2 docs at
https://api-v2.heysummit.com/ are JavaScript-rendered and were not readable at
spec time. Everything below is therefore an **assumption** derived from the
v1-era field lists in the project specification, awaiting live verification.
Where v2 turns out to differ, v2 wins.

All assumed shapes live in one place in code: `src/Api/Shapes.php`. The
mappers (`src/Mappers/`) and the runtime discovery diagnostic
(`src/Api/Discovery.php`) both read from it, so a correction after live
verification is a one-file change.

## How assumptions get verified

1. Install the plugin, enter the API key, press **Test connection**.
2. On success the plugin automatically runs a discovery pass: it GETs one
   sample record from each known resource, records field names and types
   (never values, so no personal data is stored), and compares them with
   `Shapes::RESOURCES`.
3. The result appears in the **API discovery diagnostics** panel on the
   settings page and in the sync log flagged `discovery`. It is also
   available as `wp eex discover`.
4. Missing expected fields are warnings, not fatals; sync proceeds with
   whatever maps.

## Assumed API surface

- Base URL: `https://app.heysummit.com/api/v2/` (legacy v1 at
  `https://api.heysummit.com/api/`).
- Auth: `Authorization: Token <API_KEY>` header. Requires the HeySummit
  Business plan.
- Pagination: Django REST Framework style — `count`, `next`, `previous`,
  `results`. The client follows `next` until exhausted with a hard cap
  (50 pages, `eex_max_pages` filter) and refuses to follow a `next` link
  that leaves the API host.
- Resources assumed present: `events/`, `events/<id>/`, `talks/`,
  `talks/<id>/`, `speakers/`, `attendees/`, `attendees/<id>/`,
  `categories/`.

## Per-resource assumptions awaiting live verification

| Resource | Assumption | Risk if wrong |
|---|---|---|
| `events` | Fields: `id`, `title`, `event_url`, `first_talk_at`, `last_talk_at`, `is_live`, `is_archived`, `is_evergreen`, `is_open_for_registrations`; also hoped for: `description`, `timezone` | Missing `timezone` means event-local rendering falls back to the site timezone; discovery will flag it |
| `talks` | Filterable by event via a query parameter — the sync engine tries `?event=<id>` first, then `?event_id=<id>` (see `SyncEngine::fetch_talks`) | Wrong parameter returns an unfiltered collection; the engine detects talks from other events by their `event` field where present, and discovery makes the shape visible |
| `talks` | `starts_at` / `ends_at` ISO 8601 timestamps; a `replay_url` or similar may exist | If no replay field exists, `_eex_replay_url` remains a manual editor field (design already assumes this) |
| `talks` | `speakers` is a list of speaker objects or IDs; `categories` similar | Mapper accepts objects (uses `id`) or scalar IDs |
| `speakers` | Either `name` or `first_name`/`last_name`; photo under `avatar` or `photo_url` (string or object with `url`); `email` may not be exposed | Dedup falls back from HS ID → email → name+company; photo sideloading skips when no URL maps |
| `categories` | `id` plus `title` or `name`; filterable by event via `?event=<id>` | If not filterable, the category list for the filter UI shows all categories on the account — cosmetic, not corrupting |
| `attendees` | Fields per spec 3.1: `email`, `name`, `registration_status`, `event_id`, `created_at`, `utm_*`, `http_referer`, `affiliate_email`, `talks[]`, `tickets[]` | Webhook verification fetches `attendees/<id>/`; the parser and mapper are null-safe so missing attribution fields store as empty strings |

## Webhook payload assumptions

HeySummit sends outgoing webhooks (unsigned JSON POST) for three actions:
registration started, checkout complete, talk added to attendee schedule.
Exact payload shapes are undocumented. The parser
(`src/Webhooks/Parser.php`):

- accepts the action name under `action`, `event`, `type` or `trigger`;
- accepts the attendee as a nested object (`attendee`, `data.attendee`,
  `data`) or flat top-level fields;
- accepts string or integer IDs everywhere;
- treats every payload as untrusted: state-mutating actions re-fetch the
  attendee from the API by ID and use the fetched record, not the payload.

Use capture mode (Settings → Webhooks) plus
`wp eex webhooks:replay <log_id>` to verify parsing against real payloads —
procedure in the README.

## Hard runtime rules

- **Reads everywhere, writes nowhere except the allowlist.** (Amended in
  v2; unchanged in v3.) Sync and discovery are GET/OPTIONS only. The single
  write surface is `HeySummitClient::post()`, which throws for any endpoint
  outside `Api\WriteEndpoints::ALLOWLIST` (attendee create and external
  ticket sale import — used only by the WooCommerce bridge and the v3
  accounts module, which added no new endpoints). The v1-era API includes
  an event archive action; a stray POST against a live event ID would
  archive it — that path is structurally unreachable.
- No API calls from any front-end request path.
- The API key is never logged, never rendered in full (last 4 characters
  only), never present in REST responses.

## v2: write endpoints (WooCommerce bridge)

The amended hard rule allows writes to exactly two endpoints, enforced in
code by `Api\WriteEndpoints::ALLOWLIST` (the single place the list is
defined; `HeySummitClient::post()` throws for anything else, including the
event archive action):

| Purpose | Assumed endpoint | Assumed body | Risk if wrong |
|---|---|---|---|
| Attendee create | `POST attendees/` | `{name, email, event}` | Discovery records the real POST schema via OPTIONS (`write:attendees` in the report); correct the body in `Mappers\AttendeeRequestBuilder` or via the `eex_attendee_request` filter |
| External ticket sale import | `POST external-ticket-sales/` | `{attendee, ticket, amount_gross, amount_net, currency, order_reference}` | Same, via `write:external-ticket-sales` and `Mappers\TicketSaleRequestBuilder` / `eex_ticket_sale_request`. If the real endpoint has a different name, change it in `WriteEndpoints::ALLOWLIST` and the builder together |
| Ticket enumeration | `GET tickets/?event=<id>` | n/a (read) | Added to `Shapes::RESOURCES`, so the standard discovery pass samples it |

Write-shape verification is non-mutating: discovery issues an OPTIONS
request per allowlisted endpoint and records the DRF `actions.POST` field
schema. **No POST is ever made during discovery.** Attendee removal /
ticket-sale reversal is NOT implemented: those endpoints are outside the
allowlist, so refunds produce an order note, an admin notice and the
`eex_woo_refunded` hook instead (docs/decisions.md D24).

## v3: ticket assignment at attendee creation (accounts module)

The accounts module uses only the two already-allowlisted write endpoints.
How a specific (including free) ticket attaches to a new attendee is
resolved at runtime from the discovery snapshots (`write:attendees`,
`write:external-ticket-sales`), per `Accounts\TicketAssignment`:

| Discovery finding | Method used | Notes |
|---|---|---|
| `write:attendees` POST schema contains `ticket`/`ticket_id`/`tickets` | `create_param` — ticket rides in the attendee-create body | Field name taken from the schema |
| No ticket field on create, `write:external-ticket-sales` usable | `ticket_import` — zero-amount external ticket sale after creation | Also the default before any discovery data exists; a free assignment, not a sale |
| Neither endpoint can assign | `unsupported` — attendee registered without a ticket | Warning logged (flagged `discovery`) naming the intended ticket; assign manually in HeySummit |

"Attendee already exists" responses (HTTP 409, or 400 with
already/exists/unique/duplicate in the body) are treated as success and
recorded; no ticket import follows for a pre-existing attendee. Verify the
resolved method in the diagnostics panel after Test connection and override
with the `eex_ticket_assignment_method` filter if the live API differs.


## Live verification findings (first real account)

The first live discovery run (Lite-only install) verified:

- `events/` matches the assumed shape, with extras: `url` (the record's
  own API URL — DRF hyperlinked style), `company_name`, `logo`,
  `logo_white`, `feature_image`, inline `categories`/`tags`, `status`,
  and **`_is_open_for_registrations` with a leading underscore** (the
  mappers now accept both spellings).
- **Top-level collection routes other than `events/` answered HTTP 403**
  (`talks/`, `speakers/`, `categories/`, `tickets/`, `attendees/`, and
  OPTIONS on both write endpoints) even though the key is valid. The
  same data is served nested under the event
  (`events/<id>/talks/`, `events/<id>/tickets/`). All talk/ticket
  fetchers now negotiate three route styles — `talks/?event=`,
  `talks/?event_id=`, `events/<id>/talks/` — and remember the working
  style per connection (`Api\PathStyles`); the discovery panel samples
  the nested route before reporting an error.
- Whether the write endpoints accept POST despite refusing OPTIONS is
  still unverified — the first sandbox WooCommerce order will tell.


## Reconciled against the published OpenAPI 3.1 spec

The operator supplied the actual HeySummit v2 OpenAPI document; every
assumption has been reconciled (docs/decisions.md D45):

- **All resources are event-nested**: `events/<id>/talks|speakers|
  categories|tickets|attendees|talkattendees/`. No top-level collections
  besides `events/` and `webhooks/`. Fetchers default to nested first.
- **Talks carry `date`** (a single date-time; no end time — the +1h
  default applies everywhere an end is needed), inline full `speakers`
  and `categories` objects, `is_active` (respected: inactive = removed
  from the site) and `is_featured`.
- **Speakers**: `first_name`/`last_name`, `headshot`, `company`,
  `company_title`, `expert_creds`, `bio`, `is_active`. No email.
- **Attendees**: `http_referer_domain` and `referer_ref` (not
  `http_referer`); `talks` is a list of IDs; no tickets field.
- **Writes**: attendee create is `POST events/<id>/attendees/`
  (`email`, `name`, optional `ticket_price_id`, optional `questions`);
  ticket assignment for an existing attendee is the documented-idempotent
  `POST events/<id>/attendees/<pk>/tickets/` (`ticket_price_id`).
  **`external-ticket-sales/` does not exist** — the v2 API cannot record
  off-platform sale amounts; they stay on the WooCommerce order. The
  allowlist holds exactly these two patterns; the spec also exposes
  create/update/delete on events, talks, speakers, categories, attendees
  and webhook subscriptions — all structurally unreachable.
- **The mapped value is a ticket PRICE id** (`ticket_price_id`), not a
  ticket id. The mapping UI expands `Ticket.prices` when the payload
  allows and labels unexpanded rows so the operator can confirm the ID.
- **Outbound webhook payloads carry no action key in the body**; the
  parser now infers the action from documented shapes (checkout has
  `paid_at`/`ticket_purchases`; talk-added has `talk_id` plus
  `attendee_*`-prefixed fields; registration-started has
  `registration_status`/`registration_answers`).
- The API also documents webhook-subscription management
  (`GET/POST webhooks/`); the plugin only ever needs the manual setup in
  HeySummit's UI and does not write there (outside the allowlist).

## Talks are paginated 10 per page, oldest first (live finding)

The v1 reference's own example shows it: `"count": 62, "next":
".../talks/?page=2"`. There is no date filter and no upcoming filter —
the only documented talk filters are `event` and `is_active`. On a real
long-running account the collection is ordered oldest-first, so page 1
is years old and the *upcoming* sessions sit on the last pages. Any
consumer that reads a single page therefore concludes "nothing
upcoming" while dozens of future sessions exist.

Sync walks every `next` link (`HeySummitClient::get_all()`, capped at
300 pages by `eex_max_pages` as runaway protection — 500+ talk accounts
are real, so the cap must sit far above them). The Lite render path
cannot afford a full walk at all: it reads page 1, jumps to the LAST
page via the response's `count`, and walks backwards until a page holds
nothing upcoming (plus a symmetric forward walk for newest-first
accounts), merged and de-duplicated by talk id. A handful of requests
regardless of history depth, capped by `eex_live_max_pages` (default 12
fetches), all inside one cached fetch per cache lifetime.

## Register destinations (v1.7.0)

The API exposes no ticketing-page URL anywhere — Event carries only
`event_url` (the public landing page) and Ticket carries no URL at all.
The `register_link=checkout` default therefore rewrites `event_url` to
`<event_url>/checkout/` (HeySummit's hosted ticket-selection path; the
UTM query survives the rewrite), and pricing/drawer buttons append
`?ticket=<id>` — HeySummit preselects a recognised ticket and ignores
the parameter otherwise, so the deep link degrades to plain checkout.
Events sold through an external ticketing provider are invisible to the
API too: operators handle them with `register_link=custom` +
`register_url`, or fall back to `register_link=event`. Verify the
checkout path on the live hub after deploying; it is a HeySummit URL
convention, not a documented API field.

## Talk landing pages are reconstructed too (v1.7.1)

Confirmed against the live v2 Event schema (operator-supplied): the
Event object carries `event_url` only — its `url` field is the API
self-link — and the Talk object carries no public URL at all. Yet every
talk has a landing page at `<event site>/talks/<slug>/`. Lite mode now
reconstructs it (payload `slug`/`talk_slug` when present, else
`sanitize_title(title)`) exactly like speaker hub links; talk permalinks
and the session button both use it. Session-row tickets buttons append
`?talk=<id>` to the checkout — preselected when HeySummit recognises
it, ignored otherwise. Both conventions want a live spot-check. For the record, the v2 Talk
schema is exactly: `id, title, date, event, speakers, categories,
is_active, is_featured` — no slug and no URL of any kind, so the
title-derived slug is not a shortcut but the only possible source.
Duplicate-titled talks are the known weak spot: HeySummit will have
suffixed one of the slugs, and the reconstructed link for the later
one will land on the wrong talk (or 404) until retitled.

## Correction (v1.9.0): no synthesised checkout or preselect URLs

Live testing on the hub invalidated the v1.7.x URL conventions:
`<event_url>/checkout/` and the `?ticket=` / `?talk=` preselect
parameters all produced error pages. Tickets buttons now use
`event_url` verbatim (external ticketing override unchanged), and
in-slider registration for free tickets goes through the plugin's own
allowlisted `events/<id>/attendees/` create (POST /eex/v1/register).
Talk landing page reconstruction (`/talks/<slug>/`) remains in use —
verify separately.

## Correction (v1.10.1): the checkout path is /checkout/select-tickets/

Operator-supplied: the ticket-selection page on the hub is
`<event_url>/checkout/select-tickets/` — bare `/checkout/` was an error
page, which is why v1.9.0 retreated to the event page. Tickets buttons
now land on select-tickets (path filterable via `eex_checkout_path`),
and per-ticket buttons re-add `?ticket=<id>` there — the parameter's
earlier failure was the broken base path, not the parameter. Pending
live click-tests: (1) a tickets button reaches select-tickets, (2) a
per-ticket button preselects; if (2) errors again, strip only the
parameter.

## Serializer expansion (v1.12.0 observations)

Talks now expose slug, url, external_url, date_localised,
broadcast_duration_mins, description_short/long, stage, inperson_venue,
custom_promo_image_primary/primary_image, brand_logo fields,
is_open_access/is_public_access, replay_planned, talk_cancelled,
agenda_item fields. Sponsors expose the spotlight set (promo_banner,
intro_source_type/intro_video_id/intro_video_autoplay, long_description,
link_title, books_url, phone_number, booth_enabled). The plugin maps
what it displays; discovery keeps reporting the rest.

## Sponsor hub pages (v1.14.0)

Sponsor rows carry a real `slug`; the hub page is assumed at
`<event_url>/sponsors/<slug>/` (the same convention family as speaker
hub pages, but with an API-provided slug rather than a reconstruction).
VERIFIED live by the operator (v1.15.0): sponsor hub pages resolve at
`<event_url>/sponsors/<slug>/`.
