<?php
/**
 * Repository over the synced local database.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Query;
use Emailexpert\Events\Frontend\Utm;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Full mode: the existing WP_Query/meta path, unchanged in behaviour — this
 * class only assembles the same reads into the shared data-array shapes the
 * templates consume. No HTTP anywhere.
 */
class SyncedRepository implements Repository {

	/**
	 * Upcoming talks.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function upcoming_talks( array $atts ): array {
		return array_map( [ Components::class, 'talk_data' ], Query::upcoming_talks( $atts ) );
	}

	/**
	 * Past talks.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function past_talks( array $atts ): array {
		return array_map( [ Components::class, 'talk_data' ], Query::past_talks( $atts ) );
	}

	/**
	 * Past talk count.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	public function past_talks_total( array $atts ): int {
		return Query::past_talks_total( $atts );
	}

	/**
	 * Upcoming events.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function upcoming_events( array $atts ): array {
		return array_map( [ self::class, 'event_data' ], Query::upcoming_events( $atts ) );
	}

	/**
	 * Past events.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function past_events( array $atts ): array {
		return array_map( [ self::class, 'event_data' ], Query::past_events( $atts ) );
	}

	/**
	 * One event summary.
	 *
	 * @param string $ref Event reference.
	 * @return array<string,mixed>|null
	 */
	public function event_summary( string $ref ): ?array {
		$post_id = Query::resolve_event( $ref );

		return $post_id > 0 ? self::event_data( $post_id ) : null;
	}

	/**
	 * One talk by HeySummit ID or post ID.
	 *
	 * @param string $ref Talk reference.
	 * @return array<string,mixed>|null
	 */
	public function talk( string $ref ): ?array {
		$talk_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( PostTypes::TALK, $ref );

		if ( 0 === $talk_id ) {
			$post    = get_post( (int) $ref );
			$talk_id = ( $post && PostTypes::TALK === $post->post_type ) ? (int) $post->ID : 0;
		}

		return $talk_id > 0 ? Components::talk_data( $talk_id ) : null;
	}

	/**
	 * Same as talk(): local post lookups involve no remote fetch.
	 *
	 * @param string $ref Talk reference.
	 * @return array<string,mixed>|null
	 */
	public function known_talk( string $ref ): ?array {
		return $this->talk( $ref );
	}

	/**
	 * Speakers.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function speakers( array $atts ): array {
		return array_map( [ self::class, 'speaker_data' ], Query::speakers( $atts ) );
	}

	/**
	 * Categories (all terms; matches the previous filter-bar behaviour).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function categories( array $atts ): array {
		$terms = get_terms(
			[
				'taxonomy'   => Taxonomies::CATEGORY,
				'hide_empty' => false,
			]
		);

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$out = [];
		foreach ( $terms as $term ) {
			$link  = function_exists( 'get_term_link' ) ? get_term_link( $term ) : '';
			$out[] = [
				'slug' => (string) $term->slug,
				'name' => (string) $term->name,
				'url'  => is_string( $link ) ? $link : '',
			];
		}

		return $out;
	}

	/**
	 * Sponsors, flat with tier metadata for grouping.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function sponsors( array $atts ): array {
		$event_post_id = Query::resolve_event( (string) ( $atts['event'] ?? '' ) );

		$posts = get_posts(
			[
				'post_type'      => PostTypes::SPONSOR,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);

		$out = [];
		foreach ( $posts as $post ) {
			$sponsor_id = (int) $post->ID;

			if ( $event_post_id > 0 ) {
				$event_ids = array_map( 'intval', (array) get_post_meta( $sponsor_id, '_eex_event_ids', true ) );
				if ( ! empty( $event_ids ) && ! in_array( $event_post_id, $event_ids, true ) ) {
					continue;
				}
			}

			$terms = get_the_terms( $sponsor_id, Taxonomies::TIER );
			$term  = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;

			$out[] = [
				'id'         => $sponsor_id,
				'name'       => get_the_title( $sponsor_id ),
				'url'        => (string) get_post_meta( $sponsor_id, '_eex_url', true ),
				'logo_id'    => (int) get_post_meta( $sponsor_id, '_eex_logo_attachment_id', true ),
				'logo_url'   => '',
				'blurb'      => (string) get_post_meta( $sponsor_id, '_eex_blurb', true ),
				'tier_name'  => $term ? (string) $term->name : __( 'Partner', 'emailexpert-events' ),
				'tier_order' => $term ? (int) get_term_meta( (int) $term->term_id, '_eex_tier_order', true ) : 99,
			];
		}

		return $out;
	}

	/**
	 * Assemble event data from an event post.
	 *
	 * @param int $post_id Event post ID.
	 * @return array<string,mixed>
	 */
	public static function event_data( int $post_id ): array {
		$terms  = get_the_terms( $post_id, Taxonomies::SERIES );
		$series = [];

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$series[] = [
					'slug' => (string) $term->slug,
					'name' => (string) $term->name,
				];
			}
		}

		return [
			'id'            => $post_id,
			'hs_id'         => (string) get_post_meta( $post_id, '_eex_heysummit_id', true ),
			'title'         => get_the_title( $post_id ),
			'url'           => (string) get_permalink( $post_id ),
			'event_url'     => Utm::tag( (string) get_post_meta( $post_id, '_eex_event_url', true ) ),
			'first_talk_at' => (string) get_post_meta( $post_id, '_eex_first_talk_at', true ),
			'last_talk_at'  => (string) get_post_meta( $post_id, '_eex_last_talk_at', true ),
			'timezone'      => (string) get_post_meta( $post_id, '_eex_timezone', true ),
			'open'          => (bool) get_post_meta( $post_id, '_eex_is_open_for_registrations', true ),
			'evergreen'     => (bool) get_post_meta( $post_id, '_eex_is_evergreen', true ),
			'venue'         => (string) get_post_meta( $post_id, '_eex_venue_name', true ),
			'reg_count'     => (int) get_post_meta( $post_id, '_eex_registration_count', true ),
			'series'        => $series,
		];
	}

	/**
	 * Assemble speaker data from a speaker post.
	 *
	 * @param int $post_id Speaker post ID.
	 * @return array<string,mixed>
	 */
	public static function speaker_data( int $post_id ): array {
		return [
			'id'        => $post_id,
			'name'      => get_the_title( $post_id ),
			'url'       => (string) get_permalink( $post_id ),
			'headline'  => (string) get_post_meta( $post_id, '_eex_headline', true ),
			'company'   => (string) get_post_meta( $post_id, '_eex_company', true ),
			'photo_id'  => (int) get_post_meta( $post_id, '_eex_photo_attachment_id', true ),
			'photo_url' => '',
		];
	}
}
