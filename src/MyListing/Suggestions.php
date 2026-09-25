<?php
/**
 * Proposed MyListing projection mappings.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\MyListing;

defined( 'ABSPATH' ) || exit;

/**
 * Works out what the projection mapping almost certainly ought to be, so the
 * operator reviews a filled-in form instead of building one from nothing.
 *
 * Three of the source fields have exactly one possible target — a title goes
 * to the listing title, a description to the listing description, a photo to
 * the featured image — so leaving those unset was asking for a decision that
 * does not exist. The rest are matched against the listing type's discovered
 * fields on name and type.
 *
 * Nothing here writes anything. Suggestions are offered as the pre-selected
 * value in the form and take effect only when the operator saves, so the
 * bridge still never projects on a mapping nobody approved.
 */
final class Suggestions {

	/**
	 * Words that identify each source field in a listing field's key or
	 * label, strongest first, with the listing field type that fits it.
	 */
	private const SIGNALS = [
		'starts_at'    => [
			'words' => [ 'start_date', 'startdate', 'event_date', 'start', 'begins', 'begin', 'from', 'when', 'date' ],
			'types' => [ 'date', 'datetime', 'date-picker' ],
		],
		'ends_at'      => [
			'words' => [ 'end_date', 'enddate', 'finish', 'until', 'ends', 'end', 'till', 'to' ],
			'types' => [ 'date', 'datetime', 'date-picker' ],
		],
		'register_url' => [
			'words' => [ 'register', 'registration', 'signup', 'sign_up', 'booking', 'book', 'ticket', 'rsvp', 'apply' ],
			'types' => [ 'url', 'link' ],
		],
		'replay_url'   => [
			'words' => [ 'replay', 'recording', 'on_demand', 'ondemand', 'watch', 'video', 'youtube', 'vimeo', 'stream' ],
			'types' => [ 'url', 'link', 'video' ],
		],
		'event_url'    => [
			'words' => [ 'event_url', 'website', 'web_site', 'homepage', 'site_url', 'website_url', 'url', 'link', 'web' ],
			'types' => [ 'url', 'link' ],
		],
	];

	/** Words that identify the listing type each source belongs in. */
	private const TYPE_SIGNALS = [
		'events'   => [ 'event', 'summit', 'conference', 'webinar', 'meetup' ],
		'sessions' => [ 'session', 'talk', 'workshop', 'presentation', 'agenda', 'schedule' ],
		'speakers' => [ 'speaker', 'presenter', 'person', 'people', 'profile', 'author', 'host' ],
	];

	/**
	 * The listing type a source most likely projects into.
	 *
	 * @param string                            $source Source key.
	 * @param array<int,array<string,mixed>>    $types  Detected listing types.
	 * @return string Type slug, or '' when nothing matches well enough.
	 */
	public static function listing_type( string $source, array $types ): string {
		$words = self::TYPE_SIGNALS[ $source ] ?? [];

		if ( empty( $words ) ) {
			return '';
		}

		$best       = '';
		$best_score = 0;

		foreach ( $types as $type ) {
			$haystack = strtolower( (string) ( $type['slug'] ?? '' ) . ' ' . (string) ( $type['label'] ?? '' ) );

			foreach ( $words as $rank => $word ) {
				if ( ! str_contains( $haystack, $word ) ) {
					continue;
				}

				// Earlier words are stronger; an exact slug is stronger still.
				$score = ( count( $words ) - $rank ) * 10;

				if ( strtolower( (string) ( $type['slug'] ?? '' ) ) === $word ) {
					$score += 100;
				}

				if ( $score > $best_score ) {
					$best_score = $score;
					$best       = (string) ( $type['slug'] ?? '' );
				}

				break;
			}
		}

		return $best;
	}

	/**
	 * The mapping a source most likely wants, for the fields that are not
	 * already mapped.
	 *
	 * @param string                    $source     Source key.
	 * @param array<string,mixed>|null  $type       The chosen listing type, or null.
	 * @param array<string,string>      $already    Existing mapping (never overridden).
	 * @return array<string,string> Source field => suggested target.
	 */
	public static function map( string $source, ?array $type, array $already = [] ): array {
		$suggested = [];

		foreach ( array_keys( Module::source_fields( $source ) ) as $source_field ) {
			if ( '' !== (string) ( $already[ $source_field ] ?? '' ) ) {
				continue; // The operator has already decided this one.
			}

			$target = self::target_for( $source_field, $type );

			if ( '' !== $target ) {
				$suggested[ $source_field ] = $target;
			}
		}

		return $suggested;
	}

	/**
	 * The target a single source field should map to.
	 *
	 * @param string                   $source_field Source field key.
	 * @param array<string,mixed>|null $type         The chosen listing type, or null.
	 */
	private static function target_for( string $source_field, ?array $type ): string {
		// Structural targets: one possible answer, so not really a choice.
		if ( 'title' === $source_field || 'description' === $source_field ) {
			return 'post';
		}

		if ( 'photo' === $source_field ) {
			return '_thumbnail';
		}

		if ( null === $type ) {
			return ''; // Everything below needs to know the listing type.
		}

		if ( 'categories' === $source_field ) {
			return self::taxonomy_for( (array) ( $type['taxonomies'] ?? [] ) );
		}

		$signals = self::SIGNALS[ $source_field ] ?? null;

		if ( null === $signals ) {
			return '';
		}

		return self::field_for( $signals, (array) ( $type['fields'] ?? [] ) );
	}

	/**
	 * The taxonomy categories should go to: the one that reads like a
	 * category, else the only one on offer.
	 *
	 * @param array<int,string> $taxonomies Listing taxonomies.
	 */
	private static function taxonomy_for( array $taxonomies ): string {
		$taxonomies = array_values( array_filter( array_map( 'strval', $taxonomies ) ) );

		foreach ( $taxonomies as $taxonomy ) {
			if ( str_contains( strtolower( $taxonomy ), 'category' ) ) {
				return $taxonomy;
			}
		}

		return 1 === count( $taxonomies ) ? $taxonomies[0] : '';
	}

	/**
	 * The best-matching listing field for a set of signals.
	 *
	 * @param array<string,array<int,string>>  $signals Words and field types.
	 * @param array<int,array<string,string>>  $fields  The type's fields.
	 */
	private static function field_for( array $signals, array $fields ): string {
		$words      = $signals['words'];
		$types      = $signals['types'];
		$best       = '';
		$best_score = 0;

		foreach ( $fields as $field ) {
			$key = (string) ( $field['key'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$haystack = strtolower( $key . ' ' . (string) ( $field['label'] ?? '' ) );
			$score    = 0;

			foreach ( $words as $rank => $word ) {
				if ( str_contains( $haystack, $word ) ) {
					$score = ( count( $words ) - $rank ) * 10;
					break;
				}
			}

			if ( 0 === $score ) {
				continue; // No name evidence: never guess from the type alone.
			}

			if ( in_array( strtolower( (string) ( $field['type'] ?? '' ) ), $types, true ) ) {
				$score += 5;
			}

			if ( $score > $best_score ) {
				$best_score = $score;
				$best       = $key;
			}
		}

		return $best;
	}
}
