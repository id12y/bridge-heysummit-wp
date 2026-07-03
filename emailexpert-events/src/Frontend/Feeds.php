<?php
/**
 * Subscribable calendar feed.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * /feeds/eex/calendar.ics — all upcoming sessions, filterable by ?event= and
 * ?category=. Cached in a transient and regenerated when the component cache
 * flushes (the cache key embeds the same generation counter).
 */
final class Feeds {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
	}

	/**
	 * Register the pretty feed URL.
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( '^feeds/eex/calendar\.ics$', 'index.php?eex_feed=calendar', 'top' );
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'eex_feed';

		return $vars;
	}

	/**
	 * Serve the feed when requested.
	 */
	public function maybe_serve(): void {
		$feed = get_query_var( 'eex_feed' );

		// Also accept the query-string form before rewrite rules are flushed.
		if ( '' === (string) $feed && isset( $_GET['eex_feed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only feed.
			$feed = sanitize_key( wp_unslash( $_GET['eex_feed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( 'calendar' !== $feed ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- public read-only filters.
		$atts = [
			'event'    => isset( $_GET['event'] ) ? sanitize_text_field( wp_unslash( $_GET['event'] ) ) : '',
			'category' => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '',
			'limit'    => 0,
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$cached = Cache::get( 'feed-calendar', $atts );

		if ( null === $cached ) {
			$talk_ids = Query::upcoming_talks( $atts );
			$cached   = Ics::calendar( $talk_ids, get_bloginfo( 'name' ) . ' — ' . __( 'Sessions', 'emailexpert-events' ) );
			Cache::set( 'feed-calendar', $atts, $cached );
		}

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Cache-Control: max-age=300' );

		echo $cached; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar document, escaped per RFC 5545.
		exit;
	}
}
