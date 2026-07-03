<?php
/**
 * Taxonomies.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers event series, HeySummit categories and sponsor tiers, and seeds
 * the fixed series and tier terms.
 */
final class Taxonomies {

	public const SERIES   = 'eex_event_series';
	public const CATEGORY = 'eex_category';
	public const TIER     = 'eex_sponsor_tier';

	/**
	 * Sponsor tiers in display order.
	 */
	public const TIER_ORDER = [ 'platinum', 'gold', 'silver', 'partner' ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_taxonomies' ] );
		add_action( 'init', [ $this, 'seed_terms' ], 20 );
	}

	/**
	 * Register all taxonomies.
	 */
	public function register_taxonomies(): void {
		register_taxonomy(
			self::SERIES,
			[ PostTypes::EVENT, PostTypes::TALK ],
			[
				'labels'       => [
					'name'          => __( 'Event series', 'emailexpert-events' ),
					'singular_name' => __( 'Event series', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite'      => [ 'slug' => 'event-series' ],
			]
		);

		register_taxonomy(
			self::CATEGORY,
			[ PostTypes::TALK ],
			[
				'labels'       => [
					'name'          => __( 'Session categories', 'emailexpert-events' ),
					'singular_name' => __( 'Session category', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'hierarchical' => false,
				'rewrite'      => [ 'slug' => 'session-category' ],
			]
		);

		register_taxonomy(
			self::TIER,
			[ PostTypes::SPONSOR ],
			[
				'labels'       => [
					'name'          => __( 'Sponsor tiers', 'emailexpert-events' ),
					'singular_name' => __( 'Sponsor tier', 'emailexpert-events' ),
				],
				'public'       => false,
				'show_ui'      => true,
				'show_in_rest' => true,
				'hierarchical' => true,
			]
		);
	}

	/**
	 * Seed the fixed series and tier terms once.
	 */
	public function seed_terms(): void {
		if ( get_option( 'eex_terms_seeded' ) ) {
			return;
		}

		$series = [ 'FORUM', 'Deliverability Summit', 'Sender Symposium', 'Festival of Email' ];
		foreach ( $series as $name ) {
			if ( ! term_exists( $name, self::SERIES ) ) {
				wp_insert_term( $name, self::SERIES );
			}
		}

		$tiers = [
			'Platinum' => 1,
			'Gold'     => 2,
			'Silver'   => 3,
			'Partner'  => 4,
		];
		foreach ( $tiers as $name => $order ) {
			if ( ! term_exists( $name, self::TIER ) ) {
				$term = wp_insert_term( $name, self::TIER );
				if ( is_array( $term ) ) {
					update_term_meta( (int) $term['term_id'], '_eex_tier_order', $order );
				}
			}
		}

		update_option( 'eex_terms_seeded', 1, false );
	}
}
