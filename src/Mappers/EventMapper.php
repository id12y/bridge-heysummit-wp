<?php
/**
 * Event resource mapper.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a raw HeySummit event record to the plugin's normalised shape.
 */
final class EventMapper extends BaseMapper {

	/**
	 * Map one raw record.
	 *
	 * @param array<string,mixed> $raw Raw API record.
	 * @return array<string,mixed>|null Null when the record has no usable ID.
	 */
	public static function map( array $raw ): ?array {
		$hs_id = self::id_of( $raw, [ 'id' ] );

		if ( '' === $hs_id ) {
			return null;
		}

		return [
			'hs_id'                     => $hs_id,
			'title'                     => self::str( $raw, [ 'title', 'name' ] ),
			'description'               => self::str( $raw, [ 'description', 'summary', 'about' ] ),
			'event_url'                 => self::url_str( $raw, [ 'event_url', 'url', 'public_url' ] ),
			'timezone'                  => self::str( $raw, [ 'timezone', 'time_zone', 'tz' ] ),
			'first_talk_at'             => self::datetime( $raw, [ 'first_talk_at', 'starts_at', 'start_date' ] ),
			'last_talk_at'              => self::datetime( $raw, [ 'last_talk_at', 'ends_at', 'end_date' ] ),
			'is_live'                   => self::boolish( $raw, 'is_live' ),
			'is_archived'               => self::boolish( $raw, 'is_archived' ),
			'is_evergreen'              => self::boolish( $raw, 'is_evergreen' ),
			'is_open_for_registrations' => self::boolish( $raw, [ 'is_open_for_registrations', '_is_open_for_registrations' ] ),
		];
	}
}
