<?php
/**
 * Elementor Pro Loop Grid query IDs.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Named queries for Loop Grid: eex_upcoming_sessions, eex_past_sessions and
 * eex_event_sessions, each with the same ordering and evergreen rules as the
 * display components, so card designs get correct data without expressing
 * meta date logic in the UI.
 */
final class Queries {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'elementor/query/eex_upcoming_sessions', [ $this, 'upcoming_sessions' ] );
		add_action( 'elementor/query/eex_past_sessions', [ $this, 'past_sessions' ] );
		add_action( 'elementor/query/eex_event_sessions', [ $this, 'event_sessions' ] );
	}

	/**
	 * Future sessions, soonest first.
	 *
	 * @param \WP_Query $query The Loop Grid query.
	 */
	public function upcoming_sessions( $query ): void {
		$this->base( $query );

		$query->set(
			'meta_query',
			[
				[
					'key'     => '_eex_starts_at',
					'value'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'compare' => '>=',
					'type'    => 'CHAR',
				],
			]
		);
		$query->set( 'order', 'ASC' );
	}

	/**
	 * Past sessions, newest first.
	 *
	 * @param \WP_Query $query The Loop Grid query.
	 */
	public function past_sessions( $query ): void {
		$this->base( $query );

		$query->set(
			'meta_query',
			[
				[
					'key'     => '_eex_starts_at',
					'value'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'compare' => '<',
					'type'    => 'CHAR',
				],
				[
					'key'     => '_eex_starts_at',
					'value'   => '',
					'compare' => '!=',
				],
			]
		);
		$query->set( 'order', 'DESC' );
	}

	/**
	 * All sessions of the current event (for Theme Builder event templates),
	 * chronological.
	 *
	 * @param \WP_Query $query The Loop Grid query.
	 */
	public function event_sessions( $query ): void {
		$this->base( $query );
		$query->set( 'order', 'ASC' );

		$post = get_post();
		if ( $post && PostTypes::EVENT === $post->post_type ) {
			$hs_id = (string) get_post_meta( (int) $post->ID, '_eex_heysummit_id', true );
			if ( '' !== $hs_id ) {
				$query->set(
					'meta_query',
					[
						[
							'key'   => '_eex_source_event_id',
							'value' => $hs_id,
						],
					]
				);
			}
		}
	}

	/**
	 * Shared query base: published talks ordered by start time.
	 *
	 * @param \WP_Query $query The Loop Grid query.
	 */
	private function base( $query ): void {
		$query->set( 'post_type', PostTypes::TALK );
		$query->set( 'post_status', 'publish' );
		// ISO 8601 UTC strings sort correctly as CHAR.
		$query->set( 'meta_key', '_eex_starts_at' );
		$query->set( 'orderby', 'meta_value' );
	}
}
