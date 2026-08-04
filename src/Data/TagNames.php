<?php
/**
 * Event tag names seen on any fetch, so a talk's tag ID can be shown.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

defined( 'ABSPATH' ) || exit;

/**
 * A talk's `custom_tag` arrives as the tag's record ID, and the talk
 * payload carries the wording nowhere. The EVENT payload does: it lists
 * its tags inline (docs/api-notes.md, live verification). Every event
 * fetch therefore leaves its tags behind as id => name, and the mappers
 * translate the talk's ID through that memory.
 *
 * Not a guess and not a fallback label: an ID that resolves to nothing
 * stays unshown. Same option-backed pattern as EventTitles and
 * CategoryTitles, non-autoloaded and capped.
 */
final class TagNames {

	/**
	 * Per-request de-duplication: one option write per request at most.
	 *
	 * @var bool
	 */
	private static bool $dirty = false;

	/**
	 * Pending names for this request (id => name).
	 *
	 * @var array<string,string>
	 */
	private static array $pending = [];

	/**
	 * Remember the tags listed on a raw event record.
	 *
	 * The shape is read defensively because it is undocumented: a list of
	 * objects with an id and a title/name is what the account sends, but
	 * a list of bare strings (no ID to key on) is tolerated and ignored
	 * rather than mis-keyed.
	 *
	 * @param mixed $tags Raw `tags` value from an event record.
	 */
	public static function remember( $tags ): void {
		if ( ! is_array( $tags ) ) {
			return;
		}

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) ) {
				continue;
			}

			$id   = isset( $tag['id'] ) && is_scalar( $tag['id'] ) ? trim( (string) $tag['id'] ) : '';
			$name = '';

			foreach ( [ 'title', 'name', 'label', 'tag' ] as $key ) {
				if ( isset( $tag[ $key ] ) && is_scalar( $tag[ $key ] ) && '' !== trim( (string) $tag[ $key ] ) ) {
					$name = trim( (string) $tag[ $key ] );
					break;
				}
			}

			// A name that is itself a number tells a visitor nothing, and
			// an unkeyed tag cannot be looked up.
			if ( '' === $id || '' === $name || preg_match( '/^\d+$/', $name ) ) {
				continue;
			}

			if ( ! isset( self::$pending[ $id ] ) ) {
				self::$pending[ $id ] = $name;
				self::$dirty          = true;
			}
		}

		if ( ! self::$dirty ) {
			return;
		}

		// NOT array_merge: tag IDs are numeric, PHP stores them as integer
		// keys, and array_merge renumbers integer keys from zero — which
		// silently turns "tag 2 is a Conference" into "tag 0 is".
		$known  = self::known();
		$merged = $known;

		foreach ( self::$pending as $id => $name ) {
			$merged[ $id ] = $name;
		}

		if ( $merged !== $known ) {
			update_option( 'eex_tag_names', array_slice( $merged, -100, null, true ), false );
		}

		self::$dirty = false;
	}

	/**
	 * The name behind a tag ID, or '' when the site has never seen it.
	 *
	 * @param string $id Tag ID.
	 */
	public static function name( string $id ): string {
		$known = self::known();

		return (string) ( $known[ trim( $id ) ] ?? '' );
	}

	/**
	 * Clear the per-request memo (tests; a real request never needs it).
	 */
	public static function reset_request_state(): void {
		self::$pending = [];
		self::$dirty   = false;
	}

	/**
	 * Every tag this site has seen (id => name).
	 *
	 * @return array<string,string>
	 */
	public static function known(): array {
		$names = get_option( 'eex_tag_names', [] );

		return is_array( $names ) ? array_map( 'strval', $names ) : [];
	}
}
