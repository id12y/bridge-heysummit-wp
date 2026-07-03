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
 * flushes at run completion itself).
 */
final class CacheFlush {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'eex_webhook_processed', [ Cache::class, 'flush' ] );
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
}
