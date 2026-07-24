<?php
/**
 * A logged-in visitor's own registration state.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Rest;

use Emailexpert\Events\Api\AttendeeLookup;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Options;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/eex/v1/my-schedule?event=<id> — whether the CURRENT
 * logged-in user is registered for an event and which sessions are on
 * their schedule, so widgets can confirm an RSVP with zero clicks.
 *
 * The privacy boundary that makes this safe where a public lookup would
 * not be: the email is always taken from the authenticated WordPress
 * user — never from the request — so a caller can only ever ask about
 * themselves. Anonymous visitors get nothing (the public register
 * endpoint stays deliberately unprobeable; see D103).
 */
final class MyScheduleController {

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
			'/my-schedule',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'lookup' ],
				'permission_callback' => static fn(): bool => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
				'args'                => [
					'event' => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Look up the current user's registration on one event.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function lookup( WP_REST_Request $request ): WP_REST_Response {
		$user  = wp_get_current_user();
		$email = $user ? (string) $user->user_email : '';

		if ( '' === $email ) {
			return $this->respond( [ 'registered' => false, 'talks' => [] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$event_hs_id = sanitize_text_field( (string) $request['event'] );
		$event       = Repositories::current()->event_summary( $event_hs_id );

		if ( null === $event || (string) ( $event['hs_id'] ?? '' ) !== $event_hs_id ) {
			return $this->respond( [ 'registered' => false, 'talks' => [] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		// One short lookup per user+event per ten minutes: a page full of
		// widgets (and every reload) answers from the transient.
		$cache_key = 'eex_mysched_' . md5( strtolower( $email ) . '|' . $event_hs_id );
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $this->respond( $cached );
		}

		$connection = Options::connection( (string) ( $event['connection'] ?? '' ) );

		if ( null === $connection ) {
			return $this->respond( [ 'registered' => false, 'talks' => [] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$row = AttendeeLookup::find(
			HeySummitClient::for_connection( $connection ),
			$event_hs_id,
			$email,
			[
				'timeout' => 5,
				'retries' => 0,
			]
		);

		$talks = [];
		foreach ( (array) ( $row['talks'] ?? [] ) as $talk ) {
			$talk_id = is_scalar( $talk ) ? (string) $talk : (string) ( $talk['id'] ?? '' );
			if ( '' !== $talk_id ) {
				$talks[] = $talk_id;
			}
		}

		$body = [
			'registered' => ! empty( $row ),
			'talks'      => $talks,
		];

		set_transient( $cache_key, $body, 10 * MINUTE_IN_SECONDS );

		return $this->respond( $body );
	}

	/**
	 * An uncacheable JSON response (it is personal to the caller).
	 *
	 * @param array<string,mixed> $body Body.
	 */
	private function respond( array $body ): WP_REST_Response {
		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
