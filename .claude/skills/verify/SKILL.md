---
name: verify
description: Stand up a real WordPress with this plugin active and observe rendered components over HTTP. Use to verify front-end/plugin changes end-to-end (unit tests already cover logic; this drives the real surface).
---

# Verify emailexpert-events in a live WordPress

Unit tests (`vendor/bin/phpunit`) render components through WP stubs — fast,
but stubs differ from real WP (e.g. stub `esc_url_raw` is a passthrough;
real WP empties disallowed schemes). To observe the real surface, run a real
WordPress. No Docker needed (daemon is unavailable in remote sessions;
wordpress.org downloads are proxy-blocked — use composer/packagist instead).

## Recipe (~2 min, all in a scratch dir)

1. **WP core + SQLite driver via composer** (packagist works through the proxy):
   ```bash
   composer init -n --name=verify/wp --require="johnpbloch/wordpress-core:6.7.1" --require="aaemnnosttv/wp-sqlite-db:*"
   composer config allow-plugins.johnpbloch/wordpress-core-installer true
   composer config allow-plugins.composer/installers true
   COMPOSER_ALLOW_SUPERUSER=1 composer install
   mv vendor/johnpbloch/wordpress-core wpcore
   cp wp-content/wp-sqlite-db/src/db.php wpcore/wp-content/db.php   # dropin lands in ./wp-content
   ```
2. **wp-cli**: `curl -sSL -o wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar`
   (run as `php wp-cli.phar --allow-root ...` from `wpcore/`).
3. **Install**: `config create` with dummy DB creds + `--skip-check`, then
   `core install --url=http://127.0.0.1:8899 ...`.
4. **Plugin**: `ln -s <repo> wpcore/wp-content/plugins/emailexpert-events`, then
   `plugin activate emailexpert-events`.
5. **Mock HeySummit at the HTTP boundary** (no real API key in sessions): an
   mu-plugin filtering `pre_http_request` for `app.heysummit.com/api/v2/`.
   Match `/tickets/` BEFORE `/events/` (nested URLs contain both). Return
   DRF-shaped `{count,next,results:[...]}` pages.
6. **Configure Lite mode** (no sync needed):
   ```bash
   wp option update eex_connections --format=json '[{"id":"c1","label":"Primary","api_key":"k"}]'
   wp option update eex_settings --format=json '{"mode":"lite","mode_chosen":1,"lite_events":["c1|101"],"utm_enabled":1,"utm_source":"example.org"}'
   ```
7. **Pages + drive**: `wp post create --post_type=page --post_content='[eex_pricing event="101"]' --porcelain`,
   then `php -S 127.0.0.1:8899` from `wpcore/` and
   `curl 'http://127.0.0.1:8899/?page_id=N' | grep -o '<a class="eex-cta eex-cta-register"[^>]*>'`.
   For the ticket drawer/JS, Playwright with
   `executablePath: '/opt/pw-browsers/chromium'` (`npm i playwright-core`).

## Gotchas

- `wp transient delete --all` throws SQLite syntax noise but still deletes;
  use it to force a fresh ticket fetch (raw rows cache 15 min under
  `eex_tickets_<md5('v2|conn|event')>`), or `wp eval` `Frontend\Cache::flush()`
  for fragments only.
- Shortcodes: `eex_pricing`, `eex_next_session`, `eex_upcoming_sessions`, …
  (see `src/Frontend/Shortcodes.php`); drawer via `register_action="panel"`.
- PHP 8.4 + WP 6.7 logs deprecations; keep `WP_DEBUG_DISPLAY` off.
