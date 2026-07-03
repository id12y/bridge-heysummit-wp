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
