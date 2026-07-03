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
