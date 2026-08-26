<?php
/**
 * The homepage feature target: one normalised result for event or session.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Homepage Editorial Hero's "featured event or session"
 * controls into one coherent target every downstream consumer shares:
 * deduplication, registration, media, schema and the More Events list all
 * read the same result. A session target always carries — and inherits the
 * identity of — its owning event, so a manually featured London Forum
 * session removes London Forum itself from More Events.
 *
 * The target shape:
 *   kind      'event' or 'session' (what was SELECTED)
 *   event     the owning event, enriched (presentation/identity/window)
 *   session   the session being PRESENTED, or null for an event-only card
 *   identity  the owning event's canonical identity
 *   lifecycle the EventLifecycle reading for the presented target
 *   source    'automatic', 'manual_event', 'manual_session',
 *             'fallback_automatic' or 'expired_kept'
 */
final class FeatureTargetResolver {

	/**
	 * Resolve the feature target from component attributes.
	 *
	 * @param array<string,mixed> $atts Sanitised composition attributes
	 *                                  (featured_source, featured_event,
	 *                                  featured_session, event_presentation,
	 *                                  presentation_session, selection_strategy,
	 *                                  pin_duration, pin_until, pin_expiry_action).
	 * @return array<string,mixed>|null Null = nothing to feature.
	 */
	public static function resolve( array $atts ): ?array {
		$source   = (string) ( $atts['featured_source'] ?? 'auto' );
		$strategy = (string) ( $atts['selection_strategy'] ?? 'chronological' );

		if ( 'none' === $source ) {
			Diagnostics::note( __( 'Featured target: none — the featured-event area is switched off.', 'emailexpert-events' ) );

			return null;
		}

		$manual = in_array( $source, [ 'manual_event', 'manual_session' ], true );
		$target = null;
		$hidden = false;

		if ( 'manual_event' === $source ) {
			$target = self::manual_event( $atts );
		} elseif ( 'manual_session' === $source ) {
			$target = self::manual_session( $atts );
		}

		if ( null !== $target ) {
			[ $target, $hidden ] = self::apply_pin( $target, $atts );
		} elseif ( $manual && 'hide' === (string) ( $atts['pin_expiry_action'] ?? 'auto' ) ) {
			// The configured fallback for an unavailable manual selection.
			Diagnostics::note( __( 'The featured-event area is hidden (the fallback for an unavailable selection is "hide").', 'emailexpert-events' ) );
			$hidden = true;
		}

		// Automatic mode, or a manual selection that fell through (missing
		// item with an automatic fallback, or an expired pin returning to
		// automatic).
		if ( null === $target && ! $hidden ) {
			$auto = EventSelector::auto_target( $strategy );

			if ( null !== $auto ) {
				$reason = 'auto' === $source
					? __( 'next eligible event by automatic selection', 'emailexpert-events' )
					: __( 'automatic fallback (the manual selection was unavailable or its pin expired)', 'emailexpert-events' );

				$target = self::build( 'event', $auto, null, 'auto' === $source ? 'automatic' : 'fallback_automatic', $reason, $atts );
			}
		}

		if ( null === $target ) {
			if ( ! $hidden ) {
				Diagnostics::note( __( 'Featured target: none — no eligible event is available.', 'emailexpert-events' ) );
			}

			return null;
		}

		/**
		 * Filter the final selected feature target.
		 *
		 * @param array<string,mixed> $target Feature target.
		 * @param array<string,mixed> $atts   Composition attributes.
		 */
		return (array) apply_filters( 'eex_feature_target', $target, $atts );
	}

	/**
	 * A manually selected event.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<string,mixed>|null
	 */
	private static function manual_event( array $atts ): ?array {
		$ref   = (string) ( $atts['featured_event'] ?? '' );
		$event = EventSelector::find_event( $ref );

		if ( null === $event ) {
			Diagnostics::note(
				sprintf(
					/* translators: %s: the stored event reference. */
					__( 'The manually selected event "%s" could not be resolved (missing, unpublished or detached); the configured fallback applies.', 'emailexpert-events' ),
					$ref
				)
			);

			return null;
		}

		if ( empty( $event['presentation']['feature_eligible'] ) || 'none' === (string) $event['effective_level'] ) {
			Diagnostics::note(
				sprintf(
					/* translators: %s: event title. */
					__( 'Warning: %s is manually featured although its presentation settings mark it ineligible for automatic promotion. The manual selection wins.', 'emailexpert-events' ),
					(string) $event['title']
				)
			);
		}

		return self::build( 'event', $event, null, 'manual_event', __( 'manually selected event', 'emailexpert-events' ), $atts );
	}

	/**
	 * A manually selected session, resolved to its owning event.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<string,mixed>|null
	 */
	private static function manual_session( array $atts ): ?array {
		$ref     = (string) ( $atts['featured_session'] ?? '' );
		$session = '' !== $ref ? Repositories::current()->talk( $ref ) : null;

		if ( null === $session || empty( $session['published'] ) ) {
			Diagnostics::note(
				sprintf(
					/* translators: %s: the stored session reference. */
					__( 'The manually selected session "%s" could not be resolved (missing, unpublished or detached); the configured fallback applies.', 'emailexpert-events' ),
					$ref
				)
			);

			return null;
		}

		$event = EventSelector::find_event( (string) ( $session['event_hs_id'] ?? '' ) );

		if ( null === $event ) {
			Diagnostics::note( __( 'The selected session\'s owning event could not be resolved; the configured fallback applies.', 'emailexpert-events' ) );

			return null;
		}

		return self::build(
			'session',
			$event,
			$session,
			'manual_session',
			__( 'manually selected session (its owning event is excluded from More Events)', 'emailexpert-events' ),
			$atts
		);
	}

	/**
	 * Apply the manual pin: decide whether it has expired and what follows.
	 *
	 * @param array<string,mixed> $target Resolved manual target.
	 * @param array<string,mixed> $atts   Attributes.
	 * @return array{0:array<string,mixed>|null,1:bool} The surviving target
	 *               (null = fall through) and whether the area is hidden.
	 */
	private static function apply_pin( array $target, array $atts ): array {
		$duration = (string) ( $atts['pin_duration'] ?? 'until_end' );
		$after    = (string) ( $atts['pin_expiry_action'] ?? 'auto' );
		$now      = Clock::now();

		$expiry = 0;

		if ( 'until_date' === $duration ) {
			// The attribute may arrive as a bare datetime-local value; the
			// same site-timezone interpretation the presentation store uses.
			$expiry = \Emailexpert\Events\Data\EventPresentation::timestamp(
				\Emailexpert\Events\Data\EventPresentation::sanitise_datetime( (string) ( $atts['pin_until'] ?? '' ) )
			);
		} elseif ( 'until_end' === $duration ) {
			$expiry = (int) ( $target['lifecycle']['ends_ts'] ?? 0 );
		}

		if ( 'never' === $duration || 0 === $expiry ) {
			return [ $target, false ];
		}

		if ( $now < $expiry ) {
			// Still pinned; the expiry is a real selection boundary.
			Diagnostics::boundary( $expiry );

			return [ $target, false ];
		}

		// Expired.
		if ( 'keep' === $after ) {
			Diagnostics::note( __( 'The manual pin has expired; the selection is kept as post-event content (After expiry: keep).', 'emailexpert-events' ) );
			$target['source'] = 'expired_kept';

			return [ $target, false ];
		}

		if ( 'hide' === $after ) {
			Diagnostics::note( __( 'The manual pin has expired; the featured-event area is hidden (After expiry: hide).', 'emailexpert-events' ) );

			return [ null, true ];
		}

		Diagnostics::note( __( 'The manual pin has expired; selection has returned to automatic mode (After expiry: return to automatic).', 'emailexpert-events' ) );

		return [ null, false ];
	}

	/**
	 * Assemble the normalised target, applying the event-presentation mode
	 * (Auto / Event itself / Next session / Specific session) for event
	 * targets.
	 *
	 * @param string                   $kind    'event' or 'session'.
	 * @param array<string,mixed>      $event   Enriched owning event.
	 * @param array<string,mixed>|null $session Selected session (session targets).
	 * @param string                   $source  Selection source tag.
	 * @param string                   $reason  Human explanation of the selection.
	 * @param array<string,mixed>      $atts    Attributes.
	 * @return array<string,mixed>
	 */
	private static function build( string $kind, array $event, ?array $session, string $source, string $reason, array $atts ): array {
		$presented    = $session;
		$presentation = __( 'the session itself', 'emailexpert-events' );

		if ( 'event' === $kind ) {
			$mode = (string) ( $atts['event_presentation'] ?? 'auto' );

			if ( 'session' === $mode ) {
				$chosen = self::session_in_event( (string) ( $atts['presentation_session'] ?? '' ), $event );

				if ( null !== $chosen ) {
					$presented    = $chosen;
					$presentation = __( 'the specifically chosen session in the selected event', 'emailexpert-events' );
				} else {
					Diagnostics::note( __( 'Warning: the chosen presentation session does not belong to the selected event (or no longer exists); presenting automatically instead.', 'emailexpert-events' ) );
					$mode = 'auto';
				}
			}

			if ( 'next_session' === $mode || 'auto' === $mode ) {
				$next = self::next_session_of( $event );

				if ( null !== $next ) {
					$presented    = $next;
					$presentation = __( 'the next session in the event', 'emailexpert-events' );
				} elseif ( 'auto' === $mode || 'next_session' === $mode ) {
					$presented    = null;
					$presentation = __( 'the event itself (no suitable next session)', 'emailexpert-events' );
				}
			}

			if ( 'event' === $mode ) {
				$presented    = null;
				$presentation = __( 'the event itself', 'emailexpert-events' );
			}
		}

		// The presented session's speakers honour the same per-session
		// relationship the More Sessions rows use (local assignment first in
		// auto, canonical records only, unresolvable references skipped).
		if ( null !== $presented ) {
			$presented['speakers'] = EventSelector::session_speakers( $presented, (array) ( $event['presentation'] ?? [] ) );
		}

		$lifecycle = EventLifecycle::resolve( $event, $presented, (array) ( $event['presentation'] ?? [] ) );

		Diagnostics::boundaries( (array) $lifecycle['boundaries'] );
		Diagnostics::note(
			sprintf(
				/* translators: 1: event or session title, 2: selection reason. */
				__( 'Featured target: %1$s. Reason: %2$s.', 'emailexpert-events' ),
				(string) ( null !== $session ? $session['title'] : $event['title'] ),
				$reason
			)
		);
		Diagnostics::note(
			sprintf(
				/* translators: %s: how the target is presented. */
				__( 'Presentation: %s.', 'emailexpert-events' ),
				$presentation
			)
		);

		if ( null !== $presented ) {
			Diagnostics::boundaries( (array) EventLifecycle::resolve( $event, null, (array) ( $event['presentation'] ?? [] ) )['boundaries'] );
		}

		return [
			'kind'      => $kind,
			'event'     => $event,
			'session'   => $presented,
			'identity'  => (string) ( $event['identity'] ?? EventIdentity::of( $event ) ),
			'lifecycle' => $lifecycle,
			'source'    => $source,
			'title'     => (string) ( null !== $presented ? $presented['title'] : $event['title'] ),
			'starts_at' => null !== $presented ? (string) ( $presented['starts_at'] ?? '' ) : (string) ( $event['first_talk_at'] ?? '' ),
			'ends_at'   => null !== $presented ? (string) ( $presented['ends_at'] ?? '' ) : (string) ( $event['last_talk_at'] ?? '' ),
			'timezone'  => (string) ( null !== $presented ? ( $presented['timezone'] ?? '' ) : ( $event['timezone'] ?? '' ) ),
			'speakers'  => null !== $presented ? (array) ( $presented['speakers'] ?? [] ) : [],
		];
	}

	/**
	 * The event's next suitable session: the soonest one still running or
	 * ahead of now, cancelled sessions excluded by the repositories.
	 *
	 * @param array<string,mixed> $event Enriched owning event.
	 * @return array<string,mixed>|null
	 */
	private static function next_session_of( array $event ): ?array {
		$now  = Clock::now();
		$atts = [
			'event' => (string) ( $event['hs_id'] ?? '' ),
			'limit' => Clock::is_frozen() ? 0 : 4,
		];

		if ( '' === $atts['event'] && (int) ( $event['id'] ?? 0 ) > 0 ) {
			$atts['event'] = (string) $event['id'];
		}

		$candidates = Repositories::current()->current_and_next( $atts );

		// Under a frozen clock the repository split ran on real time; sweep
		// the whole event so a preview date far ahead still lands correctly.
		if ( Clock::is_frozen() ) {
			$candidates = array_merge(
				Repositories::current()->past_talks( $atts ),
				$candidates
			);

			usort(
				$candidates,
				static fn( array $a, array $b ): int => strtotime( (string) $a['starts_at'] ) <=> strtotime( (string) $b['starts_at'] )
			);
		}

		foreach ( $candidates as $talk ) {
			$start = (int) strtotime( (string) ( $talk['starts_at'] ?? '' ) );
			$end   = (int) strtotime( (string) ( $talk['ends_at'] ?? '' ) );

			if ( $start <= 0 ) {
				continue;
			}

			if ( 0 >= $end ) {
				$end = $start + HOUR_IN_SECONDS;
			}

			if ( $end > $now ) {
				return $talk;
			}
		}

		return null;
	}

	/**
	 * A specific session validated as belonging to the selected event.
	 *
	 * @param string              $ref   Session reference.
	 * @param array<string,mixed> $event Enriched owning event.
	 * @return array<string,mixed>|null Null when missing or foreign.
	 */
	private static function session_in_event( string $ref, array $event ): ?array {
		if ( '' === $ref ) {
			return null;
		}

		$session = Repositories::current()->talk( $ref );

		if ( null === $session || empty( $session['published'] ) ) {
			return null;
		}

		$owner = [
			'hs_id'      => (string) ( $session['event_hs_id'] ?? '' ),
			'id'         => (int) ( $session['event_post_id'] ?? 0 ),
			'connection' => (string) ( $event['connection'] ?? '' ),
		];

		return EventIdentity::same( $owner, $event ) ? $session : null;
	}
}
