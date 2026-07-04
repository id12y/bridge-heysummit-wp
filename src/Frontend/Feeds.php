<?php
/**
 * Subscribable calendar feed.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * All upcoming sessions as a subscribable ICS, filterable by ?event= and
 * ?category=. In Full mode the pretty URL /feeds/eex/calendar.ics rewrites
 * here; Lite adds no rewrite rules, so the feed lives at
 * /?eex_feed=calendar and builds from the live repository's cached data
 * instead of WP_Query. Cached in a transient and regenerated when the
 * component cache flushes (the cache key embeds the same generation
 * counter).
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
	 * Register the pretty feed URL — Full mode only; Lite promises no
	 * extra rewrite rules and serves the query-var form instead.
	 */
	public function add_rewrite(): void {
		if ( Options::is_lite() ) {
			return;
		}

		add_rewrite_rule( '^feeds/eex/calendar\.ics$', 'index.php?eex_feed=calendar', 'top' );
	}

	/**
	 * The feed's subscribe URL for the current mode.
	 */
	public static function url(): string {
		return Options::is_lite()
			? home_url( '/?eex_feed=calendar' )
			: home_url( '/feeds/eex/calendar.ics' );
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

		// Also accept the query-string form before rewrite rules are flushed
		// (and always, in Lite, where it is the canonical form).
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

		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Cache-Control: max-age=300' );

		echo $this->build( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar document, escaped per RFC 5545.
		exit;
	}

	/**
	 * Build (or fetch the cached) ICS body for a filter set.
	 *
	 * @param array<string,mixed> $atts event, category, limit.
	 */
	public function build( array $atts ): string {
		$cacheable = $this->should_cache( $atts );

		$cached = $cacheable ? Cache::get( 'feed-calendar', $atts ) : null;

		if ( null === $cached ) {
			$title = get_bloginfo( 'name' ) . ' — ' . __( 'Sessions', 'emailexpert-events' );

			if ( Options::is_lite() ) {
				// The repository's data arrays feed the ICS directly — the
				// same path the per-session download has always used.
				$cached = Ics::calendar_from_data( Repositories::current()->upcoming_talks( $atts ), $title );
			} else {
				$cached = Ics::calendar( Query::upcoming_talks( $atts ), $title );
			}

			if ( $cacheable ) {
				Cache::set( 'feed-calendar', $atts, $cached );
			}
		}

		return (string) $cached;
	}

	/**
	 * Cache only parameter combinations that resolve to known entities:
	 * arbitrary visitor strings must not mint transient rows. Unknown
	 * values still get a (fresh-built, cache-only) response.
	 *
	 * @param array<string,mixed> $atts Request attributes.
	 */
	public function should_cache( array $atts ): bool {
		if ( Options::is_lite() ) {
			// Lite knows only the configured events and their live
			// categories — both bounded sets.
			$repository = Repositories::current();

			$event_ok = '' === (string) $atts['event']
				|| null !== $repository->event_summary( (string) $atts['event'] );

			if ( ! $event_ok ) {
				return false;
			}

			if ( '' === (string) $atts['category'] ) {
				return true;
			}

			$known = array_map(
				static fn( array $category ): string => (string) $category['slug'],
				$repository->categories( [] )
			);

			foreach ( array_filter( array_map( 'sanitize_title', explode( ',', (string) $atts['category'] ) ) ) as $slug ) {
				if ( ! in_array( $slug, $known, true ) ) {
					return false;
				}
			}

			return true;
		}

		return ( '' === (string) $atts['event'] || Query::resolve_event( (string) $atts['event'] ) > 0 )
			&& ( '' === (string) $atts['category'] || $this->known_categories( (string) $atts['category'] ) );
	}

	/**
	 * Whether every slug in a comma-separated category filter exists.
	 *
	 * @param string $category Comma-separated slugs.
	 */
	private function known_categories( string $category ): bool {
		foreach ( array_filter( array_map( 'sanitize_title', explode( ',', $category ) ) ) as $slug ) {
			if ( ! get_term_by( 'slug', $slug, \Emailexpert\Events\PostTypes\Taxonomies::CATEGORY ) ) {
				return false;
			}
		}

		return true;
	}
}
