<?php
/**
 * Defensive webhook payload parser.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

use Emailexpert\Events\Mappers\AttendeeMapper;

defined( 'ABSPATH' ) || exit;

/**
 * HeySummit sends three actions (attendee registration started, checkout
 * complete, talk added to attendee schedule) as unsigned JSON POSTs with
 * undocumented shapes. This parser accepts reasonable variations: the action
 * under several keys with fuzzy value matching, the attendee nested or flat,
 * string or integer IDs. Payload data is untrusted throughout; the processor
 * re-fetches the attendee from the API before mutating state.
 */
final class Parser {

	public const ACTION_CHECKOUT   = 'checkout_complete';
	public const ACTION_STARTED    = 'registration_started';
	public const ACTION_TALK_ADDED = 'talk_added';

	/**
	 * Parse a payload.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @return array<string,mixed> action ('' when unrecognised), attendee
	 *                             (mapped array or null), event_hs_id,
	 *                             talk_hs_id.
	 */
	public static function parse( array $payload ): array {
		$action   = self::detect_action( $payload );
		$attendee = self::extract_attendee( $payload );

		$event_hs_id = '';
		$talk_hs_id  = '';

		if ( null !== $attendee ) {
			$event_hs_id = (string) $attendee['event_hs_id'];
		}

		foreach ( [ 'event_id', 'event' ] as $key ) {
			if ( '' === $event_hs_id && isset( $payload[ $key ] ) ) {
				$event_hs_id = self::id_from( $payload[ $key ] );
			}
		}

		foreach ( [ 'talk_id', 'talk' ] as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$talk_hs_id = self::id_from( $payload[ $key ] );
				break;
			}
		}

		if ( '' === $talk_hs_id && null !== $attendee && ! empty( $attendee['talk_hs_ids'] ) ) {
			$talk_hs_id = (string) $attendee['talk_hs_ids'][ count( $attendee['talk_hs_ids'] ) - 1 ];
		}

		return [
			'action'      => $action,
			'attendee'    => $attendee,
			'event_hs_id' => $event_hs_id,
			'talk_hs_id'  => $talk_hs_id,
		];
	}

	/**
	 * Idempotency hash: action + attendee identifier + 15-minute bucket
	 * (HeySummit retries every 15 minutes up to 3 times; the dedupe
	 * transient outlives the whole retry window).
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 */
	public static function dedupe_hash( array $payload ): string {
		$parsed = self::parse( $payload );

		$identifier = '';
		if ( null !== $parsed['attendee'] ) {
			$identifier = (string) ( $parsed['attendee']['hs_id'] ?: $parsed['attendee']['email_hash'] );
		}
		if ( '' === $identifier ) {
			$identifier = md5( (string) wp_json_encode( $payload ) );
		}

		$bucket = (int) floor( time() / ( 15 * MINUTE_IN_SECONDS ) );

		return hash( 'sha256', $parsed['action'] . '|' . $identifier . '|' . $parsed['talk_hs_id'] . '|' . $bucket );
	}

	/**
	 * Detect the action from candidate keys with fuzzy value matching.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 */
	private static function detect_action( array $payload ): string {
		$raw = '';

		foreach ( [ 'action', 'event', 'type', 'trigger', 'webhook_action', 'topic' ] as $key ) {
			if ( isset( $payload[ $key ] ) && is_string( $payload[ $key ] ) && '' !== $payload[ $key ] ) {
				$raw = strtolower( $payload[ $key ] );
				break;
			}
		}

		if ( '' === $raw ) {
			return self::infer_action( $payload );
		}

		if ( str_contains( $raw, 'checkout' ) || str_contains( $raw, 'complete' ) || str_contains( $raw, 'purchase' ) ) {
			return self::ACTION_CHECKOUT;
		}

		if ( str_contains( $raw, 'talk' ) || str_contains( $raw, 'schedule' ) || str_contains( $raw, 'session' ) ) {
			return self::ACTION_TALK_ADDED;
		}

		if ( str_contains( $raw, 'regist' ) || str_contains( $raw, 'start' ) || str_contains( $raw, 'attendee' ) ) {
			return self::ACTION_STARTED;
		}

		return '';
	}

	/**
	 * Infer the action from the documented payload shapes when no action
	 * key travels in the body (the OpenAPI spec's outbound webhook examples
	 * carry none): checkout has paid_at/ticket_purchases, talk-added has
	 * talk_id plus attendee_* fields, registration-started has
	 * registration_status/registration_answers.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 */
	private static function infer_action( array $payload ): string {
		if ( isset( $payload['paid_at'] ) || isset( $payload['ticket_purchases'] ) ) {
			return self::ACTION_CHECKOUT;
		}

		if ( isset( $payload['talk_id'] ) && ( isset( $payload['attendee_id'] ) || isset( $payload['attendee_email'] ) ) ) {
			return self::ACTION_TALK_ADDED;
		}

		if ( isset( $payload['registration_status'] ) || isset( $payload['registration_answers'] ) ) {
			return self::ACTION_STARTED;
		}

		return '';
	}

	/**
	 * Extract and map the attendee from nested or flat shapes.
	 *
	 * @param array<string,mixed> $payload Raw payload.
	 * @return array<string,mixed>|null
	 */
	private static function extract_attendee( array $payload ): ?array {
		$candidates = [];

		if ( isset( $payload['attendee'] ) && is_array( $payload['attendee'] ) ) {
			$candidates[] = $payload['attendee'];
		}
		if ( isset( $payload['data']['attendee'] ) && is_array( $payload['data']['attendee'] ) ) {
			$candidates[] = $payload['data']['attendee'];
		}
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) && ! array_is_list( $payload['data'] ) ) {
			$candidates[] = $payload['data'];
		}
		// The talk-added payload prefixes every attendee field; check it
		// before the flat shape, which would otherwise half-match.
		if ( isset( $payload['attendee_id'] ) || isset( $payload['attendee_email'] ) ) {
			$candidates[] = [
				'id'         => $payload['attendee_id'] ?? null,
				'email'      => $payload['attendee_email'] ?? ( $payload['email'] ?? '' ),
				'name'       => $payload['attendee_name'] ?? ( $payload['name'] ?? '' ),
				'created_at' => $payload['attendee_created_at'] ?? '',
				'event_id'   => $payload['event_id'] ?? '',
				'utm_source' => $payload['utm_source'] ?? '',
				'tickets'    => $payload['tickets'] ?? null,
			];
		}

		$candidates[] = $payload; // Flat shape.

		foreach ( $candidates as $candidate ) {
			$mapped = AttendeeMapper::map( $candidate );
			if ( null !== $mapped ) {
				return $mapped;
			}
		}

		return null;
	}

	/**
	 * Extract an ID from a scalar or an object with an id key.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function id_from( $value ): string {
		if ( is_scalar( $value ) && '' !== (string) $value && ! is_bool( $value ) ) {
			// A string here may be an action name rather than an ID; only
			// accept digits or short tokens.
			$string = (string) $value;

			return ( ctype_digit( $string ) || preg_match( '/^[A-Za-z0-9_-]{1,32}$/', $string ) ) ? $string : '';
		}

		if ( is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) ) {
			return (string) $value['id'];
		}

		return '';
	}
}
