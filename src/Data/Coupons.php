<?php
/**
 * Shared HeySummit coupon fetching (read-only).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Api\PathStyles;
use Emailexpert\Events\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Coupons are commerce data, never synced content. The only consumer today is
 * the editor's coupon picker: it lists an event's coupons so an operator can
 * pick one by name instead of typing the code, which then feeds the existing
 * coupon-baked checkout-link generator (Tickets::couponed_checkout_link,
 * docs/decisions.md D91). Read-only: the API's coupon create/update/delete
 * stay outside the write allowlist (D45/D91).
 */
final class Coupons {

	/**
	 * Raw coupon rows for one event, cached for 15 minutes.
	 *
	 * The spec documents coupons only nested under the event
	 * (GET events/<id>/coupons/), so that route leads; a top-level
	 * coupons/?event= is tried as a defensive fallback for accounts that
	 * expose it (the same route-shape drift tickets and sponsors handle).
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function raw( string $connection_id, string $event_id ) {
		if ( '' === $connection_id || '' === $event_id ) {
			return new WP_Error( 'eex_coupons_args', __( 'Choose a connection and event first.', 'emailexpert-events' ) );
		}

		$key    = 'eex_coupons_' . md5( $connection_id . '|' . $event_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return new WP_Error( 'eex_coupons_connection', __( 'Connection not found or has no API key saved.', 'emailexpert-events' ) );
		}

		$client  = HeySummitClient::for_connection( $connection );
		$coupons = $client->get_all( 'events/' . rawurlencode( $event_id ) . '/coupons/' );

		if ( is_wp_error( $coupons ) ) {
			$top = $client->get_all( 'coupons/', [ 'event' => $event_id ] );

			if ( ! is_wp_error( $top ) ) {
				PathStyles::remember( $client->connection_id(), 'coupons', 'top' );
				$coupons = $top;
			}
		}

		if ( is_wp_error( $coupons ) ) {
			return $coupons;
		}

		$coupons = array_values(
			array_filter(
				$coupons,
				static fn( $coupon ): bool => is_array( $coupon ) && isset( $coupon['id'] )
			)
		);

		set_transient( $key, $coupons, 15 * MINUTE_IN_SECONDS );

		return $coupons;
	}

	/**
	 * Coupon options for the editor picker: one row per redeemable coupon
	 * (active, with a code), shaped as { id, code, title } — the code is what
	 * the widget's coupon attribute stores, the title is what the operator
	 * recognises. Inactive or codeless coupons are dropped: they cannot bake a
	 * usable checkout link.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array{id:string,code:string,title:string}>|WP_Error
	 */
	public static function code_options( string $connection_id, string $event_id ) {
		$coupons = self::raw( $connection_id, $event_id );

		if ( is_wp_error( $coupons ) ) {
			return $coupons;
		}

		$list = [];

		foreach ( $coupons as $coupon ) {
			if ( isset( $coupon['is_active'] ) && false === $coupon['is_active'] ) {
				continue;
			}

			$code = trim( (string) ( $coupon['coupon_code'] ?? '' ) );
			if ( '' === $code ) {
				continue;
			}

			$title = trim( (string) ( $coupon['title'] ?? '' ) );
			if ( '' === $title ) {
				$title = trim( (string) ( $coupon['description'] ?? '' ) );
			}

			$list[] = [
				'id'    => (string) $coupon['id'],
				'code'  => $code,
				'title' => '' !== $title ? $title : $code,
			];
		}

		return $list;
	}
}
