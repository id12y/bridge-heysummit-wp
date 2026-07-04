<?php
/**
 * Form submission capture: gates, then queue.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The single entry point every form adapter feeds. Runs the consent and
 * suppression gates at capture time (they are re-checked again at delivery),
 * dedupes, then queues an async push — the visitor's page submit never
 * waits on the HeySummit API. Email addresses are never logged.
 */
final class Capture {

	/**
	 * Handle a submission for every mapping attached to a form.
	 *
	 * @param string              $source  Source plugin key.
	 * @param string              $form_id The plugin's form ID.
	 * @param array<string,mixed> $values  Submission values keyed by field ID.
	 */
	public static function handle( string $source, string $form_id, array $values ): void {
		foreach ( Mappings::for_form( $source, $form_id ) as $mapping ) {
			self::capture( $mapping, $values );
		}
	}

	/**
	 * Gate and queue one submission for one mapping.
	 *
	 * @param array<string,mixed> $mapping Mapping row.
	 * @param array<string,mixed> $values  Submission values keyed by field ID.
	 * @return array{status:string,message:string}
	 */
	public static function capture( array $mapping, array $values ): array {
		$mapping_id = (string) ( $mapping['id'] ?? '' );
		$event      = (string) ( $mapping['event'] ?? '' );
		$email      = sanitize_email( self::value_of( $values, (string) ( $mapping['email_field'] ?? '' ) ) );

		if ( '' === $mapping_id || '' === $event ) {
			return [
				'status'  => 'skipped',
				'message' => 'unusable mapping',
			];
		}

		if ( '' === $email || ! is_email( $email ) ) {
			Logger::info( Logger::CONTEXT_API, sprintf( 'Form submission skipped for mapping %s: no valid email in field "%s".', $mapping_id, (string) ( $mapping['email_field'] ?? '' ) ) );

			return [
				'status'  => 'skipped',
				'message' => 'no valid email address',
			];
		}

		// Consent is a hard rule: either the mapped consent field was ticked,
		// or the operator declared that submitting this form IS the consent
		// (a form whose stated purpose is event registration).
		if ( 'implied' !== (string) ( $mapping['consent_mode'] ?? 'field' ) ) {
			$consent = self::value_of( $values, (string) ( $mapping['consent_field'] ?? '' ) );

			if ( '' === (string) ( $mapping['consent_field'] ?? '' ) || ! self::is_ticked( $consent ) ) {
				Logger::info( Logger::CONTEXT_API, sprintf( 'Form submission not pushed for mapping %s: consent field not ticked.', $mapping_id ) );

				return [
					'status'  => 'no_consent',
					'message' => 'consent not given',
				];
			}
		}

		// Suppressed addresses are skipped silently — indistinguishable from
		// a queued push from the outside, and never logged with the address.
		if ( class_exists( '\Emailexpert\Events\Accounts\Suppression' )
			&& \Emailexpert\Events\Accounts\Suppression::is_suppressed( $email, $event ) ) {
			Logger::info( Logger::CONTEXT_API, sprintf( 'Form submission suppressed for mapping %s.', $mapping_id ) );

			return [
				'status'  => 'suppressed',
				'message' => 'suppressed',
			];
		}

		$name = sanitize_text_field( self::value_of( $values, (string) ( $mapping['name_field'] ?? '' ) ) );
		if ( '' === $name ) {
			// The attendee-create body requires a name; the local part is the
			// honest best available when the form asks only for an email.
			$name = (string) strstr( $email, '@', true );
		}

		$questions = [];
		foreach ( (array) ( $mapping['questions'] ?? [] ) as $field => $question_id ) {
			$answer = self::value_of( $values, (string) $field );

			if ( '' !== $answer && (int) $question_id > 0 ) {
				$questions[] = [
					'question_id' => (int) $question_id,
					'answer'      => sanitize_text_field( $answer ),
				];
			}
		}

		$id     = Queue::key_for( $email, $event, $mapping_id );
		$queued = Queue::add(
			$id,
			[
				'mapping'   => $mapping_id,
				'name'      => $name,
				'email'     => $email,
				'questions' => $questions,
				'status'    => 'pending',
				'attempts'  => 0,
				'queued_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
			]
		);

		if ( ! $queued ) {
			return [
				'status'  => 'duplicate',
				'message' => 'already queued or pushed recently',
			];
		}

		wp_schedule_single_event( time() - 1, 'eex_forms_push', [ $id, 1 ] );

		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return [
			'status'  => 'queued',
			'message' => 'queued for push',
		];
	}

	/**
	 * One submission value as a flat string; arrays (multi-selects,
	 * checkbox groups) join with a comma.
	 *
	 * @param array<string,mixed> $values Submission values.
	 * @param string              $field  Field key.
	 */
	private static function value_of( array $values, string $field ): string {
		if ( '' === $field || ! isset( $values[ $field ] ) ) {
			return '';
		}

		$value = $values[ $field ];

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Whether a checkbox-ish value counts as ticked. Form plugins send '1',
	 * 'on', 'yes' or the choice's label text; explicit negatives and empty
	 * values do not consent.
	 *
	 * @param string $value Flattened field value.
	 */
	private static function is_ticked( string $value ): bool {
		return '' !== $value && ! in_array( strtolower( $value ), [ '0', 'no', 'off', 'false' ], true );
	}
}
