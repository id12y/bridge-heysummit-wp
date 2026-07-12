<?php
/**
 * Admin self-test: one button that exercises every integration path.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Api\WriteEndpoints;
use Emailexpert\Events\Data\Coupons;
use Emailexpert\Events\Data\LiveCache;
use Emailexpert\Events\Data\LiveRepository;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Tickets;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The operator's "does everything actually work?" button. The Live status
 * row diagnoses the display pipeline (D46/D50); this covers the rest of the
 * integration — configuration, persistence, cron, the write allowlist, the
 * registration route, and (on an explicit run) live probes of every API
 * surface the plugin consumes: events, tickets (with checkout_link),
 * coupons, and the checkout-link generator. Every check returns pass, warn,
 * fail or skip with a plain-sentence detail, so a problem names itself
 * before a visitor finds it.
 *
 * Two tiers, because Site Health loads on every look at that screen:
 * cheap checks (config + cached state, no HTTP) feed the Site Health test
 * in BOTH modes — Lite previously had no Site Health coverage at all —
 * while the API probes run only from the health page's Run button or
 * `wp eex health`. Probes are read-only except one generate-only
 * checkout-link POST, which mints a URL and mutates nothing (D91/D95).
 */
final class SelfTest {

	private const OPTION = 'eex_selftest';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_eex_run_selftest', [ $this, 'run_handler' ] );
		add_filter( 'site_status_tests', [ $this, 'register_site_health' ] );
	}

	/**
	 * The health page, alongside the plugin's settings page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'emailexpert Events health', 'emailexpert-events' ),
			__( 'Events health', 'emailexpert-events' ),
			'manage_options',
			'emailexpert-events-health',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Run every check, including the API probes, and store the result.
	 */
	public function run_handler(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_run_selftest' );

		self::store_run();

		wp_safe_redirect( admin_url( 'options-general.php?page=emailexpert-events-health' ) );
		exit;
	}

	/**
	 * Run the full check (API probes included) and store the timestamped
	 * result for the page and the dashboard.
	 */
	public static function store_run(): void {
		update_option(
			self::OPTION,
			[
				'at'      => gmdate( 'Y-m-d H:i:s' ),
				'results' => self::checks( true ),
			],
			false
		);
	}

	/**
	 * Every check as {id, label, status, detail}. Cheap checks always run;
	 * API probes only when $probe is true (the Run button, the CLI) — never
	 * on a passive page view.
	 *
	 * @param bool $probe Also exercise the live API surfaces.
	 * @return array<int,array{id:string,label:string,status:string,detail:string}>
	 */
	public static function checks( bool $probe = false ): array {
		$out  = [];
		$lite = Options::is_lite();

		// -- Configuration. --------------------------------------------------
		$keyed = array_values( array_filter( Options::connections(), static fn( array $c ): bool => '' !== (string) ( $c['api_key'] ?? '' ) ) );

		$out[] = self::check(
			'connection',
			__( 'API connection configured', 'emailexpert-events' ),
			! empty( $keyed ) ? 'pass' : 'fail',
			! empty( $keyed )
				? sprintf( /* translators: %d: connection count. */ __( '%d connection(s) with an API key saved.', 'emailexpert-events' ), count( $keyed ) )
				: __( 'No connection has an API key. Nothing can be fetched or registered.', 'emailexpert-events' )
		);

		$chosen = $lite
			? array_filter( array_map( 'strval', (array) Options::setting( 'lite_events' ) ) )
			: \Emailexpert\Events\Sync\SyncEngine::enabled_event_keys();

		$out[] = self::check(
			'events_chosen',
			$lite ? __( 'Events chosen for display', 'emailexpert-events' ) : __( 'Events enabled for sync', 'emailexpert-events' ),
			! empty( $chosen ) ? 'pass' : 'warn',
			! empty( $chosen )
				? sprintf( /* translators: %d: event count. */ __( '%d event(s).', 'emailexpert-events' ), count( $chosen ) )
				: __( 'None chosen — components will render their empty state.', 'emailexpert-events' )
		);

		// -- Environment. ----------------------------------------------------
		$out[] = self::check(
			'environment',
			__( 'PHP and WordPress versions', 'emailexpert-events' ),
			version_compare( PHP_VERSION, '8.1', '>=' ) && version_compare( (string) get_bloginfo( 'version' ), '6.4', '>=' ) ? 'pass' : 'warn',
			sprintf( 'PHP %s, WordPress %s (plugin requires 8.1 / 6.4).', PHP_VERSION, (string) get_bloginfo( 'version' ) )
		);

		// Every cache guarantee in this plugin rides on transients actually
		// persisting; a broken object cache voids them all silently.
		$token = uniqid( 'eex_', true );
		set_transient( 'eex_selftest_probe', $token, MINUTE_IN_SECONDS );
		$readback = (string) get_transient( 'eex_selftest_probe' );
		delete_transient( 'eex_selftest_probe' );

		$out[] = self::check(
			'persistence',
			__( 'Caches persist (transients)', 'emailexpert-events' ),
			$readback === $token ? 'pass' : 'fail',
			$readback === $token
				? __( 'A transient written by this check read back intact.', 'emailexpert-events' )
				: __( 'A transient did not read back — the object cache is dropping writes, which disables every cache and budget guarantee. Check the object-cache drop-in.', 'emailexpert-events' )
		);

		$out[] = self::check(
			'upgrade',
			__( 'Version bookkeeping', 'emailexpert-events' ),
			(string) Options::setting( 'version' ) === EEX_VERSION ? 'pass' : 'warn',
			(string) Options::setting( 'version' ) === EEX_VERSION
				? sprintf( /* translators: %s: version. */ __( 'Running %s; upgrade routines have completed.', 'emailexpert-events' ), EEX_VERSION )
				: sprintf( /* translators: 1: stored version, 2: code version. */ __( 'Stored version %1$s ≠ code version %2$s — the upgrade flush has not run yet; load any page once.', 'emailexpert-events' ), (string) Options::setting( 'version' ), EEX_VERSION )
		);

		// -- Registration path (structural). ----------------------------------
		$patterns = [
			'events/1/attendees/'               => __( 'attendee create', 'emailexpert-events' ),
			'events/1/attendees/2/tickets/'     => __( 'ticket attach', 'emailexpert-events' ),
			'events/1/tickets/2/checkout-link/' => __( 'checkout-link generator', 'emailexpert-events' ),
			'events/1/attendees/2/talks/3/'     => __( 'session attach', 'emailexpert-events' ),
		];
		$blocked  = array_filter( array_keys( $patterns ), static fn( string $path ): bool => ! WriteEndpoints::allowed( $path ) );

		$out[] = self::check(
			'allowlist',
			__( 'Write allowlist intact', 'emailexpert-events' ),
			empty( $blocked ) ? 'pass' : 'fail',
			empty( $blocked )
				? __( 'All four sanctioned writes (attendee create, ticket attach, checkout-link, session attach) are permitted.', 'emailexpert-events' )
				: sprintf( /* translators: %s: blocked paths. */ __( 'Blocked paths that must be allowed: %s. Registrations will throw.', 'emailexpert-events' ), implode( ', ', $blocked ) )
		);

		if ( function_exists( 'rest_get_server' ) ) {
			$routes = rest_get_server()->get_routes( 'eex/v1' );

			$out[] = self::check(
				'register_route',
				__( 'Registration endpoint registered', 'emailexpert-events' ),
				isset( $routes['/eex/v1/register'] ) ? 'pass' : 'fail',
				isset( $routes['/eex/v1/register'] )
					? __( 'POST /eex/v1/register is live (the ticket panel\'s free form).', 'emailexpert-events' )
					: __( 'POST /eex/v1/register is missing — the free registration form cannot submit.', 'emailexpert-events' )
			);
		} else {
			$out[] = self::check( 'register_route', __( 'Registration endpoint registered', 'emailexpert-events' ), 'skip', __( 'REST server not loaded in this context.', 'emailexpert-events' ) );
		}

		// -- Scheduled work. ---------------------------------------------------
		if ( ! $lite ) {
			$status = \Emailexpert\Events\Sync\Health::status();

			$cron_state  = 'pass';
			$cron_detail = $status['next_cron']
				? sprintf( /* translators: %s: timestamp. */ __( 'Next sync run: %s UTC.', 'emailexpert-events' ), (string) $status['next_cron'] )
				: __( 'No sync cron scheduled (no events enabled).', 'emailexpert-events' );

			if ( $status['cron_overdue'] ) {
				$cron_state  = 'fail';
				$cron_detail = __( 'The sync cron is more than an hour overdue — WP-Cron is not firing. If DISABLE_WP_CRON is set, confirm the system cron job exists.', 'emailexpert-events' );
			} elseif ( $status['enabled_count'] > 0 && empty( $status['last_sync'] ) ) {
				$cron_state  = 'warn';
				$cron_detail = __( 'Events are enabled but no sync has completed yet.', 'emailexpert-events' );
			}

			$out[] = self::check( 'cron', __( 'Sync schedule', 'emailexpert-events' ), $cron_state, $cron_detail );

			$out[] = self::check(
				'webhooks',
				__( 'Webhook receiver', 'emailexpert-events' ),
				'' !== Options::webhook_secret() ? 'pass' : 'warn',
				'' !== Options::webhook_secret()
					? ( '' !== (string) get_option( 'eex_last_webhook_at', '' )
						? sprintf( /* translators: %s: timestamp. */ __( 'Secret set; last webhook received %s.', 'emailexpert-events' ), (string) get_option( 'eex_last_webhook_at' ) )
						: __( 'Secret set; no webhook received yet.', 'emailexpert-events' ) )
					: __( 'No webhook secret yet — open Settings → Webhooks once to generate it, then configure HeySummit.', 'emailexpert-events' )
			);
		}

		// -- The display pipeline (Lite): the Live status diagnosis. -----------
		if ( $lite ) {
			$repository = Repositories::current();

			if ( $repository instanceof LiveRepository ) {
				$diagnosis = $probe ? $repository->diagnose() : '';
				$cache     = LiveCache::status();

				if ( $probe ) {
					$out[] = self::check(
						'display',
						__( 'Display pipeline (sessions)', 'emailexpert-events' ),
						'' === $diagnosis ? 'pass' : 'fail',
						'' === $diagnosis ? __( 'Upcoming sessions are flowing.', 'emailexpert-events' ) : $diagnosis
					);
				}

				$out[] = self::check(
					'live_cache',
					__( 'Live cache state', 'emailexpert-events' ),
					LiveCache::degraded() ? 'warn' : 'pass',
					LiveCache::degraded()
						? sprintf( /* translators: %s: error. */ __( 'The last fetch failed (%s); pages are serving the last good copy.', 'emailexpert-events' ), $cache['last_error'] ?: __( 'no reason recorded', 'emailexpert-events' ) )
						: sprintf( /* translators: %s: timestamp. */ __( 'Last successful fetch: %s.', 'emailexpert-events' ), $cache['last_success'] ?: __( 'none yet', 'emailexpert-events' ) )
				);
			}
		}

		// -- Live API probes: only on an explicit run. --------------------------
		if ( $probe ) {
			$out = array_merge( $out, self::probe_api( $keyed, $chosen, $lite ) );
		}

		return $out;
	}

	/**
	 * Exercise every API surface the plugin consumes. Read-only except the
	 * checkout-link generation, which mints a URL and mutates nothing.
	 *
	 * @param array<int,array<string,string>> $keyed  Connections with keys.
	 * @param array<int,string>               $chosen Configured event keys.
	 * @param bool                            $lite   Lite mode.
	 * @return array<int,array{id:string,label:string,status:string,detail:string}>
	 */
	private static function probe_api( array $keyed, array $chosen, bool $lite ): array {
		$out = [];

		foreach ( $keyed as $connection ) {
			$conn_id = (string) ( $connection['id'] ?? '' );
			$client  = HeySummitClient::for_connection( $connection );

			$started  = microtime( true );
			$response = $client->get(
				'events/',
				[],
				[
					'timeout' => 15,
					'retries' => 1,
				]
			);
			$ms       = (int) round( ( microtime( true ) - $started ) * 1000 );

			$ok    = ! is_wp_error( $response );
			$count = $ok ? ( isset( $response['count'] ) ? (int) $response['count'] : count( (array) ( $response['results'] ?? [] ) ) ) : 0;

			$out[] = self::check(
				'api_events_' . $conn_id,
				sprintf( /* translators: %s: connection ID. */ __( 'API reachable (connection %s)', 'emailexpert-events' ), $conn_id ),
				$ok ? 'pass' : 'fail',
				$ok
					? sprintf( /* translators: 1: event count, 2: milliseconds. */ __( '%1$d event(s) listed in %2$dms.', 'emailexpert-events' ), $count, $ms )
					: $response->get_error_message()
			);
		}

		// Per-event commerce surfaces, bounded to the first three events.
		foreach ( array_slice( array_values( $chosen ), 0, 3 ) as $key ) {
			[ $conn_id, $event_id ] = array_pad( explode( '|', (string) $key, 2 ), 2, '' );

			// Full-mode keys are event IDs on a single connection.
			if ( '' === $event_id && ! $lite ) {
				$event_id = $conn_id;
				$conn_id  = (string) ( $keyed[0]['id'] ?? '' );
			}

			if ( '' === $conn_id || '' === $event_id ) {
				continue;
			}

			$tickets = Tickets::raw( $conn_id, $event_id );
			$ok      = ! is_wp_error( $tickets );

			$with_link = 0;
			if ( $ok ) {
				foreach ( $tickets as $ticket ) {
					if ( preg_match( '#^https?://#i', (string) ( $ticket['checkout_link'] ?? '' ) ) ) {
						++$with_link;
					}
				}
			}

			$out[] = self::check(
				'api_tickets_' . $event_id,
				sprintf( /* translators: %s: event ID. */ __( 'Tickets readable (event %s)', 'emailexpert-events' ), $event_id ),
				$ok ? ( ( empty( $tickets ) || $with_link > 0 ) ? 'pass' : 'warn' ) : 'fail',
				$ok
					? sprintf( /* translators: 1: ticket count, 2: with checkout links. */ __( '%1$d ticket(s), %2$d with a checkout link.', 'emailexpert-events' ), count( $tickets ), $with_link )
					: $tickets->get_error_message()
			);

			$coupons = Coupons::raw( $conn_id, $event_id );

			$out[] = self::check(
				'api_coupons_' . $event_id,
				sprintf( /* translators: %s: event ID. */ __( 'Coupons readable (event %s)', 'emailexpert-events' ), $event_id ),
				is_wp_error( $coupons ) ? 'warn' : 'pass',
				is_wp_error( $coupons )
					? sprintf( /* translators: %s: error. */ __( 'Coupon list unavailable (%s) — the editor dropdown falls back to typed codes.', 'emailexpert-events' ), $coupons->get_error_message() )
					: sprintf( /* translators: %d: coupon count. */ __( '%d coupon(s) listed.', 'emailexpert-events' ), count( $coupons ) )
			);

			// The checkout-link generator, exercised through the exact
			// production path (Tickets::couponed_checkout_link, including its
			// cache) with the event's first live coupon. Generate-only: it
			// mints a URL and touches no attendee, ticket or event data. With
			// no coupon to test with, skip honestly rather than guess a body.
			$ticket_id = '';
			if ( $ok ) {
				foreach ( $tickets as $ticket ) {
					if ( ! ( isset( $ticket['is_active'] ) && false === $ticket['is_active'] ) && '' !== (string) ( $ticket['id'] ?? '' ) ) {
						$ticket_id = (string) $ticket['id'];
						break;
					}
				}
			}

			$code = '';
			if ( ! is_wp_error( $coupons ) ) {
				foreach ( $coupons as $coupon ) {
					if ( '' !== (string) ( $coupon['coupon_code'] ?? '' ) && ! ( isset( $coupon['is_active'] ) && false === $coupon['is_active'] ) ) {
						$code = (string) $coupon['coupon_code'];
						break;
					}
				}
			}

			if ( '' === $ticket_id || '' === $code ) {
				$out[] = self::check(
					'api_generator_' . $event_id,
					sprintf( /* translators: %s: event ID. */ __( 'Checkout-link generator (event %s)', 'emailexpert-events' ), $event_id ),
					'skip',
					__( 'No live coupon (or ticket) to test with — generation is exercised the first time a couponed or session link renders.', 'emailexpert-events' )
				);
			} else {
				$link = Tickets::couponed_checkout_link( $conn_id, $event_id, $ticket_id, $code );

				$out[] = self::check(
					'api_generator_' . $event_id,
					sprintf( /* translators: %s: event ID. */ __( 'Checkout-link generator (event %s)', 'emailexpert-events' ), $event_id ),
					'' !== $link ? 'pass' : 'warn',
					'' !== $link
						? sprintf( /* translators: %s: coupon code. */ __( 'A discounted checkout link generated with coupon %s (generate-only; nothing was modified).', 'emailexpert-events' ), $code )
						: __( 'Generation returned no link — couponed and session deep links fall back to the plain checkout. Recent failures are negative-cached for 5 minutes; see the log for the API\'s reason.', 'emailexpert-events' )
				);
			}
		}

		return $out;
	}

	/**
	 * One check row.
	 *
	 * @param string $id     Stable ID.
	 * @param string $label  Human label.
	 * @param string $status pass|warn|fail|skip.
	 * @param string $detail Plain-sentence detail.
	 * @return array{id:string,label:string,status:string,detail:string}
	 */
	private static function check( string $id, string $label, string $status, string $detail ): array {
		return [
			'id'     => $id,
			'label'  => $label,
			'status' => $status,
			'detail' => $detail,
		];
	}

	/**
	 * The stored result of the last explicit run.
	 *
	 * @return array{at:string,results:array<int,array<string,string>>}
	 */
	public static function last_run(): array {
		$stored = (array) get_option( self::OPTION, [] );

		return [
			'at'      => (string) ( $stored['at'] ?? '' ),
			'results' => is_array( $stored['results'] ?? null ) ? $stored['results'] : [],
		];
	}

	/**
	 * Render the health page: cheap checks live, probe results from the last
	 * explicit run, and the Run button.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'emailexpert-events' ) );
		}

		AdminAssets::enqueue();

		$last = self::last_run();
		$rows = ! empty( $last['results'] ) ? $last['results'] : self::checks( false );

		echo '<div class="wrap eex-admin"><h1>' . esc_html__( 'emailexpert Events health', 'emailexpert-events' ) . '</h1>';

		printf(
			'<p>%s</p>',
			'' !== $last['at']
				? sprintf( /* translators: %s: UTC timestamp. */ esc_html__( 'Full check last run %s UTC. Cheap checks below refresh on every view; API probes refresh when you run the full check.', 'emailexpert-events' ), esc_html( $last['at'] ) )
				: esc_html__( 'The full check has not been run yet — the rows below are the configuration checks only.', 'emailexpert-events' )
		);

		printf(
			'<form method="post" action="%s" style="margin:0 0 16px">%s<input type="hidden" name="action" value="eex_run_selftest" /><button type="submit" class="button button-primary">%s</button> <span class="description">%s</span></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			wp_nonce_field( 'eex_run_selftest', '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nonce field HTML.
			esc_html__( 'Run full check', 'emailexpert-events' ),
			esc_html__( 'Read-only, plus one generate-only checkout-link call. Takes a few seconds.', 'emailexpert-events' )
		);

		$badge = [
			'pass' => '✓ ' . __( 'Pass', 'emailexpert-events' ),
			'warn' => '△ ' . __( 'Check', 'emailexpert-events' ),
			'fail' => '✗ ' . __( 'Fail', 'emailexpert-events' ),
			'skip' => '– ' . __( 'n/a', 'emailexpert-events' ),
		];

		echo '<table class="widefat striped"><thead><tr><th style="width:220px">' . esc_html__( 'Check', 'emailexpert-events' ) . '</th><th style="width:90px">' . esc_html__( 'Status', 'emailexpert-events' ) . '</th><th>' . esc_html__( 'Detail', 'emailexpert-events' ) . '</th></tr></thead><tbody>';

		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td></tr>',
				esc_html( (string) ( $row['label'] ?? '' ) ),
				esc_html( $badge[ (string) ( $row['status'] ?? 'skip' ) ] ?? (string) ( $row['status'] ?? '' ) ),
				esc_html( (string) ( $row['detail'] ?? '' ) )
			);
		}

		echo '</tbody></table>';

		printf(
			'<p class="description" style="margin-top:12px">%s</p></div>',
			esc_html__( 'The Live status row on the settings page diagnoses the display pipeline in depth; this page covers the whole integration. wp-cli: wp eex health.', 'emailexpert-events' )
		);
	}

	/**
	 * Site Health: the cheap checks in both modes (Lite previously had no
	 * Site Health presence at all). Fails surface as critical, warns as
	 * recommended, with a pointer to the health page for the full probe.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function register_site_health( array $tests ): array {
		$tests['direct']['eex_integration'] = [
			'label' => __( 'emailexpert Events integration', 'emailexpert-events' ),
			'test'  => [ $this, 'site_health_test' ],
		];

		return $tests;
	}

	/**
	 * The Site Health test callback (cheap checks only; no HTTP).
	 *
	 * @return array<string,mixed>
	 */
	public function site_health_test(): array {
		$rows  = self::checks( false );
		$fails = array_filter( $rows, static fn( array $row ): bool => 'fail' === $row['status'] );
		$warns = array_filter( $rows, static fn( array $row ): bool => 'warn' === $row['status'] );

		$status = ! empty( $fails ) ? 'critical' : ( ! empty( $warns ) ? 'recommended' : 'good' );

		$description = '';
		foreach ( array_merge( $fails, $warns ) as $row ) {
			$description .= '<p><strong>' . esc_html( (string) $row['label'] ) . ':</strong> ' . esc_html( (string) $row['detail'] ) . '</p>';
		}

		$description .= '<p><a href="' . esc_url( admin_url( 'options-general.php?page=emailexpert-events-health' ) ) . '">' . esc_html__( 'Run the full check (including live API probes) on the Events health page.', 'emailexpert-events' ) . '</a></p>';

		return [
			'label'       => 'good' === $status
				? __( 'emailexpert Events integration is healthy', 'emailexpert-events' )
				: __( 'emailexpert Events integration needs attention', 'emailexpert-events' ),
			'status'      => $status,
			'badge'       => [
				'label' => __( 'emailexpert Events', 'emailexpert-events' ),
				'color' => 'good' === $status ? 'blue' : 'red',
			],
			'description' => $description,
			'test'        => 'eex_integration',
		];
	}
}
