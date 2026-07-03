<?php
/**
 * Repository over the live HeySummit API (Lite mode).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Frontend\Utm;
use Emailexpert\Events\Mappers\BaseMapper;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches the configured events and their talks server-side through the
 * existing client (3-second timeout, no transport retries) and holds
 * responses in the LiveCache. The browser never contacts HeySummit and the
 * key never leaves the server. Nothing here writes posts, media or tables.
 *
 * Extends BaseMapper only for the defensive field extractors the sync
 * mappers use, so live parsing tolerates the same shape drift.
 *
 * Lite is forward-looking: past sessions and past events return empty (an
 * unbounded past would mean unbounded live queries).
 */
class LiveRepository extends BaseMapper implements Repository {

	/**
	 * Per-request memo of talk data per event key.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private array $talks_memo = [];

	/**
	 * Upcoming talks across the configured events, soonest first.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function upcoming_talks( array $atts ): array {
		$now   = time();
		$talks = array_filter(
			$this->talks_matching( $atts ),
			static fn( array $talk ): bool => $talk['start_ts'] > 0 && $talk['start_ts'] >= $now
		);

		usort( $talks, static fn( array $a, array $b ): int => $a['start_ts'] <=> $b['start_ts'] );

		return $this->limit( array_values( $talks ), $atts );
	}

	/**
	 * Past talks: none in Lite (forward-looking).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function past_talks( array $atts ): array {
		return [];
	}

	/**
	 * Past talk count: none in Lite.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	public function past_talks_total( array $atts ): int {
		return 0;
	}

	/**
	 * Upcoming events among the configured ones.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function upcoming_events( array $atts ): array {
		$now = time();

		$events = array_filter(
			$this->configured_events(),
			static function ( array $event ) use ( $now ): bool {
				if ( ! empty( $event['evergreen'] ) ) {
					return ! empty( $event['open'] );
				}

				$first = $event['first_talk_at'] ? (int) strtotime( (string) $event['first_talk_at'] ) : 0;
				$last  = $event['last_talk_at'] ? (int) strtotime( (string) $event['last_talk_at'] ) : 0;

				return $last >= $now || $first >= $now;
			}
		);

		usort(
			$events,
			static function ( array $a, array $b ): int {
				if ( (bool) $a['evergreen'] !== (bool) $b['evergreen'] ) {
					return $a['evergreen'] ? 1 : -1;
				}

				return (int) strtotime( (string) $a['first_talk_at'] ) <=> (int) strtotime( (string) $b['first_talk_at'] );
			}
		);

		return $this->limit( array_values( $events ), $atts );
	}

	/**
	 * Past events: none in Lite.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function past_events( array $atts ): array {
		return [];
	}

	/**
	 * One configured event by reference ('' = the sole configured event).
	 *
	 * @param string $ref Event reference (HeySummit ID).
	 * @return array<string,mixed>|null
	 */
	public function event_summary( string $ref ): ?array {
		$events = $this->configured_events();

		if ( '' === $ref ) {
			return 1 === count( $events ) ? $events[0] : null;
		}

		foreach ( $events as $event ) {
			if ( (string) $event['hs_id'] === $ref ) {
				return $event;
			}
		}

		return null;
	}

	/**
	 * One talk by HeySummit ID: from the cached event talks when possible,
	 * otherwise a targeted (budgeted) fetch.
	 *
	 * @param string $ref Talk reference.
	 * @return array<string,mixed>|null
	 */
	public function talk( string $ref ): ?array {
		$known = $this->known_talk( $ref );
		if ( null !== $known ) {
			return $known;
		}

		if ( ! preg_match( '/^\d+$/', $ref ) ) {
			return null;
		}

		// The spec serves single talks nested under their event only; try
		// each configured event (budgeted, cached).
		foreach ( $this->configured_keys() as $key ) {
			[ $conn_id, $event_id ] = array_pad( explode( '|', $key, 2 ), 2, '' );

			$client = $this->client( $conn_id );
			if ( null === $client || '' === $event_id ) {
				continue;
			}

			$raw = LiveCache::remember(
				'talk|' . $conn_id . '|' . $event_id . '|' . $ref,
				static function () use ( $client, $event_id, $ref ) {
					// A WP_Error propagates its reason to the cache status.
					return $client->get( 'events/' . rawurlencode( $event_id ) . '/talks/' . rawurlencode( $ref ) . '/', [], self::request_options() );
				}
			);

			if ( is_array( $raw ) && '' !== self::id_of( $raw, [ 'id' ] ) ) {
				$event = $this->event_summary( $event_id );

				return $this->map_talk( $raw, $event ?? [] );
			}
		}

		return null;
	}

	/**
	 * A talk from the configured events' (cached) collections only — no
	 * targeted fetch, so visitor-controlled references cannot spend API
	 * calls or mint per-ID cache rows.
	 *
	 * @param string $ref Talk reference.
	 * @return array<string,mixed>|null
	 */
	public function known_talk( string $ref ): ?array {
		foreach ( $this->configured_events() as $event ) {
			foreach ( $this->talks_for_event( $event ) as $talk ) {
				if ( (string) $talk['hs_id'] === $ref ) {
					return $talk;
				}
			}
		}

		return null;
	}

	/**
	 * Speakers across the matching talks, alphabetical.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function speakers( array $atts ): array {
		$speakers = [];

		foreach ( $this->talks_matching( $atts + [ 'limit' => 0 ] ) as $talk ) {
			foreach ( (array) $talk['raw_speakers'] as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$entry = $this->map_speaker( $raw, (string) $talk['event_url'] );

				if ( '' !== $entry['name'] ) {
					$speakers[ $entry['id'] ?: $entry['name'] ] = $entry;
				}
			}
		}

		$speakers = array_values( $speakers );
		usort( $speakers, static fn( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] ) );

		return $this->limit( $speakers, $atts );
	}

	/**
	 * Categories across the matching talks (no local pages, so no URLs).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function categories( array $atts ): array {
		$out = [];

		foreach ( $this->talks_matching( $atts + [ 'limit' => 0 ] ) as $talk ) {
			foreach ( (array) $talk['categories'] as $category ) {
				$out[ (string) $category->slug ] = [
					'slug' => (string) $category->slug,
					'name' => (string) $category->name,
					'url'  => '',
				];
			}
		}

		ksort( $out );

		return array_values( $out );
	}

	/**
	 * Sponsors from the settings option (manual data; capped and lean).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	public function sponsors( array $atts ): array {
		$out = [];

		foreach ( array_slice( (array) Options::setting( 'lite_sponsors' ), 0, 60 ) as $index => $sponsor ) {
			if ( ! is_array( $sponsor ) || '' === (string) ( $sponsor['name'] ?? '' ) ) {
				continue;
			}

			$out[] = [
				'id'         => $index + 1,
				'name'       => (string) $sponsor['name'],
				'url'        => (string) ( $sponsor['url'] ?? '' ),
				'logo_id'    => 0,
				'logo_url'   => (string) ( $sponsor['logo_url'] ?? '' ),
				'blurb'      => (string) ( $sponsor['blurb'] ?? '' ),
				'tier_name'  => '' !== (string) ( $sponsor['tier'] ?? '' ) ? (string) $sponsor['tier'] : __( 'Partner', 'emailexpert-events' ),
				'tier_order' => max( 0, (int) ( $sponsor['tier_order'] ?? 99 ) ),
			];
		}

		return $out;
	}

	/**
	 * A one-line explanation of why components might be empty, for
	 * administrators (the on-page debug note and the dashboard widget).
	 * Walks the pipeline in order and reports the first gap; '' when data
	 * is flowing. Uses the same caches and budget as any render.
	 */
	public function diagnose(): string {
		$keys = $this->configured_keys();

		if ( empty( $keys ) ) {
			return __( 'No events are chosen to display. Go to Settings → emailexpert Events → Live display → Choose events.', 'emailexpert-events' );
		}

		$has_client = false;
		foreach ( $keys as $key ) {
			[ $conn_id ] = explode( '|', $key, 2 );
			if ( null !== $this->client( (string) $conn_id ) ) {
				$has_client = true;
				break;
			}
		}

		if ( ! $has_client ) {
			return __( 'The configured events reference a connection with no API key saved. Check Settings → emailexpert Events → API.', 'emailexpert-events' );
		}

		$events = $this->configured_events();

		if ( empty( $events ) ) {
			return sprintf(
				/* translators: %s: comma-separated configured connection|event keys. */
				__( 'The configured event(s) %s could not be fetched from HeySummit — check the event IDs and the cache status on the dashboard widget.', 'emailexpert-events' ),
				implode( ', ', $keys )
			);
		}

		$total   = 0;
		$next    = 0;
		$undated = 0;
		$latest  = 0;
		$now     = time();

		foreach ( $events as $event ) {
			foreach ( $this->talks_for_event( $event ) as $talk ) {
				++$total;

				$ts = (int) $talk['start_ts'];

				if ( $ts <= 0 ) {
					++$undated;
					continue;
				}

				if ( $ts >= $now ) {
					++$next;
				}

				$latest = max( $latest, $ts );
			}
		}

		if ( 0 === $total ) {
			return sprintf(
				/* translators: %s: comma-separated event IDs. */
				__( 'HeySummit returned no sessions for event(s) %s (tried both ?event= and ?event_id= filters). Confirm the event has published talks.', 'emailexpert-events' ),
				implode( ', ', array_map( static fn( array $event ): string => (string) $event['hs_id'], $events ) )
			);
		}

		if ( 0 === $next ) {
			if ( $undated === $total ) {
				return sprintf(
					/* translators: %d: total sessions found. */
					__( '%d session(s) were found, but none of them has a date set on HeySummit, so none can be shown as upcoming. Give the sessions a date and time on HeySummit, then flush the live cache.', 'emailexpert-events' ),
					$total
				);
			}

			$detail = sprintf(
				/* translators: %s: most recent session date/time, UTC. */
				__( 'the most recent was %s UTC', 'emailexpert-events' ),
				gmdate( 'Y-m-d H:i', $latest )
			);

			if ( $undated > 0 ) {
				$detail .= sprintf(
					/* translators: %d: sessions without a date. */
					__( ', and %d of them has no date set on HeySummit', 'emailexpert-events' ),
					$undated
				);
			}

			return sprintf(
				/* translators: 1: total sessions found, 2: detail about the most recent session. */
				__( '%1$d session(s) were found, but none start in the future (%2$s) — Lite is forward-looking and shows upcoming sessions only.', 'emailexpert-events' ),
				$total,
				$detail
			);
		}

		return '';
	}

	/**
	 * Client options for render-time fetches: short timeout, no retries.
	 *
	 * @return array<string,int>
	 */
	protected static function request_options(): array {
		return [
			'timeout' => 3,
			'retries' => 0,
		];
	}

	/**
	 * The configured "connection|event" keys from the settings option.
	 *
	 * @return string[]
	 */
	protected function configured_keys(): array {
		return array_values( array_filter( array_map( 'strval', (array) Options::setting( 'lite_events' ) ) ) );
	}

	/**
	 * Event data arrays for every configured event, via the cache.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function configured_events(): array {
		$by_connection = [];
		foreach ( $this->configured_keys() as $key ) {
			[ $conn_id, $event_id ] = array_pad( explode( '|', $key, 2 ), 2, '' );
			if ( '' !== $conn_id && '' !== $event_id ) {
				$by_connection[ $conn_id ][] = $event_id;
			}
		}

		$events = [];

		foreach ( $by_connection as $conn_id => $event_ids ) {
			$client = $this->client( (string) $conn_id );
			if ( null === $client ) {
				continue;
			}

			$collection = LiveCache::remember(
				'events|' . $conn_id,
				static function () use ( $client ) {
					$response = $client->get( 'events/', [], self::request_options() );

					if ( is_wp_error( $response ) ) {
						return $response; // The reason reaches the cache status.
					}

					$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : ( array_is_list( $response ) ? $response : [ $response ] );

					return array_values( array_filter( $results, 'is_array' ) );
				}
			);

			$found = [];

			foreach ( (array) $collection as $raw ) {
				if ( is_array( $raw ) && in_array( self::id_of( $raw, [ 'id' ] ), array_map( 'strval', $event_ids ), true ) ) {
					$found[ self::id_of( $raw, [ 'id' ] ) ] = true;

					$events[] = $this->map_event( $raw, (string) $conn_id );
				}
			}

			// A configured event beyond the first collection page still
			// resolves: one targeted, cached fetch per missing ID.
			foreach ( array_map( 'strval', $event_ids ) as $event_id ) {
				if ( isset( $found[ $event_id ] ) ) {
					continue;
				}

				$raw = LiveCache::remember(
					'event|' . $conn_id . '|' . $event_id,
					static function () use ( $client, $event_id ) {
						return $client->get( 'events/' . rawurlencode( $event_id ) . '/', [], self::request_options() );
					}
				);

				if ( is_array( $raw ) && '' !== self::id_of( $raw, [ 'id' ] ) ) {
					$events[] = $this->map_event( $raw, (string) $conn_id );
				}
			}
		}

		return $events;
	}

	/**
	 * Talk data arrays matching the component attributes (event, category,
	 * q, ids), unsorted.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	protected function talks_matching( array $atts ): array {
		$event_ref = (string) ( $atts['event'] ?? '' );
		$category  = strtolower( trim( (string) ( $atts['category'] ?? '' ) ) );
		$search    = strtolower( trim( (string) ( $atts['q'] ?? '' ) ) );

		$events = $this->configured_events();

		if ( '' !== $event_ref ) {
			$events = array_values( array_filter( $events, static fn( array $event ): bool => (string) $event['hs_id'] === $event_ref ) );
		}

		$talks = [];
		foreach ( $events as $event ) {
			$talks = array_merge( $talks, $this->talks_for_event( $event ) );
		}

		return array_values(
			array_filter(
				$talks,
				static function ( array $talk ) use ( $category, $search ): bool {
					if ( '' !== $search && ! str_contains( strtolower( (string) $talk['title'] ), $search ) ) {
						return false;
					}

					if ( '' !== $category ) {
						$slugs = array_map( static fn( object $term ): string => (string) $term->slug, (array) $talk['categories'] );

						if ( empty( array_intersect( array_filter( array_map( 'sanitize_title', explode( ',', $category ) ) ), $slugs ) ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	/**
	 * Talk data arrays for one event, via the cache and a per-request memo.
	 *
	 * @param array<string,mixed> $event Event data array.
	 * @return array<int,array<string,mixed>>
	 */
	protected function talks_for_event( array $event ): array {
		$key = (string) $event['connection'] . '|' . (string) $event['hs_id'];

		if ( isset( $this->talks_memo[ $key ] ) ) {
			return $this->talks_memo[ $key ];
		}

		$client = $this->client( (string) $event['connection'] );
		if ( null === $client ) {
			$this->talks_memo[ $key ] = [];

			return [];
		}

		$event_hs_id = (string) $event['hs_id'];

		$conn_id = (string) $event['connection'];

		$collection = LiveCache::remember(
			'talks|' . $key,
			static function () use ( $client, $event_hs_id, $conn_id ) {
				$normalise = static function ( $response ): array {
					$results = isset( $response['results'] ) && is_array( $response['results'] ) ? $response['results'] : ( is_array( $response ) && array_is_list( $response ) ? $response : [] );

					return array_values( array_filter( $results, 'is_array' ) );
				};

				$usable = static function ( array $results ) use ( $event_hs_id ): bool {
					foreach ( $results as $raw ) {
						$talk_event = (string) ( is_scalar( $raw['event'] ?? null ) ? $raw['event'] : ( $raw['event']['id'] ?? ( $raw['event_id'] ?? '' ) ) );
						if ( '' === $talk_event || $talk_event === $event_hs_id ) {
							return true; // At least one talk plausibly belongs here.
						}
					}

					return false;
				};

				// Three known route styles (docs/api-notes.md): top-level
				// filtered by ?event=, by ?event_id=, and nested under the
				// event — live verification found accounts where only the
				// nested route is permitted (top-level answers 403). The
				// working style is remembered per connection so later
				// fetches lead with it.
				$requests = \Emailexpert\Events\Api\TalkRoutes::requests( $event_hs_id );

				$first_error = null;
				$saw_empty   = false;

				// The collection is paginated (10 per page, oldest first on
				// real accounts) — page 1 of a long-running summit is years
				// old and the upcoming sessions live on the LAST pages, so a
				// single-page read shows nothing. Walk every page, capped.
				$options = self::request_options();

				/**
				 * Filter the page cap for render-time talk fetches.
				 *
				 * @param int $max_pages Maximum pages per collection (default 20).
				 */
				$options['max_pages'] = max( 1, (int) apply_filters( 'eex_live_max_pages', 20 ) );

				foreach ( \Emailexpert\Events\Api\PathStyles::ordered( $conn_id, 'talks', array_keys( $requests ) ) as $style ) {
					[ $path, $args ] = $requests[ $style ];

					$response = $client->get_all( $path, $args, $options );

					if ( is_wp_error( $response ) ) {
						$first_error = $first_error ?? $response;
						continue;
					}

					$results = $normalise( $response );

					if ( ! empty( $results ) && $usable( $results ) ) {
						\Emailexpert\Events\Api\PathStyles::remember( $conn_id, 'talks', $style );

						return $results;
					}

					$saw_empty = true;
				}

				// Any style that answered successfully-but-empty means "no
				// sessions yet" (an event with no talks is normal); only
				// when every style errored does the reason surface.
				return $saw_empty ? [] : ( $first_error ?? [] );
			}
		);

		$talks = [];
		foreach ( (array) $collection as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			// Tolerate an unfiltered collection: drop talks that declare a
			// different event.
			$talk_event = self::id_of( $raw, [ 'event', 'event_id' ] );
			if ( '' !== $talk_event && $talk_event !== $event_hs_id ) {
				continue;
			}

			// "Mark this talk as inactive if you want to remove it from the
			// site" (spec) — respect it.
			if ( isset( $raw['is_active'] ) && false === $raw['is_active'] ) {
				continue;
			}

			$talks[] = $this->map_talk( $raw, $event );
		}

		$this->talks_memo[ $key ] = $talks;

		return $talks;
	}

	/**
	 * Map a raw API event to the shared event data shape.
	 *
	 * @param array<string,mixed> $raw     Raw record.
	 * @param string              $conn_id Connection ID.
	 * @return array<string,mixed>
	 */
	protected function map_event( array $raw, string $conn_id ): array {
		$url = self::url_str( $raw, [ 'event_url', 'url', 'public_url' ] );

		return [
			'id'            => 0,
			'hs_id'         => self::id_of( $raw, [ 'id' ] ),
			'connection'    => $conn_id,
			'title'         => self::str( $raw, [ 'title', 'name' ] ),
			'url'           => Utm::tag( $url ),
			'event_url'     => Utm::tag( $url ),
			'raw_event_url' => $url,
			'first_talk_at' => self::datetime( $raw, [ 'first_talk_at', 'starts_at' ] ),
			'last_talk_at'  => self::datetime( $raw, [ 'last_talk_at', 'ends_at' ] ),
			'timezone'      => self::str( $raw, [ 'timezone' ] ),
			'open'          => self::boolish( $raw, [ 'is_open_for_registrations', '_is_open_for_registrations' ] ),
			'evergreen'     => self::boolish( $raw, 'is_evergreen' ),
			'venue'         => self::str( $raw, [ 'venue_name', 'venue' ] ),
			'reg_count'     => 0,
			'series'        => [],
		];
	}

	/**
	 * Map a raw API talk to the shared talk data shape. Where Full links to
	 * local pages, this links to the HeySummit talk or event URL — the one
	 * permitted rendering difference.
	 *
	 * @param array<string,mixed> $raw   Raw record.
	 * @param array<string,mixed> $event Event data array (may be empty).
	 * @return array<string,mixed>
	 */
	protected function map_talk( array $raw, array $event ): array {
		$hs_id         = self::id_of( $raw, [ 'id' ] );
		$talk_url      = self::url_str( $raw, [ 'talk_url', 'url', 'public_url' ] );
		$raw_event_url = (string) ( $event['raw_event_url'] ?? '' );
		$event_url     = '' !== $raw_event_url ? Utm::tag( $raw_event_url ) : '';

		$categories = [];
		foreach ( (array) ( $raw['categories'] ?? [] ) as $category ) {
			$name = is_array( $category ) ? self::str( $category, [ 'title', 'name' ] ) : ( is_scalar( $category ) ? (string) $category : '' );
			if ( '' !== $name ) {
				$categories[] = (object) [
					'slug' => sanitize_title( $name ),
					'name' => $name,
				];
			}
		}

		$speakers     = [];
		$raw_speakers = [];
		foreach ( (array) ( $raw['speakers'] ?? [] ) as $speaker ) {
			if ( ! is_array( $speaker ) ) {
				continue;
			}
			if ( isset( $speaker['is_active'] ) && false === $speaker['is_active'] ) {
				continue; // Hidden speaker (spec).
			}
			$raw_speakers[] = $speaker;
			$entry          = $this->map_speaker( $speaker, $event_url );
			if ( '' !== $entry['name'] ) {
				$speakers[] = [
					'id'   => (int) $entry['id'],
					'name' => (string) $entry['name'],
					'url'  => (string) $entry['url'],
				];
			}
		}

		return [
			'id'            => (int) $hs_id,
			'hs_id'         => $hs_id,
			'title'         => self::str( $raw, [ 'title', 'name' ] ),
			'permalink'     => Utm::tag( $talk_url ) ?: $event_url,
			'description'   => self::str( $raw, [ 'description' ] ),
			'starts_at'     => self::datetime( $raw, [ 'starts_at', 'date' ] ),
			'ends_at'       => self::datetime( $raw, [ 'ends_at' ] ),
			'start_ts'      => (int) strtotime( self::datetime( $raw, [ 'starts_at', 'date' ] ) ),
			'talk_url'      => Utm::tag( $talk_url ),
			'replay_url'    => self::url_of( $raw['replay_url'] ?? '' ),
			'speakers'      => $speakers,
			'categories'    => $categories,
			'raw_speakers'  => $raw_speakers,
			'event_hs_id'   => (string) ( $event['hs_id'] ?? self::id_of( $raw, [ 'event', 'event_id' ] ) ),
			'event_post_id' => 0,
			'timezone'      => (string) ( $event['timezone'] ?? '' ),
			'event_url'     => $event_url,
			'raw_event_url' => $raw_event_url,
			'ics_ref'       => (int) $hs_id,
			'published'     => true,
		];
	}

	/**
	 * Map a raw API speaker to the shared speaker data shape. Speaker cards
	 * link to the HeySummit event page (no local speaker pages in Lite).
	 *
	 * @param array<string,mixed> $raw       Raw record.
	 * @param string              $event_url Tagged event URL.
	 * @return array<string,mixed>
	 */
	protected function map_speaker( array $raw, string $event_url ): array {
		$name = self::str( $raw, [ 'name' ] );
		if ( '' === $name ) {
			$name = trim( self::str( $raw, [ 'first_name' ] ) . ' ' . self::str( $raw, [ 'last_name' ] ) );
		}

		return [
			'id'        => (int) self::id_of( $raw, [ 'id' ] ),
			'name'      => $name,
			'url'       => $event_url,
			'headline'  => self::str( $raw, [ 'headline', 'company_title', 'expert_creds', 'title' ] ),
			'company'   => self::str( $raw, [ 'company' ] ),
			'photo_id'  => 0,
			'photo_url' => self::url_str( $raw, [ 'headshot', 'avatar', 'photo_url' ] ),
		];
	}

	/**
	 * Build a client for a connection ID.
	 *
	 * @param string $conn_id Connection ID.
	 */
	protected function client( string $conn_id ): ?HeySummitClient {
		$connection = Options::connection( $conn_id );

		if ( null === $connection || '' === (string) ( $connection['api_key'] ?? '' ) ) {
			return null;
		}

		return HeySummitClient::for_connection( $connection );
	}

	/**
	 * Apply limit/offset attributes.
	 *
	 * @param array<int,array<string,mixed>> $items Items.
	 * @param array<string,mixed>            $atts  limit, offset.
	 * @return array<int,array<string,mixed>>
	 */
	protected function limit( array $items, array $atts ): array {
		$offset = max( 0, (int) ( $atts['offset'] ?? 0 ) );
		$limit  = (int) ( $atts['limit'] ?? 0 );

		if ( $offset > 0 || $limit > 0 ) {
			return array_slice( $items, $offset, $limit > 0 ? $limit : null );
		}

		return $items;
	}
}
