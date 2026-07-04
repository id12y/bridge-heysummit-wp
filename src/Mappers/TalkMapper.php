<?php
/**
 * Talk resource mapper.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a raw HeySummit talk record to the plugin's normalised shape.
 */
final class TalkMapper extends BaseMapper {

	/**
	 * Map one raw record.
	 *
	 * @param array<string,mixed> $raw            Raw API record.
	 * @param string              $event_timezone The event's timezone — bare
	 *                                            (offset-less) timestamps are
	 *                                            event-local, not UTC.
	 * @return array<string,mixed>|null Null when the record has no usable ID.
	 */
	public static function map( array $raw, string $event_timezone = '' ): ?array {
		$hs_id = self::id_of( $raw, [ 'id' ] );

		if ( '' === $hs_id ) {
			return null;
		}

		$categories = [];
		if ( isset( $raw['categories'] ) && is_array( $raw['categories'] ) ) {
			foreach ( $raw['categories'] as $category ) {
				if ( is_array( $category ) ) {
					$mapped = CategoryMapper::map( $category );
					if ( null !== $mapped ) {
						$categories[] = $mapped;
					}
				} elseif ( is_scalar( $category ) && '' !== (string) $category ) {
					$categories[] = [
						'hs_id' => (string) $category,
						'title' => '',
					];
				}
			}
		}

		return [
			'hs_id'           => $hs_id,
			'title'           => self::str( $raw, [ 'title', 'name' ] ),
			'description'     => self::str( $raw, [ 'description', 'summary', 'abstract' ] ),
			'starts_at'       => self::datetime( $raw, [ 'starts_at', 'date', 'start_time', 'start_date', 'scheduled_at' ], $event_timezone ),
			'ends_at'         => self::datetime( $raw, [ 'ends_at', 'end_time', 'end_date' ], $event_timezone ),
			'talk_url'        => self::url_str( $raw, [ 'talk_url', 'url', 'public_url' ] ),
			'replay_url'      => self::url_str( $raw, [ 'replay_url', 'recording_url', 'video_url' ] ),
			'event_hs_id'     => self::id_of( $raw, [ 'event', 'event_id' ] ),
			'speaker_hs_ids'  => self::id_list( $raw['speakers'] ?? null ),
			'category_hs_ids' => array_values( array_unique( array_column( $categories, 'hs_id' ) ) ),
			'categories'      => $categories,
		];
	}
}
