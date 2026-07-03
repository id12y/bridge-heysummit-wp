<?php
/**
 * Schema.org data generation.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Builds Event, Person and VideoObject arrays from post data. Emits nothing
 * when required fields are missing and never emits placeholder values.
 */
final class SchemaGenerator {

	/**
	 * Event schema for an event post.
	 *
	 * @param int $post_id Event post ID.
	 * @return array<string,mixed>|null Null when required fields are missing.
	 */
	public static function event( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || PostTypes::EVENT !== $post->post_type ) {
			return null;
		}

		$name  = (string) $post->post_title;
		$start = (string) get_post_meta( $post_id, '_eex_first_talk_at', true );

		if ( '' === $name || '' === $start ) {
			return null; // Required fields missing: emit nothing.
		}

		$venue_name = (string) get_post_meta( $post_id, '_eex_venue_name', true );
		$event_url  = (string) get_post_meta( $post_id, '_eex_event_url', true );
		$end        = (string) get_post_meta( $post_id, '_eex_last_talk_at', true );

		$location = [];

		if ( '' !== $venue_name ) {
			$address = array_filter(
				[
					'@type'           => 'PostalAddress',
					'streetAddress'   => (string) get_post_meta( $post_id, '_eex_venue_street', true ),
					'addressLocality' => (string) get_post_meta( $post_id, '_eex_venue_locality', true ),
					'postalCode'      => (string) get_post_meta( $post_id, '_eex_venue_postcode', true ),
					'addressCountry'  => (string) get_post_meta( $post_id, '_eex_venue_country', true ),
				]
			);

			$place = [
				'@type' => 'Place',
				'name'  => $venue_name,
			];
			if ( count( $address ) > 1 ) {
				$place['address'] = $address;
			}
			$location[] = $place;
		}

		if ( '' !== $event_url ) {
			$location[] = [
				'@type' => 'VirtualLocation',
				'url'   => $event_url,
			];
		}

		if ( '' !== $venue_name && '' !== $event_url ) {
			$attendance = 'https://schema.org/MixedEventAttendanceMode';
		} elseif ( '' !== $venue_name ) {
			$attendance = 'https://schema.org/OfflineEventAttendanceMode';
		} else {
			$attendance = 'https://schema.org/OnlineEventAttendanceMode';
		}

		// FORUM-style in-person conferences are BusinessEvent (docs/decisions.md D14).
		$type = '' !== $venue_name ? 'BusinessEvent' : 'Event';

		$schema = [
			'@type'               => $type,
			'@id'                 => get_permalink( $post_id ) . '#event',
			'name'                => $name,
			'startDate'           => $start,
			'eventAttendanceMode' => $attendance,
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'url'                 => (string) get_permalink( $post_id ),
			'organizer'           => [
				'@type' => 'Organization',
				'name'  => 'emailexpert UK Ltd',
				'url'   => 'https://emailexpert.com/',
			],
		];

		if ( '' !== $end ) {
			$schema['endDate'] = $end;
		}

		if ( ! empty( $location ) ) {
			$schema['location'] = 1 === count( $location ) ? $location[0] : $location;
		}

		$description = (string) get_post_meta( $post_id, '_eex_description', true );
		if ( '' !== $description ) {
			$schema['description'] = wp_strip_all_tags( $description );
		}

		// Offers only while registrations are open; URL only, never a guessed price.
		if ( get_post_meta( $post_id, '_eex_is_open_for_registrations', true ) && '' !== $event_url ) {
			$schema['offers'] = [
				'@type'        => 'Offer',
				'url'          => $event_url,
				'availability' => 'https://schema.org/InStock',
			];
		}

		$performers = self::event_performers( $post_id );
		if ( ! empty( $performers ) ) {
			$schema['performer'] = $performers;
		}

		return self::filtered( $schema, 'event', $post_id );
	}

	/**
	 * Event schema for a talk post, nested under its parent via superEvent.
	 *
	 * @param int $post_id Talk post ID.
	 * @return array<string,mixed>|null
	 */
	public static function talk( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || PostTypes::TALK !== $post->post_type ) {
			return null;
		}

		$data = Components::talk_data( $post_id );

		if ( '' === (string) $data['title'] || '' === (string) $data['starts_at'] ) {
			return null;
		}

		$schema = [
			'@type'               => 'Event',
			'@id'                 => (string) $data['permalink'] . '#session',
			'name'                => (string) $data['title'],
			'startDate'           => (string) $data['starts_at'],
			'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'url'                 => (string) $data['permalink'],
			'organizer'           => [
				'@type' => 'Organization',
				'name'  => 'emailexpert UK Ltd',
				'url'   => 'https://emailexpert.com/',
			],
		];

		if ( '' !== (string) $data['ends_at'] ) {
			$schema['endDate'] = (string) $data['ends_at'];
		}

		if ( '' !== (string) $data['description'] ) {
			$schema['description'] = wp_strip_all_tags( (string) $data['description'] );
		}

		if ( $data['event_post_id'] > 0 ) {
			$parent_title = get_the_title( (int) $data['event_post_id'] );
			if ( '' !== $parent_title ) {
				$schema['superEvent'] = [
					'@type' => 'Event',
					'@id'   => get_permalink( (int) $data['event_post_id'] ) . '#event',
					'name'  => $parent_title,
					'url'   => (string) get_permalink( (int) $data['event_post_id'] ),
				];
			}
		}

		$performers = [];
		foreach ( (array) $data['speakers'] as $speaker ) {
			$performers[] = [
				'@type' => 'Person',
				'name'  => (string) $speaker['name'],
				'url'   => (string) $speaker['url'],
			];
		}
		if ( ! empty( $performers ) ) {
			$schema['performer'] = $performers;
		}

		return self::filtered( $schema, 'talk', $post_id );
	}

	/**
	 * VideoObject schema for a past talk with a replay.
	 *
	 * @param int $post_id Talk post ID.
	 * @return array<string,mixed>|null
	 */
	public static function video( int $post_id ): ?array {
		$data = Components::talk_data( $post_id );

		$replay = (string) $data['replay_url'];
		$start  = strtotime( (string) $data['starts_at'] );

		if ( '' === $replay || false === $start || $start >= time() || '' === (string) $data['title'] ) {
			return null;
		}

		$schema = [
			'@type'      => 'VideoObject',
			'@id'        => (string) $data['permalink'] . '#replay',
			'name'       => (string) $data['title'],
			'uploadDate' => gmdate( 'Y-m-d', $start ),
			'contentUrl' => $replay,
		];

		$description           = '' !== (string) $data['description']
			? wp_strip_all_tags( (string) $data['description'] )
			: (string) $data['title'];
		$schema['description'] = $description;

		$embed = self::embed_url( $replay );
		if ( '' !== $embed ) {
			$schema['embedUrl'] = $embed;
		}

		return self::filtered( $schema, 'video', $post_id );
	}

	/**
	 * Person schema for a speaker post.
	 *
	 * @param int $post_id Speaker post ID.
	 * @return array<string,mixed>|null
	 */
	public static function speaker( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post || PostTypes::SPEAKER !== $post->post_type || '' === (string) $post->post_title ) {
			return null;
		}

		$schema = [
			'@type' => 'Person',
			'@id'   => get_permalink( $post_id ) . '#person',
			'name'  => (string) $post->post_title,
			'url'   => (string) get_permalink( $post_id ),
		];

		$headline = (string) get_post_meta( $post_id, '_eex_headline', true );
		if ( '' !== $headline ) {
			$schema['jobTitle'] = $headline;
		}

		$company = (string) get_post_meta( $post_id, '_eex_company', true );
		if ( '' !== $company ) {
			$schema['worksFor'] = [
				'@type' => 'Organization',
				'name'  => $company,
			];
		}

		$photo_id = (int) get_post_meta( $post_id, '_eex_photo_attachment_id', true );
		if ( $photo_id > 0 && function_exists( 'wp_get_attachment_url' ) ) {
			$image = wp_get_attachment_url( $photo_id );
			if ( $image ) {
				$schema['image'] = (string) $image;
			}
		}

		$links = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $post_id, '_eex_links', true ) ) ) );
		if ( ! empty( $links ) ) {
			$schema['sameAs'] = $links;
		}

		return self::filtered( $schema, 'speaker', $post_id );
	}

	/**
	 * Speakers across an event's talks as Person references.
	 *
	 * @param int $event_post_id Event post ID.
	 * @return array<int,array<string,string>>
	 */
	private static function event_performers( int $event_post_id ): array {
		$event_hs_id = (string) get_post_meta( $event_post_id, '_eex_heysummit_id', true );

		if ( '' === $event_hs_id ) {
			return [];
		}

		$talk_ids = Query::talks( [ 'event' => $event_hs_id ] );
		$seen     = [];

		foreach ( $talk_ids as $talk ) {
			$data = Components::talk_data( (int) $talk['id'] );
			foreach ( (array) $data['speakers'] as $speaker ) {
				$seen[ (string) $speaker['name'] ] = [
					'@type' => 'Person',
					'name'  => (string) $speaker['name'],
					'url'   => (string) $speaker['url'],
				];
			}
		}

		return array_values( $seen );
	}

	/**
	 * Embed URL for well-known video hosts.
	 *
	 * @param string $url Replay URL.
	 */
	public static function embed_url( string $url ): string {
		if ( preg_match( '#youtube\.com/watch\?v=([\w-]+)#', $url, $m ) || preg_match( '#youtu\.be/([\w-]+)#', $url, $m ) ) {
			return 'https://www.youtube.com/embed/' . $m[1];
		}

		if ( preg_match( '#vimeo\.com/(\d+)#', $url, $m ) ) {
			return 'https://player.vimeo.com/video/' . $m[1];
		}

		return '';
	}

	/**
	 * Apply the schema filter.
	 *
	 * @param array<string,mixed> $schema  Schema array.
	 * @param string              $kind    event|talk|video|speaker.
	 * @param int                 $post_id Source post.
	 * @return array<string,mixed>|null
	 */
	/**
	 * Inline Event schema from a talk data array (Lite mode: emitted by the
	 * rendering block itself, since no local page exists to carry it).
	 * Follows the Google Event requirements: name + startDate required,
	 * VirtualLocation for the online URL.
	 *
	 * @param array<string,mixed> $data Talk data (see Data\Repository).
	 * @return array<string,mixed>|null
	 */
	public static function inline_event_from_talk( array $data ): ?array {
		$name  = (string) ( $data['title'] ?? '' );
		$start = (string) ( $data['starts_at'] ?? '' );
		$url   = (string) ( ( $data['raw_event_url'] ?? '' ) ?: ( $data['permalink'] ?? '' ) );

		if ( '' === $name || '' === $start ) {
			return null; // Required fields missing: emit nothing.
		}

		$schema = [
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $name,
			'startDate'           => $start,
			'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
		];

		if ( '' !== (string) ( $data['ends_at'] ?? '' ) ) {
			$schema['endDate'] = (string) $data['ends_at'];
		}

		if ( '' !== $url ) {
			$schema['location'] = [
				'@type' => 'VirtualLocation',
				'url'   => $url,
			];
		}

		if ( '' !== (string) ( $data['description'] ?? '' ) ) {
			$schema['description'] = wp_strip_all_tags( (string) $data['description'] );
		}

		$performers = [];
		foreach ( (array) ( $data['speakers'] ?? [] ) as $speaker ) {
			if ( '' !== (string) ( $speaker['name'] ?? '' ) ) {
				$performers[] = [
					'@type' => 'Person',
					'name'  => (string) $speaker['name'],
				];
			}
		}
		if ( ! empty( $performers ) ) {
			$schema['performer'] = $performers;
		}

		return self::filtered( $schema, 'talk', 0 );
	}

	/**
	 * Inline Event schema from an event data array (Lite mode).
	 *
	 * @param array<string,mixed> $event Event data (see Data\Repository).
	 * @return array<string,mixed>|null
	 */
	public static function inline_event_from_event( array $event ): ?array {
		$name  = (string) ( $event['title'] ?? '' );
		$start = (string) ( $event['first_talk_at'] ?? '' );
		$url   = (string) ( ( $event['raw_event_url'] ?? '' ) ?: ( $event['event_url'] ?? '' ) );

		if ( '' === $name || '' === $start ) {
			return null;
		}

		$schema = [
			'@context'            => 'https://schema.org',
			'@type'               => 'Event',
			'name'                => $name,
			'startDate'           => $start,
			'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
			'eventStatus'         => 'https://schema.org/EventScheduled',
		];

		if ( '' !== (string) ( $event['last_talk_at'] ?? '' ) ) {
			$schema['endDate'] = (string) $event['last_talk_at'];
		}

		if ( '' !== $url ) {
			$schema['location'] = [
				'@type' => 'VirtualLocation',
				'url'   => $url,
			];

			if ( ! empty( $event['open'] ) ) {
				$schema['offers'] = [
					'@type'        => 'Offer',
					'url'          => $url,
					'availability' => 'https://schema.org/InStock',
				];
			}
		}

		return self::filtered( $schema, 'event', 0 );
	}

	private static function filtered( array $schema, string $kind, int $post_id ): ?array {
		/**
		 * Filter a generated schema array before output.
		 *
		 * @param array<string,mixed> $schema  Schema piece.
		 * @param string              $kind    event|talk|video|speaker.
		 * @param int                 $post_id Source post ID.
		 */
		$schema = apply_filters( 'eex_schema_data', $schema, $kind, $post_id );

		return is_array( $schema ) && ! empty( $schema ) ? $schema : null;
	}
}
