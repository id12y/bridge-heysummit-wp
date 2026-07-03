<?php
/**
 * Post meta registration.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every meta key the plugin uses. Sync-owned keys are exposed
 * read-only through REST (other emailexpert properties consume them);
 * private plumbing (_eex_raw, hashes) stays out of REST entirely.
 */
final class Meta {

	/**
	 * Keys common to every synced post.
	 */
	public const COMMON_SYNCED = [
		'_eex_heysummit_id'    => 'string',
		'_eex_source_event_id' => 'string',
		'_eex_connection_id'   => 'string',
		'_eex_sync_hash'       => 'string',
		'_eex_last_synced'     => 'string',
		'_eex_sync_mode'       => 'string',
		'_eex_orphaned'        => 'boolean',
		'_eex_description'     => 'string',
	];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_meta' ] );
	}

	/**
	 * Register all meta keys.
	 */
	public function register_meta(): void {
		foreach ( PostTypes::SYNCED as $post_type ) {
			foreach ( self::COMMON_SYNCED as $key => $type ) {
				$this->add( $post_type, $key, $type, '_eex_description' === $key );
			}
			// Raw payload: never in REST.
			$this->add( $post_type, '_eex_raw', 'string', false );
		}

		// Events.
		$event_keys = [
			'_eex_event_url'                 => [ 'string', true ],
			'_eex_timezone'                  => [ 'string', true ],
			'_eex_first_talk_at'             => [ 'string', true ],
			'_eex_last_talk_at'              => [ 'string', true ],
			'_eex_is_live'                   => [ 'boolean', true ],
			'_eex_is_archived'               => [ 'boolean', true ],
			'_eex_is_evergreen'              => [ 'boolean', true ],
			'_eex_is_open_for_registrations' => [ 'boolean', true ],
			'_eex_registration_count'        => [ 'integer', true ],
			'_eex_venue_name'                => [ 'string', true ],
			'_eex_venue_street'              => [ 'string', true ],
			'_eex_venue_locality'            => [ 'string', true ],
			'_eex_venue_postcode'            => [ 'string', true ],
			'_eex_venue_country'             => [ 'string', true ],
			'_eex_hero_override'             => [ 'integer', false ],
		];
		foreach ( $event_keys as $key => [ $type, $in_rest ] ) {
			$this->add( PostTypes::EVENT, $key, $type, $in_rest );
		}

		// Talks.
		$talk_keys = [
			'_eex_starts_at'         => [ 'string', true ],
			'_eex_ends_at'           => [ 'string', true ],
			'_eex_talk_url'          => [ 'string', true ],
			'_eex_replay_url'        => [ 'string', true ],
			'_eex_replay_url_synced' => [ 'string', true ],
		];
		foreach ( $talk_keys as $key => [ $type, $in_rest ] ) {
			$this->add( PostTypes::TALK, $key, $type, $in_rest );
		}
		$this->add_array( PostTypes::TALK, '_eex_speaker_ids' );

		// Speakers.
		$speaker_keys = [
			'_eex_name'                 => [ 'string', true ],
			'_eex_headline'             => [ 'string', true ],
			'_eex_company'              => [ 'string', true ],
			'_eex_photo_attachment_id'  => [ 'integer', true ],
			'_eex_photo_source_url'     => [ 'string', false ],
			'_eex_email_hash'           => [ 'string', false ],
			'_eex_directory_listing_id' => [ 'string', false ], // Reserved for Phase 4.
		];
		foreach ( $speaker_keys as $key => [ $type, $in_rest ] ) {
			$this->add( PostTypes::SPEAKER, $key, $type, $in_rest );
		}
		$this->add_array( PostTypes::SPEAKER, '_eex_links' );

		// Sponsors (all manual).
		$sponsor_keys = [
			'_eex_logo_attachment_id' => [ 'integer', true ],
			'_eex_url'                => [ 'string', true ],
			'_eex_blurb'              => [ 'string', true ],
		];
		foreach ( $sponsor_keys as $key => [ $type, $in_rest ] ) {
			$this->add( PostTypes::SPONSOR, $key, $type, $in_rest );
		}
		$this->add_array( PostTypes::SPONSOR, '_eex_event_ids' );
	}

	/**
	 * Register a scalar meta key.
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Meta key.
	 * @param string $type      Scalar type.
	 * @param bool   $in_rest   Expose read-only in REST.
	 */
	private function add( string $post_type, string $key, string $type, bool $in_rest ): void {
		register_post_meta(
			$post_type,
			$key,
			[
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => $in_rest,
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			]
		);
	}

	/**
	 * Register an array-valued meta key (stored as a single serialised value).
	 *
	 * @param string $post_type Post type.
	 * @param string $key       Meta key.
	 */
	private function add_array( string $post_type, string $key ): void {
		register_post_meta(
			$post_type,
			$key,
			[
				'type'          => 'array',
				'single'        => true,
				'show_in_rest'  => [
					'schema' => [
						'type'  => 'array',
						'items' => [ 'type' => [ 'string', 'integer', 'object' ] ],
					],
				],
				'auth_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
			]
		);
	}
}
