<?php
/**
 * Cache-safe time rendering.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered HTML never bakes in time-relative state: timestamps render
 * as <time> with UTC in the attribute and event-local time as visible
 * fallback; the eex-time JS module localises them and computes live states
 * client-side.
 */
final class TimeFormat {

	/**
	 * Render a `<time>` element.
	 *
	 * @param string $utc_iso  UTC ISO 8601 timestamp.
	 * @param string $timezone Event timezone identifier ('' = site timezone).
	 * @param string $format   Display format ('' = settings/site default).
	 * @return string HTML, '' when the timestamp is unparseable.
	 */
	public static function render( string $utc_iso, string $timezone = '', string $format = '' ): string {
		$timestamp = strtotime( $utc_iso );

		if ( false === $timestamp || '' === $utc_iso ) {
			return '';
		}

		$tz = self::timezone( $timezone );

		if ( '' === $format ) {
			$format = (string) Options::setting( 'date_format' );
		}
		if ( '' === $format ) {
			$format = get_option( 'date_format', 'j F Y' ) . ' ' . get_option( 'time_format', 'H:i' );
		}

		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz );

		return sprintf(
			'<time datetime="%s" data-eex-time="1">%s <span class="eex-tz">(%s)</span></time>',
			esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) ),
			esc_html( $local->format( $format ) ),
			esc_html( $local->format( 'T' ) )
		);
	}

	/**
	 * Resolve a timezone identifier to a DateTimeZone, falling back to the
	 * site timezone.
	 *
	 * @param string $timezone Identifier.
	 */
	public static function timezone( string $timezone ): \DateTimeZone {
		if ( '' !== $timezone ) {
			try {
				return new \DateTimeZone( $timezone );
			} catch ( \Exception $e ) {
				// Fall through to the site timezone.
				unset( $e );
			}
		}

		return wp_timezone();
	}
}
