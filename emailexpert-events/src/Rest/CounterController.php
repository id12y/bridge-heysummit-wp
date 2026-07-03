<?php
/**
 * Public registration counter read.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Rest;

use Emailexpert\Events\Frontend\Components;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/eex/v1/counter/<event> — a lightweight public read so a
 * cached page never shows a stale number; the server-rendered figure is the
 * no-JS fallback. Read-only, no personal data, no API calls.
 */
final class CounterController {

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
			'/counter/(?P<event>[A-Za-z0-9_-]+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'read' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'event' => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Return the counter for an event (HeySummit ID).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function read( WP_REST_Request $request ): WP_REST_Response {
		$event_hs_id = (string) $request['event'];
		$post_id     = Components::event_post_for_hs_id( $event_hs_id );

		if ( 0 === $post_id || 'publish' !== get_post_status( $post_id ) ) {
			return new WP_REST_Response( [ 'code' => 'not_found' ], 404 );
		}

		$response = new WP_REST_Response(
			[ 'count' => (int) get_post_meta( $post_id, '_eex_registration_count', true ) ],
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
