<?php
/**
 * The API write allowlist.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The single place that defines which HeySummit endpoints may ever be
 * written to. The amended hard rule: no writes to the HeySummit API except
 * the WooCommerce module's attendee-create and external-ticket-sale-import
 * calls. HeySummitClient::post() consults this list and throws for anything
 * else — including every other resource the read side knows about (events,
 * talks, speakers, categories) and the event archive action.
 */
final class WriteEndpoints {

	/**
	 * Allowed write path prefixes, relative to the API base.
	 */
	public const ALLOWLIST = [
		'attendees/',
		'external-ticket-sales/',
	];

	/**
	 * Whether a relative path is allowed for writing.
	 *
	 * @param string $path Relative API path.
	 */
	public static function allowed( string $path ): bool {
		$path = ltrim( $path, '/' );

		// Normalise away any query string or traversal before matching.
		if ( str_contains( $path, '..' ) || str_contains( $path, '?' ) || str_contains( $path, '#' ) ) {
			return false;
		}

		foreach ( self::ALLOWLIST as $prefix ) {
			if ( str_starts_with( $path, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
