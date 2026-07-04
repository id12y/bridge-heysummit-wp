<?php
/**
 * Form → HeySummit mapping store.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Operator-defined mappings from a form (Elementor Pro, Gravity Forms,
 * WPForms or Fluent Forms) to a HeySummit event: which submission field is
 * the email, which is the name, how consent is established, which ticket
 * price to assign and which fields answer registration questions. Nothing is
 * ever pushed for a form without a mapping.
 */
final class Mappings {

	public const OPTION = 'eex_form_mappings';

	public const SOURCES = [ 'elementor', 'gravity', 'wpforms', 'fluent' ];

	public const CONSENT_MODES = [ 'field', 'implied' ];

	/**
	 * All mappings.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all(): array {
		return array_values( array_filter( (array) get_option( self::OPTION, [] ), 'is_array' ) );
	}

	/**
	 * One mapping by ID.
	 *
	 * @param string $id Mapping ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		if ( '' === $id ) {
			return null;
		}

		foreach ( self::all() as $mapping ) {
			if ( (string) ( $mapping['id'] ?? '' ) === $id ) {
				return $mapping;
			}
		}

		return null;
	}

	/**
	 * Every mapping for one form of one source plugin.
	 *
	 * @param string $source  Source key (see SOURCES).
	 * @param string $form_id The source plugin's form ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_form( string $source, string $form_id ): array {
		if ( '' === $form_id ) {
			return [];
		}

		return array_values(
			array_filter(
				self::all(),
				static fn( array $mapping ): bool => (string) ( $mapping['source'] ?? '' ) === $source
					&& (string) ( $mapping['form_id'] ?? '' ) === $form_id
			)
		);
	}

	/**
	 * Persist a full set of mappings (the admin form posts all rows).
	 *
	 * @param array<int,array<string,mixed>> $rows Sanitised rows.
	 */
	public static function save( array $rows ): void {
		update_option( self::OPTION, array_values( array_filter( $rows, 'is_array' ) ), false );
	}

	/**
	 * Sanitise one submitted row into the stored shape. Null when the row is
	 * unusable (no event or no way to find an email address).
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>|null
	 */
	public static function sanitise_row( array $row ): ?array {
		$source      = sanitize_key( (string) ( $row['source'] ?? '' ) );
		$event       = sanitize_text_field( (string) ( $row['event'] ?? '' ) );
		$email_field = sanitize_text_field( (string) ( $row['email_field'] ?? '' ) );

		if ( ! in_array( $source, self::SOURCES, true ) || '' === $event || '' === $email_field ) {
			return null;
		}

		$consent_mode = sanitize_key( (string) ( $row['consent_mode'] ?? 'field' ) );
		if ( ! in_array( $consent_mode, self::CONSENT_MODES, true ) ) {
			$consent_mode = 'field';
		}

		$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
		if ( '' === $id ) {
			$id = 'fm_' . substr( md5( uniqid( $source . '|' . $event, true ) ), 0, 10 );
		}

		return [
			'id'            => $id,
			'label'         => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
			'source'        => $source,
			'form_id'       => sanitize_text_field( (string) ( $row['form_id'] ?? '' ) ),
			'connection'    => sanitize_text_field( (string) ( $row['connection'] ?? '' ) ),
			'event'         => $event,
			'ticket'        => sanitize_text_field( (string) ( $row['ticket'] ?? '' ) ),
			'email_field'   => $email_field,
			'name_field'    => sanitize_text_field( (string) ( $row['name_field'] ?? '' ) ),
			'consent_mode'  => $consent_mode,
			'consent_field' => sanitize_text_field( (string) ( $row['consent_field'] ?? '' ) ),
			'questions'     => self::sanitise_questions( $row['questions'] ?? [] ),
		];
	}

	/**
	 * Sanitise a questions map (field key → HeySummit question ID). Accepts
	 * either the stored array shape or the admin textarea's
	 * "field_key | question_id" lines.
	 *
	 * @param mixed $raw Stored array or textarea string.
	 * @return array<string,int>
	 */
	public static function sanitise_questions( $raw ): array {
		$pairs = [];

		if ( is_string( $raw ) ) {
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
				[ $field, $question ] = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				if ( '' !== $field && ctype_digit( $question ) ) {
					$pairs[ sanitize_text_field( $field ) ] = (int) $question;
				}
			}

			return $pairs;
		}

		foreach ( (array) $raw as $field => $question ) {
			$field = sanitize_text_field( (string) $field );
			if ( '' !== $field && (int) $question > 0 ) {
				$pairs[ $field ] = (int) $question;
			}
		}

		return $pairs;
	}
}
