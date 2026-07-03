<?php
/**
 * Per-connection API path-style memory.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Live verification showed accounts where top-level collection routes
 * (talks/, tickets/) answer 403 while the same data is served nested under
 * the event (events/<id>/talks/) — DRF hyperlinked style. Fetchers try
 * every known style and remember the one that worked per connection, so
 * later requests lead with it instead of burning calls on refused routes.
 */
final class PathStyles {

	private const OPTION = 'eex_api_styles';

	/**
	 * The known styles for a resource, preferred (last working) first.
	 *
	 * @param string   $connection_id Connection ID.
	 * @param string   $resource_key  Resource key, e.g. 'talks'.
	 * @param string[] $styles        All styles in default order.
	 * @return string[]
	 */
	public static function ordered( string $connection_id, string $resource_key, array $styles ): array {
		$stored    = (array) get_option( self::OPTION, [] );
		$preferred = (string) ( $stored[ $connection_id ][ $resource_key ] ?? '' );

		if ( '' === $preferred || ! in_array( $preferred, $styles, true ) ) {
			return $styles;
		}

		return array_values( array_unique( array_merge( [ $preferred ], $styles ) ) );
	}

	/**
	 * Remember the style that worked.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $resource_key  Resource key.
	 * @param string $style         Style that produced usable data.
	 */
	public static function remember( string $connection_id, string $resource_key, string $style ): void {
		$stored = (array) get_option( self::OPTION, [] );

		if ( ( $stored[ $connection_id ][ $resource_key ] ?? '' ) === $style ) {
			return;
		}

		$stored[ $connection_id ][ $resource_key ] = $style;

		update_option( self::OPTION, $stored, false );
	}
}
