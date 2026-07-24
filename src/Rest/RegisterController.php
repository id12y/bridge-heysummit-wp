<?php
/**
 * Public in-drawer registration for free tickets.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Rest;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Tickets;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Options;
use Emailexpert\Events\Registration\Registrar;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/eex/v1/register — the ticket drawer's "register free"
 * form. Server-side only: the visitor's browser talks to this site, this
 * site talks to HeySummit through the allowlisted attendee-create path.
 *
 * Guard rails, in order: honeypot (silent success), consent required,
 * valid email, per-IP rate limit, the event must be one this site is
 * configured for (never client-chosen connections), the ticket must exist
 * on that event and be FREE (a paid ticket via this route would grant
 * unpaid access), and suppression is honoured when the accounts module is
 * active. Errors reach the visitor as plain sentences and the log with
 * detail; the email address is never logged.
 */
final class RegisterController {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register the route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'eex/v1',
			'/register',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'event'     => [ 'type' => 'string' ],
					'ticket'    => [ 'type' => 'string' ],
					'price'     => [ 'type' => 'string' ],
					'talk'      => [ 'type' => 'string' ],
					'name'      => [ 'type' => 'string' ],
					'email'     => [ 'type' => 'string' ],
					'consent'   => [ 'type' => 'string' ],
					'marketing' => [ 'type' => 'string' ],
					'return'    => [ 'type' => 'string' ],
					'website'   => [ 'type' => 'string' ],
				],
			]
		);
	}

	/**
	 * Handle a registration.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response {
		// Honeypot: bots that fill every field get a quiet "success" —
		// phrased like every other anonymous outcome (see neutral()).
		if ( '' !== trim( (string) $request['website'] ) ) {
			return $this->neutral();
		}

		if ( '1' !== (string) $request['consent'] ) {
			return $this->respond( [ 'message' => __( 'Please tick the consent box to register.', 'emailexpert-events' ) ], 400 );
		}

		$email = sanitize_email( (string) $request['email'] );
		$name  = sanitize_text_field( (string) $request['name'] );

		if ( '' === $email || ! is_email( $email ) ) {
			return $this->respond( [ 'message' => __( 'That email address does not look right.', 'emailexpert-events' ) ], 400 );
		}

		if ( '' === $name ) {
			return $this->respond( [ 'message' => __( 'Please enter your name.', 'emailexpert-events' ) ], 400 );
		}

		if ( ! $this->within_rate_limit() ) {
			return $this->respond( [ 'message' => __( 'Too many attempts — please wait a few minutes and try again.', 'emailexpert-events' ) ], 429 );
		}

		$event_hs_id   = sanitize_text_field( (string) $request['event'] );
		$connection_id = $this->connection_for_event( $event_hs_id );

		if ( '' === $connection_id ) {
			return $this->respond( [ 'message' => __( 'This event is not open for registration here.', 'emailexpert-events' ) ], 404 );
		}

		$ticket = $this->free_ticket( $connection_id, $event_hs_id, sanitize_text_field( (string) $request['ticket'] ) );

		if ( null === $ticket ) {
			return $this->respond( [ 'message' => __( 'That ticket cannot be registered here — it may be paid or no longer available.', 'emailexpert-events' ) ], 400 );
		}

		$mode      = (string) Options::setting( 'reg_confirm_mode' );
		$logged_in = $this->is_own_verified_session( $email );

		// With confirmation off every submission is instant, and the plain
		// 'registered' status is safe BECAUSE it is uniform across fresh and
		// duplicate registrations (the D103 guarantee). With confirmation on,
		// anonymous callers get one neutral body whatever happened.
		$instant = $logged_in || 'off' === $mode;

		if ( class_exists( '\Emailexpert\Events\Accounts\Suppression' ) && \Emailexpert\Events\Accounts\Suppression::is_suppressed( $email, $event_hs_id ) ) {
			// Indistinguishable from success on purpose: suppression state
			// must not be probeable from the outside.
			return $instant ? $this->respond( [ 'status' => 'registered' ], 200 ) : $this->neutral();
		}

		$price_id = $this->price_id_of( $ticket, sanitize_text_field( (string) $request['price'] ) );

		// The clicked session to add to the schedule, only when it is a real
		// talk of this event — a visitor-supplied ID is never trusted.
		$talk_hs_id = $this->valid_talk( sanitize_text_field( (string) $request['talk'] ), $event_hs_id );

		$marketing = '1' === (string) $request['marketing'] && (bool) Options::setting( 'reg_marketing_show' );

		$registration = [
			'connection_id' => $connection_id,
			'event'         => $event_hs_id,
			'ticket'        => (string) $ticket['id'],
			'price_id'      => $price_id,
			'talk'          => $talk_hs_id,
			'name'          => $name,
			'email'         => $email,
			'marketing'     => $marketing,
			// The receipt records the EXACT wording the visitor agreed to.
			'consent'       => [
				'disclosure'     => \Emailexpert\Events\Frontend\Components::consent_disclosure_text(),
				'marketing_text' => $marketing ? (string) Options::setting( 'reg_marketing_text' ) : '',
				'ts'             => gmdate( 'Y-m-d\TH:i:s\Z' ),
			],
		];

		/**
		 * Whether this email address belongs to an already-verified person
		 * (a CRM with confirmed double-opt-in state can answer). Default
		 * false: unknown addresses confirm by email first.
		 *
		 * @param bool   $verified Verified?
		 * @param string $email    The address.
		 */
		$crm_verified = 'standard' === $mode && apply_filters( 'eex_email_is_verified', false, $email );

		if ( $instant || $crm_verified ) {
			// Verified identity (or confirmation disabled): register now.
			$result = Registrar::register( $registration );

			if ( is_wp_error( $result ) ) {
				return $instant
					? $this->respond( [ 'message' => __( 'Registration could not be completed — please try again on the event site.', 'emailexpert-events' ) ], 502 )
					: $this->neutral();
			}

			// A session added to an EXISTING attendee's schedule gets the
			// transactional session-added email (HeySummit is silent there).
			if ( 'already' === $result && '' !== $talk_hs_id ) {
				$talk = Repositories::current()->known_talk( $talk_hs_id );

				if ( null !== $talk ) {
					\Emailexpert\Events\Registration\Mailer::send_session_added( $email, $talk );
				}
			}

			// Logged-in callers (and mode off) get the real status — safe
			// because it is identical for fresh and duplicate. An anonymous
			// CRM-verified caller gets the same neutral body as every other
			// anonymous branch (no oracle on CRM membership either).
			return $instant ? $this->respond( [ 'status' => 'registered' ], 200 ) : $this->neutral();
		}

		// Unverified: hold the registration and ask the mailbox owner.
		// Nothing reaches HeySummit until they click — which also means
		// nobody can register or reschedule someone else's address.
		if ( \Emailexpert\Events\Registration\PendingStore::take_send_slot( $email ) ) {
			$event = Repositories::current()->event_summary( $event_hs_id );
			$talk  = '' !== $talk_hs_id ? Repositories::current()->known_talk( $talk_hs_id ) : null;

			$registration['event_title'] = null !== $event ? (string) ( $event['title'] ?? '' ) : '';
			$registration['talk_title']  = null !== $talk ? (string) ( $talk['title'] ?? '' ) : '';
			$registration['return_url']  = esc_url_raw( (string) $request['return'] );

			$token = \Emailexpert\Events\Registration\PendingStore::create( $registration );

			if ( '' !== $token ) {
				\Emailexpert\Events\Registration\Mailer::send_confirmation( $registration, $token );

				/**
				 * A registration is pending email confirmation.
				 *
				 * @param array<string,mixed> $registration Payload (includes email).
				 */
				do_action( 'eex_registration_pending', $registration );
			}
		}

		// Cooldown-blocked, store-failed and sent all answer identically.
		return $this->neutral();
	}

	/**
	 * The one answer every anonymous submission receives, whatever
	 * happened: honeypot, suppression, cooldown, instant registration or
	 * a confirmation email. Distinguishable outcomes on a public endpoint
	 * are an enumeration oracle; one body tells nothing.
	 */
	private function neutral(): WP_REST_Response {
		return $this->respond( [ 'status' => 'submitted' ], 200 );
	}

	/**
	 * Whether the caller is a logged-in user registering their OWN
	 * verified address (typing someone else's email from a logged-in
	 * session is still an anonymous claim about another person).
	 *
	 * @param string $email Submitted address.
	 */
	private function is_own_verified_session( string $email ): bool {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			return false;
		}

		$user = wp_get_current_user();

		return null !== $user && strtolower( (string) $user->user_email ) === strtolower( $email );
	}

	/**
	 * A talk ID from the form, returned only when it is a known talk of this
	 * event; '' otherwise. Registration proceeds either way — an unrecognised
	 * session is simply not attached, never an error the visitor sees.
	 *
	 * @param string $talk_hs_id  Talk ID from the form.
	 * @param string $event_hs_id Event the registration is for.
	 */
	private function valid_talk( string $talk_hs_id, string $event_hs_id ): string {
		if ( '' === $talk_hs_id ) {
			return '';
		}

		// Numeric only: the allowlisted attach path is events/<d>/attendees/
		// <d>/talks/<d>/, and a non-numeric segment would make the client
		// refuse the write by throwing — after the registration succeeded.
		if ( preg_match( '/^\d+$/', $talk_hs_id ) ) {
			$talk = Repositories::current()->known_talk( $talk_hs_id );

			if ( null !== $talk && (string) ( $talk['event_hs_id'] ?? '' ) === $event_hs_id ) {
				return $talk_hs_id;
			}
		}

		// Requested but not validated (unknown talk, another event's talk, or
		// the cached collections could not resolve it right now): register
		// without the session, and say so in the log — a silently missing
		// schedule entry is otherwise undiagnosable.
		Logger::log(
			Logger::CONTEXT_API,
			'warning',
			'drawer registration: session not attached (talk not validated against the event)',
			[
				'event' => $event_hs_id,
				'talk'  => $talk_hs_id,
			]
		);

		return '';
	}

	/**
	 * Five attempts per IP per ten minutes.
	 */
	private function within_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';

		$key   = 'eex_reg_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= 5 ) {
			return false;
		}

		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * The connection this site has configured for an event — the client
	 * never chooses a connection, only names an event we already trust.
	 *
	 * @param string $event_hs_id HeySummit event ID.
	 */
	private function connection_for_event( string $event_hs_id ): string {
		if ( '' === $event_hs_id ) {
			return '';
		}

		$event = Repositories::current()->event_summary( $event_hs_id );

		if ( null !== $event && (string) ( $event['hs_id'] ?? '' ) === $event_hs_id ) {
			return (string) ( $event['connection'] ?? '' );
		}

		return '';
	}

	/**
	 * The ticket row, only if it exists on the event and is genuinely free.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_hs_id   Event ID.
	 * @param string $ticket_id     Ticket ID from the form.
	 * @return array<string,mixed>|null
	 */
	private function free_ticket( string $connection_id, string $event_hs_id, string $ticket_id ) {
		if ( '' === $ticket_id ) {
			return null;
		}

		foreach ( Tickets::for_display( $connection_id, $event_hs_id, '' ) as $ticket ) {
			if ( (string) $ticket['id'] !== $ticket_id ) {
				continue;
			}

			if ( ! empty( $ticket['is_paid'] ) ) {
				return null;
			}

			foreach ( (array) $ticket['prices'] as $price ) {
				$amount = (string) ( $price['amount'] ?? '' );

				if ( '' !== $amount && is_numeric( $amount ) && (float) $amount > 0 ) {
					return null;
				}
			}

			return $ticket;
		}

		return null;
	}

	/**
	 * The ticket price ID to send: the client's claim is only accepted when
	 * it belongs to the ticket; otherwise the ticket's first price is used.
	 *
	 * @param array<string,mixed> $ticket  Verified free ticket.
	 * @param string              $claimed Price ID from the form.
	 */
	private function price_id_of( array $ticket, string $claimed ): string {
		$ids = array_values(
			array_filter(
				array_map(
					static fn( $price ): string => (string) ( $price['id'] ?? '' ),
					(array) $ticket['prices']
				)
			)
		);

		if ( '' !== $claimed && in_array( $claimed, $ids, true ) ) {
			return $claimed;
		}

		return $ids[0] ?? '';
	}

	/**
	 * An uncacheable JSON response.
	 *
	 * @param array<string,mixed> $body   Body.
	 * @param int                 $status HTTP status.
	 */
	private function respond( array $body, int $status ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
