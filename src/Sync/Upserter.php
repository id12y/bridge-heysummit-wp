<?php
/**
 * Post upsert logic.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Creates or updates one synced post from a mapped record, honouring the
 * ownership rules:
 *
 * - `_eex_sync_mode` detached/excluded posts are never written.
 * - Unchanged `_eex_sync_hash` skips the write entirely.
 * - New posts get the event's configured import status; sync never changes
 *   post status after creation.
 * - Sync owns title and synced meta; post_content and manual meta belong to
 *   editors and are never touched.
 */
final class Upserter {

	public const MODE_SYNCED   = 'synced';
	public const MODE_DETACHED = 'detached';
	public const MODE_EXCLUDED = 'excluded';

	/**
	 * Find a synced post by its HeySummit ID.
	 *
	 * @param string $post_type Post type.
	 * @param string $hs_id     HeySummit ID.
	 * @return int Post ID, 0 when absent.
	 */
	public static function find_by_hs_id( string $post_type, string $hs_id ): int {
		$found = get_posts(
			[
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'meta_key'       => '_eex_heysummit_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed lookup key, bounded to 1 result.
				'meta_value'     => $hs_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return empty( $found ) ? 0 : (int) $found[0];
	}

	/**
	 * Upsert one record.
	 *
	 * @param string              $post_type Post type.
	 * @param array<string,mixed> $mapped    Mapper output (must contain hs_id).
	 * @param array<string,mixed> $context   connection_id, source_event_id,
	 *                                       import_status, raw, force,
	 *                                       existing_post_id (skip the lookup),
	 *                                       preserve_identity (leave identity
	 *                                       meta as stored; cross-event speaker
	 *                                       dedup), hash_exclude (keys removed
	 *                                       from change detection).
	 * @return array{id:int,action:string} Post ID (0 when skipped before
	 *                                     creation) and what happened:
	 *                                     created|updated|skipped_hash|skipped_mode.
	 */
	public static function upsert( string $post_type, array $mapped, array $context = [] ): array {
		$hs_id   = (string) ( $mapped['hs_id'] ?? '' );
		$post_id = isset( $context['existing_post_id'] )
			? (int) $context['existing_post_id']
			: self::find_by_hs_id( $post_type, $hs_id );

		if ( $post_id > 0 ) {
			$mode = (string) get_post_meta( $post_id, '_eex_sync_mode', true );

			if ( in_array( $mode, [ self::MODE_DETACHED, self::MODE_EXCLUDED ], true ) ) {
				return [
					'id'     => $post_id,
					'action' => 'skipped_mode',
				];
			}
		}

		$hash = md5( (string) wp_json_encode( self::hashable( $mapped, (array) ( $context['hash_exclude'] ?? [] ) ) ) );

		if ( $post_id > 0 && empty( $context['force'] ) ) {
			$existing_hash = (string) get_post_meta( $post_id, '_eex_sync_hash', true );

			if ( $existing_hash === $hash ) {
				return [
					'id'     => $post_id,
					'action' => 'skipped_hash',
				];
			}
		}

		$meta = self::meta_for( $post_type, $mapped );

		if ( 0 === $post_id || empty( $context['preserve_identity'] ) ) {
			$meta['_eex_heysummit_id']    = $hs_id;
			$meta['_eex_source_event_id'] = (string) ( $context['source_event_id'] ?? ( $mapped['event_hs_id'] ?? '' ) );
			$meta['_eex_connection_id']   = (string) ( $context['connection_id'] ?? '' );
		}
		$meta['_eex_sync_hash']   = $hash;
		$meta['_eex_last_synced'] = gmdate( 'Y-m-d\TH:i:s\Z' );
		$meta['_eex_orphaned']    = 0;

		if ( isset( $context['raw'] ) ) {
			$meta['_eex_raw'] = (string) wp_json_encode( $context['raw'] );
		}

		$title = (string) ( $mapped['title'] ?? $mapped['name'] ?? '' );

		if ( $post_id > 0 ) {
			// Sync owns the title; everything else via meta. Post status and
			// post_content are never touched on update.
			wp_update_post(
				[
					'ID'         => $post_id,
					'post_title' => $title,
					'meta_input' => $meta,
				]
			);

			return [
				'id'     => $post_id,
				'action' => 'updated',
			];
		}

		$meta['_eex_sync_mode'] = self::MODE_SYNCED;

		$status = (string) ( $context['import_status'] ?? 'publish' );
		if ( ! in_array( $status, [ 'publish', 'pending' ], true ) ) {
			$status = 'publish';
		}

		$post_id = (int) wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_status' => $status,
				'post_title'  => $title,
				'meta_input'  => $meta,
			]
		);

		return [
			'id'     => $post_id,
			'action' => 'created',
		];
	}

	/**
	 * The part of a mapped record that participates in change detection.
	 * Volatile context (raw payload noise) is excluded; the mapped shape is
	 * already normalised, so it is its own hash basis.
	 *
	 * @param array<string,mixed> $mapped  Mapped record.
	 * @param string[]            $exclude Keys removed from the hash basis.
	 * @return array<string,mixed>
	 */
	private static function hashable( array $mapped, array $exclude = [] ): array {
		foreach ( $exclude as $key ) {
			unset( $mapped[ $key ] );
		}
		ksort( $mapped );

		return $mapped;
	}

	/**
	 * Meta keys per post type from a mapped record. Manual editor-owned keys
	 * are deliberately absent.
	 *
	 * @param string              $post_type Post type.
	 * @param array<string,mixed> $mapped    Mapped record.
	 * @return array<string,mixed>
	 */
	private static function meta_for( string $post_type, array $mapped ): array {
		switch ( $post_type ) {
			case PostTypes::EVENT:
				return [
					'_eex_description'               => (string) ( $mapped['description'] ?? '' ),
					'_eex_event_url'                 => (string) ( $mapped['event_url'] ?? '' ),
					'_eex_timezone'                  => (string) ( $mapped['timezone'] ?? '' ),
					'_eex_first_talk_at'             => (string) ( $mapped['first_talk_at'] ?? '' ),
					'_eex_last_talk_at'              => (string) ( $mapped['last_talk_at'] ?? '' ),
					'_eex_is_live'                   => empty( $mapped['is_live'] ) ? 0 : 1,
					'_eex_is_archived'               => empty( $mapped['is_archived'] ) ? 0 : 1,
					'_eex_is_evergreen'              => empty( $mapped['is_evergreen'] ) ? 0 : 1,
					'_eex_is_open_for_registrations' => empty( $mapped['is_open_for_registrations'] ) ? 0 : 1,
				];

			case PostTypes::TALK:
				return [
					'_eex_description'       => (string) ( $mapped['description'] ?? '' ),
					'_eex_starts_at'         => (string) ( $mapped['starts_at'] ?? '' ),
					'_eex_ends_at'           => (string) ( $mapped['ends_at'] ?? '' ),
					'_eex_talk_url'          => (string) ( $mapped['talk_url'] ?? '' ),
					// Manual _eex_replay_url always wins at render time; the
					// synced value lands in its own key (docs/decisions.md D13).
					'_eex_replay_url_synced' => (string) ( $mapped['replay_url'] ?? '' ),
					'_eex_replay_soon'       => empty( $mapped['replay_soon'] ) ? 0 : 1,
					'_eex_talk_venue'        => (string) ( $mapped['venue'] ?? '' ),
					'_eex_inperson'          => empty( $mapped['inperson'] ) ? 0 : 1,
				];

			case PostTypes::SPEAKER:
				return [
					'_eex_description' => (string) ( $mapped['bio'] ?? '' ),
					'_eex_name'        => (string) ( $mapped['name'] ?? '' ),
					'_eex_headline'    => (string) ( $mapped['headline'] ?? '' ),
					'_eex_company'     => (string) ( $mapped['company'] ?? '' ),
					'_eex_email_hash'  => '' !== (string) ( $mapped['email'] ?? '' ) ? hash( 'sha256', strtolower( (string) $mapped['email'] ) ) : '',
					'_eex_links'       => array_values( (array) ( $mapped['links'] ?? [] ) ),
				];

			default:
				return [];
		}
	}
}
