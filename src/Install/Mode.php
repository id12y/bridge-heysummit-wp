<?php
/**
 * Operating-mode transitions.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Full ↔ Lite switching. Lite → Full loses nothing and leads into the
 * standard import wizard. Full → Lite always stops sync cron; the operator
 * chooses whether synced content is kept (the "frozen archive" state — CPTs
 * stay registered so the posts remain readable) or trashed.
 */
final class Mode {

	/**
	 * The plugin's post types.
	 */
	private const POST_TYPES = [ 'eex_event', 'eex_talk', 'eex_speaker', 'eex_sponsor' ];

	/**
	 * Record the initial mode choice from wizard step 0.
	 *
	 * On a fresh install the activation ritual was Full-shaped (terms seeded,
	 * rewrites flushed); choosing Lite undoes it so a Lite site holds nothing
	 * beyond its options (docs/decisions.md D34).
	 *
	 * @param string $mode 'full' or 'lite'.
	 */
	public static function choose( string $mode ): void {
		$mode = 'lite' === $mode ? 'lite' : 'full';

		Options::update_settings(
			[
				'mode'           => $mode,
				'mode_chosen'    => 1,
				'flush_rewrites' => 1,
			]
		);

		if ( 'lite' === $mode && ! self::has_content() ) {
			self::remove_seeded_terms();
			delete_option( 'eex_wizard_notice' );
			delete_option( 'eex_version' );
		}
	}

	/**
	 * Switch to Lite mode (settings screen, after confirmation).
	 *
	 * @param bool $keep_content Keep synced posts as a frozen archive.
	 */
	public static function switch_to_lite( bool $keep_content ): void {
		Cron::unschedule( Cron::FULL_ONLY );

		if ( ! $keep_content ) {
			foreach ( self::content_ids() as $post_id ) {
				wp_trash_post( $post_id );
			}
		}

		Options::update_settings(
			[
				'mode'           => 'lite',
				'mode_chosen'    => 1,
				'lite_archive'   => ( $keep_content && self::has_content() ) ? 1 : 0,
				'flush_rewrites' => 1,
			]
		);

		\Emailexpert\Events\Frontend\Cache::flush();
		\Emailexpert\Events\Data\Repositories::reset();
	}

	/**
	 * Switch to Full mode. Nothing was lost in Lite; the caller sends the
	 * operator into the standard import wizard.
	 */
	public static function switch_to_full(): void {
		Options::update_settings(
			[
				'mode'           => 'full',
				'mode_chosen'    => 1,
				'lite_archive'   => 0,
				'flush_rewrites' => 1,
			]
		);

		\Emailexpert\Events\Frontend\Cache::flush();
		\Emailexpert\Events\Data\Repositories::reset();
	}

	/**
	 * Whether any plugin content posts exist (any status).
	 */
	public static function has_content(): bool {
		return ! empty( self::content_ids( 1 ) );
	}

	/**
	 * IDs of all plugin content posts.
	 *
	 * @param int $limit Cap (0 = all).
	 * @return int[]
	 */
	private static function content_ids( int $limit = 0 ): array {
		$ids = get_posts(
			[
				'post_type'      => self::POST_TYPES,
				'post_status'    => 'any',
				'posts_per_page' => $limit > 0 ? $limit : -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete the terms seeded at activation (fresh Lite choice only).
	 */
	private static function remove_seeded_terms(): void {
		foreach ( [ 'eex_event_series', 'eex_category', 'eex_sponsor_tier' ] as $taxonomy ) {
			$terms = get_terms(
				[
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				]
			);

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				wp_delete_term( (int) $term->term_id, $taxonomy );
			}
		}
	}
}
