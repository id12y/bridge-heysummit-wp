<?php
/**
 * Ticket-attach request builder.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the documented-idempotent ticket attach:
 * POST events/<id>/attendees/<pk>/tickets/ with { ticket_price_id }. "If
 * the attendee already holds the ticket, no action is taken" (OpenAPI
 * spec) — safe for the already-existing-attendee recovery path. Replaces
 * the v2-assumed external-ticket-sales import, which does not exist in the
 * real API (docs/decisions.md D45).
 */
final class TicketAttachRequestBuilder {

	/**
	 * Build the request.
	 *
	 * @param array<string,mixed> $attach event_hs_id, attendee_hs_id,
	 *                                    ticket_price_id.
	 * @return array{path:string,body:array<string,mixed>}
	 */
	public static function build( array $attach ): array {
		$ticket_price_id = (string) ( $attach['ticket_price_id'] ?? '' );

		/**
		 * Filter the ticket-attach request before it is sent.
		 *
		 * @param array<string,mixed> $request path + body.
		 * @param array<string,mixed> $attach  Source data.
		 */
		return (array) apply_filters(
			'eex_ticket_attach_request',
			[
				'path' => 'events/' . rawurlencode( (string) ( $attach['event_hs_id'] ?? '' ) ) . '/attendees/' . rawurlencode( (string) ( $attach['attendee_hs_id'] ?? '' ) ) . '/tickets/',
				'body' => [
					'ticket_price_id' => ctype_digit( $ticket_price_id ) ? (int) $ticket_price_id : $ticket_price_id,
				],
			],
			$attach
		);
	}
}
