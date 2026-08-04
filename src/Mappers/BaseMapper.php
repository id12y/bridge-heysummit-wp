<?php
/**
 * Shared mapper helpers.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Null-safe extraction helpers. Mappers must tolerate unknown extra fields
 * and missing optional fields without fatals; where the v2 API differs from
 * the assumed v1-era shapes, adjust here and in Api\Shapes.
 */
abstract class BaseMapper {

	/**
	 * First non-empty string among candidate keys.
	 *
	 * @param array<string,mixed> $raw  Raw record.
	 * @param string[]            $keys Candidate keys, first match wins.
	 */
	protected static function str( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}

		return '';
	}

	/**
	 * Truthiness tolerant of "true"/"1"/1/true.
	 *
	 * @param array<string,mixed> $raw Raw record.
	 * @param string              $key Key.
	 */
	protected static function boolish( array $raw, $key ): bool {
		// Live verification showed some accounts prefix flags (e.g.
		// _is_open_for_registrations), so candidates are allowed.
		$keys = (array) $key;
		$key  = null;
		foreach ( $keys as $candidate ) {
			if ( isset( $raw[ $candidate ] ) ) {
				$key = $candidate;
				break;
			}
		}

		if ( null === $key ) {
			return false;
		}

		$value = $raw[ $key ];

		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			return in_array( strtolower( $value ), [ '1', 'true', 'yes' ], true );
		}

		return (bool) $value;
	}

	/**
	 * Extract an ID from a scalar or a nested object under candidate keys.
	 *
	 * @param array<string,mixed> $raw  Raw record.
	 * @param string[]            $keys Candidate keys.
	 */
	protected static function id_of( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $raw[ $key ] ) ) {
				continue;
			}
			$value = $raw[ $key ];

			if ( is_scalar( $value ) && '' !== (string) $value ) {
				return (string) $value;
			}
			if ( is_array( $value ) && isset( $value['id'] ) && is_scalar( $value['id'] ) ) {
				return (string) $value['id'];
			}
		}

		return '';
	}

	/**
	 * Normalise a list of records-or-scalars to a list of IDs.
	 *
	 * @param mixed $raw_list Raw list.
	 * @return string[]
	 */
	protected static function id_list( $raw_list ): array {
		if ( ! is_array( $raw_list ) ) {
			return [];
		}

		$ids = [];
		foreach ( $raw_list as $item ) {
			if ( is_scalar( $item ) && '' !== (string) $item ) {
				$ids[] = (string) $item;
			} elseif ( is_array( $item ) && isset( $item['id'] ) && is_scalar( $item['id'] ) ) {
				$ids[] = (string) $item['id'];
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * How the session is delivered, in the platform's own words: the badge
	 * HeySummit's hub shows beside the time ("ONLINE", "Conference or
	 * Summit"). Agenda items carry their type; everything else carries a
	 * delivery mode. Both are free-form on the account, so the value is
	 * passed through rather than translated to a fixed vocabulary — the
	 * only tidying is turning machine slugs ("pre_recorded") into words.
	 * Absent on either side means no badge, never an invented one.
	 *
	 * @param array<string,mixed> $raw Raw talk record.
	 */
	protected static function format_of( array $raw ): string {
		$label = ! empty( $raw['is_agenda_item'] )
			? self::str( $raw, [ 'agenda_item_type' ] )
			: '';

		if ( '' === $label ) {
			$label = self::str( $raw, [ 'webinar_delivery_mode' ] );
		}

		return self::humanise_format( $label );
	}

	/**
	 * Slug-ish API values become readable words; anything already written
	 * for humans passes through untouched.
	 *
	 * @param string $label Raw label.
	 */
	public static function humanise_format( string $label ): string {
		$label = trim( $label );

		if ( '' === $label || ! preg_match( '/^[a-z0-9_\-]+$/', $label ) ) {
			return $label;
		}

		return ucfirst( str_replace( [ '_', '-' ], ' ', $label ) );
	}

	/**
	 * Parse a timestamp-ish string to a UTC ISO 8601 string, or ''.
	 *
	 * A bare timestamp (no trailing Z and no ±hh:mm offset) is the EVENT'S
	 * local wall-clock time, not UTC — HeySummit serialises times in the
	 * event's timezone. When a timezone is supplied, bare values are parsed
	 * in it before converting to UTC; without one, the old UTC assumption
	 * stands (WordPress pins PHP's default timezone to UTC). Values that
	 * carry an explicit offset are unambiguous and ignore the hint.
	 *
	 * @param array<string,mixed> $raw      Raw record.
	 * @param string[]            $keys     Candidate keys.
	 * @param string              $timezone The event's IANA timezone (e.g. Europe/London), '' = none known.
	 */
	protected static function datetime( array $raw, array $keys, string $timezone = '' ): string {
		$value = self::str( $raw, $keys );

		if ( '' === $value ) {
			return '';
		}

		// A bare HeySummit timestamp is never meant as UTC: when the event's
		// timezone is unknown (older payloads omit it), the site's timezone
		// is the best available proxy — operators' sites almost always share
		// their events' timezone. UTC remains the last resort.
		if ( '' === $timezone && function_exists( 'wp_timezone_string' ) ) {
			$timezone = (string) wp_timezone_string();
		}

		$zone = null;
		if ( '' !== $timezone
			&& preg_match( '/\d:\d{2}/', $value ) // A time component exists (a bare date has no wall clock to localise).
			&& ! preg_match( '/(?:Z|[+-]\d{2}:?\d{2})\s*$/i', $value ) ) {
			try {
				$zone = new \DateTimeZone( $timezone );
			} catch ( \Exception $e ) {
				$zone = null; // Unknown zone string: fall back to the UTC assumption.
			}
		}

		try {
			$dt = null !== $zone ? new \DateTimeImmutable( $value, $zone ) : new \DateTimeImmutable( $value );
		} catch ( \Exception $e ) {
			return '';
		}

		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Extract a URL from a value that may be a string or {url: …} object.
	 *
	 * API responses are third-party data: only http/https URLs pass.
	 * Anything else (javascript:, data:, protocol-relative, garbage)
	 * becomes '' — some of these values end up in data attributes that
	 * client-side code later assigns to href, where esc_attr alone would
	 * not neutralise a hostile scheme.
	 *
	 * @param mixed $value Raw value.
	 */
	protected static function url_of( $value ): string {
		$url = '';

		if ( is_string( $value ) ) {
			$url = trim( $value );
		} elseif ( is_array( $value ) ) {
			foreach ( [ 'url', 'src', 'href' ] as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== trim( $value[ $key ] ) ) {
					$url = trim( $value[ $key ] );
					break;
				}
			}
		}

		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		return esc_url_raw( $url );
	}

	/**
	 * First candidate key holding a valid http(s) URL.
	 *
	 * @param array<string,mixed> $raw  Raw record.
	 * @param string[]            $keys Candidate keys, first match wins.
	 */
	protected static function url_str( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$url = self::url_of( $raw[ $key ] );
				if ( '' !== $url ) {
					return $url;
				}
			}
		}

		return '';
	}
}
