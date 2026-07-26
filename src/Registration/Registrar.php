<?php
/**
 * The HeySummit registration write path, shared by instant and
 * confirmed flows.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Registration;

use Emailexpert\Events\Api\AttendeeLookup;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One place performs the attendee create (with duplicate recovery by
 * email lookup) and the idempotent session attach, whether the request
 * comes from the instant path (verified visitor) or a confirmation
 * click. Consent receipts and integration hooks fire here so the two
 * paths can never drift apart.
 */
final class Registrar {

	/**
	 * Register an attendee and attach the session.
	 *
	 * @param array<string,mixed> $reg connection_id, event, name, email,
	 *                                 price_id, talk (''=none), marketing
	 *                                 (bool), consent (array receipt).
	 * @return string|\WP_Error 'registered', 'already', or an error.
	 */
	public static function register( array $reg ) {
		$connection = Options::connection( (string) ( $reg['connection_id'] ?? '' ) );

		if ( null === $connection ) {
			return new \WP_Error( 'eex_no_connection', 'connection missing' );
		}

		$event_hs_id = (string) ( $reg['event'] ?? '' );
		$talk_hs_id  = (string) ( $reg['talk'] ?? '' );
		$email       = (string) ( $reg['email'] ?? '' );

		$req = AttendeeRequestBuilder::build(
			[
				'name'            => (string) ( $reg['name'] ?? '' ),
				'email'           => $email,
				'event_hs_id'     => $event_hs_id,
				'ticket_price_id' => (string) ( $reg['price_id'] ?? '' ),
				// On the wire when the discovered schema is unambiguous
				// (see AttendeeRequestBuilder); always in the local receipt
				// and the eex_registration_confirmed payload regardless.
				'marketing'       => ! empty( $reg['marketing'] ),
				'connection_id'   => (string) ( $reg['connection_id'] ?? '' ),
			]
		);

		$client   = HeySummitClient::for_connection( $connection );
		$response = $client->post( (string) $req['path'], (array) $req['body'] );

		if ( is_wp_error( $response ) ) {
			$detail = (string) $response->get_error_message();

			// A duplicate registration is a success from the visitor's side.
			if ( false !== stripos( $detail, 'already' ) || false !== stripos( $detail, 'exist' ) ) {
				$attendee_id = '';

				if ( '' !== $talk_hs_id ) {
					$attendee_id = AttendeeLookup::find_id(
						$client,
						$event_hs_id,
						$email,
						[
							'timeout' => 5,
							'retries' => 0,
						]
					);
					self::attach( $client, $event_hs_id, $attendee_id, $talk_hs_id );
				}

				self::receipt( $reg, 'already' );

				/**
				 * A registration completed (attendee existed; session attached).
				 *
				 * @param array<string,mixed> $reg    Registration payload (includes email).
				 * @param string              $result 'already'.
				 */
				do_action( 'eex_registration_confirmed', $reg, 'already' );

				if ( '' !== $talk_hs_id && '' !== $attendee_id ) {
					/**
					 * A session landed on an attendee's schedule.
					 *
					 * @param string              $email       Attendee email.
					 * @param string              $talk_hs_id  Session ID.
					 * @param string              $event_hs_id Event ID.
					 * @param array<string,mixed> $reg         Registration payload.
					 */
					do_action( 'eex_session_rsvp', $email, $talk_hs_id, $event_hs_id, $reg );
				}

				return 'already';
			}

			Logger::log(
				Logger::CONTEXT_API,
				'error',
				'drawer registration failed: ' . $detail,
				[
					'connection' => (string) $reg['connection_id'],
					'event'      => $event_hs_id,
					'ticket'     => (string) ( $reg['ticket'] ?? '' ),
				]
			);

			return $response;
		}

		Logger::log(
			Logger::CONTEXT_API,
			'info',
			'drawer registration completed',
			[
				'connection' => (string) $reg['connection_id'],
				'event'      => $event_hs_id,
				'ticket'     => (string) ( $reg['ticket'] ?? '' ),
			]
		);

		if ( '' !== $talk_hs_id ) {
			self::attach( $client, $event_hs_id, (string) ( $response['id'] ?? '' ), $talk_hs_id );
		}

		self::receipt( $reg, 'registered' );
		do_action( 'eex_registration_confirmed', $reg, 'registered' );

		if ( '' !== $talk_hs_id ) {
			do_action( 'eex_session_rsvp', $email, $talk_hs_id, $event_hs_id, $reg );
		}

		return 'registered';
	}

	/**
	 * Add an attendee to a talk's schedule through the allowlisted,
	 * idempotent POST (HeySummit respects ticket access and capacity).
	 * Best-effort: a failure is logged, never surfaced — the attendee is
	 * already registered by the time this runs.
	 *
	 * @param HeySummitClient $client      Keyed client.
	 * @param string          $event_hs_id Event ID.
	 * @param string          $attendee_id HeySummit attendee ID ('' skips).
	 * @param string          $talk_hs_id  Talk ID (already validated).
	 */
	public static function attach( HeySummitClient $client, string $event_hs_id, string $attendee_id, string $talk_hs_id ): void {
		if ( ! preg_match( '/^\d+$/', $attendee_id ) || ! preg_match( '/^\d+$/', $event_hs_id ) || ! preg_match( '/^\d+$/', $talk_hs_id ) ) {
			Logger::log(
				Logger::CONTEXT_API,
				'warning',
				'drawer session attach skipped: attendee/event/talk id unresolved or non-numeric',
				[
					'event' => $event_hs_id,
					'talk'  => $talk_hs_id,
				]
			);

			return;
		}

		try {
			$response = $client->post(
				'events/' . rawurlencode( $event_hs_id ) . '/attendees/' . rawurlencode( $attendee_id ) . '/talks/' . rawurlencode( $talk_hs_id ) . '/',
				[]
			);
		} catch ( \Throwable $e ) {
			$response = new \WP_Error( 'eex_attach_refused', $e->getMessage() );
		}

		if ( is_wp_error( $response ) ) {
			Logger::log(
				Logger::CONTEXT_API,
				'warning',
				'drawer session attach failed: ' . $response->get_error_message(),
				[
					'event' => $event_hs_id,
					'talk'  => $talk_hs_id,
				]
			);
		}
	}

	/**
	 * Append a consent receipt: WHEN which address (hashed — the raw email
	 * lives on the HeySummit attendee record, not here) agreed to WHICH
	 * exact wording, and the outcome. A rolling window for accountability;
	 * the eex_registration_confirmed hook carries the full payload so a
	 * CRM can be the permanent store.
	 *
	 * @param array<string,mixed> $reg    Registration payload.
	 * @param string              $result 'registered' or 'already'.
	 */
	private static function receipt( array $reg, string $result ): void {
		$log = get_option( 'eex_consent_receipts', [] );
		$log = is_array( $log ) ? $log : [];

		$log[] = [
			'ts'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'email_hash' => \Emailexpert\Events\Support\Crypto::hash_email( (string) ( $reg['email'] ?? '' ) ),
			'event'      => (string) ( $reg['event'] ?? '' ),
			'talk'       => (string) ( $reg['talk'] ?? '' ),
			'marketing'  => ! empty( $reg['marketing'] ),
			'consent'    => (array) ( $reg['consent'] ?? [] ),
			'result'     => $result,
		];

		update_option( 'eex_consent_receipts', array_slice( $log, -1000 ), false );
	}
}
