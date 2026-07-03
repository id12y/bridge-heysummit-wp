<?php
/**
 * Duplicate content control for projected listings.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\MyListing;

defined( 'ABSPATH' ) || exit;

/**
 * Exactly one side of each projection is canonical (configured per source
 * type, default eex_). The non-canonical side carries rel=canonical to the
 * canonical URL; schema is emitted only on the canonical side; "listings
 * only" mode additionally noindexes the eex_ singles. No configuration can
 * leave both sides indexed without canonicals: the setting is a binary
 * choice enforced in Module::config().
 */
final class Canonical {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'get_canonical_url', [ $this, 'filter_canonical' ], 10, 2 );
		add_filter( 'wpseo_canonical', [ $this, 'filter_seo_plugin_canonical' ] );
		add_filter( 'rank_math/frontend/canonical', [ $this, 'filter_seo_plugin_canonical' ] );
		add_filter( 'eex_schema_suppress', [ $this, 'suppress_schema' ], 10, 2 );
		add_filter( 'wp_robots', [ $this, 'robots' ] );
		add_action( 'wp_head', [ $this, 'listing_canonical_link' ], 1 );
	}

	/**
	 * The bridge config row for a post, or null when it is not bridged.
	 *
	 * @param int $post_id Post ID.
	 * @return array{source:string,config:array<string,mixed>,is_listing:bool}|null
	 */
	private function context_for( int $post_id ): ?array {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return null;
		}

		$config = Module::config();

		foreach ( [ 'events', 'sessions', 'speakers' ] as $source ) {
			if ( empty( $config[ $source ]['enabled'] ) ) {
				continue;
			}

			if ( Module::source_post_type( $source ) === $post->post_type && (int) get_post_meta( $post_id, '_eex_mylisting_id', true ) > 0 ) {
				return [
					'source'     => $source,
					'config'     => $config[ $source ],
					'is_listing' => false,
				];
			}

			if ( (string) get_post_meta( $post_id, '_eex_source_type', true ) === $source && (int) get_post_meta( $post_id, '_eex_source_post_id', true ) > 0 ) {
				return [
					'source'     => $source,
					'config'     => $config[ $source ],
					'is_listing' => true,
				];
			}
		}

		return null;
	}

	/**
	 * The canonical URL for a bridged post ('' when the post is canonical
	 * itself or its partner is unavailable).
	 *
	 * @param int $post_id Post ID.
	 */
	public function canonical_partner_url( int $post_id ): string {
		$context = $this->context_for( $post_id );

		if ( null === $context ) {
			return '';
		}

		if ( $context['is_listing'] && 'eex' === $context['config']['canonical'] ) {
			$partner = (int) get_post_meta( $post_id, '_eex_source_post_id', true );
		} elseif ( ! $context['is_listing'] && 'listing' === $context['config']['canonical'] ) {
			$partner = (int) get_post_meta( $post_id, '_eex_mylisting_id', true );
		} else {
			return ''; // This side is canonical.
		}

		if ( $partner <= 0 || 'publish' !== get_post_status( $partner ) ) {
			return '';
		}

		return (string) get_permalink( $partner );
	}

	/**
	 * Core canonical URL filter (used by WP's own rel=canonical).
	 *
	 * @param string   $url  Canonical URL.
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	public function filter_canonical( $url, $post ) {
		$partner = $this->canonical_partner_url( (int) $post->ID );

		return '' !== $partner ? $partner : $url;
	}

	/**
	 * Yoast / Rank Math canonical filters for the current singular view.
	 *
	 * @param string $url Canonical URL.
	 * @return string
	 */
	public function filter_seo_plugin_canonical( $url ) {
		if ( ! is_singular() ) {
			return $url;
		}

		$partner = $this->canonical_partner_url( (int) get_queried_object_id() );

		return '' !== $partner ? $partner : $url;
	}

	/**
	 * Schema is emitted only on the canonical side.
	 *
	 * @param bool $suppress Current value.
	 * @param int  $post_id  Post being rendered.
	 */
	public function suppress_schema( $suppress, $post_id ): bool {
		if ( $suppress ) {
			return true;
		}

		$context = $this->context_for( (int) $post_id );

		return null !== $context && ! $context['is_listing'] && 'listing' === $context['config']['canonical'];
	}

	/**
	 * "Listings only" mode: noindex the eex_ singles of that source type.
	 *
	 * @param array<string,mixed> $robots Robots directives.
	 * @return array<string,mixed>
	 */
	public function robots( $robots ) {
		if ( ! is_singular() ) {
			return $robots;
		}

		$context = $this->context_for( (int) get_queried_object_id() );

		if ( null !== $context && ! $context['is_listing'] && ! empty( $context['config']['listings_only'] ) ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/**
	 * Listings do not go through WP's canonical for CPTs in all themes;
	 * print an explicit rel=canonical on non-canonical listing singles when
	 * no SEO plugin is handling canonicals.
	 */
	public function listing_canonical_link(): void {
		if ( ! is_singular() || defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		$context = $this->context_for( $post_id );

		if ( null === $context || ! $context['is_listing'] ) {
			return; // eex_ singles are covered by get_canonical_url.
		}

		$partner = $this->canonical_partner_url( $post_id );

		if ( '' !== $partner ) {
			printf( '<link rel="canonical" href="%s" />' . "\n", esc_url( $partner ) );
		}
	}
}
