<?php
/**
 * Event and session lifecycle resolution.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

use Emailexpert\Events\Data\EventPresentation;
use Emailexpert\Events\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One lifecycle resolver for the compositions, so no template compares
 * time() by hand. Chronological state, registration state and replay state
 * are kept separate — an ended event can still sell nothing, an open event
 * can be months away — and every reading also names the next moment the
 * answer changes, which is what bounds the composition cache.
 *
 * The rendered HTML keeps the plugin's existing time discipline: cached
 * markup never claims live state in text (eex-time.js decides that in the
 * browser); the resolver's 'live'/'starting_soon' readings steer section
 * emphasis, CTA wiring and cache boundaries, all of which age gracefully.
 */
final class EventLifecycle {

	/**
	 * Minutes before the start that count as "starting soon" (matches the
	 * eex-time.js soonMinutes window).
	 */
	private const SOON_MINUTES = 60;

	/**
	 * The modelled duration of a session whose end is unknown — the same
	 * one-hour fallback the repositories already use for talk ends.
	 */
	private const FALLBACK_DURATION = HOUR_IN_SECONDS;

	/**
	 * Resolve the lifecycle of a feature target.
	 *
	 * @param array<string,mixed>      $event        Owning event data array.
	 * @param array<string,mixed>|null $session      Session data array when the
	 *                                               target is (presented as) a session.
	 * @param array<string,mixed>|null $presentation Sanitised presentation values
	 *                                               (null = resolved here).
	 * @return array{
	 *     status:string, registration_open:bool, replay_available:bool,
	 *     starts_ts:int, ends_ts:int, notice:string, boundaries:int[]
	 * }
	 */
	public static function resolve( array $event, ?array $session = null, ?array $presentation = null ): array {
		$now          = Clock::now();
		$presentation = $presentation ?? EventPresentation::for_event( $event );

		[ $starts_ts, $ends_ts ] = self::window( $event, $session );

		$evergreen = ! empty( $event['evergreen'] ) && null === $session && 0 === $starts_ts;
		$boundary  = [];

		foreach ( [ $starts_ts - self::SOON_MINUTES * MINUTE_IN_SECONDS, $starts_ts, $ends_ts ] as $ts ) {
			if ( $ts > $now ) {
				$boundary[] = $ts;
			}
		}

		$boundary = array_merge( $boundary, EventPresentation::future_boundaries( $presentation, $now ) );

		// Overrides first: a cancelled or postponed event must never read as
		// merely scheduled, and a cancelled session (HeySummit's own flag)
		// counts the same as an operator override.
		$override = (string) ( $presentation['status'] ?? 'auto' );

		if ( 'cancelled' === $override || ! empty( $session['cancelled'] ) || ! empty( $event['cancelled'] ) ) {
			return self::reading( 'cancelled', false, false, $starts_ts, $ends_ts, (string) $presentation['status_message'], $boundary );
		}

		if ( 'postponed' === $override ) {
			return self::reading( 'postponed', false, self::has_replay( $event, $session ), $starts_ts, $ends_ts, (string) $presentation['status_message'], $boundary );
		}

		$registration_open = ! empty( $event['open'] );
		$replay            = self::has_replay( $event, $session );
		$notice            = 'scheduled' === $override ? (string) $presentation['status_message'] : '';

		if ( $evergreen ) {
			return self::reading( 'evergreen', $registration_open, $replay, $starts_ts, $ends_ts, $notice, $boundary );
		}

		if ( 0 === $starts_ts ) {
			// Dated nothing and not evergreen: treat as scheduled-without-a-date.
			return self::reading( 'scheduled', $registration_open, $replay, 0, 0, $notice, $boundary );
		}

		if ( $now >= $ends_ts ) {
			$status = $replay ? 'replay' : 'ended';

			return self::reading( $status, false, $replay, $starts_ts, $ends_ts, $notice, $boundary );
		}

		if ( $now >= $starts_ts ) {
			return self::reading( 'live', $registration_open, $replay, $starts_ts, $ends_ts, $notice, $boundary );
		}

		if ( $now >= $starts_ts - self::SOON_MINUTES * MINUTE_IN_SECONDS ) {
			return self::reading( 'starting_soon', $registration_open, $replay, $starts_ts, $ends_ts, $notice, $boundary );
		}

		return self::reading( 'scheduled', $registration_open, $replay, $starts_ts, $ends_ts, $notice, $boundary );
	}

	/**
	 * The chronological window of a target: the session's own when given,
	 * otherwise the event's range. An event with no final end on record is
	 * modelled as ending one hour after its last session begins (the same
	 * fallback duration the repositories use), so a multi-day event stays
	 * current through its final day and never vanishes at the start of day
	 * one.
	 *
	 * @param array<string,mixed>      $event   Event data array.
	 * @param array<string,mixed>|null $session Session data array.
	 * @return array{0:int,1:int} Start and end timestamps (0 = undated).
	 */
	public static function window( array $event, ?array $session = null ): array {
		if ( null !== $session ) {
			$start = self::ts( (string) ( $session['starts_at'] ?? '' ) );
			$end   = self::ts( (string) ( $session['ends_at'] ?? '' ) );

			if ( 0 === $end && $start > 0 ) {
				$end = $start + self::FALLBACK_DURATION;
			}

			return [ $start, max( $start, $end ) ];
		}

		$first = self::ts( (string) ( $event['first_talk_at'] ?? '' ) );
		$last  = self::ts( (string) ( $event['last_talk_at'] ?? '' ) );

		if ( 0 === $first && 0 === $last ) {
			return [ 0, 0 ];
		}

		if ( 0 === $first ) {
			$first = $last;
		}

		$end = max( $first, $last ) + self::FALLBACK_DURATION;

		return [ $first, $end ];
	}

	/**
	 * Whether the target has a replay to offer.
	 *
	 * Session targets answer from their own replay URL. Event targets answer
	 * from a caller-supplied 'has_replays' hint when present (the landing
	 * page already knows its past sessions) and never trigger a fetch here.
	 *
	 * @param array<string,mixed>      $event   Event data array.
	 * @param array<string,mixed>|null $session Session data array.
	 */
	private static function has_replay( array $event, ?array $session ): bool {
		if ( null !== $session ) {
			return '' !== (string) ( $session['replay_url'] ?? '' );
		}

		return ! empty( $event['has_replays'] );
	}

	/**
	 * Assemble one reading.
	 *
	 * @param string $status            Chronological status.
	 * @param bool   $registration_open Registration state.
	 * @param bool   $replay            Replay availability.
	 * @param int    $starts_ts         Start timestamp.
	 * @param int    $ends_ts           End timestamp.
	 * @param string $notice            Public status message ('' = none).
	 * @param int[]  $boundaries        Future timestamps where the reading changes.
	 * @return array<string,mixed>
	 */
	private static function reading( string $status, bool $registration_open, bool $replay, int $starts_ts, int $ends_ts, string $notice, array $boundaries ): array {
		$reading = [
			'status'            => $status,
			'registration_open' => $registration_open,
			'replay_available'  => $replay,
			'starts_ts'         => $starts_ts,
			'ends_ts'           => $ends_ts,
			'notice'            => $notice,
			'boundaries'        => array_values( array_unique( $boundaries ) ),
		];

		/**
		 * Filter the final lifecycle reading for a composition target.
		 *
		 * @param array<string,mixed> $reading Lifecycle reading.
		 */
		return (array) apply_filters( 'eex_lifecycle_result', $reading );
	}

	/**
	 * Parse an ISO timestamp (0 = missing/unparseable).
	 *
	 * @param string $iso ISO 8601 string.
	 */
	private static function ts( string $iso ): int {
		if ( '' === $iso ) {
			return 0;
		}

		$ts = strtotime( $iso );

		return false === $ts ? 0 : $ts;
	}
}
