<?php
/**
 * Webhook receiver endpoint.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Options;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/eex/v1/heysummit/<secret>. Constant-time secret comparison;
 * a wrong secret gets 404 (never confirming the route exists) and touches
 * nothing. Rate limited to 60 requests per minute per IP. Payloads are
 * logged, deduplicated (HeySummit retries failed deliveries every 15
 * minutes up to 3 times), acknowledged immediately with 200 and processed
 * asynchronously.
 */
final class RestController {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'eex/v1',
			'/heysummit/(?P<secret>[A-Za-z0-9]+)',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true', // Authentication is the secret path segment, checked in constant time below.
				'args'                => [
					'secret' => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Handle a delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response {
		$secret = Options::webhook_secret();
		$given  = (string) $request['secret'];

		if ( '' === $secret || ! hash_equals( $secret, $given ) ) {
			// Do not confirm the route exists, log nothing to attribution.
			return new WP_REST_Response( [ 'code' => 'rest_no_route' ], 404 );
		}

		if ( ! $this->within_rate_limit() ) {
			return new WP_REST_Response( [ 'code' => 'eex_rate_limited' ], 429 );
		}

		$payload = $request->get_json_params();
		$payload = is_array( $payload ) ? $payload : [];

		update_option( 'eex_last_webhook_at', gmdate( 'Y-m-d\TH:i:s\Z' ), false );

		// Always log the receipt. Capture mode stores the complete raw
		// payload (flagged capture) for parser verification via
		// `wp eex webhooks:replay <log_id>`; email addresses in the stored
		// copy are redacted by the logger either way.
		$capture = (bool) Options::setting( 'wh_capture' );
		Logger::info(
			Logger::CONTEXT_WEBHOOK,
			$capture ? 'Webhook received (capture)' : 'Webhook received',
			array_filter(
				[
					'flag'    => $capture ? 'capture' : '',
					'payload' => $payload,
				]
			)
		);

		// Idempotency: HeySummit retries deliveries; drop duplicates on a
		// hash of (action, attendee identifier, 15-minute bucket).
		$dedupe_key = 'eex_wh_' . Parser::dedupe_hash( $payload );
		if ( false !== get_transient( $dedupe_key ) ) {
			return new WP_REST_Response(
				[
					'received'  => true,
					'duplicate' => true,
				],
				200
			);
		}
		set_transient( $dedupe_key, 1, 2 * HOUR_IN_SECONDS );

		// Respond 200 immediately; the work happens in a queued single event.
		wp_schedule_single_event( time() - 1, 'eex_process_webhook', [ $payload ] );
		spawn_cron();

		return new WP_REST_Response( [ 'received' => true ], 200 );
	}

	/**
	 * Sliding per-IP rate limit: 60 requests per minute.
	 */
	private function within_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		$key   = 'eex_rl_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );

		if ( $count >= 60 ) {
			return false;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
