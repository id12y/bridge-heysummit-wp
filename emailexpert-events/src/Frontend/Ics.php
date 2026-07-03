<?php
/**
 * RFC 5545 calendar generation.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds .ics documents (single session downloads and the subscribable
 * feed) and Google Calendar template links, server-side from talk data.
 */
final class Ics {

	/**
	 * Download URL for one session's .ics.
	 *
	 * @param int|array<string,mixed> $talk Talk post ID, or a talk data array
	 *                                      whose ics_ref identifies the
	 *                                      session in the active repository.
	 */
	public static function download_url( $talk ): string {
		$ref = is_array( $talk ) ? ( $talk['ics_ref'] ?? $talk['id'] ?? 0 ) : (int) $talk;

		return add_query_arg( 'eex_ics', $ref, home_url( '/' ) );
	}

	/**
	 * Google Calendar template link for one session.
	 *
	 * @param array<string,mixed> $data Talk data from Components::talk_data().
	 */
	public static function google_url( array $data ): string {
		$start = strtotime( (string) ( $data['starts_at'] ?? '' ) );
		$end   = strtotime( (string) ( $data['ends_at'] ?? '' ) );

		if ( false === $start ) {
			return '';
		}
		if ( false === $end || $end <= $start ) {
			$end = $start + HOUR_IN_SECONDS;
		}

		return add_query_arg(
			[
				'action'  => 'TEMPLATE',
				'text'    => rawurlencode( (string) ( $data['title'] ?? '' ) ),
				'dates'   => gmdate( 'Ymd\THis\Z', $start ) . '/' . gmdate( 'Ymd\THis\Z', $end ),
				'details' => rawurlencode( (string) ( $data['permalink'] ?? '' ) ),
			],
			'https://calendar.google.com/calendar/render'
		);
	}

	/**
	 * A complete VCALENDAR document for a set of talks.
	 *
	 * @param int[]  $talk_ids Talk post IDs.
	 * @param string $name     Calendar display name.
	 */
	public static function calendar( array $talk_ids, string $name = '' ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//emailexpert//emailexpert Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		];

		if ( '' !== $name ) {
			$lines[] = self::fold( 'X-WR-CALNAME:' . self::escape( $name ) );
		}

		foreach ( $talk_ids as $talk_id ) {
			$event_lines = self::vevent( (int) $talk_id );
			if ( ! empty( $event_lines ) ) {
				$lines = array_merge( $lines, $event_lines );
			}
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * A complete VCALENDAR document from talk data arrays (Lite mode, where
	 * sessions have no posts).
	 *
	 * @param array<int,array<string,mixed>> $talks Talk data arrays.
	 * @param string                         $name  Calendar display name.
	 */
	public static function calendar_from_data( array $talks, string $name = '' ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//emailexpert//emailexpert Events//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
		];

		if ( '' !== $name ) {
			$lines[] = self::fold( 'X-WR-CALNAME:' . self::escape( $name ) );
		}

		foreach ( $talks as $data ) {
			$event_lines = self::vevent_from_data( (array) $data );
			if ( ! empty( $event_lines ) ) {
				$lines = array_merge( $lines, $event_lines );
			}
		}

		$lines[] = 'END:VCALENDAR';

		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * VEVENT lines for one talk; empty when the talk has no start time.
	 *
	 * @param int $talk_id Talk post ID.
	 * @return string[]
	 */
	public static function vevent( int $talk_id ): array {
		return self::vevent_from_data( Components::talk_data( $talk_id ) );
	}

	/**
	 * VEVENT lines from a talk data array (see Data\Repository); empty when
	 * the talk has no start time.
	 *
	 * @param array<string,mixed> $data Talk data.
	 * @return string[]
	 */
	public static function vevent_from_data( array $data ): array {
		$start = strtotime( (string) ( $data['starts_at'] ?? '' ) );

		if ( false === $start ) {
			return [];
		}

		$end = strtotime( (string) ( $data['ends_at'] ?? '' ) );
		if ( false === $end || $end <= $start ) {
			$end = $start + HOUR_IN_SECONDS;
		}

		$hs_id = (string) ( $data['hs_id'] ?? '' );
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$speakers    = array_map( static fn( array $s ): string => (string) $s['name'], (array) ( $data['speakers'] ?? [] ) );
		$description = (string) ( $data['permalink'] ?? '' );
		if ( ! empty( $speakers ) ) {
			$description .= '\n' . sprintf( 'Speakers: %s', implode( ', ', $speakers ) );
		}
		// Tag from the raw URL (talk_data may already carry page-context
		// tags; calendar entries get their own campaign).
		$register = Utm::tag( (string) ( $data['raw_event_url'] ?? '' ), 0, 'calendar' );
		if ( '' !== $register ) {
			$description .= '\n' . sprintf( 'Register: %s', $register );
		}

		return [
			'BEGIN:VEVENT',
			self::fold( 'UID:eex-talk-' . ( $hs_id ?: (string) ( $data['id'] ?? '' ) ) . '@' . $host ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . gmdate( 'Ymd\THis\Z', $start ),
			'DTEND:' . gmdate( 'Ymd\THis\Z', $end ),
			self::fold( 'SUMMARY:' . self::escape( (string) ( $data['title'] ?? '' ) ) ),
			self::fold( 'DESCRIPTION:' . self::escape( $description ) ),
			self::fold( 'URL:' . self::escape( (string) ( $data['permalink'] ?? '' ) ) ),
			'END:VEVENT',
		];
	}

	/**
	 * Escape text per RFC 5545 3.3.11.
	 *
	 * @param string $text Raw text.
	 */
	public static function escape( string $text ): string {
		$text = str_replace( [ '\\', ';', ',' ], [ '\\\\', '\\;', '\\,' ], $text );

		return str_replace( [ "\r\n", "\n", "\r" ], '\\n', $text );
	}

	/**
	 * Fold a content line at 75 octets per RFC 5545 3.1.
	 *
	 * @param string $line Unfolded line.
	 */
	public static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}

		$out   = [];
		$first = true;

		while ( '' !== $line ) {
			$width = $first ? 75 : 74;
			$chunk = mb_strcut( $line, 0, $width, 'UTF-8' );
			$out[] = ( $first ? '' : ' ' ) . $chunk;
			$line  = (string) substr( $line, strlen( $chunk ) );
			$first = false;
		}

		return implode( "\r\n", $out );
	}
}
