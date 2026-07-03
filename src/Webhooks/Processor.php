<?php
/**
 * Asynchronous webhook processing.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeMapper;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the per-action behaviours, gated by settings toggles. Payloads are
 * untrusted: state-mutating actions verify by fetching the attendee record
 * back from the API by ID and use the fetched data, not the payload
 * (docs/decisions.md D15 covers the unverifiable fallback).
 */
class Processor {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_process_webhook', [ $this, 'process' ] );
		add_action( 'eex_abandonment_check', [ $this, 'abandonment_check' ], 10, 2 );
	}

	/**
	 * Queued entry point.
	 *
	 * @param array $payload Raw payload.
	 */
	public function process( $payload = [] ): void {
		$this->process_payload( (array) $payload );
	}

	/**
	 * Process one payload. Also used by `wp eex webhooks:replay` with
	 * $dry_run = true to verify parsing without side effects.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @param bool                $dry_run Parse and report only.
	 * @return array<string,mixed> Result summary.
	 */
	public function process_payload( array $payload, bool $dry_run = false ): array {
		$parsed = Parser::parse( $payload );
		$notes  = [];

		$result = [
			'action'         => (string) $parsed['action'],
			'attendee_hs_id' => null !== $parsed['attendee'] ? (string) $parsed['attendee']['hs_id'] : '',
			'event_hs_id'    => (string) $parsed['event_hs_id'],
			'talk_hs_id'     => (string) $parsed['talk_hs_id'],
			'handled'        => false,
			'notes'          => &$notes,
		];

		if ( '' === $parsed['action'] || null === $parsed['attendee'] ) {
			$notes[] = 'Payload not recognised: missing action or attendee.';
			Logger::warning( Logger::CONTEXT_WEBHOOK, 'Webhook payload not recognised.', [ 'parsed_action' => $parsed['action'] ] );

			return $result;
		}

		if ( $dry_run ) {
			$result['handled'] = true;
			$notes[]           = 'Dry run: no state mutated.';

			return $result;
		}

		$enabled = [
			Parser::ACTION_CHECKOUT   => (bool) Options::setting( 'wh_checkout' ),
			Parser::ACTION_STARTED    => (bool) Options::setting( 'wh_started' ),
			Parser::ACTION_TALK_ADDED => (bool) Options::setting( 'wh_talk' ),
		];

		if ( empty( $enabled[ $parsed['action'] ] ) ) {
			$notes[] = 'Action disabled in settings.';

			return $result;
		}

		// Verify: fetch the attendee back from the API and prefer the
		// fetched record for anything that mutates state.
		$attendee = $parsed['attendee'];
		$verified = false;

		$fetched = $this->fetch_attendee( (string) $attendee['hs_id'] );
		if ( null !== $fetched ) {
			$attendee = $fetched;
			$verified = true;
			if ( '' === (string) $parsed['event_hs_id'] ) {
				$parsed['event_hs_id'] = (string) $attendee['event_hs_id'];
			}
		}

		$event_hs_id   = (string) ( $attendee['event_hs_id'] ?: $parsed['event_hs_id'] );
		$event_post_id = Components::event_post_for_hs_id( $event_hs_id );

		switch ( $parsed['action'] ) {
			case Parser::ACTION_CHECKOUT:
				if ( $verified && $event_post_id > 0 ) {
					$count = (int) get_post_meta( $event_post_id, '_eex_registration_count', true );
					update_post_meta( $event_post_id, '_eex_registration_count', $count + 1 );
				} elseif ( ! $verified ) {
					$notes[] = 'Attendee could not be verified against the API; counter not incremented.';
					Logger::warning( Logger::CONTEXT_WEBHOOK, 'Checkout complete received but attendee verification failed; counter not incremented.', [ 'attendee' => $attendee['hs_id'] ] );
				}

				Attribution::insert( $attendee, $event_hs_id, 'completed' );

				/**
				 * A registration checkout completed.
				 *
				 * @param array<string,mixed> $attendee      Mapped attendee (verified when possible).
				 * @param int                 $event_post_id Event post ID (0 when unmapped).
				 */
				do_action( 'eex_checkout_complete', $attendee, $event_post_id );

				if ( (bool) Options::setting( 'notify_checkout_email' ) ) {
					wp_mail(
						get_bloginfo( 'admin_email' ),
						__( 'New registration completed', 'emailexpert-events' ),
						sprintf(
							/* translators: 1: event title or HeySummit ID, 2: ticket name. */
							__( "A registration checkout completed for %1\$s.\nTicket: %2\$s", 'emailexpert-events' ),
							$event_post_id > 0 ? get_the_title( $event_post_id ) : $event_hs_id,
							(string) $attendee['ticket_name'] ?: '-'
						)
					);
				}

				$result['handled'] = true;
				break;

			case Parser::ACTION_STARTED:
				Attribution::insert( $attendee, $event_hs_id, 'started' );

				/**
				 * A registration started.
				 *
				 * @param array<string,mixed> $attendee      Mapped attendee.
				 * @param int                 $event_post_id Event post ID (0 when unmapped).
				 */
				do_action( 'eex_registration_started', $attendee, $event_post_id );

				// Abandonment check in 60 minutes; fires eex_registration_abandoned
				// if no matching checkout has completed by then. The plugin
				// sends no email to attendees; downstream automation owns that.
				if ( '' !== (string) $attendee['email_hash'] ) {
					wp_schedule_single_event(
						time() + HOUR_IN_SECONDS,
						'eex_abandonment_check',
						[ (string) $attendee['email_hash'], $event_hs_id ]
					);
				}

				$result['handled'] = true;
				break;

			case Parser::ACTION_TALK_ADDED:
				/**
				 * A talk was added to an attendee's schedule. Log and hook
				 * only in this build.
				 *
				 * @param array<string,mixed> $attendee      Mapped attendee.
				 * @param string              $talk_hs_id    HeySummit talk ID ('' when absent).
				 * @param int                 $event_post_id Event post ID (0 when unmapped).
				 */
				do_action( 'eex_talk_signup', $attendee, (string) $parsed['talk_hs_id'], $event_post_id );

				Logger::info(
					Logger::CONTEXT_WEBHOOK,
					'Talk added to attendee schedule.',
					[
						'talk'  => (string) $parsed['talk_hs_id'],
						'event' => $event_hs_id,
					]
				);

				$result['handled'] = true;
				break;
		}

		/**
		 * A webhook finished processing (used to flush the component cache).
		 *
		 * @param array<string,mixed> $result Processing summary.
		 */
		do_action( 'eex_webhook_processed', $result );

		return $result;
	}

	/**
	 * The 60-minute abandonment check.
	 *
	 * @param string $email_hash  SHA-256 of the lowercased email.
	 * @param string $event_hs_id HeySummit event ID.
	 */
	public function abandonment_check( $email_hash = '', $event_hs_id = '' ): void {
		$email_hash  = (string) $email_hash;
		$event_hs_id = (string) $event_hs_id;

		if ( '' === $email_hash || Attribution::has_completed( $email_hash, $event_hs_id ) ) {
			return;
		}

		/**
		 * A started registration did not complete within 60 minutes. The
		 * plugin fires the hook only; it sends no email to attendees.
		 *
		 * @param string $email_hash  SHA-256 of the lowercased email.
		 * @param string $event_hs_id HeySummit event ID.
		 */
		do_action( 'eex_registration_abandoned', $email_hash, $event_hs_id );

		Logger::info(
			Logger::CONTEXT_WEBHOOK,
			'Registration abandoned (no checkout within 60 minutes).',
			[ 'event' => $event_hs_id ]
		);
	}

	/**
	 * Fetch an attendee record from the API, trying each connection.
	 *
	 * @param string $attendee_hs_id Attendee ID from the payload.
	 * @return array<string,mixed>|null Mapped attendee, null when unavailable.
	 */
	protected function fetch_attendee( string $attendee_hs_id ): ?array {
		if ( '' === $attendee_hs_id ) {
			return null;
		}

		foreach ( Options::connections() as $connection ) {
			if ( '' === (string) ( $connection['api_key'] ?? '' ) ) {
				continue;
			}

			$client   = HeySummitClient::for_connection( $connection );
			$response = $client->get( 'attendees/' . rawurlencode( $attendee_hs_id ) . '/' );

			if ( ! is_wp_error( $response ) ) {
				return AttendeeMapper::map( $response );
			}
		}

		return null;
	}
}
