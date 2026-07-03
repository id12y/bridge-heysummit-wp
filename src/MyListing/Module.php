<?php
/**
 * MyListing bridge module.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\MyListing;

defined( 'ABSPATH' ) || exit;

/**
 * One-way projection of the eex_ CPTs into MyListing listings. The eex_
 * posts stay canonical as data; the bridge creates and updates listings and
 * never reads them back as truth. Loaded only when MyListing is detected
 * (the cheap presence check lives inline in Plugin so nothing in this
 * namespace is touched when the theme is absent). If listing-type detection
 * is not confident, the bridge disables itself with a clear admin notice
 * rather than guessing.
 */
final class Module {

	/**
	 * Cheap presence check (safe when the theme is absent).
	 */
	public static function detected(): bool {
		return class_exists( '\MyListing\App' )
			|| defined( 'CASE27_THEME_DIR' )
			/**
			 * Test/integration override for MyListing presence.
			 *
			 * @param bool $present Whether MyListing should be treated as present.
			 */
			|| (bool) apply_filters( 'eex_mylisting_present', false );
	}

	/**
	 * Entry point, called only after presence is established.
	 */
	public static function register(): void {
		$detection = Detection::get();

		if ( empty( $detection['confident'] ) ) {
			if ( is_admin() ) {
				\Emailexpert\Events\Admin\Notices::add(
					'mylisting_detection',
					__( 'MyListing was found but its listing types could not be read confidently, so the listings bridge is disabled. No listings will be created or changed.', 'emailexpert-events' ),
					'warning'
				);
			}

			return;
		}

		\Emailexpert\Events\Admin\Notices::remove( 'mylisting_detection' );

		( new Projector() )->register();
		( new Canonical() )->register();
	}

	/**
	 * Bridge configuration.
	 *
	 * @return array<string,array<string,mixed>> Per source type: enabled,
	 *         listing_type, canonical ('eex'|'listing'), listings_only,
	 *         map (source field => target field key).
	 */
	public static function config(): array {
		$stored = (array) get_option( 'eex_mylisting', [] );
		$out    = [];

		foreach ( [ 'events', 'sessions', 'speakers' ] as $source ) {
			$out[ $source ] = wp_parse_args(
				(array) ( $stored[ $source ] ?? [] ),
				[
					'enabled'       => 0,
					'listing_type'  => '',
					'canonical'     => 'eex',
					'listings_only' => 0,
					'map'           => [],
				]
			);

			// Duplicate-content control is enforced by construction: the
			// canonical side is always one of the two.
			if ( ! in_array( $out[ $source ]['canonical'], [ 'eex', 'listing' ], true ) ) {
				$out[ $source ]['canonical'] = 'eex';
			}
		}

		return $out;
	}

	/**
	 * The eex_ post type for a source type key.
	 *
	 * @param string $source events|sessions|speakers.
	 */
	public static function source_post_type( string $source ): string {
		return match ( $source ) {
			'events'   => 'eex_event',
			'sessions' => 'eex_talk',
			'speakers' => 'eex_speaker',
			default    => '',
		};
	}

	/**
	 * The source fields the mapping UI offers per source type.
	 *
	 * @param string $source Source type key.
	 * @return array<string,string> field key => label.
	 */
	public static function source_fields( string $source ): array {
		$common = [
			'title'       => __( 'Title', 'emailexpert-events' ),
			'description' => __( 'Description', 'emailexpert-events' ),
			'photo'       => __( 'Photo / image', 'emailexpert-events' ),
			'categories'  => __( 'Categories', 'emailexpert-events' ),
		];

		return match ( $source ) {
			'events'   => $common + [
				'starts_at'    => __( 'Start date and time', 'emailexpert-events' ),
				'ends_at'      => __( 'End date and time', 'emailexpert-events' ),
				'register_url' => __( 'Register URL', 'emailexpert-events' ),
				'event_url'    => __( 'Event URL', 'emailexpert-events' ),
			],
			'sessions' => $common + [
				'starts_at'    => __( 'Start date and time', 'emailexpert-events' ),
				'ends_at'      => __( 'End date and time', 'emailexpert-events' ),
				'register_url' => __( 'Register URL', 'emailexpert-events' ),
				'replay_url'   => __( 'Replay URL', 'emailexpert-events' ),
				'event_url'    => __( 'Event URL', 'emailexpert-events' ),
			],
			default    => $common,
		};
	}
}
