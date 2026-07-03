<?php
/**
 * Schema output with SEO plugin coexistence.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Injects the plugin's schema pieces into Yoast's or Rank Math's graph when
 * either is active (duplicate conflicting schema is worse than none);
 * otherwise outputs a standalone JSON-LD block in wp_head, plus basic Open
 * Graph and Twitter card tags when no SEO plugin covers these CPTs.
 */
final class SchemaOutput {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'wp', [ $this, 'setup' ] );
	}

	/**
	 * Choose the output path once the query is known.
	 */
	public function setup(): void {
		if ( ! Options::setting( 'schema_enabled' ) ) {
			return;
		}

		if ( defined( 'WPSEO_VERSION' ) ) {
			add_filter( 'wpseo_schema_graph', [ $this, 'inject_into_yoast' ], 11 );

			return;
		}

		if ( defined( 'RANK_MATH_VERSION' ) ) {
			add_filter( 'rank_math/json_ld', [ $this, 'inject_into_rank_math' ], 99 );

			return;
		}

		add_action( 'wp_head', [ $this, 'output_standalone' ] );

		if ( Options::setting( 'og_fallback' ) && ! $this->another_seo_plugin_active() ) {
			add_action( 'wp_head', [ $this, 'output_social_tags' ], 5 );
		}
	}

	/**
	 * The schema pieces for the current view, honouring per-type toggles.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function pieces(): array {
		$pieces  = [];
		$post_id = (int) get_queried_object_id();

		if ( 0 === $post_id ) {
			return [];
		}

		if ( is_singular( PostTypes::EVENT ) && Options::setting( 'schema_event' ) ) {
			$pieces[] = SchemaGenerator::event( $post_id );
		} elseif ( is_singular( PostTypes::TALK ) ) {
			if ( Options::setting( 'schema_event' ) ) {
				$pieces[] = SchemaGenerator::talk( $post_id );
			}
			if ( Options::setting( 'schema_video' ) ) {
				$pieces[] = SchemaGenerator::video( $post_id );
			}
		} elseif ( is_singular( PostTypes::SPEAKER ) && Options::setting( 'schema_person' ) ) {
			$pieces[] = SchemaGenerator::speaker( $post_id );
		}

		return array_values( array_filter( $pieces ) );
	}

	/**
	 * Append pieces to the Yoast graph.
	 *
	 * @param array<int,array<string,mixed>> $graph Yoast graph.
	 * @return array<int,array<string,mixed>>
	 */
	public function inject_into_yoast( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}

		foreach ( $this->pieces() as $piece ) {
			$graph[] = $piece;
		}

		return $graph;
	}

	/**
	 * Append pieces to the Rank Math data.
	 *
	 * @param array<string,mixed> $data Rank Math JSON-LD data.
	 * @return array<string,mixed>
	 */
	public function inject_into_rank_math( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		foreach ( $this->pieces() as $index => $piece ) {
			$data[ 'eex_' . $index ] = $piece;
		}

		return $data;
	}

	/**
	 * Standalone JSON-LD block for sites without a graph-capable SEO plugin.
	 */
	public function output_standalone(): void {
		$pieces = $this->pieces();

		if ( empty( $pieces ) ) {
			return;
		}

		$graph = [
			'@context' => 'https://schema.org',
			'@graph'   => $pieces,
		];

		printf(
			'<script type="application/ld+json">%s</script>' . "\n",
			wp_json_encode( $graph, JSON_UNESCAPED_SLASHES ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD payload; values built from escaped/typed data.
		);
	}

	/**
	 * Basic Open Graph and Twitter tags so shared links unfurl.
	 */
	public function output_social_tags(): void {
		if ( ! is_singular( [ PostTypes::EVENT, PostTypes::TALK, PostTypes::SPEAKER ] ) ) {
			return;
		}

		$post_id     = (int) get_queried_object_id();
		$title       = get_the_title( $post_id );
		$description = wp_strip_all_tags( (string) get_post_meta( $post_id, '_eex_description', true ) );
		$description = mb_substr( $description, 0, 200 );

		$image = '';
		if ( is_singular( PostTypes::SPEAKER ) ) {
			$photo_id = (int) get_post_meta( $post_id, '_eex_photo_attachment_id', true );
			if ( $photo_id > 0 && function_exists( 'wp_get_attachment_url' ) ) {
				$image = (string) ( wp_get_attachment_url( $photo_id ) ?: '' );
			}
		}
		if ( '' === $image && function_exists( 'get_the_post_thumbnail_url' ) ) {
			$image = (string) ( get_the_post_thumbnail_url( $post_id, 'large' ) ?: '' );
		}

		printf( '<meta property="og:type" content="website" />' . "\n" );
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( (string) get_permalink( $post_id ) ) );

		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
			printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
		}

		if ( '' !== $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
			printf( '<meta name="twitter:card" content="summary_large_image" />' . "\n" );
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		} else {
			printf( '<meta name="twitter:card" content="summary" />' . "\n" );
		}

		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
	}

	/**
	 * Whether another mainstream SEO plugin (beyond Yoast/Rank Math, handled
	 * above) is active.
	 */
	private function another_seo_plugin_active(): bool {
		return defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' ) || class_exists( 'The_SEO_Framework\Load' );
	}
}
