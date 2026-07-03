<?php
/**
 * Custom post types.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the four CPTs. All public, REST-enabled, archived. Content is
 * machine-managed for synced types: sync writes descriptions to meta and
 * templates render from meta, so post_content belongs entirely to editors.
 */
final class PostTypes {

	public const EVENT   = 'eex_event';
	public const TALK    = 'eex_talk';
	public const SPEAKER = 'eex_speaker';
	public const SPONSOR = 'eex_sponsor';

	/**
	 * Post types whose content is synced from HeySummit.
	 */
	public const SYNCED = [ self::EVENT, self::TALK, self::SPEAKER ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_types' ] );
	}

	/**
	 * Register all post types.
	 */
	public function register_types(): void {
		register_post_type(
			self::EVENT,
			[
				'labels'       => [
					'name'          => __( 'Events', 'emailexpert-events' ),
					'singular_name' => __( 'Event', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-calendar-alt',
				'rewrite'      => [ 'slug' => 'events' ],
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			]
		);

		register_post_type(
			self::TALK,
			[
				'labels'       => [
					'name'          => __( 'Sessions', 'emailexpert-events' ),
					'singular_name' => __( 'Session', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-microphone',
				'rewrite'      => [ 'slug' => 'sessions' ],
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			]
		);

		register_post_type(
			self::SPEAKER,
			[
				'labels'       => [
					'name'          => __( 'Speakers', 'emailexpert-events' ),
					'singular_name' => __( 'Speaker', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-groups',
				'rewrite'      => [ 'slug' => 'speakers' ],
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			]
		);

		register_post_type(
			self::SPONSOR,
			[
				'labels'       => [
					'name'          => __( 'Sponsors', 'emailexpert-events' ),
					'singular_name' => __( 'Sponsor', 'emailexpert-events' ),
				],
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => true,
				'menu_icon'    => 'dashicons-awards',
				'rewrite'      => [ 'slug' => 'sponsors' ],
				'supports'     => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ],
			]
		);
	}
}
