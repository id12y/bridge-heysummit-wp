<?php
/**
 * Session category names seen on any Lite fetch, for editor dropdowns.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Full mode's category pickers read taxonomy terms; Lite has no terms, so
 * every category the live repository maps leaves its name behind
 * (slug => name, non-autoloaded, capped) — the same pattern as event and
 * ticket titles. Pickers fall back to this when no terms exist.
 */
final class CategoryTitles {

	/**
	 * Per-request de-duplication: one option write per request at most.
	 *
	 * @var bool
	 */
	private static bool $dirty = false;

	/**
	 * Pending names for this request (slug => name).
	 *
	 * @var array<string,string>
	 */
	private static array $pending = [];

	/**
	 * Remember category names (slug => name objects or arrays tolerated).
	 *
	 * @param array<int,object|array<string,string>> $categories Mapped categories.
	 */
	public static function remember( array $categories ): void {
		foreach ( $categories as $category ) {
			$category = (array) $category;
			$slug     = (string) ( $category['slug'] ?? '' );
			$name     = (string) ( $category['name'] ?? '' );

			if ( '' !== $slug && '' !== $name && ! isset( self::$pending[ $slug ] ) ) {
				self::$pending[ $slug ] = $name;
				self::$dirty            = true;
			}
		}

		if ( ! self::$dirty ) {
			return;
		}

		$known  = self::known();
		$merged = array_merge( $known, self::$pending );

		if ( $merged !== $known ) {
			update_option( 'eex_category_titles', array_slice( $merged, -100, null, true ), false );
		}

		self::$dirty = false;
	}

	/**
	 * Clear the per-request memo (tests; a real request never needs it).
	 */
	public static function reset_request_state(): void {
		self::$pending = [];
		self::$dirty   = false;
	}

	/**
	 * Every category this site has seen (slug => name).
	 *
	 * @return array<string,string>
	 */
	public static function known(): array {
		$titles = get_option( 'eex_category_titles', [] );

		return is_array( $titles ) ? array_map( 'strval', $titles ) : [];
	}
}
