# Contributing

Thanks for looking! This is BETA software under active development —
issues, questions and pull requests are welcome, with the caveats below.

## Getting set up

```bash
git clone <this repo> wp-content/plugins/emailexpert-events
cd emailexpert-events
composer install          # dev tools only; the shipped plugin has no runtime dependencies
vendor/bin/phpunit        # unit suite (WordPress stub layer — no Docker or database needed)
vendor/bin/phpcs          # WordPress Coding Standards
npx wp-env start          # optional: a full WordPress for manual testing
```

The test suite runs in under a second against a WordPress stub layer
(`tests/wp-stubs.php`); please keep it that way — no network, no database,
no sleeps in tests.

## Ground rules for changes

These are load-bearing decisions, not preferences (each has history in
`docs/decisions.md`):

1. **The write allowlist is sacred.** `Api\WriteEndpoints::ALLOWLIST`
   contains exactly two endpoints. PRs that widen it need extraordinary
   justification.
2. **Consent is a hard rule.** Nothing pushes a person to HeySummit
   without a satisfied consent source, and suppression is re-checked at
   delivery time.
3. **No browser ever calls HeySummit** and the API key appears nowhere in
   output or logs (last-4 only). Email addresses never reach the log.
4. **Never invent URLs the platform hasn't documented.** Three releases
   broke this way. If HeySummit doesn't document a path, we don't link it.
5. **Markup-affecting options are component attributes** (they auto-key
   the fragment cache and auto-surface in the block editor and Elementor);
   style-only options are CSS custom properties.
6. **Both modes always work.** Every change must behave with no API key,
   no Elementor, no WooCommerce, and the API unreachable. Lite mode is a
   first-class citizen, not a demo.
7. All output escaped; British English in UI copy; WPCS clean
   (`vendor/bin/phpcbf` then `vendor/bin/phpcs` must exit 0).

## Pull requests

- One coherent change per PR, with tests. The suite must stay green.
- Bump `EEX_VERSION` (both the plugin header and the constant) — the
  update self-flush depends on it.
- Add a line to `CHANGELOG.md`; significant design decisions get an entry
  in `docs/decisions.md`.
- CI runs the suite and coding standards on PHP 8.1 and 8.3.

## Reporting bugs

Open an issue with your WordPress and PHP versions, the operating mode
(Lite/Full), and — if it's an API-shape problem — the relevant section of
the discovery diagnostic (Settings → emailexpert Events → Test
connection), which never contains live values.

Security problems: **do not open an issue** — see [SECURITY.md](SECURITY.md).
