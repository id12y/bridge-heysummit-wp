<?php
/**
 * Talk route map.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The three known ways to list an event's talks, in default preference
 * order. The published OpenAPI spec documents only the nested route; the
 * top-level parameter styles are kept for older accounts. Every fetcher
 * builds from this map and PathStyles remembers the per-connection winner.
 */
final class TalkRoutes {

	/**
	 * style => [ path, args ].
	 *
	 * @param string $event_id Event ID.
	 * @return array<string,array{0:string,1:array<string,string>}>
	 */
	public static function requests( string $event_id ): array {
		return [
			'nested'   => [ 'events/' . rawurlencode( $event_id ) . '/talks/', [] ],
			'event'    => [ 'talks/', [ 'event' => $event_id ] ],
			'event_id' => [ 'talks/', [ 'event_id' => $event_id ] ],
		];
	}
}
