<?php
/**
 * Injectable clock.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Support;

defined( 'ABSPATH' ) || exit;

/**
 * One source of "now" for the selection and lifecycle layers, so tests can
 * evaluate specific dates deterministically and the admin "Preview as at"
 * facility can render the compositions as though it were another moment.
 *
 * The freeze is request-scoped and never persisted: public renders always
 * see the real time, and every capability-checked preview restores the real
 * clock in a finally block. Code outside the composition layer keeps using
 * time() directly — existing behaviour is untouched.
 */
final class Clock {

	/**
	 * The frozen timestamp, 0 = real time.
	 *
	 * @var int
	 */
	private static int $frozen = 0;

	/**
	 * The current Unix timestamp (UTC).
	 */
	public static function now(): int {
		return self::$frozen > 0 ? self::$frozen : time();
	}

	/**
	 * Whether the clock is frozen (a test or an admin preview is active).
	 */
	public static function is_frozen(): bool {
		return self::$frozen > 0;
	}

	/**
	 * Freeze the clock at a timestamp (tests, admin preview).
	 *
	 * @param int $timestamp Unix timestamp; 0 or negative resets instead.
	 */
	public static function freeze( int $timestamp ): void {
		self::$frozen = max( 0, $timestamp );
	}

	/**
	 * Return to real time.
	 */
	public static function reset(): void {
		self::$frozen = 0;
	}
}
