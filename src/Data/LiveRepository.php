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
			// "No sessions" and "the sessions request failed" need different
			// fixes — a timeout is not an empty summit. The cache records
			// the failing key, so a talks failure is distinguishable here.
			$status = LiveCache::status();

			// Not gated on degraded(): a success and a failure in the same
			// second (events then talks, one page view) tie on timestamps.
			if ( '' !== $status['last_error'] && str_contains( $status['last_error'], '[talks|' ) ) {
				return $this->with_harvest(
					sprintf(
					/* translators: %s: the recorded fetch error. */
						__( 'The sessions request to HeySummit failed rather than returning empty: %s. It is retried on every admin view of this page (with a longer timeout) and on front-end views; if timeouts persist, HeySummit is responding slowly and the eex_live_timeout filter can extend the wait.', 'emailexpert-events' ),
						$status['last_error']
					)
				);
			}

			return $this->with_harvest(
				sprintf(
				/* translators: %s: comma-separated event IDs. */
					__( 'HeySummit returned no sessions for event(s) %s (tried the nested route and both ?event= and ?event_id= filters). Confirm the event has published talks.', 'emailexpert-events' ),
					implode( ', ', array_map( static fn( array $event ): string => (string) $event['hs_id'], $events ) )
				)
			);
		}

		if ( 0 === $next ) {
			if ( $undated === $total ) {
				return $this->with_harvest(
					sprintf(
					/* translators: %d: total sessions found. */
						__( '%d session(s) were found, but none of them has a date set on HeySummit, so none can be shown as upcoming. Give the sessions a date and time on HeySummit, then flush the live cache.', 'emailexpert-events' ),
						$total
					)
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

			return $this->with_harvest(
				sprintf(
				/* translators: 1: total sessions found, 2: detail about the most recent session. */
					__( '%1$d session(s) were found, but none start in the future (%2$s) — Lite is forward-looking and shows upcoming sessions only.', 'emailexpert-events' ),
					$total,
					$detail
				)
			);
		}

		return '';
	}

	/**
	 * The recorded page harvest for one configured "connection|event" key.
	 *
	 * @param string $key Configured key.
	 * @return array<string,mixed> count/pages/read/failed/style, or empty.
	 */
	public static function harvest_meta( string $key ): array {
		$meta = get_transient( 'eex_harvest_' . md5( $key ) );

		return is_array( $meta ) ? $meta : [];
	}

	/**
	 * A one-line account of the last talk harvest per configured event:
	 * what HeySummit reported, which pages were read, which failed and why.
	 * Empty until a harvest has run.
	 */
	protected function harvest_summary(): string {
		$lines = [];

		foreach ( $this->configured_keys() as $key ) {
			$meta = self::harvest_meta( $key );

			if ( empty( $meta ) ) {
				continue;
			}

			[ , $event_id ] = array_pad( explode( '|', $key, 2 ), 2, '' );

			$line = sprintf(
				/* translators: 1: event ID, 2: sessions reported by the API, 3: page count, 4: pages read. */
				__( 'Event %1$s: HeySummit reports %2$d session(s) across %3$d page(s); pages read: %4$s', 'emailexpert-events' ),
				$event_id,
				(int) ( $meta['count'] ?? 0 ),
				(int) ( $meta['pages'] ?? 1 ),
				implode( ', ', array_map( 'strval', (array) ( $meta['read'] ?? [] ) ) )
			);

			if ( ! empty( $meta['failed'] ) ) {
				$failures = [];
				foreach ( (array) $meta['failed'] as $page => $why ) {
					$failures[] = sprintf( '%d (%s)', (int) $page, (string) $why );
				}

				$line .= sprintf(
					/* translators: %s: failed pages with reasons. */
					__( '; pages that failed: %s', 'emailexpert-events' ),
					implode( ', ', $failures )
				);
			}

			$lines[] = $line . '.';
		}

		return implode( ' ', $lines );
	}

	/**
	 * Append the harvest account to a session-related diagnosis.
	 *
	 * @param string $verdict The diagnosis sentence.
	 */
	protected function with_harvest( string $verdict ): string {
		$summary = trim( $this->harvest_summary() . ' ' . $this->event_identity() );

		return '' === $summary ? $verdict : $verdict . ' ' . $summary;
	}

	/**
	 * HeySummit's own record of each configured event's session range, plus
	 * the other events on the same connection — because "no upcoming
	 * sessions" is very often "the upcoming sessions belong to a different
	 * summit on this account". Uses only the already-cached events
	 * collection; no extra requests.
	 */
	protected function event_identity(): string {
		$lines      = [];
		$configured = [];

		foreach ( $this->configured_events() as $event ) {
			$configured[ (string) $event['connection'] ][ (string) $event['hs_id'] ] = true;

			$range = '' !== (string) $event['last_talk_at']
				? sprintf(
					/* translators: 1: first session date, 2: last session date. */
					__( 'sessions from %1$s to %2$s', 'emailexpert-events' ),
					substr( (string) $event['first_talk_at'], 0, 10 ) ?: '?',
					substr( (string) $event['last_talk_at'], 0, 10 )
				)
				: __( 'no session dates on record', 'emailexpert-events' );

			$lines[] = sprintf(
				/* translators: 1: event ID, 2: event title, 3: session date range, 4: flags. */
				__( 'HeySummit\'s own record for event %1$s (\'%2$s\') says: %3$s%4$s.', 'emailexpert-events' ),
				(string) $event['hs_id'],
				(string) $event['title'],
				$range,
				( ! empty( $event['evergreen'] ) ? __( ', evergreen', 'emailexpert-events' ) : '' ) . ( ! empty( $event['open'] ) ? __( ', open for registrations', 'emailexpert-events' ) : '' )
			);
		}

		// Unconfigured siblings, from the cached page-1 collection only.
		$others = [];

		foreach ( array_keys( $configured ) as $conn_id ) {
			$client = $this->client( $conn_id );
			if ( null === $client ) {
				continue;
			}

			foreach ( array_slice( (array) $this->raw_events( $conn_id, $client ), 0, 20 ) as $raw ) {
				if ( ! is_array( $raw ) ) {
					continue;
				}

				$id = self::id_of( $raw, [ 'id' ] );

				if ( '' === $id || isset( $configured[ $conn_id ][ $id ] ) ) {
					continue;
				}

				$last     = self::datetime( $raw, [ 'last_talk_at', 'ends_at' ] );
				$others[] = sprintf(
					'%s \'%s\'%s',
					$id,
					self::str( $raw, [ 'title', 'name' ] ),
					'' !== $last ? sprintf(
						/* translators: %s: last session date. */
						__( ' (sessions to %s)', 'emailexpert-events' ),
						substr( $last, 0, 10 )
					) : ''
				);
			}
		}

		if ( ! empty( $others ) ) {
			$lines[] = sprintf(
				/* translators: %s: other event IDs and titles. */
				__( 'Other events on this connection: %s. If your upcoming sessions belong to one of these, add it under Live display → Choose events.', 'emailexpert-events' ),
				implode( '; ', array_slice( $others, 0, 8 ) )
			);
		}

		return implode( ' ', $lines );
	}

	/**
	 * Client options for live fetches.
	 *
	 * Front-end renders stay impatient (a slow API must never hang a
	 * visitor's page; last-good data covers the gap), but admin screens —
	 * the dashboard widget and the settings Live status row — are allowed
	 * to wait and retry, because a busy account's talks endpoint can take
	 * several seconds and an admin page view is the natural moment to warm
	 * the cache for everyone else.
	 *
	 * @return array<string,int>
	 */
	protected static function request_options(): array {
		$admin = function_exists( 'is_admin' ) && is_admin();

		/**
		 * Filter the per-request timeout for live fetches, in seconds.
		 *
		 * @param int  $timeout Seconds (default 5 on the front end, 15 in admin).
		 * @param bool $admin   Whether this is an admin-screen fetch.
		 */
		$timeout = (int) apply_filters( 'eex_live_timeout', $admin ? 15 : 5, $admin );

		return [
			'timeout' => max( 1, $timeout ),
			'retries' => $admin ? 1 : 0,
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

			$collection = $this->raw_events( (string) $conn_id, $client );

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
	 * The raw events collection for one connection, via the cache.
	 *
	 * @param string          $conn_id Connection ID.
	 * @param HeySummitClient $client  Keyed client.
	 * @return array<int,array<string,mixed>>|mixed Cached collection, or
	 *                                              null/WP_Error on failure.
	 */
	protected function raw_events( string $conn_id, HeySummitClient $client ) {
		return LiveCache::remember(
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

				$options = self::request_options();
				$now     = time();

				/**
				 * Filter the total page fetches allowed per talk harvest.
				 *
				 * @param int $max_pages Maximum page fetches (default 12).
				 */
				$budget = max( 2, (int) apply_filters( 'eex_live_max_pages', 12 ) );

				$has_future = static function ( array $rows ) use ( $now ): bool {
					foreach ( $rows as $row ) {
						foreach ( [ 'starts_at', 'date' ] as $field ) {
							if ( isset( $row[ $field ] ) && is_string( $row[ $field ] ) && '' !== $row[ $field ] && (int) strtotime( $row[ $field ] ) >= $now ) {
								return true;
							}
						}
					}

					return false;
				};

				// A wall-clock stop for the whole harvest: a slow API must
				// degrade to partial data, never hang the page.
				$deadline = microtime( true ) + max(
					2.0,
					/**
					 * Filter the total seconds a talk harvest may spend.
					 *
					 * @param int $seconds Default 8 on the front end, 25 in admin.
					 */
					(float) apply_filters( 'eex_live_deadline', ( function_exists( 'is_admin' ) && is_admin() ) ? 25 : 8 )
				);

				$meta = [];

				// The collection is paginated (10 per page) with no date
				// filter, and real accounts order it oldest-first — a summit
				// with 500 past talks keeps its upcoming sessions on the LAST
				// pages, far beyond any forward page walk. Harvest from both
				// ends instead: page 1 (which also covers newest-first
				// accounts), then jump to the last page via the reported
				// count and walk towards the middle until a page contains
				// nothing upcoming. Cost stays a handful of requests no
				// matter how much history the summit has. Every page read or
				// failed is recorded so the Live status row can show the
				// harvest instead of leaving the operator guessing.
				$harvest = static function ( string $path, array $args ) use ( $client, $options, $normalise, $has_future, $budget, $deadline, &$meta ) {
					$meta = [
						'count'  => 0,
						'pages'  => 1,
						'read'   => [],
						'failed' => [],
					];

					$first = $client->get( $path, $args, $options );

					if ( is_wp_error( $first ) ) {
						return $first;
					}

					$rows           = $normalise( $first );
					$meta['read'][] = 1;

					$size  = max( 1, count( $rows ) );
					$count = (int) ( $first['count'] ?? 0 );
					$next  = isset( $first['next'] ) && is_string( $first['next'] ) ? $first['next'] : '';
					$last  = (int) ceil( max( 0, $count ) / $size );

					$meta['count'] = $count > 0 ? $count : count( $rows );
					$meta['pages'] = max( 1, $last );

					// The count is trusted even when the next link is absent
					// (some routes omit it); the next link is trusted even
					// when the count is absent.
					if ( $last <= 1 && '' === $next ) {
						return $rows; // Single page: nothing more to walk.
					}

					if ( $last <= 1 ) {
						$last = $budget; // A next link but no count: walk forward blind.
					}

					$fetched   = 1;
					$collected = $rows;
					$seen      = [ 1 => true ];

					$read_page = static function ( int $page ) use ( $client, $path, $args, $options, $normalise, &$meta, &$fetched ) {
						$response = $client->get( $path, [ 'page' => $page ] + $args, $options );
						++$fetched;

						if ( is_wp_error( $response ) ) {
							$meta['failed'][ $page ] = $response->get_error_message();

							return null;
						}

						$meta['read'][] = $page;

						return $normalise( $response );
					};

					// Backwards from the end (oldest-first accounts). A page
					// that fails is recorded and skipped, not a wall — deep
					// offsets are the slowest queries on big accounts, and
					// one timeout must not hide every upcoming session.
					for ( $page = $last; $page >= 2 && $fetched < $budget; $page-- ) {
						if ( microtime( true ) >= $deadline ) {
							break;
						}

						if ( isset( $seen[ $page ] ) ) {
							continue;
						}

						$page_rows = $read_page( $page );

						if ( null === $page_rows ) {
							continue;
						}

						$collected     = array_merge( $collected, $page_rows );
						$seen[ $page ] = true;

						if ( ! $has_future( $page_rows ) ) {
							break; // Everything from here back is older still.
						}
					}

					// Forwards from page 1 (newest-first accounts).
					if ( $has_future( $rows ) ) {
						for ( $page = 2; $page <= $last && $fetched < $budget; $page++ ) {
							if ( microtime( true ) >= $deadline || isset( $seen[ $page ] ) ) {
								break; // Out of time, or met the backwards walk.
							}

							$page_rows = $read_page( $page );

							if ( null === $page_rows ) {
								break;
							}

							$collected     = array_merge( $collected, $page_rows );
							$seen[ $page ] = true;

							if ( ! $has_future( $page_rows ) ) {
								break;
							}
						}
					}

					// The two walks can overlap on unsorted accounts.
					$unique = [];
					foreach ( $collected as $row ) {
						$rid = isset( $row['id'] ) && is_scalar( $row['id'] ) ? (string) $row['id'] : '';

						if ( '' === $rid ) {
							$unique[] = $row;
							continue;
						}

						$unique[ 'id-' . $rid ] = $row;
					}

					return array_values( $unique );
				};

				foreach ( \Emailexpert\Events\Api\PathStyles::ordered( $conn_id, 'talks', array_keys( $requests ) ) as $style ) {
					[ $path, $args ] = $requests[ $style ];

					$response = $harvest( $path, $args );

					if ( is_wp_error( $response ) ) {
						$first_error = $first_error ?? $response;
						continue;
					}

					$results = $response;

					if ( ! empty( $results ) && $usable( $results ) ) {
						\Emailexpert\Events\Api\PathStyles::remember( $conn_id, 'talks', $style );

						$meta['style'] = $style;
						set_transient( 'eex_harvest_' . md5( $conn_id . '|' . $event_hs_id ), $meta, DAY_IN_SECONDS );

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
