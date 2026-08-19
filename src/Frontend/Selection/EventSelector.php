<?php
/**
 * Automatic event selection and the More Events list.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

use Emailexpert\Events\Data\EventPresentation;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * The event pool behind the compositions: which events are candidates for
 * the automatic homepage feature, which one automatic mode picks, and what
 * the More Events list contains after the featured event is removed.
 *
 * Candidates come only from events the operator has configured for public
 * display — the configured Live Display events in Lite, published and
 * non-excluded event posts in Full (excluded posts are drafts, which the
 * repository never returns) — never every event on every connected account.
 * Deduplication is by canonical identity (EventIdentity), never by array
 * position or offset, and always happens before the limit so the list
 * refills after exclusions.
 */
final class EventSelector {

	/**
	 * The More Events source modes.
	 */
	public const MORE_MODES = [ 'all_upcoming', 'after_featured', 'same_series', 'upcoming_sessions' ];

	/**
	 * The automatic selection strategies.
	 */
	public const STRATEGIES = [ 'chronological', 'priority' ];

	/**
	 * Per-request memo of the enriched candidate pool.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private static ?array $pool = null;

	/**
	 * Drop request-scoped memos (tests, admin preview, long processes).
	 */
	public static function reset_request_state(): void {
		self::$pool = null;
	}

	/**
	 * Every current (upcoming or still-running) displayable event, enriched
	 * with its presentation values, effective promotion level and canonical
	 * identity. Soonest first, evergreen events last — the repository's own
	 * order.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function candidates(): array {
		if ( null !== self::$pool ) {
			return self::$pool;
		}

		$now  = Clock::now();
		$pool = [];

		foreach ( Repositories::current()->upcoming_events( [ 'limit' => 0 ] ) as $event ) {
			$presentation = EventPresentation::for_event( $event );

			[ $starts_ts, $ends_ts ] = EventLifecycle::window( $event );

			// The repository's "upcoming" verdict is real-time; under a frozen
			// clock (tests, admin preview) re-check against the injected now so
			// a preview dated after an event genuinely rolls past it.
			if ( ! Clock::is_frozen() || 0 === $ends_ts || $ends_ts > $now || ! empty( $event['evergreen'] ) ) {
				$event['presentation']    = $presentation;
				$event['effective_level'] = EventPresentation::effective_level( $presentation, $now );
				$event['identity']        = EventIdentity::of( $event );
				$event['starts_ts']       = $starts_ts;
				$event['ends_ts']         = $ends_ts;

				$pool[] = $event;
			}
		}

		/**
		 * Filter the automatic candidate pool for the compositions.
		 *
		 * @param array<int,array<string,mixed>> $pool Enriched event data arrays.
		 */
		self::$pool = (array) apply_filters( 'eex_feature_candidates', $pool );

		return self::$pool;
	}

	/**
	 * The event automatic mode features, or null when nothing is eligible.
	 *
	 * Chronological (default) takes the next eligible event and ignores
	 * promotion levels entirely; priority takes the highest effective level
	 * first (flagship, featured, normal), then the soonest within a level.
	 * "Do not promote" events and events with the homepage-feature toggle
	 * off are never picked automatically under either strategy.
	 *
	 * @param string $strategy 'chronological' or 'priority'.
	 * @return array<string,mixed>|null Enriched event data array.
	 */
	public static function auto_target( string $strategy = 'chronological' ): ?array {
		$eligible = array_values(
			array_filter(
				self::candidates(),
				static function ( array $event ): bool {
					if ( empty( $event['presentation']['feature_eligible'] ) ) {
						Diagnostics::note(
							sprintf(
								/* translators: %s: event title. */
								__( '%s: skipped for automatic feature — its "eligible for automatic homepage feature" switch is off.', 'emailexpert-events' ),
								(string) $event['title']
							)
						);

						return false;
					}

					if ( 'none' === (string) $event['effective_level'] ) {
						Diagnostics::note(
							sprintf(
								/* translators: %s: event title. */
								__( '%s: skipped for automatic feature — its promotion level is "Do not promote".', 'emailexpert-events' ),
								(string) $event['title']
							)
						);

						return false;
					}

					return true;
				}
			)
		);

		if ( empty( $eligible ) ) {
			return null;
		}

		if ( 'priority' === $strategy ) {
			$rank = [
				'flagship' => 0,
				'featured' => 1,
				'normal'   => 2,
			];

			usort(
				$eligible,
				static function ( array $a, array $b ) use ( $rank ): int {
					$level = ( $rank[ (string) $a['effective_level'] ] ?? 2 ) <=> ( $rank[ (string) $b['effective_level'] ] ?? 2 );

					if ( 0 !== $level ) {
						return $level;
					}

					return self::chronological_order( $a, $b );
				}
			);

			// Promotion windows can open or close and change this ordering.
			foreach ( $eligible as $event ) {
				Diagnostics::boundaries( EventPresentation::future_boundaries( (array) $event['presentation'] ) );
			}
		} else {
			usort( $eligible, [ self::class, 'chronological_order' ] );
		}

		return $eligible[0];
	}

	/**
	 * The More Events list: upcoming eligible events with the featured event
	 * removed by canonical identity BEFORE the limit is applied, so a
	 * request for three returns three whenever three eligible events exist.
	 *
	 * @param string                   $mode     'all_upcoming', 'after_featured' or 'same_series'.
	 * @param int                      $limit    Rows wanted (0 = all).
	 * @param array<string,mixed>|null $featured The featured target's OWNING event
	 *                                           (null = nothing featured).
	 * @return array<int,array<string,mixed>>
	 */
	public static function more_events( string $mode, int $limit, ?array $featured ): array {
		$mode = in_array( $mode, self::MORE_MODES, true ) ? $mode : 'all_upcoming';
		$pool = self::candidates();
		$out  = [];
		$seen = [];

		$featured_start = null !== $featured ? EventLifecycle::window( $featured )[0] : 0;
		$series_slugs   = [];

		if ( 'same_series' === $mode && null !== $featured ) {
			foreach ( (array) ( $featured['series'] ?? [] ) as $series ) {
				$slug = is_array( $series ) ? (string) ( $series['slug'] ?? '' ) : (string) ( $series->slug ?? '' );

				if ( '' !== $slug ) {
					$series_slugs[] = $slug;
				}
			}

			if ( empty( $series_slugs ) ) {
				Diagnostics::note( __( 'More Events is set to "same series", but the featured event belongs to no series — the list is empty. (Lite mode has no series data; use another source mode there.)', 'emailexpert-events' ) );
			}
		}

		foreach ( $pool as $event ) {
			$title = (string) $event['title'];

			// The featured event is always excluded, whatever selected it and
			// wherever it falls chronologically.
			if ( null !== $featured && EventIdentity::same( $event, $featured ) ) {
				Diagnostics::note(
					/* translators: %s: event title. */
					sprintf( __( '%s: excluded from More Events because it is the featured event.', 'emailexpert-events' ), $title )
				);
				continue;
			}

			if ( empty( $event['presentation']['more_eligible'] ) ) {
				Diagnostics::note(
					/* translators: %s: event title. */
					sprintf( __( '%s: excluded because its More Events eligibility is off.', 'emailexpert-events' ), $title )
				);
				continue;
			}

			if ( 'after_featured' === $mode && $featured_start > 0 && (int) $event['starts_ts'] < $featured_start ) {
				Diagnostics::note(
					/* translators: %s: event title. */
					sprintf( __( '%s: excluded because it starts before the featured event ("after featured" mode).', 'emailexpert-events' ), $title )
				);
				continue;
			}

			if ( 'same_series' === $mode ) {
				$slugs = [];
				foreach ( (array) ( $event['series'] ?? [] ) as $series ) {
					$slugs[] = is_array( $series ) ? (string) ( $series['slug'] ?? '' ) : (string) ( $series->slug ?? '' );
				}

				if ( empty( array_intersect( $series_slugs, $slugs ) ) ) {
					Diagnostics::note(
						/* translators: %s: event title. */
						sprintf( __( '%s: excluded because it is not in the featured event\'s series.', 'emailexpert-events' ), $title )
					);
					continue;
				}
			}

			// Deduplicate by canonical identity, never by position.
			$identity = (string) $event['identity'];
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;

			Diagnostics::note(
				/* translators: %s: event title. */
				sprintf( __( '%s: included in More Events.', 'emailexpert-events' ), $title )
			);

			// The list changes when a listed event's window closes.
			if ( (int) $event['ends_ts'] > 0 ) {
				Diagnostics::boundary( (int) $event['ends_ts'] );
			}

			$out[] = $event;

			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}
		}

		/**
		 * Filter the final More Events rows.
		 *
		 * The featured event has already been removed; re-adding it here is a
		 * developer's deliberate act, never an editor toggle.
		 *
		 * @param array<int,array<string,mixed>> $out      Event data arrays.
		 * @param string                         $mode     Source mode.
		 * @param array<string,mixed>|null       $featured The featured owning event.
		 */
		return (array) apply_filters( 'eex_more_events', $out, $mode, $featured );
	}

	/**
	 * The More Events rows in "upcoming sessions" mode: the next sessions
	 * across the displayable events, shaped like compact event rows (title,
	 * date, link), with the featured session excluded. This is the mode for
	 * a calendar that is one or two long-running events holding many
	 * sessions — where distinct-event modes have nothing left to list once
	 * the featured event is removed.
	 *
	 * @param int                       $limit    Maximum rows (0 = all).
	 * @param array<string,mixed>|null  $featured The featured session, when the
	 *                                            hero features one.
	 * @return array<int,array<string,mixed>> Compact-row-shaped arrays.
	 */
	public static function more_sessions( int $limit, ?array $featured ): array {
		$featured_key = null !== $featured
			? (string) ( $featured['event_hs_id'] ?? '' ) . '|' . (string) ( $featured['hs_id'] ?? '' )
			: '';

		$out  = [];
		$seen = [];

		foreach ( Repositories::current()->upcoming_talks( [ 'limit' => 0 ] ) as $talk ) {
			$title = (string) ( $talk['title'] ?? '' );
			$key   = (string) ( $talk['event_hs_id'] ?? '' ) . '|' . (string) ( $talk['hs_id'] ?? '' );

			if ( '' === $title || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			if ( '' !== $featured_key && $key === $featured_key ) {
				Diagnostics::note(
					/* translators: %s: session title. */
					sprintf( __( '%s: excluded from More Events because it is the featured session.', 'emailexpert-events' ), $title )
				);
				continue;
			}

			if ( ! empty( $talk['cancelled'] ) ) {
				Diagnostics::note(
					/* translators: %s: session title. */
					sprintf( __( '%s: excluded from More Events because it is cancelled.', 'emailexpert-events' ), $title )
				);
				continue;
			}

			Diagnostics::note(
				/* translators: %s: session title. */
				sprintf( __( '%s: included in More Events (upcoming session).', 'emailexpert-events' ), $title )
			);

			// The list changes when a listed session starts (Full-mode talk
			// data carries no precomputed timestamp, only the ISO string).
			$start_ts = (int) ( $talk['start_ts'] ?? 0 );
			if ( $start_ts <= 0 ) {
				$start_ts = (int) strtotime( (string) ( $talk['starts_at'] ?? '' ) );
			}
			if ( $start_ts > 0 ) {
				Diagnostics::boundary( $start_ts );
			}

			// The compact row's event shape (see parts/compact-event-row.php).
			$out[] = [
				'title'         => $title,
				'first_talk_at' => (string) ( $talk['starts_at'] ?? '' ),
				'timezone'      => (string) ( $talk['timezone'] ?? '' ),
				'url'           => (string) ( $talk['permalink'] ?? '' ),
				'hs_id'         => (string) ( $talk['hs_id'] ?? '' ),
				'evergreen'     => false,
				'speakers_row'  => self::normalise_speakers( (array) ( $talk['speakers'] ?? [] ) ),
				'venue'         => (string) ( $talk['venue'] ?? '' ),
				'inperson'      => ! empty( $talk['inperson'] ),
			];

			if ( $limit > 0 && count( $out ) >= $limit ) {
				break;
			}
		}

		/**
		 * Filter the final More Events rows in "upcoming sessions" mode.
		 *
		 * @param array<int,array<string,mixed>> $out      Compact-row-shaped arrays.
		 * @param array<string,mixed>|null       $featured The featured session.
		 */
		return (array) apply_filters( 'eex_more_sessions', $out, $featured );
	}

	/**
	 * Attach speaker rows to More Events rows in the event modes: each listed
	 * event gains the speakers of its soonest upcoming session, from the talk
	 * data the page already loads — one bounded pass, no per-row fetches.
	 * Session-mode rows carry their own speakers already; rows without
	 * speaker information simply stay event-only.
	 *
	 * @param array<int,array<string,mixed>> $rows More Events rows (event shape).
	 * @return array<int,array<string,mixed>>
	 */
	public static function attach_speakers( array $rows ): array {
		$by_event = [];

		foreach ( Repositories::current()->upcoming_talks( [ 'limit' => 0 ] ) as $talk ) {
			$event_id = (string) ( $talk['event_hs_id'] ?? '' );

			if ( '' === $event_id || isset( $by_event[ $event_id ] ) ) {
				continue; // Soonest session wins; the list is soonest-first.
			}

			$speakers = self::normalise_speakers( (array) ( $talk['speakers'] ?? [] ) );

			if ( ! empty( $speakers ) ) {
				$by_event[ $event_id ] = $speakers;
			}
		}

		foreach ( $rows as &$row ) {
			if ( empty( $row['speakers_row'] ) ) {
				$row['speakers_row'] = (array) ( $by_event[ (string) ( $row['hs_id'] ?? '' ) ] ?? [] );
			}
		}
		unset( $row );

		return $rows;
	}

	/**
	 * The "featured people" rows: the people connected to the next sessions,
	 * soonest first, each with their event context — person-led promotion of
	 * upcoming activity. Built entirely from the talk data the page already
	 * loads; people are deduplicated by name, and names already visible on
	 * the featured card can be excluded so nobody appears twice.
	 *
	 * @param int      $limit         Maximum rows (0 = all).
	 * @param string[] $exclude_names Names already shown elsewhere.
	 * @return array<int,array<string,mixed>>
	 */
	public static function people( int $limit, array $exclude_names = [] ): array {
		$seen = [];

		foreach ( $exclude_names as $name ) {
			$seen[ strtolower( trim( (string) $name ) ) ] = true;
		}
		unset( $seen[''] );

		$out = [];

		foreach ( Repositories::current()->upcoming_talks( [ 'limit' => 0 ] ) as $talk ) {
			if ( ! empty( $talk['cancelled'] ) ) {
				continue;
			}

			foreach ( self::normalise_speakers( (array) ( $talk['speakers'] ?? [] ) ) as $speaker ) {
				$key = strtolower( trim( (string) $speaker['name'] ) );

				if ( '' === $key || isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$out[] = $speaker + [
					'context'  => (string) ( $talk['title'] ?? '' ),
					'date'     => (string) ( $talk['starts_at'] ?? '' ),
					'timezone' => (string) ( $talk['timezone'] ?? '' ),
					'link'     => (string) ( $talk['permalink'] ?? '' ),
				];

				// The list changes when this person's session starts.
				$start_ts = (int) strtotime( (string) ( $talk['starts_at'] ?? '' ) );
				if ( $start_ts > 0 ) {
					Diagnostics::boundary( $start_ts );
				}

				if ( $limit > 0 && count( $out ) >= $limit ) {
					return $out;
				}
			}
		}

		return $out;
	}

	/**
	 * Normalise a talk's speaker entries to the display shape the templates
	 * share ({ name, url, headline, photo_id, photo_url }); entries without
	 * a name are dropped, portraits are never fabricated.
	 *
	 * @param array<int,mixed> $speakers Raw speaker entries.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalise_speakers( array $speakers ): array {
		$out = [];

		foreach ( $speakers as $speaker ) {
			if ( ! is_array( $speaker ) ) {
				continue;
			}

			$name = trim( (string) ( $speaker['name'] ?? '' ) );

			if ( '' === $name ) {
				continue;
			}

			$out[] = [
				'name'      => $name,
				'url'       => (string) ( $speaker['url'] ?? '' ),
				'headline'  => (string) ( $speaker['headline'] ?? '' ),
				'photo_id'  => (int) ( $speaker['photo_id'] ?? 0 ),
				'photo_url' => (string) ( $speaker['photo_url'] ?? ( $speaker['headshot'] ?? '' ) ),
			];
		}

		return $out;
	}

	/**
	 * Find one candidate by an event reference (HeySummit ID, post ID, slug
	 * or canonical identity), searching the displayable pool first and the
	 * repository second, so a manual pick of a not-currently-upcoming event
	 * (ended, far future, archived) still resolves.
	 *
	 * @param string $ref Event reference.
	 * @return array<string,mixed>|null
	 */
	public static function find_event( string $ref ): ?array {
		if ( '' === $ref ) {
			return null;
		}

		foreach ( self::candidates() as $event ) {
			if ( (string) $event['identity'] === $ref
				|| (string) $event['hs_id'] === $ref
				|| ( (int) ( $event['id'] ?? 0 ) > 0 && (string) $event['id'] === $ref ) ) {
				return $event;
			}
		}

		// A "conn|id" identity falls back to its event-ID half.
		$hs_ref = str_contains( $ref, '|' ) ? (string) substr( (string) strrchr( $ref, '|' ), 1 ) : $ref;

		$event = Repositories::current()->event_summary( $hs_ref );

		if ( null === $event ) {
			return null;
		}

		$presentation = EventPresentation::for_event( $event );

		[ $starts_ts, $ends_ts ] = EventLifecycle::window( $event );

		$event['presentation']    = $presentation;
		$event['effective_level'] = EventPresentation::effective_level( $presentation );
		$event['identity']        = EventIdentity::of( $event );
		$event['starts_ts']       = $starts_ts;
		$event['ends_ts']         = $ends_ts;

		return $event;
	}

	/**
	 * Soonest first; evergreen (undated) events after dated ones.
	 *
	 * @param array<string,mixed> $a One enriched event.
	 * @param array<string,mixed> $b Another enriched event.
	 */
	private static function chronological_order( array $a, array $b ): int {
		$a_ts = (int) $a['starts_ts'];
		$b_ts = (int) $b['starts_ts'];

		if ( ( 0 === $a_ts ) !== ( 0 === $b_ts ) ) {
			return 0 === $a_ts ? 1 : -1;
		}

		return $a_ts <=> $b_ts;
	}
}
