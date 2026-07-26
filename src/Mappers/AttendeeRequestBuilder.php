<?php
/**
 * Attendee-create request builder.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the attendee-create request, per the published OpenAPI spec:
 * POST events/<id>/attendees/ with { email, name } and an optional
 * ticket_price_id that assigns the ticket in the same call. Filterable for
 * live corrections.
 */
final class AttendeeRequestBuilder {

	/**
	 * Build the request.
	 *
	 * @param array<string,mixed> $purchase name, email, event_hs_id,
	 *                                      order_reference, and optionally
	 *                                      ticket_price_id.
	 * @return array{path:string,body:array<string,mixed>}
	 */
	public static function build( array $purchase ): array {
		$body = [
			'name'  => (string) ( $purchase['name'] ?? '' ),
			'email' => (string) ( $purchase['email'] ?? '' ),
		];

		$ticket_price_id = (string) ( $purchase['ticket_price_id'] ?? '' );
		if ( '' !== $ticket_price_id ) {
			// The spec types this as an integer; tolerate non-numeric stored
			// values so the API's own validation message reaches the log.
			$body['ticket_price_id'] = ctype_digit( $ticket_price_id ) ? (int) $ticket_price_id : $ticket_price_id;
		}

		// Registration-question answers (the spec's AttendeeCreateRequest
		// carries them inline): only well-formed pairs travel; anything
		// unanswered or malformed is dropped rather than sent empty.
		$questions = [];
		foreach ( (array) ( $purchase['questions'] ?? [] ) as $question ) {
			if ( ! is_array( $question ) ) {
				continue;
			}

			$question_id = (int) ( $question['question_id'] ?? 0 );
			$answer      = trim( (string) ( $question['answer'] ?? '' ) );

			if ( $question_id > 0 && '' !== $answer ) {
				$questions[] = [
					'question_id' => $question_id,
					'answer'      => $answer,
				];
			}
		}

		if ( ! empty( $questions ) ) {
			$body['questions'] = $questions;
		}

		// The visitor's marketing choice, on the wire ONLY when the stored
		// discovery schema makes the value unambiguous (HeySummit honours
		// communication_preferences on create — founder-confirmed 26 Jul
		// 2026 — but a guessed shape could 400 every registration). A
		// boolean field sends the checkbox as-is; anything else stays off
		// the wire unless the filter maps it (the diagnostics table shows
		// the field's type and choice vocabulary to map against).
		if ( isset( $purchase['marketing'] ) && '' !== (string) ( $purchase['connection_id'] ?? '' ) ) {
			$schema = \Emailexpert\Events\Api\Discovery::write_field( (string) $purchase['connection_id'], 'write:attendees', 'communication_preferences' );

			$value = 'boolean' === $schema['type'] ? (bool) $purchase['marketing'] : null;

			// A nested object whose children are ALL booleans is still
			// unambiguous in shape: send the checkbox to every child (one
			// consent question on our form → one answer across the
			// platform's channels). Mixed-type children stay off the wire.
			if ( null === $value && ! empty( $schema['children'] ) ) {
				$flags = [];
				foreach ( (array) $schema['children'] as $child_name => $child_meta ) {
					if ( 'boolean' !== (string) ( ( (array) $child_meta )['type'] ?? '' ) ) {
						$flags = null;
						break;
					}
					$flags[ (string) $child_name ] = (bool) $purchase['marketing'];
				}

				if ( ! empty( $flags ) ) {
					$value = $flags;
				}
			}

			/**
			 * Filter the communication_preferences value sent on attendee
			 * create. Null = do not send the field.
			 *
			 * @param mixed               $value     Value to send (null = omit).
			 * @param bool                $marketing The visitor's checkbox.
			 * @param array<string,mixed> $schema    Stored write schema (type/required/choices).
			 */
			$value = apply_filters( 'eex_communication_preferences_value', $value, ! empty( $purchase['marketing'] ), $schema );

			if ( null !== $value ) {
				$body['communication_preferences'] = $value;
			}
		}

		/**
		 * Filter the attendee-create request before it is sent.
		 *
		 * @param array<string,mixed> $request  path + body.
		 * @param array<string,mixed> $purchase Source purchase data.
		 */
		return (array) apply_filters(
			'eex_attendee_request',
			[
				'path' => 'events/' . rawurlencode( (string) ( $purchase['event_hs_id'] ?? '' ) ) . '/attendees/',
				'body' => $body,
			],
			$purchase
		);
	}
}
