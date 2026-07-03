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
  v2.) Sync and discovery are GET/OPTIONS only. The single write surface is
  `HeySummitClient::post()`, which throws for any endpoint outside
  `Api\WriteEndpoints::ALLOWLIST` (attendee create and external ticket sale
  import, used only by the WooCommerce bridge). The v1-era API includes an
  event archive action; a stray POST against a live event ID would archive
  it — that path is structurally unreachable.
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
