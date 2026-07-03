<?php
/**
 * Live-mode response cache.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Transients only — self-expiring, nothing of substance stored. Each cached
 * resource has a fresh copy (configurable TTL, default 15 minutes) and a
 * last-good copy (24 hours) served whenever the API fails or the request
 * budget is spent. A hard per-request budget of 2 cold fetches plus a
 * stampede lock means a cold page triggers at most two short API calls no
 * matter how many components or visitors hit it; everything else renders
 * from last-good or the empty state and the cache warms on later views.
 */
final class LiveCache {

	public const COLD_BUDGET = 2;

	private const GENERATION  = 'eex_live_generation';
	private const LOCK_WINDOW = 30;

	/**
	 * Cold fetches performed during this request.
	 *
	 * @var int
	 */
	private static int $cold = 0;

	/**
	 * Fetch a resource through the cache.
	 *
	 * @param string   $key   Stable resource key (resource + parameters).
	 * @param callable $fetch Returns the value, a WP_Error (failure with a
	 *                        reason, recorded for the dashboard), or null
	 *                        (failure without one). The callable owns its
	 *                        timeout (3 seconds for API calls) so a page
	 *                        never hangs.
	 * @return mixed Fresh value, last-good value, or null (empty state).
	 */
	public static function remember( string $key, callable $fetch ) {
		$fresh_key = self::key( 'live', $key );
		$good_key  = self::key( 'lg', $key );

		$fresh = get_transient( $fresh_key );
		if ( false !== $fresh ) {
			return $fresh;
		}

		// Budget spent: no more cold fetches this request.
		if ( self::$cold >= self::budget() ) {
			return self::last_good( $good_key );
		}

		// Stampede lock: concurrent visitors trigger one fetch, not many.
		if ( ! self::lock( $key ) ) {
			return self::last_good( $good_key );
		}

		++self::$cold;

		$reason = '';

		try {
			$value = $fetch();

			if ( is_wp_error( $value ) ) {
				$reason = $value->get_error_message();
				$value  = null;
			}
		} catch ( \Throwable $e ) {
			// Never a fatal from a render path.
			$reason = $e->getMessage();
			$value  = null;
		}

		self::unlock( $key );

		if ( null === $value ) {
			self::note( 'failure', sprintf( '%s [%s]', $reason ?: 'no data returned', $key ) );

			return self::last_good( $good_key );
		}

		set_transient( $fresh_key, $value, self::ttl() );
		set_transient( $good_key, $value, DAY_IN_SECONDS );
		self::note( 'success' );

		return $value;
	}

	/**
	 * Flush every live transient at once (generation bump: O(1); orphaned
	 * rows expire by their own TTL). Also resets the request budget.
	 */
	public static function flush(): void {
		update_option( self::GENERATION, (int) get_option( self::GENERATION, 0 ) + 1, false );
		delete_transient( 'eex_live_status' );
		self::reset_request_state();
	}

	/**
	 * Reset per-request statics (tests, mode switches).
	 */
	public static function reset_request_state(): void {
		self::$cold = 0;
	}

	/**
	 * Cold fetches spent this request.
	 */
	public static function spent(): int {
		return self::$cold;
	}

	/**
	 * Cache status for the dashboard widget.
	 *
	 * @return array<string,string> last_success / last_failure timestamps
	 *                              plus the last failure's reason.
	 */
	public static function status(): array {
		$status = (array) get_transient( 'eex_live_status' );

		return [
			'last_success' => (string) ( $status['last_success'] ?? '' ),
			'last_failure' => (string) ( $status['last_failure'] ?? '' ),
			'last_error'   => (string) ( $status['last_error'] ?? '' ),
		];
	}

	/**
	 * Whether the most recent fetch attempt failed (pages are on last-good
	 * or empty-state data).
	 */
	public static function degraded(): bool {
		$status = self::status();

		return '' !== $status['last_failure'] && $status['last_failure'] > $status['last_success'];
	}

	/**
	 * The per-request cold-fetch budget.
	 */
	private static function budget(): int {
		/**
		 * Filter the per-request cold-fetch budget (default 2).
		 *
		 * @param int $budget Cold fetches allowed per page request.
		 */
		return max( 1, (int) apply_filters( 'eex_live_budget', self::COLD_BUDGET ) );
	}

	/**
	 * Fresh TTL in seconds from the lite_ttl setting (minutes).
	 */
	private static function ttl(): int {
		return max( 1, (int) Options::setting( 'lite_ttl' ) ) * MINUTE_IN_SECONDS;
	}

	/**
	 * The last-good copy, or null.
	 *
	 * @param string $good_key Last-good transient key.
	 * @return mixed
	 */
	private static function last_good( string $good_key ) {
		$good = get_transient( $good_key );

		return false === $good ? null : $good;
	}

	/**
	 * Take the fetch lock for a key. add_option is an INSERT, so exactly
	 * one concurrent request wins; stale locks (crashed requests) expire
	 * after LOCK_WINDOW seconds.
	 *
	 * @param string $key Resource key.
	 */
	private static function lock( string $key ): bool {
		$name = 'eex_live_lock_' . md5( $key );

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		$taken = (int) get_option( $name, 0 );

		if ( $taken > 0 && $taken < time() - self::LOCK_WINDOW ) {
			// Stale: steal it.
			update_option( $name, time(), false );

			return true;
		}

		return false;
	}

	/**
	 * Release the fetch lock.
	 *
	 * @param string $key Resource key.
	 */
	private static function unlock( string $key ): void {
		delete_option( 'eex_live_lock_' . md5( $key ) );
	}

	/**
	 * Record fetch outcome for the dashboard.
	 *
	 * @param string $outcome 'success' or 'failure'.
	 * @param string $reason  Failure reason (kept until the next failure).
	 */
	private static function note( string $outcome, string $reason = '' ): void {
		$status = (array) get_transient( 'eex_live_status' );

		$status[ 'last_' . $outcome ] = gmdate( 'Y-m-d H:i:s' );

		if ( 'failure' === $outcome ) {
			$status['last_error'] = $reason;
		}

		set_transient( 'eex_live_status', $status, DAY_IN_SECONDS );
	}

	/**
	 * Build a generation-scoped transient key.
	 *
	 * @param string $prefix 'live' or 'lg'.
	 * @param string $key    Resource key.
	 */
	private static function key( string $prefix, string $key ): string {
		return 'eex_' . $prefix . '_' . md5( $key . '|' . (int) get_option( self::GENERATION, 0 ) );
	}
}
