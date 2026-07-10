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
 * written to. The hard rule (v2, re-verified against the published OpenAPI
 * spec — docs/decisions.md D45): the only sanctioned data writes are
 * attendee create and ticket assignment. In the real v2 API those live at
 * POST events/<id>/attendees/ and the documented-idempotent
 * POST events/<id>/attendees/<pk>/tickets/; the originally assumed
 * external-ticket-sales endpoint does not exist. The third entry,
 * checkout-link, is a generate-only POST (docs/decisions.md D91): it mints
 * a checkout URL — optionally with a coupon baked in — and mutates no
 * attendee, ticket or event data. HeySummitClient::post() consults this
 * list and throws for anything else — including event create/update (which
 * the spec exposes and this plugin must never touch), every other
 * resource, and anything with traversal or query noise.
 */
final class WriteEndpoints {

	/**
	 * Allowed write paths, as anchored patterns over the relative path.
	 * Numeric IDs only; no other segments; nothing else matches.
	 */
	public const ALLOWLIST = [
		'#^events/\d+/attendees/$#',
		'#^events/\d+/attendees/\d+/tickets/$#',
		'#^events/\d+/tickets/\d+/checkout-link/$#',
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

		foreach ( self::ALLOWLIST as $pattern ) {
			if ( 1 === preg_match( $pattern, $path ) ) {
				return true;
			}
		}

		return false;
	}
}
