<?php
/**
 * Component data queries.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches talks and events for the display components. All reads are from
 * the local database only; sessions split upcoming/past by talk start time
 * against now (timezone aware, both sides UTC); evergreen events never
 * become past.
 */
final class Query {

	/**
	 * Upcoming talks, soonest first.
	 *
	 * @param array<string,mixed> $atts event, category, limit, offset.
	 * @return int[] Talk post IDs.
	 */
	public static function upcoming_talks( array $atts ): array {
		$talks = self::talks( $atts );
		$now   = time();

		$talks = array_filter(
			$talks,
			static function ( array $talk ) use ( $now ): bool {
				return $talk['start_ts'] > 0 && $talk['start_ts'] >= $now;
			}
		);

		usort( $talks, static fn( array $a, array $b ): int => $a['start_ts'] <=> $b['start_ts'] );

		return self::limit( array_column( $talks, 'id' ), $atts );
	}

	/**
	 * Past talks, newest first.
	 *
	 * @param array<string,mixed> $atts event, category, limit, offset.
	 * @return int[] Talk post IDs.
	 */
	public static function past_talks( array $atts ): array {
		$talks = self::talks( $atts );
		$now   = time();

		$talks = array_filter(
			$talks,
			static function ( array $talk ) use ( $now ): bool {
				return $talk['start_ts'] > 0 && $talk['start_ts'] < $now;
			}
		);

		usort( $talks, static fn( array $a, array $b ): int => $b['start_ts'] <=> $a['start_ts'] );

		return self::limit( array_column( $talks, 'id' ), $atts );
	}

	/**
	 * Count of past talks matching the attributes (for pagination).
	 *
	 * @param array<string,mixed> $atts event, category.
	 */
	public static function past_talks_total( array $atts ): int {
		unset( $atts['limit'], $atts['offset'] );

		return count( self::past_talks( $atts + [ 'limit' => 0 ] ) );
	}

	/**
	 * Upcoming events: first talk in the future, or evergreen with
	 * registrations open. Soonest first, evergreen events last.
	 *
	 * @param array<string,mixed> $atts limit, series.
	 * @return int[] Event post IDs.
	 */
	public static function upcoming_events( array $atts ): array {
		$events = self::events( $atts );
		$now    = time();

		$upcoming = array_filter(
			$events,
			static function ( array $event ) use ( $now ): bool {
				if ( $event['evergreen'] ) {
					return $event['open'];
				}

				return $event['last_ts'] >= $now || $event['first_ts'] >= $now;
			}
		);

		usort(
			$upcoming,
			static function ( array $a, array $b ): int {
				if ( $a['evergreen'] !== $b['evergreen'] ) {
					return $a['evergreen'] ? 1 : -1;
				}

				return $a['first_ts'] <=> $b['first_ts'];
			}
		);

		return self::limit( array_column( $upcoming, 'id' ), $atts );
	}

	/**
	 * Past events, newest first. Evergreen events never appear here.
	 *
	 * @param array<string,mixed> $atts limit, series.
	 * @return int[] Event post IDs.
	 */
	public static function past_events( array $atts ): array {
		$events = self::events( $atts );
		$now    = time();

		$past = array_filter(
			$events,
			static function ( array $event ) use ( $now ): bool {
				if ( $event['evergreen'] ) {
					return false;
				}

				return $event['last_ts'] > 0 && $event['last_ts'] < $now && ( 0 === $event['first_ts'] || $event['first_ts'] < $now );
			}
		);

		usort( $past, static fn( array $a, array $b ): int => $b['last_ts'] <=> $a['last_ts'] );

		return self::limit( array_column( $past, 'id' ), $atts );
	}

	/**
	 * Speakers with at least one published talk matching the filters.
	 *
	 * @param array<string,mixed> $atts event, category, limit.
	 * @return int[] Speaker post IDs, alphabetical by title.
	 */
	public static function speakers( array $atts ): array {
		$talks       = self::talks( $atts );
		$speaker_ids = [];

		foreach ( $talks as $talk ) {
			foreach ( (array) get_post_meta( $talk['id'], '_eex_speaker_ids', true ) as $speaker_id ) {
				$speaker_ids[ (int) $speaker_id ] = true;
			}
		}

		$speakers = [];
		foreach ( array_keys( $speaker_ids ) as $speaker_id ) {
			$post = get_post( $speaker_id );
			if ( $post && 'publish' === $post->post_status ) {
				$speakers[] = [
					'id'    => (int) $post->ID,
					'title' => (string) $post->post_title,
				];
			}
		}

		usort( $speakers, static fn( array $a, array $b ): int => strcasecmp( $a['title'], $b['title'] ) );

		return self::limit( array_column( $speakers, 'id' ), $atts );
	}

	/**
	 * Resolve the `event` attribute to an event post ID.
	 *
	 * Accepts a HeySummit event ID, a WP post ID or a slug. Empty defaults to
	 * the sole synced event when exactly one exists (single-event futures must
	 * work without the attribute).
	 *
	 * @param string $event Attribute value.
	 * @return int Post ID, 0 = no restriction.
	 */
	public static function resolve_event( string $event ): int {
		$published = get_posts(
			[
				'post_type'      => PostTypes::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);

		if ( '' === $event ) {
			return 1 === count( $published ) ? (int) $published[0]->ID : 0;
		}

		foreach ( $published as $post ) {
			if ( (string) get_post_meta( (int) $post->ID, '_eex_heysummit_id', true ) === $event ) {
				return (int) $post->ID;
			}
		}

		foreach ( $published as $post ) {
			if ( (string) $post->ID === $event || ( $post->post_name ?? '' ) === $event ) {
				return (int) $post->ID;
			}
		}

		return 0;
	}

	/**
	 * All published talks matching event and category filters, with parsed
	 * start/end timestamps.
	 *
	 * @param array<string,mixed> $atts event, category, ids.
	 * @return array<int,array<string,mixed>>
	 */
	public static function talks( array $atts ): array {
		$args = [
			'post_type'      => PostTypes::TALK,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		];

		/**
		 * Filter the query arguments used by every component query.
		 *
		 * @param array<string,mixed> $args Query args.
		 * @param string              $kind 'talks' or 'events'.
		 * @param array<string,mixed> $atts Component attributes.
		 */
		$args = (array) apply_filters( 'eex_query_args', $args, 'talks', $atts );

		$posts          = get_posts( $args );
		$event_post_id  = self::resolve_event( (string) ( $atts['event'] ?? '' ) );
		$event_hs_id    = $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_heysummit_id', true ) : '';
		$category_slugs = self::category_slugs( (string) ( $atts['category'] ?? '' ) );
		$only_ids       = array_filter( array_map( 'intval', (array) ( $atts['ids'] ?? [] ) ) );

		$talks = [];

		foreach ( $posts as $post ) {
			$post_id = (int) $post->ID;

			if ( ! empty( $only_ids ) && ! in_array( $post_id, $only_ids, true ) ) {
				continue;
			}

			if ( '' !== $event_hs_id && (string) get_post_meta( $post_id, '_eex_source_event_id', true ) !== $event_hs_id ) {
				continue;
			}

			if ( ! empty( $category_slugs ) ) {
				$post_slugs = wp_get_object_terms( [ $post_id ], Taxonomies::CATEGORY, [ 'fields' => 'slugs' ] );
				$post_slugs = is_array( $post_slugs ) ? $post_slugs : [];
				if ( empty( array_intersect( $category_slugs, $post_slugs ) ) ) {
					continue;
				}
			}

			$starts = (string) get_post_meta( $post_id, '_eex_starts_at', true );
			$ends   = (string) get_post_meta( $post_id, '_eex_ends_at', true );

			$talks[] = [
				'id'       => $post_id,
				'start_ts' => $starts ? (int) strtotime( $starts ) : 0,
				'end_ts'   => $ends ? (int) strtotime( $ends ) : 0,
			];
		}

		return $talks;
	}

	/**
	 * All published events with parsed range and evergreen flags.
	 *
	 * @param array<string,mixed> $atts series.
	 * @return array<int,array<string,mixed>>
	 */
	private static function events( array $atts ): array {
		$args = [
			'post_type'      => PostTypes::EVENT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		];

		/** This filter is documented in self::talks(). */
		$args = (array) apply_filters( 'eex_query_args', $args, 'events', $atts );

		$series = sanitize_title( (string) ( $atts['series'] ?? '' ) );
		$events = [];

		foreach ( get_posts( $args ) as $post ) {
			$post_id = (int) $post->ID;

			if ( '' !== $series ) {
				$slugs = wp_get_object_terms( [ $post_id ], Taxonomies::SERIES, [ 'fields' => 'slugs' ] );
				if ( ! is_array( $slugs ) || ! in_array( $series, $slugs, true ) ) {
					continue;
				}
			}

			$first = (string) get_post_meta( $post_id, '_eex_first_talk_at', true );
			$last  = (string) get_post_meta( $post_id, '_eex_last_talk_at', true );

			$events[] = [
				'id'        => $post_id,
				'first_ts'  => $first ? (int) strtotime( $first ) : 0,
				'last_ts'   => $last ? (int) strtotime( $last ) : 0,
				'evergreen' => (bool) get_post_meta( $post_id, '_eex_is_evergreen', true )
					|| ( '' === $first && '' === $last ),
				'open'      => (bool) get_post_meta( $post_id, '_eex_is_open_for_registrations', true ),
			];
		}

		return $events;
	}

	/**
	 * Parse the category attribute (slug or comma-separated slugs).
	 *
	 * @param string $category Attribute.
	 * @return string[]
	 */
	private static function category_slugs( string $category ): array {
		if ( '' === trim( $category ) ) {
			return [];
		}

		return array_values( array_filter( array_map( 'sanitize_title', explode( ',', $category ) ) ) );
	}

	/**
	 * Apply limit/offset attributes.
	 *
	 * @param int[]               $ids  IDs.
	 * @param array<string,mixed> $atts limit, offset.
	 * @return int[]
	 */
	private static function limit( array $ids, array $atts ): array {
		$offset = max( 0, (int) ( $atts['offset'] ?? 0 ) );
		$limit  = (int) ( $atts['limit'] ?? 0 );

		if ( $offset > 0 || $limit > 0 ) {
			return array_slice( $ids, $offset, $limit > 0 ? $limit : null );
		}

		return $ids;
	}
}
