<?php
/**
 * UTM auto-tagging.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Appends UTM parameters to every HeySummit register and event URL the
 * plugin outputs: configurable source and medium, campaign auto-set from
 * the rendering page's slug with a per-page override
 * (`_eex_utm_campaign`). Combined with the attribution table this closes
 * the loop: which page produced which registration. Active only once a
 * source is configured; existing UTM parameters on a URL are never
 * overwritten.
 */
final class Utm {

	/**
	 * Whether tagging is active (enabled and a source configured).
	 */
	public static function active(): bool {
		return (bool) Options::setting( 'utm_enabled' ) && '' !== (string) Options::setting( 'utm_source' );
	}

	/**
	 * Tag a URL.
	 *
	 * @param string $url             Outbound URL.
	 * @param int    $context_post_id Rendering page (0 = the queried object).
	 * @param string $campaign        Explicit campaign override.
	 */
	public static function tag( string $url, int $context_post_id = 0, string $campaign = '' ): string {
		if ( '' === $url || ! self::active() ) {
			return $url;
		}

		// Never clobber parameters already present on the URL.
		if ( str_contains( $url, 'utm_source=' ) ) {
			return $url;
		}

		$params = [
			'utm_source' => (string) Options::setting( 'utm_source' ),
			'utm_medium' => (string) Options::setting( 'utm_medium' ),
		];

		$campaign = '' !== $campaign ? $campaign : self::campaign( $context_post_id );
		if ( '' !== $campaign ) {
			$params['utm_campaign'] = $campaign;
		}

		return add_query_arg( array_map( 'rawurlencode', $params ), $url );
	}

	/**
	 * The campaign for a rendering context: per-page override meta first,
	 * then the page slug.
	 *
	 * @param int $context_post_id Rendering page (0 = the queried object).
	 */
	public static function campaign( int $context_post_id = 0 ): string {
		if ( 0 === $context_post_id && function_exists( 'get_queried_object_id' ) ) {
			$context_post_id = (int) get_queried_object_id();
		}

		if ( $context_post_id <= 0 ) {
			return '';
		}

		$override = (string) get_post_meta( $context_post_id, '_eex_utm_campaign', true );
		if ( '' !== $override ) {
			return sanitize_title( $override );
		}

		$post = get_post( $context_post_id );

		return $post ? (string) ( $post->post_name ?? '' ) : '';
	}

	/**
	 * Cache context: rendered component HTML embeds campaign-tagged URLs, so
	 * the component cache key must vary by campaign.
	 */
	public static function cache_context(): string {
		return self::active() ? self::campaign() : '';
	}
}
