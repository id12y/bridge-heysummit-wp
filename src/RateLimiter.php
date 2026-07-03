<?php
/**
 * Simple per-IP rate limiting.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window per-IP counter over transients, for unauthenticated public
 * endpoints (the webhook receiver keeps its own identical logic). Fails
 * open on cache trouble: limiting is abuse protection, not access control.
 */
final class RateLimiter {

	/**
	 * Whether the current client is within the limit for a bucket, counting
	 * this request.
	 *
	 * @param string $bucket     Endpoint bucket key.
	 * @param int    $per_minute Allowed requests per minute per IP.
	 */
	public static function allow( string $bucket, int $per_minute = 60 ): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';

		$key   = 'eex_rl_' . md5( $bucket . '|' . $ip . '|' . gmdate( 'YmdHi' ) );
		$count = (int) get_transient( $key );

		if ( $count >= $per_minute ) {
			return false;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
