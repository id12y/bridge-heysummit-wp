<?php
/**
 * Shared HeySummit ticket fetching.
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
 * Tickets are commerce data, never synced content: every consumer — the
 * WooCommerce mapping picker, the forms bridge picker and the pricing
 * component — reads them live through this one cached fetch, in both modes.
 */
final class Tickets {

	/**
	 * Raw ticket rows for one event, cached for 15 minutes.
	 *
	 * Tries the top-level route then the nested one (some accounts refuse
	 * the top level; verified live, see docs/api-notes.md).
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function raw( string $connection_id, string $event_id ) {
		if ( '' === $connection_id || '' === $event_id ) {
			return new WP_Error( 'eex_tickets_args', __( 'Choose a connection and event first.', 'emailexpert-events' ) );
		}

		// The plugin version salts the key: every update starts from fresh
		// rows immediately (any new mapped fields included) instead of
		// serving the old build's cache for up to 15 minutes.
		$key    = 'eex_tickets_' . md5( EEX_VERSION . '|' . $connection_id . '|' . $event_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return new WP_Error( 'eex_tickets_connection', __( 'Connection not found or has no API key saved.', 'emailexpert-events' ) );
		}

		$client  = HeySummitClient::for_connection( $connection );
		$tickets = $client->get_all( 'tickets/', [ 'event' => $event_id ] );

		if ( is_wp_error( $tickets ) ) {
			$nested = $client->get_all( 'events/' . rawurlencode( $event_id ) . '/tickets/' );

			if ( ! is_wp_error( $nested ) ) {
				PathStyles::remember( $client->connection_id(), 'tickets', 'nested' );
				$tickets = $nested;
			}
		}

		if ( is_wp_error( $tickets ) ) {
			return $tickets;
		}

		$tickets = array_values(
			array_filter(
				$tickets,
				static fn( $ticket ): bool => is_array( $ticket ) && isset( $ticket['id'] )
			)
		);

		set_transient( $key, $tickets, 15 * MINUTE_IN_SECONDS );
		self::remember_titles( $tickets );

		return $tickets;
	}

	/**
	 * Ticket titles seen on any fetch, for human-friendly editor dropdowns
	 * (ticket ID => title). Non-autoloaded, capped.
	 *
	 * @param array<int,array<string,mixed>> $tickets Raw ticket rows.
	 */
	private static function remember_titles( array $tickets ): void {
		$titles = self::known_titles();

		foreach ( $tickets as $ticket ) {
			$titles[ (string) $ticket['id'] ] = (string) ( $ticket['title'] ?? $ticket['name'] ?? $ticket['id'] );
		}

		update_option( 'eex_ticket_titles', array_slice( $titles, -200, null, true ), false );
	}

	/**
	 * Every ticket title this site has seen (ticket ID => title).
	 *
	 * @return array<string,string>
	 */
	public static function known_titles(): array {
		$titles = get_option( 'eex_ticket_titles', [] );

		return is_array( $titles ) ? array_map( 'strval', $titles ) : [];
	}

	/**
	 * A ticket's price records. The spec types Ticket.prices as an opaque
	 * string; decode when it is JSON, tolerate a ready-made array.
	 *
	 * @param array<string,mixed> $ticket Raw ticket row.
	 * @return array<int,array<string,mixed>>
	 */
	public static function prices_of( array $ticket ): array {
		$prices = $ticket['prices'] ?? null;

		if ( is_string( $prices ) ) {
			$decoded = json_decode( $prices, true );
			$prices  = is_array( $decoded ) ? $decoded : null;
		}

		if ( ! is_array( $prices ) ) {
			return [];
		}

		return array_values( array_filter( $prices, 'is_array' ) );
	}

	/**
	 * Ticket PRICE options for mapping pickers: what attendee-create/attach
	 * need is a ticket price ID. Falls back to the ticket row (flagged for
	 * manual confirmation) when prices do not decode.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array{id:string,title:string}>|WP_Error
	 */
	public static function price_options( string $connection_id, string $event_id ) {
		$tickets = self::raw( $connection_id, $event_id );

		if ( is_wp_error( $tickets ) ) {
			return $tickets;
		}

		$list = [];

		foreach ( $tickets as $ticket ) {
			$expanded = false;

			foreach ( self::prices_of( $ticket ) as $price ) {
				if ( isset( $price['id'] ) && is_scalar( $price['id'] ) ) {
					$expanded = true;
					$list[]   = [
						'id'    => (string) $price['id'],
						'title' => trim( (string) ( $ticket['title'] ?? $ticket['id'] ) . ' — ' . (string) ( $price['title'] ?? $price['name'] ?? $price['price'] ?? $price['id'] ) ),
					];
				}
			}

			if ( $expanded ) {
				continue;
			}

			$list[] = [
				'id'    => (string) $ticket['id'],
				'title' => sanitize_text_field( (string) ( $ticket['title'] ?? $ticket['name'] ?? $ticket['id'] ) . ' ' . __( '(confirm the ticket price ID)', 'emailexpert-events' ) ),
			];
		}

		return $list;
	}

	/**
	 * A checkout link with a coupon baked in, generated on demand through
	 * the allowlisted POST events/<id>/tickets/<pk>/checkout-link/ and
	 * cached per ticket+coupon. '' on any failure — the caller then keeps
	 * the plain checkout_link (or the constructed URL), so a bad or expired
	 * coupon can only ever cost the visitor the discount, never the button.
	 * Failures are negative-cached briefly so an account without the
	 * endpoint cannot be re-POSTed on every ticket of every render.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @param string $ticket_id     HeySummit ticket ID.
	 * @param string $coupon        Coupon code to bake in.
	 */
	public static function couponed_checkout_link( string $connection_id, string $event_id, string $ticket_id, string $coupon ): string {
		if ( '' === $connection_id || '' === $event_id || '' === $ticket_id || '' === $coupon ) {
			return '';
		}

		$key    = 'eex_ticket_link_' . md5( EEX_VERSION . '|' . $connection_id . '|' . $event_id . '|' . $ticket_id . '|' . $coupon );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		$response   = null !== $connection
			? HeySummitClient::for_connection( $connection )->post(
				'events/' . rawurlencode( $event_id ) . '/tickets/' . rawurlencode( $ticket_id ) . '/checkout-link/',
				[ 'coupon' => $coupon ]
			)
			: null;

		$link = '';
		if ( is_array( $response ) ) {
			$link = (string) ( $response['checkout_link'] ?? $response['link'] ?? $response['url'] ?? '' );
			if ( ! preg_match( '#^https?://#i', $link ) ) {
				$link = '';
			}
			$link = esc_url_raw( $link );
		}

		/**
		 * How long a generated coupon link is reused. Failures ('' result)
		 * are kept only briefly so a transient API problem heals itself.
		 */
		$ttl = '' !== $link ? (int) apply_filters( 'eex_coupon_link_ttl', 12 * HOUR_IN_SECONDS ) : 5 * MINUTE_IN_SECONDS;
		set_transient( $key, $link, $ttl );

		return $link;
	}

	/**
	 * Display-shaped ticket data for the pricing component. Each row carries
	 * checkout_link — the API's dedicated per-ticket checkout URL (empty when
	 * the account does not return one yet), replaced by a coupon-baked
	 * variant when a coupon is given and generates successfully.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @param string $register_url  Event registration URL (already tagged).
	 * @param string $coupon        Optional coupon code to bake into links.
	 * @return array<int,array<string,mixed>> Empty on any failure (the
	 *                                        component renders its empty state).
	 */
	public static function for_display( string $connection_id, string $event_id, string $register_url, string $coupon = '' ): array {
		$tickets = self::raw( $connection_id, $event_id );

		if ( is_wp_error( $tickets ) ) {
			return [];
		}

		$out = [];

		foreach ( $tickets as $ticket ) {
			// Inactive tickets are not purchasable; respect the flag.
			if ( isset( $ticket['is_active'] ) && false === $ticket['is_active'] ) {
				continue;
			}

			// Only an absolute web URL may become a button destination;
			// anything else (relative noise, hostile schemes) falls back to
			// the constructed checkout URL.
			$checkout_link = (string) ( $ticket['checkout_link'] ?? '' );
			if ( ! preg_match( '#^https?://#i', $checkout_link ) ) {
				$checkout_link = '';
			}

			// A coupon-baked link replaces the plain one; generation failure
			// keeps the plain link, so the visitor loses only the discount.
			if ( '' !== $coupon ) {
				$couponed = self::couponed_checkout_link( $connection_id, $event_id, (string) $ticket['id'], $coupon );
				if ( '' !== $couponed ) {
					$checkout_link = $couponed;
				}
			}

			$prices = [];
			foreach ( self::prices_of( $ticket ) as $price ) {
				$prices[] = [
					'id'     => (string) ( $price['id'] ?? '' ),
					'title'  => (string) ( $price['title'] ?? $price['name'] ?? '' ),
					'amount' => (string) ( $price['price'] ?? $price['amount'] ?? '' ),
				];
			}

			$out[] = [
				'id'            => (string) $ticket['id'],
				'title'         => (string) ( $ticket['title'] ?? $ticket['name'] ?? $ticket['id'] ),
				'description'   => (string) ( $ticket['description'] ?? '' ),
				'is_paid'       => ! empty( $ticket['is_paid'] ) && 'false' !== strtolower( (string) $ticket['is_paid'] ),
				'popular'       => ! empty( $ticket['mark_as_popular'] ),
				'remaining'     => (string) ( $ticket['quantity_remaining'] ?? '' ),
				'applies'       => [
					'live'     => ! empty( $ticket['apply_to_broadcasts'] ),
					'replays'  => ! empty( $ticket['apply_to_replays'] ),
					'inperson' => ! empty( $ticket['apply_to_inperson'] ),
				],
				'prices'        => $prices,
				'checkout_link' => esc_url_raw( $checkout_link ),
				'register_url'  => $register_url,
			];
		}

		return $out;
	}
}
