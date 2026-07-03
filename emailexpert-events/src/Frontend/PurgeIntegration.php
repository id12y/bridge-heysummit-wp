<?php
/**
 * Page cache purge integration.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * After each sync and on webhook-driven counter changes, fires the standard
 * purge hooks for common page caches (WP Rocket, LiteSpeed, W3 Total Cache,
 * the Cloudflare plugin), scoped to affected URLs where the cache supports
 * it with full purge as the fallback. Off by default.
 */
final class PurgeIntegration {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_sync_completed', [ $this, 'purge_after_sync' ] );
		add_action( 'eex_webhook_processed', [ $this, 'purge_after_webhook' ] );
	}

	/**
	 * Whether purging is enabled.
	 */
	private function enabled(): bool {
		return (bool) Options::setting( 'purge_enabled' );
	}

	/**
	 * A sync may have touched anything: purge the plugin's archives and
	 * singles (scoped where the cache supports URLs, full purge otherwise).
	 */
	public function purge_after_sync(): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$urls = [ home_url( '/' ) ];

		foreach ( [ PostTypes::EVENT, PostTypes::TALK, PostTypes::SPEAKER ] as $post_type ) {
			if ( function_exists( 'get_post_type_archive_link' ) ) {
				$archive = get_post_type_archive_link( $post_type );
				if ( is_string( $archive ) && '' !== $archive ) {
					$urls[] = $archive;
				}
			}
		}

		self::purge( $urls, true );
	}

	/**
	 * A webhook changed a counter: purge that event's URL.
	 *
	 * @param array $result Processing summary from the webhook processor.
	 */
	public function purge_after_webhook( $result = [] ): void {
		if ( ! $this->enabled() ) {
			return;
		}

		$event_hs_id = (string) ( ( (array) $result )['event_hs_id'] ?? '' );
		$post_id     = '' !== $event_hs_id ? Components::event_post_for_hs_id( $event_hs_id ) : 0;

		$urls = $post_id > 0 ? [ (string) get_permalink( $post_id ) ] : [];

		self::purge( $urls, 0 === $post_id );
	}

	/**
	 * Fire the purge hooks.
	 *
	 * @param string[] $urls          Affected URLs.
	 * @param bool     $allow_full    Whether a full purge is an acceptable fallback.
	 */
	public static function purge( array $urls, bool $allow_full = false ): void {
		$urls = array_values( array_filter( array_map( 'strval', $urls ) ) );

		// WP Rocket.
		if ( function_exists( 'rocket_clean_files' ) && ! empty( $urls ) ) {
			rocket_clean_files( $urls );
		} elseif ( $allow_full && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}

		// LiteSpeed Cache (actions are no-ops when the plugin is absent).
		if ( ! empty( $urls ) ) {
			foreach ( $urls as $url ) {
				do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party plugin's own hook.
			}
		} elseif ( $allow_full ) {
			do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party plugin's own hook.
		}

		// W3 Total Cache.
		if ( function_exists( 'w3tc_flush_url' ) && ! empty( $urls ) ) {
			foreach ( $urls as $url ) {
				w3tc_flush_url( $url );
			}
		} elseif ( $allow_full && function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}

		// Cloudflare plugin (standard action; no-op when absent).
		if ( ! empty( $urls ) ) {
			do_action( 'cloudflare_purge_by_url', $urls ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- third-party plugin's own hook.
		}

		/**
		 * The plugin purged (or asked caches to purge) these URLs. Hosts
		 * with bespoke caches can hook here.
		 *
		 * @param string[] $urls       Affected URLs ([] = full purge request).
		 * @param bool     $allow_full Whether a full purge was acceptable.
		 */
		do_action( 'eex_cache_purged', $urls, $allow_full );
	}
}
