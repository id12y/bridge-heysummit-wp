<?php
/**
 * Component output cache.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Transient cache for server-rendered component HTML, keyed by component and
 * attributes plus a generation counter. Flushing bumps the generation, which
 * invalidates every cached fragment at once (sync completion, webhook
 * receipt).
 */
final class Cache {

	private const TTL        = 5 * MINUTE_IN_SECONDS;
	private const GENERATION = 'eex_cache_generation';

	/**
	 * Fetch a cached fragment.
	 *
	 * @param string              $component Component name.
	 * @param array<string,mixed> $atts      Attributes.
	 * @return string|null
	 */
	public static function get( string $component, array $atts ): ?string {
		$cached = get_transient( self::key( $component, $atts ) );

		return is_string( $cached ) ? $cached : null;
	}

	/**
	 * Store a fragment.
	 *
	 * @param string              $component Component name.
	 * @param array<string,mixed> $atts      Attributes.
	 * @param string              $html      Rendered HTML.
	 */
	public static function set( string $component, array $atts, string $html ): void {
		set_transient( self::key( $component, $atts ), $html, self::TTL );
	}

	/**
	 * Invalidate every cached fragment.
	 */
	public static function flush(): void {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
	}

	/**
	 * Build the transient key.
	 *
	 * @param string              $component Component name.
	 * @param array<string,mixed> $atts      Attributes.
	 */
	private static function key( string $component, array $atts ): string {
		ksort( $atts );

		return 'eex_c_' . md5( $component . '|' . wp_json_encode( $atts ) . '|' . (int) get_option( self::GENERATION, 0 ) );
	}
}
