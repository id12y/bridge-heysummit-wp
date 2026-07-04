<?php
/**
 * Public in-drawer registration for free tickets.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Rest;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Tickets;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Options;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/eex/v1/register — the ticket drawer's "register free"
 * form. Server-side only: the visitor's browser talks to this site, this
 * site talks to HeySummit through the allowlisted attendee-create path.
 *
 * Guard rails, in order: honeypot (silent success), consent required,
 * valid email, per-IP rate limit, the event must be one this site is
 * configured for (never client-chosen connections), the ticket must exist
 * on that event and be FREE (a paid ticket via this route would grant
 * unpaid access), and suppression is honoured when the accounts module is
 * active. Errors reach the visitor as plain sentences and the log with
 * detail; the email address is never logged.
 */
final class RegisterController {

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
			'/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'event'   => [ 'type' => 'string' ],
					'ticket'  => [ 'type' => 'string' ],
					'price'   => [ 'type' => 'string' ],
					'name'    => [ 'type' => 'string' ],
					'email'   => [ 'type' => 'string' ],
					'consent' => [ 'type' => 'string' ],
					'website' => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Handle a registration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response {
		// Honeypot: bots that fill every field get a quiet "success".
		if ( '' !== trim( (string) $request['website'] ) ) {
			return $this->respond( [ 'status' => 'registered' ], 200 );
		}

		if ( '1' !== (string) $request['consent'] ) {
			return $this->respond( [ 'message' => __( 'Please tick the consent box to register.', 'emailexpert-events' ) ], 400 );
		}

		$email = sanitize_email( (string) $request['email'] );
		$name  = sanitize_text_field( (string) $request['name'] );

		if ( '' === $email || ! is_email( $email ) ) {
			return $this->respond( [ 'message' => __( 'That email address does not look right.', 'emailexpert-events' ) ], 400 );
		}

		if ( '' === $name ) {
			return $this->respond( [ 'message' => __( 'Please enter your name.', 'emailexpert-events' ) ], 400 );
		}

		if ( ! $this->within_rate_limit() ) {
			return $this->respond( [ 'message' => __( 'Too many attempts — please wait a few minutes and try again.', 'emailexpert-events' ) ], 429 );
		}

		$event_hs_id   = sanitize_text_field( (string) $request['event'] );
		$connection_id = $this->connection_for_event( $event_hs_id );

		if ( '' === $connection_id ) {
			return $this->respond( [ 'message' => __( 'This event is not open for registration here.', 'emailexpert-events' ) ], 404 );
		}

		$ticket = $this->free_ticket( $connection_id, $event_hs_id, sanitize_text_field( (string) $request['ticket'] ) );

		if ( null === $ticket ) {
			return $this->respond( [ 'message' => __( 'That ticket cannot be registered here — it may be paid or no longer available.', 'emailexpert-events' ) ], 400 );
		}

		if ( class_exists( '\Emailexpert\Events\Accounts\Suppression' ) && \Emailexpert\Events\Accounts\Suppression::is_suppressed( $email, $event_hs_id ) ) {
			// Indistinguishable from success on purpose: suppression state
			// must not be probeable from the outside.
			return $this->respond( [ 'status' => 'registered' ], 200 );
		}

		$price_id = $this->price_id_of( $ticket, sanitize_text_field( (string) $request['price'] ) );

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return $this->respond( [ 'message' => __( 'Registration is temporarily unavailable.', 'emailexpert-events' ) ], 503 );
		}

		$req = AttendeeRequestBuilder::build(
			[
				'name'            => $name,
				'email'           => $email,
				'event_hs_id'     => $event_hs_id,
				'ticket_price_id' => $price_id,
			]
		);

		$client   = HeySummitClient::for_connection( $connection );
		$response = $client->post( (string) $req['path'], (array) $req['body'] );

		if ( is_wp_error( $response ) ) {
			$detail = (string) $response->get_error_message();

			// A duplicate registration is a success from the visitor's side.
			if ( false !== stripos( $detail, 'already' ) || false !== stripos( $detail, 'exist' ) ) {
				return $this->respond( [ 'status' => 'already' ], 200 );
			}

			Logger::log(
				Logger::CONTEXT_API,
				'error',
				'drawer registration failed: ' . $detail,
				[
					'connection' => $connection_id,
					'event'      => $event_hs_id,
					'ticket'     => (string) ( $ticket['id'] ?? '' ),
				]
			);

			return $this->respond( [ 'message' => __( 'Registration could not be completed — please try again on the event site.', 'emailexpert-events' ) ], 502 );
		}

		Logger::log(
			Logger::CONTEXT_API,
			'info',
			'drawer registration completed',
			[
				'connection' => $connection_id,
				'event'      => $event_hs_id,
				'ticket'     => (string) ( $ticket['id'] ?? '' ),
			]
		);

		return $this->respond( [ 'status' => 'registered' ], 200 );
	}

	/**
	 * Five attempts per IP per ten minutes.
	 */
	private function within_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		$key   = 'eex_reg_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * The connection this site has configured for an event — the client
	 * never chooses a connection, only names an event we already trust.
	 *
	 * @param string $event_hs_id HeySummit event ID.
	 */
	private function connection_for_event( string $event_hs_id ): string {
		if ( '' === $event_hs_id ) {
			return '';
		}

		$event = Repositories::current()->event_summary( $event_hs_id );

		if ( null !== $event && (string) ( $event['hs_id'] ?? '' ) === $event_hs_id ) {
			return (string) ( $event['connection'] ?? '' );
		}

		return '';
	}

	/**
	 * The ticket row, only if it exists on the event and is genuinely free.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_hs_id   Event ID.
	 * @param string $ticket_id     Ticket ID from the form.
	 * @return array<string,mixed>|null
	 */
	private function free_ticket( string $connection_id, string $event_hs_id, string $ticket_id ) {
		if ( '' === $ticket_id ) {
			return null;
		}

		foreach ( Tickets::for_display( $connection_id, $event_hs_id, '' ) as $ticket ) {
			if ( (string) $ticket['id'] !== $ticket_id ) {
				continue;
			}

			if ( ! empty( $ticket['is_paid'] ) ) {
				return null;
			}

			foreach ( (array) $ticket['prices'] as $price ) {
				$amount = (string) ( $price['amount'] ?? '' );

				if ( '' !== $amount && is_numeric( $amount ) && (float) $amount > 0 ) {
					return null;
				}
			}

			return $ticket;
		}

		return null;
	}

	/**
	 * The ticket price ID to send: the client's claim is only accepted when
	 * it belongs to the ticket; otherwise the ticket's first price is used.
	 *
	 * @param array<string,mixed> $ticket  Verified free ticket.
	 * @param string              $claimed Price ID from the form.
	 */
	private function price_id_of( array $ticket, string $claimed ): string {
		$ids = array_values(
			array_filter(
				array_map(
					static fn( $price ): string => (string) ( $price['id'] ?? '' ),
					(array) $ticket['prices']
				)
			)
		);

		if ( '' !== $claimed && in_array( $claimed, $ids, true ) ) {
			return $claimed;
		}

		return $ids[0] ?? '';
	}

	/**
	 * An uncacheable JSON response.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param int                 $status HTTP status.
	 */
	private function respond( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
