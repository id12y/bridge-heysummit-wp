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
		self::keep( $component, $atts, $html, false );
	}

	/**
	 * Store a fragment and return what should actually be shown.
	 *
	 * Guardrails against a failure dressed up as emptiness: an empty state
	 * is never pinned for the full display TTL (a cold or failed fetch must
	 * retry within a minute), and when the data source is fallible (a remote
	 * API rather than the local database) the last good fragment — kept for
	 * six hours, surviving generation flushes — is served instead of a fresh
	 * empty. The time module already recomputes session states on aged HTML,
	 * so a slightly stale list degrades gracefully; a genuinely emptied
	 * schedule shows through once the last good copy ages out.
	 *
	 * $boundary is the compositions' time-awareness: the next Unix timestamp
	 * at which the fragment's selection or lifecycle would change (a featured
	 * event ending, a pin or promotion window expiring, an event going live).
	 * The effective TTL becomes the smaller of the normal display lifetime
	 * and the time until that boundary, floored at one minute so a boundary
	 * seconds away cannot churn the cache. 0 means no boundary — the normal
	 * lifetime applies, exactly as before this parameter existed.
	 *
	 * @param string              $component Component name.
	 * @param array<string,mixed> $atts      Attributes.
	 * @param string              $html      Rendered HTML.
	 * @param bool                $fallible  Whether the source can fail (Lite mode, ticket fetches).
	 * @param int                 $boundary  Next selection/lifecycle change (Unix timestamp, 0 = none).
	 */
	public static function keep( string $component, array $atts, string $html, bool $fallible, int $boundary = 0 ): string {
		if ( ! str_contains( $html, 'eex-empty' ) ) {
			set_transient( self::key( $component, $atts ), $html, self::bounded_ttl( $boundary ) );

			if ( $fallible ) {
				set_transient( self::stale_key( $component, $atts ), $html, 6 * HOUR_IN_SECONDS );
			}

			return $html;
		}

		if ( $fallible ) {
			$stale = get_transient( self::stale_key( $component, $atts ) );

			if ( is_string( $stale ) && '' !== $stale ) {
				set_transient( self::key( $component, $atts ), $stale, MINUTE_IN_SECONDS );

				return $stale;
			}
		}

		set_transient( self::key( $component, $atts ), $html, min( self::ttl(), MINUTE_IN_SECONDS ) );

		return $html;
	}

	/**
	 * Fragment lifetime from the cache_ttl setting (minutes, default 5).
	 * Sync completion, webhooks and editorial saves still flush immediately;
	 * this only bounds how long unchanged fragments (and random speaker
	 * selections) live.
	 */
	private static function ttl(): int {
		return max( 1, min( 1440, (int) \Emailexpert\Events\Options::setting( 'cache_ttl' ) ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * The effective TTL against a selection/lifecycle boundary: the normal
	 * lifetime, shortened so a cached composition cannot outlive the moment
	 * its content stops being true. Floored at one minute — an imminent
	 * boundary must not make every request a fresh render.
	 *
	 * @param int $boundary Next change (Unix timestamp, 0 = none).
	 */
	public static function bounded_ttl( int $boundary ): int {
		$ttl = self::ttl();

		if ( $boundary <= 0 ) {
			return $ttl;
		}

		$until = $boundary - time();

		if ( $until <= 0 ) {
			return MINUTE_IN_SECONDS;
		}

		return max( MINUTE_IN_SECONDS, min( $ttl, $until ) );
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

	/**
	 * The last-good key: deliberately NOT generation-scoped, so the safety
	 * copy survives flushes (a flush followed by a failed fetch is exactly
	 * when it is needed).
	 *
	 * @param string              $component Component name.
	 * @param array<string,mixed> $atts      Attributes.
	 */
	private static function stale_key( string $component, array $atts ): string {
		ksort( $atts );

		return 'eex_lg_' . md5( $component . '|' . wp_json_encode( $atts ) );
	}
}
