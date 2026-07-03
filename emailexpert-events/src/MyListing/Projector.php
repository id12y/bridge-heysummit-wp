<?php
/**
 * Listing projection.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\MyListing;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Sync\Upserter;

defined( 'ABSPATH' ) || exit;

/**
 * Projects eex_ posts into MyListing listings: one way, hash-idempotent,
 * honouring sync modes (detached stops updating its listing, excluded
 * drafts it), import status (pending source, pending listing) and orphan
 * mirroring. Runs at the end of each sync and via "Project now". Unmapped
 * fields are never written.
 */
class Projector {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_sync_completed', [ $this, 'project_all' ] );
	}

	/**
	 * Project every enabled source type.
	 *
	 * @return array<string,int> Counts per source type.
	 */
	public function project_all(): array {
		$detection = Detection::get();
		$counts    = [];

		if ( empty( $detection['confident'] ) ) {
			return $counts;
		}

		foreach ( Module::config() as $source => $config ) {
			if ( empty( $config['enabled'] ) || '' === (string) $config['listing_type'] ) {
				continue;
			}

			$post_type = Module::source_post_type( $source );
			$posts     = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
				]
			);

			$counts[ $source ] = 0;
			foreach ( $posts as $post ) {
				if ( $this->project( (int) $post->ID, $source, $config, $detection ) ) {
					++$counts[ $source ];
				}
			}
		}

		if ( ! empty( $counts ) ) {
			Logger::info(
				Logger::CONTEXT_SYNC,
				sprintf( 'MyListing projection ran: %s.', implode( ', ', array_map( static fn( $k, $v ) => "$k=$v", array_keys( $counts ), $counts ) ) )
			);
		}

		return $counts;
	}

	/**
	 * Project one source post. Returns true when the listing was written.
	 *
	 * @param int                 $post_id   Source post ID.
	 * @param string              $source    Source type key.
	 * @param array<string,mixed> $config    Bridge config for the source type.
	 * @param array<string,mixed> $detection Detection result.
	 */
	public function project( int $post_id, string $source, array $config, array $detection ): bool {
		$mode       = (string) get_post_meta( $post_id, '_eex_sync_mode', true );
		$listing_id = (int) get_post_meta( $post_id, '_eex_mylisting_id', true );

		if ( $listing_id > 0 && null === get_post( $listing_id ) ) {
			$listing_id = 0; // The listing was deleted manually; recreate.
		}

		// Excluded sources draft their listing and stop.
		if ( Upserter::MODE_EXCLUDED === $mode ) {
			if ( $listing_id > 0 && 'draft' !== get_post_status( $listing_id ) ) {
				wp_update_post(
					[
						'ID'          => $listing_id,
						'post_status' => 'draft',
					]
				);

				return true;
			}

			return false;
		}

		// Detached sources keep their listing exactly as it is.
		if ( Upserter::MODE_DETACHED === $mode ) {
			return false;
		}

		$payload = $this->payload( $post_id, $source, (array) $config['map'] );
		$status  = $this->listing_status( $post_id );

		$hash = md5( (string) wp_json_encode( [ $payload, $status, $config['listing_type'] ] ) );

		if ( $listing_id > 0 && (string) get_post_meta( $listing_id, '_eex_bridge_hash', true ) === $hash ) {
			return false;
		}

		$postarr = [
			'post_type'   => (string) $detection['post_type'],
			'post_status' => $status,
			'post_title'  => (string) ( $payload['title'] ?? get_the_title( $post_id ) ),
		];

		if ( isset( $payload['description'] ) ) {
			$postarr['post_content'] = (string) $payload['description'];
		}

		if ( $listing_id > 0 ) {
			$postarr['ID'] = $listing_id;
			wp_update_post( $postarr );
		} else {
			$listing_id = (int) wp_insert_post( $postarr );
			update_post_meta( $post_id, '_eex_mylisting_id', $listing_id );
		}

		// Linkage, type assignment and idempotency marker.
		update_post_meta( $listing_id, '_eex_source_post_id', $post_id );
		update_post_meta( $listing_id, '_eex_source_type', $source );
		update_post_meta( $listing_id, (string) $detection['type_meta_key'], (string) $config['listing_type'] );
		update_post_meta( $listing_id, '_eex_bridge_hash', $hash );

		// Mapped fields (title/description handled above).
		foreach ( (array) $config['map'] as $source_field => $target ) {
			$target = (string) $target;
			if ( '' === $target || in_array( $source_field, [ 'title', 'description' ], true ) || ! array_key_exists( $source_field, $payload ) ) {
				continue;
			}

			if ( 'categories' === $source_field ) {
				// Target is a listing taxonomy.
				wp_set_object_terms( $listing_id, (array) $payload['categories'], $target );
				continue;
			}

			if ( 'photo' === $source_field ) {
				if ( '_thumbnail' === $target ) {
					if ( (int) $payload['photo'] > 0 ) {
						set_post_thumbnail( $listing_id, (int) $payload['photo'] );
					}
				} else {
					update_post_meta( $listing_id, self::meta_key( $target ), (int) $payload['photo'] );
				}
				continue;
			}

			update_post_meta( $listing_id, self::meta_key( $target ), $payload[ $source_field ] );
		}

		return true;
	}

	/**
	 * MyListing stores listing field values under underscore-prefixed meta
	 * keys; detection returns the bare field keys. Filterable for versions
	 * that differ.
	 *
	 * @param string $field_key Detected field key.
	 */
	public static function meta_key( string $field_key ): string {
		$meta_key = str_starts_with( $field_key, '_' ) ? $field_key : '_' . $field_key;

		/**
		 * Filter the listing meta key a detected field maps to.
		 *
		 * @param string $meta_key  Meta key to write.
		 * @param string $field_key Detected field key.
		 */
		return (string) apply_filters( 'eex_mylisting_meta_key', $meta_key, $field_key );
	}

	/**
	 * Listing status mirrors the source: publish stays publish, pending
	 * stays pending (editorial approval flows through), anything else —
	 * including orphaned drafts — is draft.
	 *
	 * @param int $post_id Source post ID.
	 */
	private function listing_status( int $post_id ): string {
		$status = (string) get_post_status( $post_id );

		return in_array( $status, [ 'publish', 'pending' ], true ) ? $status : 'draft';
	}

	/**
	 * Source field values for the mapping.
	 *
	 * @param int                  $post_id Source post ID.
	 * @param string               $source  Source type key.
	 * @param array<string,string> $map     Configured mapping.
	 * @return array<string,mixed>
	 */
	private function payload( int $post_id, string $source, array $map ): array {
		$values = [
			'title'       => get_the_title( $post_id ),
			'description' => (string) get_post_meta( $post_id, '_eex_description', true ),
			'photo'       => (int) get_post_meta( $post_id, '_eex_photo_attachment_id', true ) ?: (int) get_post_thumbnail_id( $post_id ),
		];

		if ( 'sessions' === $source ) {
			$data = Components::talk_data( $post_id );

			$campaign = (string) ( get_post( $post_id )->post_name ?? '' );

			$values['starts_at']    = (string) $data['starts_at'];
			$values['ends_at']      = (string) $data['ends_at'];
			$values['register_url'] = \Emailexpert\Events\Frontend\Utm::tag( (string) ( $data['event_url'] ?: $data['talk_url'] ), $post_id, $campaign );
			$values['replay_url']   = (string) $data['replay_url'];
			$values['event_url']    = \Emailexpert\Events\Frontend\Utm::tag( (string) $data['event_url'], $post_id, $campaign );
			$values['categories']   = array_map( static fn( $term ): string => (string) $term->name, (array) $data['categories'] );
		} elseif ( 'events' === $source ) {
			$values['starts_at']    = (string) get_post_meta( $post_id, '_eex_first_talk_at', true );
			$values['ends_at']      = (string) get_post_meta( $post_id, '_eex_last_talk_at', true );
			$values['event_url']    = (string) get_post_meta( $post_id, '_eex_event_url', true );
			$values['register_url'] = $values['event_url'];
			$terms                  = get_the_terms( $post_id, 'eex_event_series' );
			$values['categories']   = is_array( $terms ) ? array_map( static fn( $term ): string => (string) $term->name, $terms ) : [];
		} else {
			$values['categories'] = [];
		}

		// Only mapped fields participate (unmapped fields are never written),
		// but title/description always feed the listing post itself when mapped.
		$payload = [];
		foreach ( $map as $source_field => $target ) {
			if ( '' !== (string) $target && array_key_exists( $source_field, $values ) ) {
				$payload[ $source_field ] = $values[ $source_field ];
			}
		}

		return $payload;
	}
}
