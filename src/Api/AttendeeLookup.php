<?php
/**
 * Attendee lookup by email.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only recovery helper: when an attendee-create answers "already
 * exists", the documented ?email= filter on events/<id>/attendees/ finds
 * the existing attendee's ID so the (idempotent) ticket attach can still
 * run. A read plus an idempotent write — never a second create.
 */
final class AttendeeLookup {

	/**
	 * Find an attendee ID by email on an event.
	 *
	 * @param HeySummitClient     $client      Client.
	 * @param string              $event_hs_id Event ID.
	 * @param string              $email       Attendee email.
	 * @param array<string,mixed> $options     Request options (timeout,
	 *                                         retries). Background jobs keep
	 *                                         the patient defaults; visitor-
	 *                                         facing callers pass short ones.
	 * @return string Attendee ID, '' when not found or on error.
	 */
	public static function find_id( HeySummitClient $client, string $event_hs_id, string $email, array $options = [] ): string {
		if ( '' === $event_hs_id || '' === $email ) {
			return '';
		}

		$response = $client->get( 'events/' . rawurlencode( $event_hs_id ) . '/attendees/', [ 'email' => $email ], $options );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : ( array_is_list( $response ) ? $response : [ $response ] );

		foreach ( $results as $row ) {
			if ( is_array( $row ) && isset( $row['id'] ) && is_scalar( $row['id'] )
				&& strtolower( (string) ( $row['email'] ?? $email ) ) === strtolower( $email ) ) {
				return (string) $row['id'];
			}
		}

		return '';
	}
}
