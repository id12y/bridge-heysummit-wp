<?php
/**
 * Lifecycle- and commerce-aware CTA resolution for the compositions.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Compositions;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Feeds;
use Emailexpert\Events\Frontend\Ics;

defined( 'ABSPATH' ) || exit;

/**
 * One CTA view model per feature target, built on the existing registration
 * architecture — HeySummit checkout, external ticketing URLs, the in-place
 * RSVP form, the ticket panel, WooCommerce routing and coupons all keep
 * their existing precedence, because the destinations come from the same
 * Components helpers every other widget uses. This class only decides the
 * WORDING and which action leads, from the lifecycle reading.
 *
 * A cancelled target never shows a registration CTA. A live session keeps
 * the existing Join-now behaviour: the flip stays client-side (data-eex-cta
 * inside session attributes), so cached HTML never claims live state.
 */
final class CtaResolver {

	/**
	 * Resolve the CTA view model.
	 *
	 * @param array<string,mixed> $target  Feature target (FeatureTargetResolver).
	 * @param array<string,mixed> $atts    Composition attributes.
	 * @param array<string,mixed> $context Optional: replay_anchor (URL/anchor of a
	 *                                     replays section), check_tickets (bool —
	 *                                     resolve sold-out state; featured target
	 *                                     and pricing surfaces only).
	 * @return array<string,mixed>
	 */
	public static function resolve( array $target, array $atts, array $context = [] ): array {
		$event     = (array) $target['event'];
		$session   = $target['session'] ?? null;
		$lifecycle = (array) $target['lifecycle'];
		$status    = (string) $lifecycle['status'];

		$presentation = (array) ( $event['presentation'] ?? [] );

		// Destination overrides, most specific first: the widget's external
		// ticketing URL, then the event's presentation CTA override, then
		// the normal register stack.
		$register = Components::register_args( $atts );

		if ( '' === $register['url'] && '' !== (string) ( $presentation['cta_url'] ?? '' ) ) {
			$register['url'] = (string) $presentation['cta_url'];
		}

		$label_override = trim( (string) ( $atts['register_text'] ?? '' ) );
		if ( '' === $label_override ) {
			$label_override = trim( (string) ( $presentation['cta_label'] ?? '' ) );
		}

		$details_url   = (string) ( $event['url'] ?? '' );
		$details_label = __( 'Details', 'emailexpert-events' );

		$buttons = (string) ( $atts['buttons'] ?? 'both' );

		$model = [
			'primary'         => null,
			'secondary'       => null,
			'calendar'        => [],
			'sold_out'        => false,
			'rsvp_context'    => [],
			'flip'            => false,
			// The resolved interaction ('form', 'panel' or 'link') — what the
			// existing registration system answered; the compositions hand it
			// back to Components::ticket_drawer and the child components.
			'register_action' => (string) ( $atts['register_action'] ?? 'link' ),
		];

		// Session-page secondary (the existing two-button model), unless the
		// widget asked for tickets only.
		if ( null !== $session && 'tickets' !== $buttons ) {
			$session_url = Components::session_url( $session );

			if ( '' !== $session_url ) {
				$session_text       = trim( (string) ( $atts['session_text'] ?? '' ) );
				$model['secondary'] = [
					'label'  => '' !== $session_text ? $session_text : __( 'Details', 'emailexpert-events' ),
					'url'    => $session_url,
					'action' => 'event-details',
				];
				// The live Join-now swap lands here, client-side.
				$model['flip'] = true;
			}
		} elseif ( '' !== $details_url ) {
			$model['secondary'] = [
				'label'  => $details_label,
				'url'    => $details_url,
				'action' => 'event-details',
			];
		}

		if ( 'cancelled' === $status ) {
			// No registration language, no calendar entry for a cancelled
			// target; details remain reachable.
			$model['secondary'] = '' !== $details_url ? [
				'label'  => $details_label,
				'url'    => $details_url,
				'action' => 'event-details',
			] : null;
			$model['flip']      = false;

			return self::filtered( $model, $target, $atts );
		}

		if ( 'postponed' === $status ) {
			// Registration language is suppressed until a new date exists;
			// an explicit CTA override (e.g. "Register interest") may still
			// lead.
			if ( '' !== (string) ( $presentation['cta_url'] ?? '' ) ) {
				$model['primary'] = [
					'label'  => '' !== $label_override ? $label_override : __( 'Register interest', 'emailexpert-events' ),
					'url'    => (string) $presentation['cta_url'],
					'action' => 'event-register',
					'drawer' => false,
					'rsvp'   => false,
				];
			}
			$model['flip'] = false;

			return self::filtered( $model, $target, $atts );
		}

		if ( in_array( $status, [ 'ended', 'replay' ], true ) ) {
			$replay_url = null !== $session ? (string) ( $session['replay_url'] ?? '' ) : '';

			if ( '' === $replay_url && ! empty( $lifecycle['replay_available'] ) ) {
				$replay_url = (string) ( $context['replay_anchor'] ?? '' );
			}

			if ( '' !== $replay_url ) {
				$model['primary'] = [
					'label'  => null !== $session
						? __( 'Watch replay', 'emailexpert-events' )
						: __( 'Watch replays', 'emailexpert-events' ),
					'url'    => $replay_url,
					'action' => 'replay',
					'drawer' => false,
					'rsvp'   => false,
				];
			}

			$model['flip'] = false;

			return self::filtered( $model, $target, $atts );
		}

		// Upcoming, starting soon, live or evergreen.
		if ( ! empty( $lifecycle['registration_open'] ) ) {
			$commerce_atts = self::commerce_atts( $atts, $event );

			// The registration interaction is the existing system's decision,
			// never this class's. "auto" (the compositions' default) asks the
			// same deciders the classic widgets run on: the in-place RSVP form
			// when Components::rsvp_context finds a usable free ticket under
			// its existing rules (no external override, form otherwise
			// eligible), else the existing ticket panel. Explicit values keep
			// their classic meaning unchanged.
			$action = (string) ( $atts['register_action'] ?? 'link' );

			if ( 'auto' === $action ) {
				$probe  = Components::rsvp_context( array_merge( $commerce_atts, [ 'register_action' => 'form' ] ) );
				$action = ! empty( $probe ) ? 'form' : 'panel';

				$model['rsvp_context'] = ! empty( $probe ) ? $probe : [];
			} else {
				$model['rsvp_context'] = Components::rsvp_context( $commerce_atts );
			}

			$model['register_action'] = $action;
			$model['sold_out']        = ! empty( $context['check_tickets'] ) && self::sold_out( $event, $commerce_atts );

			$url = null !== $session
				? Components::ticketing_url( $session, $register )
				: Components::ticketing_url( [ 'event_url' => (string) ( $event['event_url'] ?? '' ) ], $register );

			if ( '' !== $url ) {
				if ( '' !== $label_override ) {
					$label = $label_override;
				} elseif ( $model['sold_out'] ) {
					// HeySummit checkout offers the waitlist when tickets run
					// out; the wording follows the state, the destination
					// stays honest.
					$label = __( 'Join waitlist', 'emailexpert-events' );
				} elseif ( ! empty( $model['rsvp_context'] ) ) {
					$label = __( 'RSVP free', 'emailexpert-events' );
				} else {
					$label = __( 'Get tickets', 'emailexpert-events' );
				}

				$model['primary'] = [
					'label'  => $label,
					'url'    => $url,
					'action' => 'event-register',
					'drawer' => 'panel' === $action,
					'rsvp'   => ! empty( $model['rsvp_context'] ),
				];
			}
		}

		// Calendar action: the existing session .ics and Google links when a
		// session is the target; the existing event-filtered calendar
		// subscription for an event-level target. No invented endpoints.
		if ( ! empty( $atts['show_ics'] ) ) {
			if ( null !== $session && '' !== (string) ( $session['starts_at'] ?? '' ) ) {
				$model['calendar'] = [
					'ics'    => Ics::download_url( $session ),
					'google' => Ics::google_url( $session ),
				];
			} elseif ( '' !== (string) ( $event['hs_id'] ?? '' ) && (int) $lifecycle['starts_ts'] > 0 ) {
				$model['calendar'] = [
					'subscribe' => add_query_arg( 'event', (string) $event['hs_id'], Feeds::url() ),
				];
			}
		}

		return self::filtered( $model, $target, $atts );
	}

	/**
	 * Commerce attributes scoped to the owning event, so the drawer, RSVP
	 * form and ticket fetches always resolve against the right event even
	 * when the widget's own event attribute is empty.
	 *
	 * @param array<string,mixed> $atts  Composition attributes.
	 * @param array<string,mixed> $event Owning event.
	 * @return array<string,mixed>
	 */
	public static function commerce_atts( array $atts, array $event ): array {
		$atts['event'] = (string) ( $event['hs_id'] ?? '' );

		if ( '' === $atts['event'] && (int) ( $event['id'] ?? 0 ) > 0 ) {
			$atts['event'] = (string) $event['id'];
		}

		return $atts;
	}

	/**
	 * Whether every relevant ticket is sold out. Resolved only for surfaces
	 * that genuinely need it (the featured target, the pricing section) —
	 * never for compact rows. Unlimited tickets ('' remaining) are never
	 * sold out; an event with no readable tickets is not claimed sold out.
	 *
	 * @param array<string,mixed> $event         Owning event.
	 * @param array<string,mixed> $commerce_atts Attributes carrying the event ref.
	 */
	private static function sold_out( array $event, array $commerce_atts ): bool {
		unset( $event );

		$tickets = Repositories::current()->tickets( $commerce_atts );

		if ( empty( $tickets ) ) {
			return false;
		}

		$csv      = static fn( string $value ): array => array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		$only     = $csv( (string) ( $commerce_atts['tickets'] ?? '' ) );
		$excluded = $csv( (string) ( $commerce_atts['exclude'] ?? '' ) );

		$relevant = 0;

		foreach ( $tickets as $ticket ) {
			$id = (string) $ticket['id'];

			if ( ( ! empty( $only ) && ! in_array( $id, $only, true ) ) || in_array( $id, $excluded, true ) ) {
				continue;
			}

			++$relevant;

			if ( '0' !== (string) ( $ticket['remaining'] ?? '' ) ) {
				return false;
			}
		}

		return $relevant > 0;
	}

	/**
	 * Apply the final view-model filter.
	 *
	 * @param array<string,mixed> $model  CTA view model.
	 * @param array<string,mixed> $target Feature target.
	 * @param array<string,mixed> $atts   Attributes.
	 * @return array<string,mixed>
	 */
	private static function filtered( array $model, array $target, array $atts ): array {
		/**
		 * Filter the final CTA view model for a composition target.
		 *
		 * @param array<string,mixed> $model  primary/secondary/calendar/sold_out/rsvp_context/flip.
		 * @param array<string,mixed> $target Feature target.
		 * @param array<string,mixed> $atts   Composition attributes.
		 */
		return (array) apply_filters( 'eex_cta_view_model', $model, $target, $atts );
	}
}
