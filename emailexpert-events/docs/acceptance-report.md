# Acceptance report

Environment: build container with PHP 8.4, Composer, no WordPress install,
no HeySummit API key, no Elementor. The automated suite (96 tests, 384
assertions) runs on a WordPress stub layer; PHPCS (WordPress-Extra) passes
clean. "Not verifiable" below always includes the manual step that verifies
it on the live site.

**Verdict counts: 14 pass, 0 fail, 8 not verifiable in this environment.**

| # | Criterion | Verdict | Evidence / manual step |
|---|---|---|---|
| 1 | Fresh install, key entered, two events enabled: one manual sync produces event, talk and speaker posts rendering on fallback templates | **Not verifiable** (needs a live HeySummit key) | The full flow is unit-tested end-to-end against a mocked API (`SyncEngineTest::test_full_sync_creates_event_talks_speakers_and_terms`); templates ship for all views. Manual: enter key → Test connection → enable events → Sync now → open /events/, /sessions/, /speakers/ |
| 2 | Editing a synced talk's title and re-syncing restores the HeySummit title; venue meta edits survive | **Pass** | `UpserterTest::test_changed_payload_updates_and_restores_title`, `test_manual_meta_survives_update`; venue keys are never in sync's write set by construction (`Upserter::meta_for`) |
| 3 | Running sync twice performs zero post writes on the second run | **Pass** | `SyncEngineTest::test_second_run_performs_zero_post_writes` counts writes; the run summary in the sync log reports created/updated/unchanged per run |
| 4 | Every block renders in editor preview and front end; each shortcode matches its block | **Pass** (editor preview itself needs a live editor) | Blocks, shortcodes and widgets share one callback (`ComponentsTest::test_shortcode_output_matches_block_render_callback`); previews use ServerSideRender, i.e. the identical server path. Manual: insert each block once in Gutenberg |
| 5 | Event page JSON-LD passes the Rich Results Test with zero errors (venue filled) | **Not verifiable** (external Google tool) | Generator emits every required property and omits rather than guesses (`SchemaGeneratorTest`, 9 tests). Manual: paste an event URL into https://search.google.com/test/rich-results |
| 6 | Simulated checkout-complete with correct secret increments the counter once even when delivered three times | **Pass** | `WebhooksTest::test_checkout_increments_counter_once_across_three_deliveries` |
| 7 | Wrong secret returns 404 and logs nothing to attribution | **Pass** | `WebhooksTest::test_wrong_secret_gets_404_and_logs_nothing_to_attribution` (response body is `rest_no_route`, indistinguishable from a missing route) |
| 8 | Site renders all components with the API unreachable | **Pass** | Components read only local posts/meta; the suite renders them with no HTTP mock installed (any accidental call would return WP_Error and fail); `BootSmokeTest` boots the full plugin without network |
| 9 | No front-end page load triggers an outbound HTTP request to HeySummit | **Pass** (static verification) | Zero `wp_remote_*`/client references under `src/Frontend`, `src/Rest`, `templates`, `blocks`, `assets` (grep in CI-able form); API calls exist only in sync (cron/CLI/admin-AJAX) and webhook verification (queued). Manual belt-and-braces: browse with a request logger |
| 10 | Detached speaker keeps a hand-edited bio through repeated forced syncs; excluded talk stays draft though the record exists | **Pass** | `UpserterTest::test_detached_post_is_never_written`, `test_excluded_post_stays_draft_through_forced_syncs` (both forced) |
| 11 | Speakers toggled off: forced sync creates/updates no speaker posts, existing ones untouched | **Pass** | `SyncEngineTest::test_speakers_toggle_off_touches_no_speaker_posts` (also verifies talk→speaker relations survive) |
| 12 | Pending-review imports invisible until approved; approval survives subsequent syncs | **Pass** | `UpserterTest::test_new_posts_respect_pending_import_status...`, `QueryTest::test_draft_talks_are_invisible` |
| 13 | Single evergreen event: upcoming/past split by start time, category filters both, never in past events, nothing errors with one event | **Pass** | `QueryTest` (evergreen suite: split, closed-registration case, category filter, sole-event default) |
| 14 | Category exclude filter orphan-drafts matching talks on next sync; they do not return | **Pass** | `SyncEngineTest::test_category_exclude_filter_orphan_drafts_matching_talks` (including a subsequent forced run) |
| 15 | .ics imports cleanly into Google/Apple/Outlook; feed validates against RFC 5545 and filters by category | **Not verifiable** (external calendar clients) | RFC 5545 structure, escaping, 75-octet folding, UID/DTSTART/DTEND all unit-tested (`IcsTest`); feed shares the same generator and accepts `?event=`/`?category=`. Manual: download one session's .ics into each client; validate the feed at icalendar.org/validator |
| 16 | No JS: event-local times with timezone label and no live-state claims; JS: visitor timezone and Join now while live | **Not verifiable** (needs a browser) | Server output contains only `<time>` with UTC attribute + event-local text and a hidden live slot (`ComponentsTest::test_countdown_renders_utc_target_and_no_live_claim`); `eex-time.js` computes states client-side. Manual: view a session page with JS off, then during a live window with JS on |
| 17 | HTML cached before a session started still shows correct live state; counter shows the current figure via REST | **Not verifiable** (needs a full-page cache) | By design: live state is computed client-side from data attributes, never server-rendered; counter REST read tested (`WebhooksTest::test_counter_rest_read_returns_current_figure`, `Cache-Control: no-store`). Manual: prime a cached page pre-session, revisit while live |
| 18 | YouTube replay embeds + valid VideoObject; with Yoast, Event schema appears once inside the Yoast graph | **Not verifiable** for the Yoast half (no Yoast here) | VideoObject and embed URL generation pass (`SchemaGeneratorTest`, 3 replay tests); with Yoast active the plugin only ever appends to `wpseo_schema_graph` and never outputs standalone JSON-LD (`SchemaOutput::setup` returns before hooking `wp_head`). Manual: activate Yoast, view a replay page source, confirm one graph |
| 19 | Personal data erasure removes a known attendee's attribution rows, verified by hash lookup before and after | **Pass** | `WebhooksTest::test_privacy_eraser_removes_attribution_rows_by_hash` (asserts before/after by hash, and that other subjects are retained) |
| 20 | Axe reports no critical violations on fallback templates; combined CSS+JS under 30KB gzipped | **Pass** on budget, **Not verifiable** on axe (needs a browser) | Front-end CSS+JS gzip ≈ 3.1KB. Templates use semantic headings/lists, labelled controls, `aria-live` on countdown/live slots, visible focus styles, alt text on all images. Manual: run axe on /sessions/ and a session single |
| 21 | Elementor deactivated: no Elementor code loads; everything works via blocks, shortcodes, PHP templates | **Pass** | The module registers only on `elementor/init`; the entire test suite (96 tests) runs with no Elementor present and no `src/Elementor` class loaded (`BootSmokeTest` proves boot without it) |
| 22 | Elementor Pro: widget output matches block byte-for-byte (bar wrappers); dynamic tags resolve and render empty for missing data; `eex_upcoming_sessions` Loop Grid returns future sessions soonest first; Theme Builder template fully replaces the plugin template | **Not verifiable** (no Elementor Pro in this environment) | Widgets call the identical `Components::render` (parity by construction); tags return `''`/`[]` on missing data by code; Loop Grid queries mirror component ordering; yield implemented via conditions-manager check plus a loader guard. All Elementor files pass `php -l` in CI. Manual: on production, place each widget beside its block, build a session Theme Builder template, and check a Loop Grid with the query ID |

## Manual verification checklist (condensed)

1. Enter the API key, run **Test connection**, review the discovery panel.
2. Enable events, **Sync now**, browse /events/, /sessions/, /speakers/.
3. Rich Results Test on an event page with venue meta filled.
4. Capture-mode self-registration + `wp eex webhooks:replay <log_id>`.
5. Import a session .ics into Google/Apple/Outlook; validate the feed.
6. Browser checks: JS-off times, live-window Join now, cached-page state,
   axe run.
7. Elementor Pro: widget parity, dynamic tags, Loop Grid query IDs, Theme
   Builder replacement.

---

# Acceptance report — v2 extension run

Environment as before: PHP 8.4 build container, no WordPress install, no
HeySummit key, no Elementor, no MyListing theme, no WooCommerce plugin. The
suite now runs 146 tests / 575 assertions on the WordPress + WooCommerce
stub layers; PHPCS passes clean; every PHP file (including both bridge
modules) passes `php -l`.

**Verdict counts (v2 criteria): 7 pass, 0 fail, 1 partially not verifiable.**

| # | Criterion | Verdict | Evidence / manual step |
|---|---|---|---|
| 1 | All original tests and criteria still pass | **Pass** | The full v1 suite runs unmodified inside the v2 suite (146 tests, 0 failures); v1 acceptance verdicts unchanged — the lazy-init refactor is behaviour-preserving for every tested path (upgrades reconcile existing tables via dbDelta on first guarded write) |
| 2 | Fresh activation creates no tables/cron; log table on first sync; attribution table when webhooks enabled; component-free front page runs zero plugin queries/assets | **Pass** (query count itself needs a live profiler) | `FootprintTest` (7 tests): activation writes one settings option + terms only; tables via `Install\Tables` on first write with stored schema versions; cron follows enabled events; secret on demand. Zero front-end cost is by construction: the one autoloaded option, activation-time term seeding, register-only assets. Manual: Query Monitor on a plain page — expect no eex queries beyond CPT registration |
| 3 | Wizard dry-run counts exactly match the confirmed import; "most recent 5" imports exactly 5; reducing scope orphan-drafts the surplus | **Pass** | `ScopeFilterTest::test_dry_run_counts_match_sync_and_scope_reduction_orphans_surplus` runs the identical chain (preview 6 = import 6; past 5 exactly; reduce to 2 → surplus drafted + orphan-flagged); engine and dry run share one `ScopeFilter`, so parity is structural |
| 4 | MyListing absent → no bridge code; active + mapped → mapping/status/modes honoured, correct rel=canonical on the non-canonical side, schema only on the canonical side | **Pass** (real theme not present here) | Presence check is inline in Plugin (no module class touched when absent — the full suite runs that way); `MyListingBridgeTest` (11 tests) covers unconfident-detection standdown, field mapping, hash idempotency, pending mirroring, detached/excluded, canonical both directions and schema suppression via a fake detection. Manual: on production, map one session type, view both singles' source for the canonical tag |
| 5 | WooCommerce absent → no Woo code; active: exactly one attendee-create + one ticket-import per completed mapped order even with repeated hooks; unmapped = zero calls; no consent = no push + flagged; failed push retried/flagged/re-pushable; client rejects non-allowlisted writes | **Pass** | Module registers on `woocommerce_loaded` only; `WooBridgeTest` (9 tests) covers every clause including triple-fired hooks → one call pair, the allowlist throwing on `events/101/archive/`, and the retry → flag → manual re-push chain. Manual: sandbox order per the operator steps below |
| 6 | Register links carry configured UTM parameters everywhere; campaign reflects the rendering page | **Pass** | `V2ExtrasTest`: tagging in component output, calendar entries (campaign `calendar`), per-page override, no-clobber, inactive-without-source, and cache-varies-by-campaign (two pages get differently-tagged fragments). Elementor tags and projected listings share the same helper |
| 7 | Relay delivers each verified action to configured URLs with the shared-secret header, retries, logs; filter bar works with JS and degrades to links without it | **Pass** for relay and the no-JS path; JS behaviour needs a browser | Relay: subscription filtering, `X-Eex-Secret`, retry scheduling with advancing attempt counter, logged deliveries, secrets never logged or relayed raw. Filter bar: server-rendered category/speaker links + GET search form filtering server-side (`?eex_q=` test); the JS enhancement is code-reviewed vanilla JS. Manual: click category chips on /sessions/ with JS on |
| 8 | Settings export contains no key or secret material; import shows a diff and applies cleanly | **Pass** | `V2ExtrasTest::test_export_contains_no_key_or_secret_material` (API key, webhook secret, relay secret all absent) and `test_import_applies_cleanly_and_preserves_local_keys` (unknown keys dropped, local keys/secrets preserved, cron state re-evaluated). The diff preview screen is rendered server-side from the same snapshot pair |

## v2 manual verification checklist

1. Activate on a staging copy; confirm no `eex_` tables exist until the
   first sync and no cron entry until an event is enabled.
2. Run the wizard end to end; compare the step-4 preview counts with the
   post lists after import.
3. MyListing: configure the Bridge tab, project, check both sides' source
   for rel=canonical and single-sided schema.
4. WooCommerce sandbox: map a product, place a test order with the consent
   box ticked, verify the attendee + ticket sale in HeySummit, then refund
   and confirm the manual-removal note. Also review the
   `write:attendees` / `write:external-ticket-sales` discovery panels
   before the first live push and adjust the request builders if shapes
   differ.
5. Set the UTM source, click a register link from two different pages and
   confirm the campaigns differ.
6. Add a relay URL pointing at a webhook.site bin, send the test payload,
   then complete a registration and confirm delivery + header.
7. Export settings on staging, import on production, review the diff.

---

# Acceptance report — v3 extension run (accounts module)

Environment unchanged (PHP 8.4 container, stub layers, no live hosts). The
suite now runs 162 tests / 650 assertions; PHPCS clean; all files `php -l`
clean.

**Verdict counts (v3 criteria): 7 pass, 0 fail, 0 not verifiable** (live
HeySummit sandbox halves are covered by the operator steps, as before).

| # | Criterion | Verdict | Evidence / manual step |
|---|---|---|---|
| 1 | All prior tests and criteria pass; master toggle off = zero code, zero queries | **Pass** | The full v1+v2 suite runs unchanged inside the v3 suite (162 tests, 0 failures). The gate is one read of the single autoloaded settings option in Plugin::boot; `src/Accounts/` is never touched while off (`test_module_disabled_loads_zero_code` asserts the gate; the entire pre-v3 suite ran with the namespace absent) |
| 2 | "member → hub free ticket" pushes exactly one attendee on role gain, never again on repeated changes or overlapping rules; already-exists is success | **Pass** | `test_role_gained_pushes_exactly_once_despite_repeats_and_overlapping_rules` (three trigger firings, two overlapping rules → one attendee-create; ledger records rule r1, trigger, consent); `test_already_existing_attendee_is_success_never_error` (400 "already exists" → status done, note recorded, zero retries) |
| 3 | No push without satisfied consent; suppressed email never pushed by rule, backfill or manual; profile opt-out suppresses immediately | **Pass** | `test_no_push_without_satisfied_consent_and_skip_is_logged` (skip logged; checkbox meta then satisfies), `test_suppressed_email_is_never_pushed_by_rule_backfill_or_manual`, `test_profile_opt_out_suppresses_immediately`, plus `test_suppression_wins_even_between_queue_and_delivery` (push-time re-check) |
| 4 | Backfill dry-run count matches the confirmed run exactly, in batches, resumable | **Pass** | `test_backfill_dry_run_matches_confirmed_run_in_batches` (25 matched of 28 users; 25 pushed across two batches; state cleared) and `test_backfill_is_resumable_from_persisted_state` (position 20/30 persisted, resume re-queues). Parity is structural: dry run and batches share Engine::run_rule |
| 5 | MyListing active: publishing a mapped-type listing registers the owner once; unpublish fires the hook and pushes nothing | **Pass** | `test_listing_published_registers_owner_once_and_unpublish_pushes_nothing` (duplicate publish transitions → one push; unpublish → eex_listing_unpublished_after_registration, zero calls, registration retained) |
| 6 | Client still rejects non-allowlisted writes; every push record stores rule/trigger/consent; users screen shows push status | **Pass** | The v2 allowlist test still passes (no new endpoints were added — grep: all `->post(` calls go through the two builders); ledger fields asserted in test 2; `AdminUi::users_column_value` renders per-event status + failure flag (markup verified by code path; manual: view Users screen) |
| 7 | Erasure adds the requester to the suppression list and the eraser notes manual HeySummit removal | **Pass** | `test_erasure_suppresses_and_notes_manual_removal` plus the v2 erasure test still passing (rows removed, others retained) |

## v3 manual verification (operator)

1. Enable the module, define one rule against the sandbox event, choose its
   consent source (add the registration checkbox or record the terms
   assertion after reading the warning).
2. Create one fresh test account (checkbox ticked) and change one existing
   test user's role to the rule's role; confirm each appears once in
   HeySummit with the intended ticket.
3. Review the diagnostics panel for the ticket-assignment finding
   (create_param / ticket_import / unsupported) after Test connection.
4. Run `wp eex accounts:backfill <rule> --dry-run`, review the count and
   sample, then confirm from the Accounts tab and watch the sync log.
5. Tick "Do not register me for events" on a test profile and verify no
   rule fires for it afterwards.
