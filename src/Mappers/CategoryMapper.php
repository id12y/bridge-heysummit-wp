<?php
/**
 * Category resource mapper.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a raw HeySummit category record to the plugin's normalised shape.
 */
final class CategoryMapper extends BaseMapper {

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
			'hs_id' => $hs_id,
			'title' => self::str( $raw, [ 'title', 'name', 'label' ] ),
		];
	}
}
