<?php
/**
 * Attendee-create request builder.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the attendee-create request for the WooCommerce bridge. The exact
 * body shape is unverified (see docs/api-notes.md); the assumed DRF-style
 * shape lives here only, is checked against the discovery diagnostic's
 * OPTIONS snapshot at runtime, and is filterable for live corrections.
 */
final class AttendeeRequestBuilder {

	/**
	 * Build the request.
	 *
	 * @param array<string,mixed> $purchase name, email, event_hs_id,
	 *                                      order_reference.
	 * @return array{path:string,body:array<string,mixed>}
	 */
	public static function build( array $purchase ): array {
		$body = [
			'name'  => (string) ( $purchase['name'] ?? '' ),
			'email' => (string) ( $purchase['email'] ?? '' ),
			'event' => (string) ( $purchase['event_hs_id'] ?? '' ),
		];

		/**
		 * Filter the attendee-create request before it is sent. Use this to
		 * reconcile the assumed body shape with the live API (compare the
		 * write:attendees discovery snapshot).
		 *
		 * @param array<string,mixed> $request  path + body.
		 * @param array<string,mixed> $purchase Source purchase data.
		 */
		return (array) apply_filters(
			'eex_attendee_request',
			[
				'path' => 'attendees/',
				'body' => $body,
			],
			$purchase
		);
	}
}
