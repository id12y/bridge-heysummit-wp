<?php
/**
 * Sync engine.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

use Emailexpert\Events\Admin\Notices;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\CategoryMapper;
use Emailexpert\Events\Mappers\EventMapper;
use Emailexpert\Events\Mappers\SpeakerMapper;
use Emailexpert\Events\Mappers\TalkMapper;
use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the per-event sync flow: event detail, talks (with category filter),
 * speakers (deduplicated), categories, orphan drafting. Honours resource
 * toggles, time budget with continuation, and failure tracking.
 */
class SyncEngine {

	/**
	 * Seconds allowed per inline run before a continuation is queued.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Wall-clock start of this run.
	 *
	 * @var float
	 */
	private float $started_at = 0.0;

	/**
	 * Keys of every enabled synced event, "connection|event".
	 *
	 * @return string[]
	 */
	public static function enabled_event_keys(): array {
		$keys = [];

		foreach ( Options::synced_events() as $key => $config ) {
			$config = Options::normalise_event_config( (array) $config );
			if ( ! empty( $config['enabled'] ) ) {
				$keys[] = (string) $key;
			}
		}

		return $keys;
	}

	/**
	 * Run a set of event keys with a time budget; queue a continuation when
	 * the budget is exhausted.
	 *
	 * @param string[] $keys  Event keys.
	 * @param bool     $force Ignore sync hashes.
	 */
	public function run_keys( array $keys, bool $force = false ): void {
		$this->started_at = microtime( true );

		/**
		 * Filter the per-run time budget in seconds.
		 *
		 * @param int $budget Seconds.
		 */
		$budget = (int) apply_filters( 'eex_sync_time_budget', self::TIME_BUDGET );

		foreach ( $keys as $index => $key ) {
			if ( $index > 0 && ( microtime( true ) - $this->started_at ) > $budget ) {
				$remaining = array_slice( $keys, $index );
				wp_schedule_single_event( time() + 30, 'eex_sync_continue', [ $remaining, $force ? 1 : 0 ] );
				spawn_cron();
				Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Time budget reached; %d event(s) continue in a queued run.', count( $remaining ) ) );

				return;
			}

			[ $connection_id, $event_id ] = array_pad( explode( '|', $key, 2 ), 2, '' );
			$this->sync_event( $connection_id, $event_id, $force );
		}

		Cache::flush();
	}

	/**
	 * Sync one HeySummit event.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @param bool   $force         Ignore sync hashes.
	 * @return bool Success.
	 */
	public function sync_event( string $connection_id, string $event_id, bool $force = false ): bool {
		$connection = Options::connection( $connection_id );
		$config     = Options::event_config( $connection_id, $event_id );

		if ( null === $connection || null === $config || empty( $config['enabled'] ) ) {
			Logger::warning( Logger::CONTEXT_SYNC, sprintf( 'Sync skipped for %s|%s: not configured.', $connection_id, $event_id ) );

			return false;
		}

		$client = HeySummitClient::for_connection( $connection );
		$stats  = [
			'created'      => 0,
			'updated'      => 0,
			'skipped_hash' => 0,
			'skipped_mode' => 0,
			'orphaned'     => 0,
		];

		// 1. Event detail.
		$raw_event = $client->get( 'events/' . rawurlencode( $event_id ) . '/' );
		if ( is_wp_error( $raw_event ) ) {
			return $this->fail( $connection_id, $event_id, $raw_event->get_error_code(), $raw_event->get_error_message() );
		}

		$mapped_event = EventMapper::map( $raw_event );
		if ( null === $mapped_event ) {
			return $this->fail( $connection_id, $event_id, 'eex_map', 'Event record has no usable ID.' );
		}

		$context = [
			'connection_id'   => $connection_id,
			'source_event_id' => $event_id,
			'import_status'   => (string) $config['import_status'],
			'force'           => $force,
		];

		$event_result  = Upserter::upsert( PostTypes::EVENT, $mapped_event, $context + [ 'raw' => $raw_event ] );
		$event_post_id = $event_result['id'];
		++$stats[ $event_result['action'] ];

		// 2. Categories.
		$category_map = [];
		if ( ! empty( $config['categories'] ) ) {
			$raw_categories = $client->get_all( 'categories/', [ 'event' => $event_id ] );
			if ( is_wp_error( $raw_categories ) ) {
				return $this->fail( $connection_id, $event_id, $raw_categories->get_error_code(), $raw_categories->get_error_message() );
			}
			$category_map = $this->sync_categories( $raw_categories );
		}

		// 3. Talks.
		$speaker_map = []; // hs speaker id => wp post id, filled by the speaker pass.
		if ( ! empty( $config['talks'] ) ) {
			$raw_talks = $this->fetch_talks( $client, $event_id );
			if ( is_wp_error( $raw_talks ) ) {
				return $this->fail( $connection_id, $event_id, $raw_talks->get_error_code(), $raw_talks->get_error_message() );
			}

			$mapped_talks = [];
			foreach ( $raw_talks as $raw_talk ) {
				if ( ! is_array( $raw_talk ) ) {
					continue;
				}
				$mapped = TalkMapper::map( $raw_talk );
				if ( null !== $mapped ) {
					$mapped['raw']  = $raw_talk;
					$mapped_talks[] = $mapped;
				}
			}

			// Category filter and time scope together decide existence:
			// out-of-scope talks are not created and orphan-draft below.
			$mapped_talks = ScopeFilter::apply( $mapped_talks, $config );

			$seen_talk_ids = [];
			$talk_posts    = [];

			foreach ( $mapped_talks as $mapped ) {
				$raw = $mapped['raw'];
				unset( $mapped['raw'] );

				$result = Upserter::upsert( PostTypes::TALK, $mapped, $context + [ 'raw' => $raw ] );
				++$stats[ $result['action'] ];
				$seen_talk_ids[] = (string) $mapped['hs_id'];

				if ( $result['id'] > 0 && 'skipped_mode' !== $result['action'] ) {
					$talk_posts[] = [
						'post_id' => $result['id'],
						'mapped'  => $mapped,
						'written' => in_array( $result['action'], [ 'created', 'updated' ], true ),
					];
				}
			}

			// Terms: categories from the mapped record; series inherited from the event.
			$series = $event_post_id > 0 ? wp_get_object_terms( [ $event_post_id ], Taxonomies::SERIES, [ 'fields' => 'slugs' ] ) : [];
			foreach ( $talk_posts as $talk ) {
				if ( ! $talk['written'] ) {
					continue;
				}
				$slugs = [];
				foreach ( (array) $talk['mapped']['category_hs_ids'] as $cat_hs_id ) {
					if ( isset( $category_map[ $cat_hs_id ] ) ) {
						$slugs[] = $category_map[ $cat_hs_id ];
					}
				}
				wp_set_object_terms( $talk['post_id'], $slugs, Taxonomies::CATEGORY );
				if ( ! empty( $series ) && ! is_wp_error( $series ) ) {
					wp_set_object_terms( $talk['post_id'], $series, Taxonomies::SERIES );
				}
			}

			// 5. Orphans among talks (includes talks removed by the category filter).
			$stats['orphaned'] += $this->draft_orphans( PostTypes::TALK, $connection_id, $event_id, $seen_talk_ids );

			// 4. Speakers.
			if ( ! empty( $config['speakers'] ) ) {
				$speaker_result = $this->sync_speakers( $client, $event_id, $config, $context, $stats );
				if ( is_wp_error( $speaker_result ) ) {
					return $this->fail( $connection_id, $event_id, $speaker_result->get_error_code(), $speaker_result->get_error_message() );
				}
				$speaker_map = $speaker_result;
			}

			// Resolve talk -> speaker WP post IDs. Only when the speaker pass
			// ran: with speakers toggled off, existing relationships must be
			// left untouched.
			if ( ! empty( $config['speakers'] ) ) {
				foreach ( $talk_posts as $talk ) {
					$wp_ids = [];
					foreach ( (array) $talk['mapped']['speaker_hs_ids'] as $speaker_hs_id ) {
						if ( isset( $speaker_map[ $speaker_hs_id ] ) ) {
							$wp_ids[] = (int) $speaker_map[ $speaker_hs_id ];
						}
					}
					if ( ! empty( $wp_ids ) || $talk['written'] ) {
						update_post_meta( $talk['post_id'], '_eex_speaker_ids', $wp_ids );
					}
				}
			}
		} elseif ( ! empty( $config['speakers'] ) ) {
			$speaker_result = $this->sync_speakers( $client, $event_id, $config, $context, $stats );
			if ( is_wp_error( $speaker_result ) ) {
				return $this->fail( $connection_id, $event_id, $speaker_result->get_error_code(), $speaker_result->get_error_message() );
			}
		}

		$this->succeed( $connection_id, $event_id, $stats );

		return true;
	}

	/**
	 * Fetch the talks for one event, negotiating the filter parameter
	 * (docs/decisions.md D4).
	 *
	 * @param HeySummitClient $client   Client.
	 * @param string          $event_id Event ID.
	 * @return array<int,array<string,mixed>>|\WP_Error
	 */
	protected function fetch_talks( HeySummitClient $client, string $event_id ) {
		$talks = $client->get_all( 'talks/', [ 'event' => $event_id ] );

		if ( is_wp_error( $talks ) ) {
			return $talks;
		}

		if ( $this->has_foreign_talks( $talks, $event_id ) ) {
			$retry = $client->get_all( 'talks/', [ 'event_id' => $event_id ] );

			if ( ! is_wp_error( $retry ) && ! $this->has_foreign_talks( $retry, $event_id ) ) {
				return $retry;
			}

			// Client-side filtering as the last resort.
			Logger::warning(
				Logger::CONTEXT_SYNC,
				'Talk collection filter parameter not honoured by the API; filtering client-side. Verify via the discovery panel.',
				[ 'event' => $event_id ]
			);

			return array_values(
				array_filter(
					$talks,
					static function ( $talk ) use ( $event_id ): bool {
						if ( ! is_array( $talk ) ) {
							return false;
						}
						$mapped = TalkMapper::map( $talk );

						return null !== $mapped && ( '' === $mapped['event_hs_id'] || (string) $mapped['event_hs_id'] === $event_id );
					}
				)
			);
		}

		return $talks;
	}

	/**
	 * Whether a talk collection contains records belonging to another event.
	 *
	 * @param array<int,mixed> $talks    Raw talks.
	 * @param string           $event_id Expected event ID.
	 */
	private function has_foreign_talks( array $talks, string $event_id ): bool {
		foreach ( $talks as $talk ) {
			if ( ! is_array( $talk ) ) {
				continue;
			}
			$mapped = TalkMapper::map( $talk );
			if ( null !== $mapped && '' !== $mapped['event_hs_id'] && (string) $mapped['event_hs_id'] !== $event_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Sync HeySummit categories into eex_category terms.
	 *
	 * @param array<int,mixed> $raw_categories Raw records.
	 * @return array<string,string> Map hs_id => term slug.
	 */
	protected function sync_categories( array $raw_categories ): array {
		$map = [];

		foreach ( $raw_categories as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$mapped = CategoryMapper::map( $raw );
			if ( null === $mapped || '' === $mapped['title'] ) {
				continue;
			}

			$term = $this->find_category_term( $mapped['hs_id'] );

			if ( null === $term ) {
				$created = wp_insert_term( $mapped['title'], Taxonomies::CATEGORY );
				if ( is_wp_error( $created ) ) {
					$existing = get_term_by( 'name', $mapped['title'], Taxonomies::CATEGORY );
					if ( false === $existing ) {
						continue;
					}
					$term_id = (int) $existing->term_id;
					$slug    = (string) $existing->slug;
				} else {
					$term_id = (int) $created['term_id'];
					$found   = get_term_by( 'id', $term_id, Taxonomies::CATEGORY );
					$slug    = $found ? (string) $found->slug : sanitize_title( $mapped['title'] );
				}
				update_term_meta( $term_id, '_eex_hs_id', $mapped['hs_id'] );
			} else {
				$term_id = (int) $term->term_id;
				$slug    = (string) $term->slug;
				if ( $term->name !== $mapped['title'] ) {
					wp_update_term( $term_id, Taxonomies::CATEGORY, [ 'name' => $mapped['title'] ] );
				}
			}

			$map[ $mapped['hs_id'] ] = $slug;
		}

		return $map;
	}

	/**
	 * Find a category term by its HeySummit ID term meta.
	 *
	 * @param string $hs_id HeySummit category ID.
	 * @return object|null Term-like object.
	 */
	private function find_category_term( string $hs_id ): ?object {
		$terms = get_terms(
			[
				'taxonomy'   => Taxonomies::CATEGORY,
				'hide_empty' => false,
			]
		);

		if ( ! is_array( $terms ) ) {
			return null;
		}

		foreach ( $terms as $term ) {
			if ( (string) get_term_meta( (int) $term->term_id, '_eex_hs_id', true ) === $hs_id ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Sync speakers for one event, deduplicating across events.
	 *
	 * @param HeySummitClient      $client   Client.
	 * @param string               $event_id Event ID.
	 * @param array<string,mixed>  $config   Event config.
	 * @param array<string,mixed>  $context  Upsert context.
	 * @param array<string,int>    $stats    Stats accumulator (by ref).
	 * @return array<string,int>|\WP_Error Map hs speaker id => wp post id.
	 */
	protected function sync_speakers( HeySummitClient $client, string $event_id, array $config, array $context, array &$stats ) {
		$raw_speakers = $client->get_all( 'speakers/', [ 'event' => $event_id ] );

		if ( is_wp_error( $raw_speakers ) ) {
			return $raw_speakers;
		}

		$map      = [];
		$seen_ids = [];

		foreach ( $raw_speakers as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$mapped = SpeakerMapper::map( $raw );
			if ( null === $mapped ) {
				continue;
			}

			$speaker_context = $context + [
				'raw'          => $raw,
				// A shared human keeps one post whichever event synced them
				// last; identity fields must not thrash the hash.
				'hash_exclude' => [ 'hs_id', 'event_hs_id', 'email' ],
			];

			$existing = $this->find_speaker( $mapped );
			if ( $existing > 0 && (string) get_post_meta( $existing, '_eex_heysummit_id', true ) !== (string) $mapped['hs_id'] ) {
				$speaker_context['existing_post_id']  = $existing;
				$speaker_context['preserve_identity'] = true;
			}

			$result = Upserter::upsert( PostTypes::SPEAKER, $mapped, $speaker_context );
			++$stats[ $result['action'] ];

			if ( $result['id'] > 0 ) {
				$map[ (string) $mapped['hs_id'] ] = $result['id'];
				$seen_ids[]                       = (string) get_post_meta( $result['id'], '_eex_heysummit_id', true );

				if ( ! empty( $speaker_context['preserve_identity'] ) ) {
					$alt_ids = (array) get_post_meta( $result['id'], '_eex_hs_alt_ids', true );
					if ( ! in_array( (string) $mapped['hs_id'], array_map( 'strval', $alt_ids ), true ) ) {
						$alt_ids[] = (string) $mapped['hs_id'];
						update_post_meta( $result['id'], '_eex_hs_alt_ids', array_values( $alt_ids ) );
					}
				}

				if ( ! empty( $config['photos'] ) && '' !== (string) $mapped['photo_url'] && 'skipped_mode' !== $result['action'] ) {
					MediaSideloader::sideload_speaker_photo( $result['id'], (string) $mapped['photo_url'], (string) $mapped['name'] );
				}
			}
		}

		$stats['orphaned'] += $this->draft_orphans( PostTypes::SPEAKER, (string) $context['connection_id'], $event_id, $seen_ids );

		return $map;
	}

	/**
	 * Find an existing speaker post for a mapped record: HeySummit ID first
	 * (including alternate IDs), then email hash, then exact name + company.
	 *
	 * @param array<string,mixed> $mapped Mapped speaker.
	 * @return int Post ID, 0 when absent.
	 */
	protected function find_speaker( array $mapped ): int {
		$post_id = Upserter::find_by_hs_id( PostTypes::SPEAKER, (string) $mapped['hs_id'] );
		if ( $post_id > 0 ) {
			return $post_id;
		}

		// Alternate IDs collected by earlier cross-event dedup.
		$with_alt_ids = get_posts(
			[
				'post_type'      => PostTypes::SPEAKER,
				'post_status'    => 'any',
				'meta_key'       => '_eex_hs_alt_ids', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- rare cross-event dedup path.
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $with_alt_ids as $candidate ) {
			$alt_ids = array_map( 'strval', (array) get_post_meta( (int) $candidate, '_eex_hs_alt_ids', true ) );
			if ( in_array( (string) $mapped['hs_id'], $alt_ids, true ) ) {
				return (int) $candidate;
			}
		}

		$email = strtolower( (string) ( $mapped['email'] ?? '' ) );
		if ( '' !== $email ) {
			$matches = get_posts(
				[
					'post_type'      => PostTypes::SPEAKER,
					'post_status'    => 'any',
					'meta_key'       => '_eex_email_hash', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed dedup key, bounded to 1 result.
					'meta_value'     => hash( 'sha256', $email ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			if ( ! empty( $matches ) ) {
				return (int) $matches[0];
			}
		}

		$name    = (string) ( $mapped['name'] ?? '' );
		$company = (string) ( $mapped['company'] ?? '' );
		if ( '' !== $name && '' !== $company ) {
			$matches = get_posts(
				[
					'post_type'      => PostTypes::SPEAKER,
					'post_status'    => 'any',
					'meta_key'       => '_eex_name', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- exact-match dedup fallback.
					'meta_value'     => $name, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 20,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);
			foreach ( $matches as $candidate ) {
				if ( (string) get_post_meta( (int) $candidate, '_eex_company', true ) === $company ) {
					return (int) $candidate;
				}
			}
		}

		return 0;
	}

	/**
	 * Draft posts whose HeySummit record no longer appears. Never deletes.
	 * Detached/excluded posts are left alone; speakers still referenced by a
	 * live talk are spared (they may belong to another event now).
	 *
	 * @param string   $post_type     Post type.
	 * @param string   $connection_id Connection ID.
	 * @param string   $event_id      Source event ID.
	 * @param string[] $seen_hs_ids   IDs seen this run.
	 * @return int Number of posts drafted.
	 */
	protected function draft_orphans( string $post_type, string $connection_id, string $event_id, array $seen_hs_ids ): int {
		$posts = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => '_eex_source_event_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- orphan sweep, bounded per event.
				'meta_value'     => $event_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		$count = 0;

		foreach ( $posts as $post_id ) {
			$post_id = (int) $post_id;

			if ( (string) get_post_meta( $post_id, '_eex_connection_id', true ) !== $connection_id ) {
				continue;
			}

			$hs_id = (string) get_post_meta( $post_id, '_eex_heysummit_id', true );
			if ( in_array( $hs_id, $seen_hs_ids, true ) ) {
				continue;
			}

			$mode = (string) get_post_meta( $post_id, '_eex_sync_mode', true );
			if ( in_array( $mode, [ Upserter::MODE_DETACHED, Upserter::MODE_EXCLUDED ], true ) ) {
				continue;
			}

			if ( get_post_meta( $post_id, '_eex_orphaned', true ) ) {
				continue; // Already drafted on a previous run.
			}

			if ( PostTypes::SPEAKER === $post_type && $this->speaker_in_use( $post_id ) ) {
				continue;
			}

			wp_update_post(
				[
					'ID'          => $post_id,
					'post_status' => 'draft',
				]
			);
			update_post_meta( $post_id, '_eex_orphaned', 1 );
			++$count;
		}

		return $count;
	}

	/**
	 * Whether any non-draft talk still references a speaker post.
	 *
	 * @param int $speaker_post_id Speaker post ID.
	 */
	private function speaker_in_use( int $speaker_post_id ): bool {
		$talks = get_posts(
			[
				'post_type'      => PostTypes::TALK,
				'post_status'    => [ 'publish', 'pending' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		foreach ( $talks as $talk_id ) {
			$speaker_ids = array_map( 'intval', (array) get_post_meta( (int) $talk_id, '_eex_speaker_ids', true ) );
			if ( in_array( $speaker_post_id, $speaker_ids, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Record a successful event sync.
	 *
	 * @param string             $connection_id Connection ID.
	 * @param string             $event_id      Event ID.
	 * @param array<string,int>  $stats         Run statistics.
	 */
	private function succeed( string $connection_id, string $event_id, array $stats ): void {
		$last                                     = (array) get_option( 'eex_last_sync', [] );
		$last[ $connection_id . '|' . $event_id ] = gmdate( 'Y-m-d\TH:i:s\Z' );
		update_option( 'eex_last_sync', $last, false );

		Health::record_success( $connection_id );

		Logger::info(
			Logger::CONTEXT_SYNC,
			sprintf(
				'Synced event %s: %d created, %d updated, %d unchanged, %d protected, %d orphaned.',
				$event_id,
				$stats['created'],
				$stats['updated'],
				$stats['skipped_hash'],
				$stats['skipped_mode'],
				$stats['orphaned']
			),
			[
				'connection' => $connection_id,
				'event'      => $event_id,
				'stats'      => $stats,
			]
		);
	}

	/**
	 * Record a failed event sync and abort cleanly.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      Event ID.
	 * @param string $code          Error code.
	 * @param string $message       Error message.
	 * @return bool Always false.
	 */
	private function fail( string $connection_id, string $event_id, string $code, string $message ): bool {
		Health::record_failure( $connection_id );

		if ( 'eex_auth' === $code ) {
			Notices::add(
				'auth_' . $connection_id,
				__( 'HeySummit API key invalid or lacks access. Sync is paused for this connection until the key is fixed in Settings → emailexpert Events.', 'emailexpert-events' )
			);
		}

		Logger::error(
			Logger::CONTEXT_SYNC,
			sprintf( 'Sync failed for event %s: %s', $event_id, $message ),
			[
				'connection' => $connection_id,
				'event'      => $event_id,
				'code'       => $code,
			]
		);

		return false;
	}
}
