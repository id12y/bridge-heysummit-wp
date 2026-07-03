<?php
/**
 * Import dry run.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Mappers\SpeakerMapper;
use Emailexpert\Events\Mappers\TalkMapper;
use Emailexpert\Events\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches (GET only) exactly what a sync would fetch and applies the same
 * ScopeFilter, producing the counts the wizard previews. Writes nothing.
 * Because engine and dry run share the filter, preview counts match what a
 * confirmed import creates.
 */
class DryRun {

	/**
	 * Preview one event's import.
	 *
	 * @param array<string,string> $connection Connection row.
	 * @param string               $event_id   HeySummit event ID.
	 * @param array<string,mixed>  $config     Event config (scope, filters, toggles).
	 * @return array<string,int>|WP_Error Counts: sessions, past, upcoming,
	 *                                    speakers, images.
	 */
	public static function preview( array $connection, string $event_id, array $config ) {
		$config = Options::normalise_event_config( $config );
		$client = HeySummitClient::for_connection( $connection );

		$counts = [
			'sessions' => 0,
			'past'     => 0,
			'upcoming' => 0,
			'speakers' => 0,
			'images'   => 0,
		];

		if ( ! empty( $config['talks'] ) ) {
			$raw_talks = null;
			foreach ( \Emailexpert\Events\Api\PathStyles::ordered( $client->connection_id(), 'talks', array_keys( \Emailexpert\Events\Api\TalkRoutes::requests( $event_id ) ) ) as $style ) {
				$route     = \Emailexpert\Events\Api\TalkRoutes::requests( $event_id )[ $style ];
				$attempt   = $client->get_all( $route[0], $route[1] );
				$raw_talks = $raw_talks ?? $attempt;

				if ( ! is_wp_error( $attempt ) ) {
					$raw_talks = $attempt;
					break;
				}
			}

			if ( is_wp_error( $raw_talks ) ) {
				return $raw_talks;
			}

			$mapped = [];
			foreach ( $raw_talks as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$talk = TalkMapper::map( $raw );
				if ( null !== $talk && ( '' === $talk['event_hs_id'] || (string) $talk['event_hs_id'] === $event_id ) ) {
					$mapped[] = $talk;
				}
			}

			$in_scope = ScopeFilter::apply( $mapped, $config );
			$now      = time();

			$counts['sessions'] = count( $in_scope );
			foreach ( $in_scope as $talk ) {
				$start = strtotime( (string) $talk['starts_at'] );
				if ( false === $start || $start >= $now ) {
					++$counts['upcoming'];
				} else {
					++$counts['past'];
				}
			}
		}

		if ( ! empty( $config['speakers'] ) ) {
			$raw_speakers = $client->get_all( 'speakers/', [ 'event' => $event_id ] );

			if ( is_wp_error( $raw_speakers ) ) {
				return $raw_speakers;
			}

			foreach ( $raw_speakers as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}
				$speaker = SpeakerMapper::map( $raw );
				if ( null === $speaker ) {
					continue;
				}
				++$counts['speakers'];
				if ( ! empty( $config['photos'] ) && '' !== (string) $speaker['photo_url'] ) {
					++$counts['images'];
				}
			}
		}

		return $counts;
	}
}
