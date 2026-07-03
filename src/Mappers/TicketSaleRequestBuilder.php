<?php
/**
 * External-ticket-sale-import request builder.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the external-ticket-sale-import request for the WooCommerce
 * bridge — HeySummit's intended off-platform sales flow. Shape assumed and
 * filterable; verify against the write:external-ticket-sales discovery
 * snapshot before first live use (docs/api-notes.md).
 */
final class TicketSaleRequestBuilder {

	/**
	 * Build the request.
	 *
	 * @param array<string,mixed> $sale attendee_hs_id, ticket_hs_id,
	 *                                  amount_gross, amount_net, currency,
	 *                                  order_reference.
	 * @return array{path:string,body:array<string,mixed>}
	 */
	public static function build( array $sale ): array {
		$body = [
			'attendee'        => (string) ( $sale['attendee_hs_id'] ?? '' ),
			'ticket'          => (string) ( $sale['ticket_hs_id'] ?? '' ),
			'amount_gross'    => (string) ( $sale['amount_gross'] ?? '' ),
			'amount_net'      => (string) ( $sale['amount_net'] ?? '' ),
			'currency'        => (string) ( $sale['currency'] ?? '' ),
			'order_reference' => (string) ( $sale['order_reference'] ?? '' ),
		];

		/**
		 * Filter the ticket-sale-import request before it is sent.
		 *
		 * @param array<string,mixed> $request path + body.
		 * @param array<string,mixed> $sale    Source sale data.
		 */
		return (array) apply_filters(
			'eex_ticket_sale_request',
			[
				'path' => 'external-ticket-sales/',
				'body' => $body,
			],
			$sale
		);
	}
}
