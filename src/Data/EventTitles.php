<?php
/**
 * Event titles seen on any Lite fetch, for editor dropdowns.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Lite mode has no synced event posts, so the editors' event pickers have
 * nothing local to list. Mirroring the ticket-titles memory: every event
 * the live repository maps leaves its title behind (event ID => title,
 * non-autoloaded, capped), and pickers read it back.
 */
final class EventTitles {

	/**
	 * Per-request de-duplication: one option write per event per request.
	 *
	 * @var array<string,bool>
	 */
	private static array $seen = [];

	/**
	 * Remember one event's title.
	 *
	 * @param string $event_id HeySummit event ID.
	 * @param string $title    Event title.
	 */
	public static function remember( string $event_id, string $title ): void {
		if ( '' === $event_id || '' === $title || isset( self::$seen[ $event_id ] ) ) {
			return;
		}

		self::$seen[ $event_id ] = true;

		$titles = self::known();

		if ( ( $titles[ $event_id ] ?? null ) === $title ) {
			return;
		}

		$titles[ $event_id ] = $title;
		update_option( 'eex_event_titles', array_slice( $titles, -50, null, true ), false );
	}

	/**
	 * Clear the per-request memo (tests; a real request never needs it).
	 */
	public static function reset_request_state(): void {
		self::$seen = [];
	}

	/**
	 * Every event title this site has seen (event ID => title).
	 *
	 * @return array<string,string>
	 */
	public static function known(): array {
		$titles = get_option( 'eex_event_titles', [] );

		return is_array( $titles ) ? array_map( 'strval', $titles ) : [];
	}
}
