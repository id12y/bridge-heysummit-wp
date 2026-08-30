# Community Hub API — contract v1.0.0

A versioned, strictly read-only, server-to-server REST API for the
separate Community Hub application, exposing approved CRM contact facts
and Ticket Tailor ticket facts. Implemented in the emailexpert Events
plugin as an additive integration layer over the sibling **EmailExpert
Newsletter (CRM)** plugin; machine-readable spec in
[hub-openapi.yaml](hub-openapi.yaml), operations runbook in
[hub-operations.md](hub-operations.md), design reasoning in
[decisions.md](decisions.md) D108.

## Base URL and versioning

```
https://<site>/wp-json/emailexpert-crm/v1/hub/
```

The namespace is versioned (`v1`); breaking changes require a new
namespace version. The base is the site's ordinary WordPress REST base:
configure the full base URL in the Hub application (never hard-code the
host) so the systems can be separated later without a Hub release. The
namespace is deliberately plugin-agnostic — the implementation can move
into the CRM plugin without any URL change.

Nothing here exists until the operator runs `wp eex hub enable`
(disabled-by-default feature switch): while disabled, the routes are not
registered at all and return the standard WordPress 404.

## Authentication

Every route — capabilities and health included — requires a dedicated Hub
service credential:

```
Authorization: Bearer eexhub_<64 hex chars>
```

(`X-EEX-Hub-Token: <token>` is accepted for hosts that strip the
Authorization header.) Credentials are created, listed and revoked with
`wp eex hub token-*` (see the runbook); they are stored only as
purpose-keyed HMAC hashes, are not WordPress users or passwords, carry no
WordPress capabilities, and can reach nothing except these six GET
routes. Tokens are never accepted in query parameters. HTTPS is required
(HTTP requests are rejected). An optional IP allowlist can be layered on
top of the token — it is never sufficient by itself.

Failures are generic: `401` (missing/invalid/revoked credential), `403`
(HTTP, or IP not allowlisted), `429` (per-credential rate limit,
default 300 requests per 300 s), `404` (feature disabled). Bodies carry
only `{ "code": "...", "message": "..." }`.

All `POST`/`PUT`/`PATCH`/`DELETE` requests receive WordPress's standard
method rejection — every route registers `GET` only, and every `GET` is
side-effect free.

Every response carries `Cache-Control: private, no-store` and an
`X-EEX-Request-Id` header echoed in the server's redacted access log.

## Common conventions

- Timestamps are UTC ISO 8601 `Z` (`2026-08-30T12:00:00Z`).
- Pagination: `limit` (1–100, default 50, silently clamped) and `cursor`
  (opaque, signed, endpoint-typed). A tampered or foreign cursor gets
  `400 eex_hub_bad_cursor`. Replaying a cursor is always safe: same
  logical result, no mutation. Follow `next_cursor` while
  `has_more: true`.
- **Tri-state facts**: `true`/`false` mean the CRM answered; `null`
  means unknown or not applicable — never a silent default. Which is
  which is documented per field.
- `revision` is a per-contact monotonic integer; a higher revision for
  the same `contact_uuid` is always newer. `change_id`/`seq` orders the
  global change stream.

## 1. Contact identity

Every contact is identified by an immutable opaque `contact_uuid`
(UUIDv4, minted at enrolment). It never changes when the email address or
name changes, does not expose a WordPress or CRM row id, and is never
recycled. After the CRM erases a contact (GDPR purge), the UUID remains
represented as a minimal **tombstone** — uuid, revision, `deleted_at`
only; no personal data survives, so legally required erasure is never
impeded.

## 2. `GET /hub/capabilities`

Safe operational information only — never secrets, keys, paths or
configuration:

```json
{
  "contract_version": "1.0.0",
  "plugin": { "name": "emailexpert-events", "version": "1.59.0" },
  "resources": ["contacts", "changes", "ticket-catalogue", "eligibility"],
  "features": {
    "contact_uuid": true,
    "snapshot": true,
    "changes": true,
    "deletions_as_tombstones": true,
    "ticket_validity": false
  },
  "ticket_tailor": {
    "available": true,
    "box_offices": [ { "id": "main-box-office", "label": "Main Box Office" } ]
  },
  "crm": { "available": true },
  "backfill": { "enrolled": 4210, "estimated_total": 4210, "complete": true },
  "journal_head": 5321,
  "server_time_utc": "2026-08-30T12:00:00Z",
  "health": "ok"
}
```

`features.ticket_validity: false` is deliberate honesty: the CRM stores
ticket-status snapshots at receipt time and does not reconcile later
cancellations/refunds, so "currently valid" is not answerable from
durable data (see §5/§6). `health` is `degraded` when the CRM plugin is
unavailable; data routes then serve identity from the registry with all
CRM-backed fields `null`.

## 3. `GET /hub/contacts` — paginated snapshot

`GET /hub/contacts?limit=100&cursor=...` and
`GET /hub/contacts/{contact_uuid}`.

Ordering is by an internal enrolment sequence that never reorders, so a
snapshot walk cannot skip or duplicate records however the data changes
mid-walk; contacts created during the walk appear in later pages or in
`/hub/changes`. Recommended snapshot procedure: record the **first**
page's `journal_head`, walk all pages, then start `/hub/changes` from
that recorded head — every mutation during the walk is then replayed.

An active contact is exactly this allowlisted projection (no field
selection, no raw records, nothing else ever):

```json
{
  "contact_uuid": "6a903184-6a55-4b0e-9d4e-2f2f6f8f1c11",
  "revision": 3,
  "updated_at": "2026-08-30T11:58:00Z",
  "email": "person@example.org",
  "first_name": "Pat",
  "last_name": "Example",
  "display_name": "Pat Example",
  "state": "active",
  "email_news_state": "subscribed",
  "sources": ["subscribe", "ticket_tailor"],
  "community": {
    "eligible": false,
    "opt_in": true,
    "invited": null,
    "admitted": false,
    "membership_tier": null
  }
}
```

Enumerations and semantics:

| Field | Values | Notes |
|---|---|---|
| `state` | `active` \| `suppressed` \| `deleted` | `suppressed` = unsubscribed or on a CRM suppression list. `deleted` = tombstone (see below). |
| `email_news_state` | `subscribed` \| `pending_confirmation` \| `not_subscribed` \| `unsubscribed` \| `unknown` | The email-news preference as one enum: CRM status plus frequency/digest flags. `unknown` = the CRM was unreadable or used an unrecognised status. |
| `email`, `first_name`, `last_name`, `display_name` | string \| `null` | `null` = not held, or CRM unreadable. |
| `sources` | string[] | CRM provenance values (e.g. `subscribe`, `ticket_tailor`, `wp_user`, `import`). Empty = none recorded. |
| `community.*` | `true` \| `false` \| `null` | From operator-set CRM markers only (see §6). `false` = markers readable and absent; `null` = unreadable/not configured. **Never derived from tickets.** |

A tombstone row (in the snapshot, the single-contact route and change
projections):

```json
{
  "contact_uuid": "…",
  "revision": 4,
  "updated_at": "2026-08-30T12:01:00Z",
  "state": "deleted",
  "deleted_at": "2026-08-30T12:01:00Z"
}
```

Page envelope: `{ "contacts": [...], "next_cursor": "...",
"has_more": true, "journal_head": 5321 }`.

## 4. `GET /hub/changes` — incremental changes and deletions

`GET /hub/changes?cursor=...&limit=...`. Without a cursor the stream
starts from the beginning (a full replay).

```json
{
  "changes": [
    {
      "change_id": 5320,
      "contact_uuid": "6a903184-…",
      "object_type": "contact",
      "op": "upsert",
      "revision": 3,
      "changed_at": "2026-08-30T11:58:03Z",
      "projection": { "…": "the CURRENT approved projection (see §3)" },
      "tombstone": null
    },
    {
      "change_id": 5321,
      "contact_uuid": "0f11c2aa-…",
      "object_type": "contact",
      "op": "delete",
      "revision": 5,
      "changed_at": "2026-08-30T12:01:00Z",
      "projection": null,
      "tombstone": { "deleted_at": "2026-08-30T12:01:00Z" }
    }
  ],
  "next_cursor": "…",
  "has_more": false,
  "journal_head": 5321
}
```

The cursor is a position in an append-only journal whose sequence is
assigned at write time — not a timestamp — so equal timestamps, clock
skew and concurrent writes cannot hide changes behind a cursor, and
replaying any cursor returns the same logical result with no mutation.
`projection` is the contact's **current** state (possibly newer than this
change; consumers reconcile on `revision`). Consumers should treat
upserts as idempotent puts and deletes as terminal for that UUID.

Change detection semantics: mutations are observed by the CRM's own
lifecycle hooks (immediate) plus a background sweep (every 5 minutes,
bounded batches) that re-projects contacts and journals only real
projection changes (canonical-hash comparison). Worst-case detection lag
for changes that leave no timestamp in the CRM (e.g. a tag removal) is
one reconcile cycle over the registry — see the runbook for sizing.

## 5. `GET /hub/ticket-catalogue`

`GET /hub/ticket-catalogue?limit=100&cursor=...` — the durable Ticket
Tailor facts the CRM holds (allocated-ticket records written by the CRM's
Ticket Tailor webhook/import). The CRM remains the sole Ticket Tailor
connector: this layer never calls the Ticket Tailor API and never holds a
Ticket Tailor credential.

```json
{
  "tickets": [
    {
      "id": "bo:main-box-office:order:or_900:ticket:it_123",
      "box_office": { "id": "main-box-office", "label": "Main Box Office" },
      "event": { "id": null, "label": "Email Expert Live 2026" },
      "ticket_type": { "id": null, "label": "General Admission" },
      "order_id": "or_900",
      "ticket_ref": "it_123",
      "state": "valid",
      "state_raw": "valid",
      "contact_uuid": "6a903184-…",
      "updated_at": "2026-08-01T10:00:00Z",
      "revision": 3
    }
  ],
  "next_cursor": "…",
  "has_more": false
}
```

- Every identity is **scoped by box office** (`bo:<box>:order:<id>:…`),
  so identical external ids from different Ticket Tailor accounts never
  collide.
- `state`: `valid` | `cancelled` | `refunded` | `transferred` |
  `unknown` | `other` (mapped from the stored snapshot; `state_raw`
  carries the verbatim stored status). **These are snapshots at receipt
  time** — the CRM does not currently reconcile later cancellations, so
  treat `state` as "state when last recorded" (capabilities:
  `ticket_validity: false`).
- `event.id`/`ticket_type.id` are `null` because the CRM's durable
  records store labels, not Ticket Tailor event ids (documented CRM-side
  improvement in the runbook).
- No payment, billing, buyer-identity or order-amount data exists in this
  contract.

Known population limits (inherited from what the CRM stores durably,
documented rather than papered over): entries cover tickets *allocated to
attendees*; a buyer with no stored allocation appears via
`eligibility.has_ticket` (tag-derived) rather than the catalogue.

## 6. `GET /hub/eligibility?contact_uuid=...`

Separate facts, deliberately not collapsed, and **never inferred from
each other** — a booking is not membership:

```json
{
  "contact_uuid": "6a903184-…",
  "state": "active",
  "facts": {
    "has_ticket": true,
    "ticket_currently_valid": null,
    "community_eligible": false,
    "community_opt_in": true,
    "community_invited": null,
    "community_admitted": false
  },
  "membership_tier": null,
  "as_of": "2026-08-30T12:00:00Z"
}
```

- `has_ticket`: the person bought or received a ticket (durable CRM
  facts: allocation records or the CRM's `ticket-buyer` tag). `false` =
  records readable and absent; `null` = unreadable.
- `ticket_currently_valid`: **always `null` in contract v1.0.0** — see
  §5. The Hub must not treat `has_ticket` as validity.
- `community_*`: read exclusively from operator-controlled CRM markers —
  by default the tags `community-eligible`, `community-opt-in`,
  `community-invited`, `community-admitted` and the custom field
  `_membership_tier` (all configurable). The CRM can answer
  authoritatively instead via the `eex_hub_community_facts` filter once
  it grows first-class fields. Nothing sets these markers automatically;
  in particular, no ticket or registration ever does.

For a `deleted` contact all facts are `null`.

## Explicitly out of contract

No writes of any kind, no contact editing, no ticket/order mutation, no
field selection, no free-text contact search, no email lookup, no
webhooks, no realtime push, no financial data, no Ticket Tailor
credentials, no WordPress admin surface. Additions require a contract
version bump and separate review.
