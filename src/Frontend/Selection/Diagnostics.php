<?php
/**
 * Per-render selection diagnostics and cache boundaries.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

use Emailexpert\Events\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * A per-render collector the selection layer writes into while a composition
 * assembles itself: human-readable notes on why each choice fell the way it
 * did (surfaced to administrators only, never to visitors), and the future
 * timestamps at which the selection or lifecycle would change (the earliest
 * of which bounds the composition's cache lifetime).
 *
 * Components::render() resets it before a composition renders and reads it
 * straight after, so nested renders can never leak notes across widgets.
 */
final class Diagnostics {

	/**
	 * Notes for the administrator explanation, in the order recorded.
	 *
	 * @var string[]
	 */
	private static array $notes = [];

	/**
	 * Candidate future boundaries (Unix timestamps).
	 *
	 * @var int[]
	 */
	private static array $boundaries = [];

	/**
	 * Start a fresh collection.
	 */
	public static function reset(): void {
		self::$notes      = [];
		self::$boundaries = [];
	}

	/**
	 * Record an explanation line.
	 *
	 * @param string $text One line, already translated.
	 */
	public static function note( string $text ): void {
		if ( '' !== trim( $text ) ) {
			self::$notes[] = $text;
		}
	}

	/**
	 * Record a moment at which the rendered result would change.
	 *
	 * @param int $timestamp Unix timestamp (past values are ignored).
	 */
	public static function boundary( int $timestamp ): void {
		if ( $timestamp > Clock::now() ) {
			self::$boundaries[] = $timestamp;
		}
	}

	/**
	 * Record several boundaries at once.
	 *
	 * @param int[] $timestamps Unix timestamps.
	 */
	public static function boundaries( array $timestamps ): void {
		foreach ( $timestamps as $timestamp ) {
			self::boundary( (int) $timestamp );
		}
	}

	/**
	 * The recorded notes.
	 *
	 * @return string[]
	 */
	public static function notes(): array {
		return self::$notes;
	}

	/**
	 * The earliest future boundary, or 0 when none is on record.
	 */
	public static function next_boundary(): int {
		$now    = Clock::now();
		$future = array_filter( self::$boundaries, static fn( int $ts ): bool => $ts > $now );

		return empty( $future ) ? 0 : min( $future );
	}
}
