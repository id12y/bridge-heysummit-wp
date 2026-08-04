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
	 * Render a `<time>` as two lines: the date, then the clock.
	 *
	 * The single-line form gives a timezone the same weight as the date it
	 * qualifies, and repeats it on every row of a listing. Split, the date
	 * can lead and the zone can be stated once by whoever owns the list
	 * (see zone_note()).
	 *
	 * $with_zone is the caller saying whether this row still has to name
	 * its own zone. It is passed to the client as data-eex-zone so the
	 * localiser makes the same choice after it rewrites the time, rather
	 * than the two disagreeing.
	 *
	 * @param string $utc_iso   UTC ISO 8601 timestamp.
	 * @param string $timezone  Event timezone identifier ('' = site).
	 * @param bool   $with_zone Name the zone on this row.
	 * @return string HTML, '' when the timestamp is unparseable.
	 */
	public static function render_stacked( string $utc_iso, string $timezone = '', bool $with_zone = true ): string {
		$timestamp = strtotime( $utc_iso );

		if ( false === $timestamp || '' === $utc_iso ) {
			return '';
		}

		$local = ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( self::timezone( $timezone ) );

		$date_format = (string) Options::setting( 'date_format' );
		if ( '' === $date_format ) {
			$date_format = 'D j M';
		}

		$zone = $with_zone
			? ' <span class="eex-tz">' . esc_html( self::zone_label( $timezone ) ) . '</span>'
			: '';

		return sprintf(
			'<time datetime="%s" data-eex-time="1" data-eex-stacked="1" data-eex-zone="%s"><span class="eex-date">%s</span><span class="eex-clock">%s</span>%s</time>',
			esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) ),
			$with_zone ? '1' : '0',
			esc_html( $local->format( $date_format ) ),
			esc_html( $local->format( (string) get_option( 'time_format', 'H:i' ) ) ),
			$zone // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		);
	}

	/**
	 * A zone written the way a person would say it: "Madrid time".
	 *
	 * IANA identifiers are filing labels, not English. The city is the last
	 * segment, so Europe/Madrid reads Madrid and
	 * America/Argentina/Buenos_Aires reads Buenos Aires. Anything without a
	 * region prefix (UTC, a bare offset) is left exactly as given, because
	 * inventing a city for it would be worse than the raw string.
	 *
	 * @param string $timezone Timezone identifier ('' = site default).
	 */
	public static function zone_label( string $timezone ): string {
		$id = self::timezone( $timezone )->getName();

		if ( ! str_contains( $id, '/' ) ) {
			return $id;
		}

		$parts = explode( '/', $id );
		$city  = str_replace( '_', ' ', (string) end( $parts ) );

		/* translators: %s: a city name, e.g. Madrid. */
		return sprintf( __( '%s time', 'emailexpert-events' ), $city );
	}

	/**
	 * The line a listing shows in place of a zone on every row.
	 *
	 * Rendering logic, not decoration: it states the zone the times above
	 * it are ACTUALLY in, and the localiser rewrites it when it converts
	 * those times to the visitor's own zone. A fixed string here would be
	 * true only until the script ran.
	 *
	 * @param string $timezone Event timezone identifier.
	 * @return string HTML, '' when there is no zone to name.
	 */
	public static function zone_note( string $timezone ): string {
		$label = self::zone_label( $timezone );

		if ( '' === $label ) {
			return '';
		}

		return sprintf(
			'<p class="eex-tz-note" data-eex-tz-note="1" data-eex-zone-event="%s">%s</p>',
			esc_attr( $label ),
			esc_html(
				sprintf(
					/* translators: %s: a zone written for people, e.g. "Madrid time". */
					__( 'Times shown in %s', 'emailexpert-events' ),
					$label
				)
			)
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
