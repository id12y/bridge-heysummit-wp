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

		// Key versioned (v2) so upgrading picks up checkout_link-bearing rows
		// immediately instead of after the old cache's 15 minutes.
		$key    = 'eex_tickets_' . md5( 'v2|' . $connection_id . '|' . $event_id );
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
	 * Display-shaped ticket data for the pricing component. Each row carries
	 * checkout_link — the API's dedicated per-ticket checkout URL (empty when
	 * the account does not return one yet).
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @param string $register_url  Event registration URL (already tagged).
	 * @return array<int,array<string,mixed>> Empty on any failure (the
	 *                                        component renders its empty state).
	 */
	public static function for_display( string $connection_id, string $event_id, string $register_url ): array {
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
