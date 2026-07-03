<?php
/**
 * Attendee-create request builder.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the attendee-create request, per the published OpenAPI spec:
 * POST events/<id>/attendees/ with { email, name } and an optional
 * ticket_price_id that assigns the ticket in the same call. Filterable for
 * live corrections.
 */
final class AttendeeRequestBuilder {

	/**
	 * Build the request.
	 *
	 * @param array<string,mixed> $purchase name, email, event_hs_id,
	 *                                      order_reference, and optionally
	 *                                      ticket_price_id.
	 * @return array{path:string,body:array<string,mixed>}
	 */
	public static function build( array $purchase ): array {
		$body = [
			'name'  => (string) ( $purchase['name'] ?? '' ),
			'email' => (string) ( $purchase['email'] ?? '' ),
		];

		$ticket_price_id = (string) ( $purchase['ticket_price_id'] ?? '' );
		if ( '' !== $ticket_price_id ) {
			// The spec types this as an integer; tolerate non-numeric stored
			// values so the API's own validation message reaches the log.
			$body['ticket_price_id'] = ctype_digit( $ticket_price_id ) ? (int) $ticket_price_id : $ticket_price_id;
		}

		/**
		 * Filter the attendee-create request before it is sent.
		 *
		 * @param array<string,mixed> $request  path + body.
		 * @param array<string,mixed> $purchase Source purchase data.
		 */
		return (array) apply_filters(
			'eex_attendee_request',
			[
				'path' => 'events/' . rawurlencode( (string) ( $purchase['event_hs_id'] ?? '' ) ) . '/attendees/',
				'body' => $body,
			],
			$purchase
		);
	}
}
