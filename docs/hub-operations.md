# Community Hub API — operations runbook

Companion to [hub-api.md](hub-api.md) (contract) and
[hub-openapi.yaml](hub-openapi.yaml). Covers: what existed vs. what was
added, every file and table touched, credential lifecycle, the UUID
backfill, production rollout with smoke tests and rollback, monitoring,
and the verification evidence.

---

## 1. Audit: what already existed, what was genuinely missing

Audited read-only across both plugins (this repo and the CRM,
`id12y/newsletter`) before any change. Production (`emailexpert.com`)
was **not reachable from the build environment** (network policy), so
the deployed-version check is a deployment-day step (§6, step 0) rather
than something asserted here.

Existed and is reused, not duplicated:

- **The CRM's contact store and decryption** — `wp_een_subscribers` plus
  `EEN_Subscriber_Repository` (email decryption stays entirely in the
  CRM's classes; this layer never reimplements its cryptography).
- **The CRM's durable Ticket Tailor facts** — `_tt_allocated_tickets`
  custom-field records and the `ticket-buyer`/`event:*`/`ticket:*` tags
  written by the CRM's webhook/import. The CRM remains the sole Ticket
  Tailor connector; this layer never calls the TT API.
- **The CRM's lifecycle hooks** (`een_after_purge_subscriber`,
  `een_subscriber_created/confirmed/unsubscribed/resubscribed`) as
  immediacy taps for the change feed.
- **This plugin's crypto and REST conventions** — `Support\Crypto`
  (purpose-keyed HMAC token hashing, from-salts key derivation), the
  service/module boot pattern, the logger (which redacts email
  addresses), the WP-CLI surface.

Genuinely missing everywhere (confirmed by exhaustive route/schema
inventory of both plugins):

- Any opaque immutable contact identifier (only sequential row ids and
  two salt/key-dependent email hashes existed).
- Tombstones: the CRM's GDPR purge is a transactional hard delete; no
  id-addressable trace survives.
- A monotonic revision or any replay-safe change feed (`updated_at` is
  second-resolution and bypassed by several CRM writers; tag removals
  stamp nothing).
- Any server-to-server read credential (every CRM contact route is
  admin-cookie `manage_options`; Application Passwords authenticate as a
  full WP user).
- A capabilities/health endpoint safe for an external consumer.

Hence the additive layer below — and nothing else.

## 2. Exact changes

New module (all new files, namespace `Emailexpert\Events\Hub`):

| File | Role |
|---|---|
| `src/Hub/Module.php` | Boot (only when `hub_enabled`), cron schedule, CRM hook taps |
| `src/Hub/Settings.php` | Feature switch + tuning option `eex_hub_settings` |
| `src/Hub/Schema.php` | The three Hub tables (below), explicit-context dbDelta |
| `src/Hub/Crm.php` | Guarded read-only adapter over the CRM's public classes/tables |
| `src/Hub/Projection.php` | The fixed allowlisted projection + canonical hash + tri-state facts |
| `src/Hub/Registry.php` | UUID registry, revisions, tombstones |
| `src/Hub/Journal.php` | Append-only change journal (the changes cursor) |
| `src/Hub/Credentials.php` | Hash-stored, revocable bearer credentials |
| `src/Hub/Cursor.php` | Opaque signed endpoint-typed cursors |
| `src/Hub/Auth.php` | The default-deny permission callback (every route) |
| `src/Hub/RestController.php` | The six GET routes under `emailexpert-crm/v1` |
| `src/Hub/Sweeper.php` | Enrol/update/reconcile sweep = change capture + backfill |
| `src/Hub/Cli.php` | `wp eex hub *` operator commands |

Touched existing files (all additive):

- `src/Plugin.php` — boot the Hub module when `hub_enabled` (default 0).
- `src/Options.php` — the `hub_enabled` default (off).
- `src/Support/Crypto.php` — new public `mac()` helper (cursor signing).
- `src/Logging/Logger.php` — new `CONTEXT_HUB` constant.
- `src/Cli/Commands.php` — registers the Hub CLI.
- `src/Install/Cron.php`, `uninstall.php` — the sweep hook and Hub
  tables in the cleanup inventories.
- `emailexpert-events.php`, `CHANGELOG.md`, `docs/decisions.md` — version
  1.59.0 bookkeeping.
- Three pre-existing lint fixes so `phpcs` exits 0 again
  (`src/Frontend/Compositions/HomepageHero.php` `absint()` wrap,
  `templates/parts/hero-news-item.php` embedded-PHP formatting with
  byte-equivalent rendering, `src/Admin/PresentationMetaBox.php`
  annotated bounded admin query).
- Test infrastructure: `tests/wp-stubs.php` additions (guarded),
  `tests/hub-crm-stubs.php`, `tests/HubTestCase.php`, four
  `tests/Unit/Hub*Test.php` files (33 tests).

Database structures — three new tables, created ONLY by
`wp eex hub enable` (never during a front-end request), owned entirely
by this layer; **no CRM or WordPress table is altered**:

- `wp_eex_hub_contacts` — id, subscriber_id (NULL after deletion), uuid
  (unique), revision, projection_hash, state, created/updated/deleted_at.
  Contains no personal data.
- `wp_eex_hub_journal` — id (the change seq), uuid, object_type, op,
  revision, created_at. Append-only, no personal data.
- `wp_eex_hub_credentials` — label, token_hash (HMAC), token_hint (last
  4), status, timestamps. Never a raw token.

New options: `eex_hub_settings`, `eex_hub_schema_versions`,
`eex_hub_sweep_state`, `eex_hub_journal_head` (none autoloaded), plus the
`hub_enabled` key inside the existing autoloaded settings. New cron:
`eex_hub_sweep` every 5 minutes while enabled.

## 3. Credential lifecycle

```bash
wp eex hub token-create --label=community-hub   # prints the token ONCE
wp eex hub token-list                            # labels, last-4 hints, status — never tokens
wp eex hub token-revoke <id>                     # immediate
```

- Store the token only in the Hub application's secret manager. It is a
  dedicated service credential: not a WordPress user, no WP capabilities,
  valid only for the six Hub GET routes.
- **Rotation** (no downtime): `token-create` a second credential → switch
  the Hub to it → verify with `/hub/capabilities` → `token-revoke` the
  old id. Multiple active credentials are supported precisely for this.
- **Compromise response**: `wp eex hub token-revoke <id>` (that
  credential dies instantly), or `wp eex hub disable` (every Hub route
   404s instantly; sweep stops; data and credentials keep).
- Optional hardening once Hub egress IPs are stable:
  `wp option patch update eex_hub_settings ip_allowlist '["203.0.113.7"]'`
  — additional to the token, never instead of it.

## 4. UUID backfill and migration plan

There is no in-request migration. Enrolment is the sweep's first pass,
run deliberately from the CLI:

```bash
wp eex hub backfill --batch=200
```

- Batched (`--batch` ≤ 500), one short read + short writes per contact;
  no long-running transaction, no table locks on CRM tables (reads
  only), safe on InnoDB under production load.
- Resumable and idempotent: state lives in `eex_hub_sweep_state`
  (`last_enrolled_id` floor); interrupt and re-run at will; an enrolled
  contact is never re-enrolled and an unchanged contact journals
  nothing.
- Progress: printed per batch, and visible any time via
  `wp eex hub status` / `GET /hub/capabilities` (`backfill.enrolled` vs
  `estimated_total`).
- Failure recovery: a crashed batch simply re-runs; canonical-hash
  comparison makes duplicates impossible.
- Rollback of the backfill alone: `wp eex hub disable` then
  `DROP TABLE wp_eex_hub_contacts, wp_eex_hub_journal;` — the CRM is
  untouched by design, so there is nothing else to undo. (Dropping the
  registry discards issued UUIDs — only do this before the Hub has
  consumed them; afterwards, prefer leaving the tables in place.)

## 5. Change-feed operation and sizing

- The 5-minute sweep processes bounded batches (defaults: 200 enrol +
  200 updated + 50 reconcile). New/updated contacts (anything that bumps
  a CRM timestamp, plus everything the lifecycle hooks announce) surface
  within one sweep.
- Changes that leave **no** CRM timestamp (tag removal) are caught by
  the cyclic reconcile walk: worst case `registry_rows / 50` sweeps
  (≈ 7 hours per 4,000 contacts). If that lag matters, raise the
  reconcile budget via a small mu-plugin calling
  `( new Sweeper() )->run( 200, 200, <bigger> )` from its own schedule,
  or run `wp eex hub sweep` from system cron.
- The journal is append-only and tiny (~100 bytes/row, no personal
  data); no pruning is needed or performed in v1.

## 6. Production rollout

Step 0 — **deployed-version check** (this repo may be ahead of
production): on the server, `wp plugin get emailexpert-events --field=version`
and `diff -rq` the deployed plugin directory against this tag, and
confirm `wp plugin get emailexpert-newsletter --field=version` is a
build whose classes match §1 (any 5.5+ EEN with
`EEN_Subscriber_Repository::find_by_id` and `EEN_Table_Registry` is
sufficient; every accessor degrades to `null` facts if not). Preserve
any production-only edits before deploying.

Step 1 — **backup + rollback point** (before deploy and before enable):

```bash
wp db export pre-hub-$(date +%F).sql          # full DB backup
tar -czf plugin-pre-hub.tgz wp-content/plugins/emailexpert-events
```

Step 2 — deploy the 1.59.0 plugin code. This alone changes nothing:
the switch is off, no routes exist, no tables are created, no cron runs.
Verify: `curl -s https://<site>/wp-json/emailexpert-crm/v1/hub/capabilities`
→ WordPress's standard 404; existing `eex/v1` routes unchanged.

Step 3 — enable and backfill (still zero external exposure until a
token exists):

```bash
wp eex hub enable
wp eex hub backfill
wp eex hub status
```

Step 4 — credential + **smallest-possible smoke test** (page size 1):

```bash
wp eex hub token-create --label=community-hub
curl -s -H "Authorization: Bearer $TOKEN" "https://<site>/wp-json/emailexpert-crm/v1/hub/capabilities"
curl -s -H "Authorization: Bearer $TOKEN" "https://<site>/wp-json/emailexpert-crm/v1/hub/contacts?limit=1"
curl -s "https://<site>/wp-json/emailexpert-crm/v1/hub/contacts?limit=1"      # → 401
curl -s -X POST -H "Authorization: Bearer $TOKEN" "https://<site>/wp-json/emailexpert-crm/v1/hub/contacts"  # → 404/405 method rejection
```

Also confirm with an ordinary admin session that wp-admin, the CRM
plugin, sending, and the Ticket Tailor webhook
(`…/emailexpert-newsletter/v1/ticket-tailor/webhook`) behave normally.

Step 5 — hand the Hub team the base URL + token (via secret manager) and
[hub-api.md](hub-api.md). Their first integration should follow the
snapshot-then-changes procedure in the contract.

**Monitoring**: `wp eex hub status` (or `/hub/capabilities` from the Hub
side) for health/backfill/journal head; the `eex_log` table context
`hub` holds one redacted line per request (request id, route, status,
ms, credential id — no tokens, no personal data; 30-day retention) —
`wp db query "SELECT created_at, message, data FROM wp_eex_log WHERE context='hub' ORDER BY id DESC LIMIT 20"`.
Alert-worthy: `health: degraded` (CRM unavailable), a stalled
`journal_head` while CRM data is changing, or a burst of 401s (someone
probing).

**Rollback** (any time, in escalating order):

1. `wp eex hub token-revoke <id>` — cut one consumer.
2. `wp eex hub disable` — all Hub routes 404, sweep stops; nothing else
   in the plugin changes. This is the complete functional rollback.
3. Deploy the previous plugin build (the Hub tables are ignored by it
   and harmless to leave; drop them only if desired:
   `DROP TABLE IF EXISTS wp_eex_hub_contacts, wp_eex_hub_journal, wp_eex_hub_credentials;`).
4. Full restore from the Step-1 backup (should never be needed — no
   existing table was altered).

**Not done and not to be improvised**: no production load testing; no
schema work in web requests; there is no staging environment on record —
if one exists, run Steps 2–4 there first, otherwise the Step-4 smallest
read (limit=1) IS the bounded production verification.

## 7. Verification evidence

Automated: `vendor/bin/phpunit` — **511 tests, 2409 assertions, 0
failures** (478 pre-existing + 33 new Hub tests), and `vendor/bin/phpcs`
exit 0 (WordPress-Extra), matching CI (PHP 8.1/8.3). Mapping of the
required proofs to tests (all in `tests/Unit/Hub*.php`):

| Requirement | Proof |
|---|---|
| Unauthenticated requests rejected | `HubAuthTest::test_unauthenticated_requests_are_rejected` (401, no credential context) |
| Invalid and revoked credentials rejected | `…invalid_token…`, `…revoked_token_is_rejected_immediately` |
| Credential reaches only Hub routes | Credentials are rows in `wp_eex_hub_credentials`, not WP users: WordPress core auth never sees them, so wp-admin/other REST/mutation routes are unreachable by construction; every Hub route requires `Auth::permit` (`HubRestTest::test_every_route_is_get_only_and_permission_guarded`) |
| POST/PUT/PATCH/DELETE rejected | Every route registers `methods: GET` only (same test); WordPress core returns its standard method rejection |
| GETs side-effect free | Cursor decode/journal/registry reads mutate nothing; replay tests prove identical results (`HubIdentityTest::test_journal_cursor_replay…`, `HubRestTest` replay assertions) |
| Only allowlisted fields returned | `HubRestTest::test_contacts_returns_only_the_allowlisted_projection` (exact key list; a planted `_internal_notes` field never appears) |
| Page-size limits enforced | `HubRestTest::test_snapshot_pagination…` (99999 → 100 clamp) |
| Cursor replay consistent | same test + changes-feed replay |
| Concurrent updates: no silent gaps | Change detection is canonical-hash truth (not timestamps): `HubIdentityTest::test_tag_removal_is_caught_by_reconciliation…` proves even timestamp-less mutations surface; journal seq ordering proves no cursor can skip a committed change |
| Deletions appear as minimal tombstones | `HubIdentityTest::test_a_deleted_contact_becomes_a_minimal_tombstone` (state, cleared subscriber id, no email anywhere), `HubRestTest::test_single_contact_lookup_and_tombstone_shape` |
| TT identities isolated per box office | `HubRestTest::test_ticket_catalogue_scopes_identities_by_box_office` (identical order/ticket ids under two box offices never collide) |
| Logs/errors free of credentials and personal data | Tokens stored as hashes only (`HubAuthTest::test_tokens_are_stored_only_as_hashes…`); access log carries ids/route/status/ms; `Logger` redacts emails by design; error bodies are generic (`HubRestTest` 400/404 assertions; capabilities secret-leak test plants a fake API key and asserts absence) |
| Large datasets safe | Every query is LIMIT-bounded; catalogue scan hard-capped at 500 registry rows/request; sweep budgets 200/200/50 per run; no unbounded fetch exists in the module |
| Existing behaviour unchanged | The other 478 tests pass untouched; with `hub_enabled=0` (the default) the module never loads (`Plugin.php` gate), so production behaviour is bit-identical until an operator opts in |

Manual redaction check: no code path prints a raw token after creation;
`token-list` shows `…last4`; the journal and registry contain no
personal data by schema.

**Confirmation**: nothing in this work wrote to any CRM record, Ticket
Tailor record, or existing setting — the build environment held clones
only, all CRM access in code is read-only by construction, and the test
suite runs against in-memory fakes.

## 8. Known limitations and CRM-side recommendations (non-blocking)

Documented honestly in the contract; each has a clean later fix in the
CRM plugin (separate repo, separate review — out of scope here):

1. **Ticket validity is unknowable from durable data** — the CRM's
   allocated-ticket records are append-only snapshots and tags are never
   removed on cancellation. Recommendation: handle `order.updated` /
   `issued_ticket.updated` by rewriting the stored entry status; then
   this layer can flip `features.ticket_validity` on with no contract
   change (`state` already carries the vocabulary).
2. **Box offices are label-scoped** — the CRM stores no Ticket Tailor
   box-office id, and labels are operator-mutable (a rename changes the
   derived `box_office.id`). Recommendation: store the TT box-office id
   alongside the label in `een_ticket_tailor_box_offices`.
3. **Buyer-only purchases** appear via tags (`has_ticket`), not the
   catalogue — the CRM stores per-attendee allocations only.
4. **Community facts default to operator-set markers** (tags
   `community-eligible|opt-in|invited|admitted`, field
   `_membership_tier`) because no first-class fields exist in the CRM.
   The `eex_hub_community_facts` filter is the seam for the CRM to
   answer authoritatively later. Until markers are set, these facts read
   `false` (readable, absent) — correct, and deliberately never inferred
   from tickets or registrations.
5. **CRM `update()` fires no hook**, so generic field edits surface via
   the timestamp sweep (≤ 1 cycle) rather than instantly. Adding a
   `een_subscriber_updated` action in `EEN_Subscriber_Repository::update()`
   would make capture immediate; correctness does not depend on it.
6. **Production inspection was impossible from the build environment**
   (egress policy) — hence §6 step 0 is mandatory before deploy.
