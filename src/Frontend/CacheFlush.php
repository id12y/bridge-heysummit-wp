<?php
/**
 * Cache invalidation triggers.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Flushes the component cache when content changes outside a sync run:
 * editorial saves of plugin post types and webhook receipts (the sync engine
 * flushes at run completion itself), and publish-state changes of the post
 * types the Homepage Editorial Hero lists as news — so a new story shows on
 * the homepage without waiting out the display TTL.
 */
final class CacheFlush {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'eex_webhook_processed', [ Cache::class, 'flush' ] );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
	}

	/**
	 * Flush when a plugin post type is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( $post_id, $post ): void {
		if ( in_array( $post->post_type ?? '', [ PostTypes::EVENT, PostTypes::TALK, PostTypes::SPEAKER, PostTypes::SPONSOR ], true ) ) {
			Cache::flush();
		}
	}

	/**
	 * Flush when an editorial post enters or leaves publish (a new lead
	 * story, an unpublished one). Bounded to publish transitions of the
	 * filterable news types — drafts saving drafts never flush anything.
	 *
	 * @param string          $new_status New status.
	 * @param string          $old_status Old status.
	 * @param \WP_Post|object $post       Post.
	 */
	public function on_transition( $new_status, $old_status, $post ): void {
		if ( $new_status === $old_status || ( 'publish' !== $new_status && 'publish' !== $old_status ) ) {
			return;
		}

		/**
		 * Filter the post types whose publish-state changes flush the
		 * component cache for the editorial compositions.
		 *
		 * @param string[] $types Post types (default: post).
		 */
		$types = (array) apply_filters( 'eex_editorial_post_types', [ 'post' ] );

		if ( in_array( (string) ( $post->post_type ?? '' ), $types, true ) ) {
			Cache::flush();
		}
	}
}
