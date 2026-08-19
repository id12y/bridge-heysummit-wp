<?php
/**
 * Editorial story selection for the Homepage Editorial Hero.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

defined( 'ABSPATH' ) || exit;

/**
 * Selects the featured story and the Latest News list from ordinary
 * WordPress content (the standard `post` type by default; other public
 * types are configurable). No dependency on any editorial, SEO or
 * directory plugin, no external calls, no randomness: the same inputs
 * always produce the same page.
 *
 * Only public content is ever offered: published, not password-protected.
 * The featured story is always removed from Latest News before the limit
 * applies, and the list refills after every exclusion. Editorial posts are
 * local WordPress content in both operating modes.
 */
final class EditorialSelector {

	/**
	 * Reading speed for the deterministic read-time figure.
	 */
	private const WORDS_PER_MINUTE = 200;

	/**
	 * Below this many words no reading time is claimed.
	 */
	private const MIN_WORDS_FOR_TIME = 60;

	/**
	 * Select the featured story and the Latest News items.
	 *
	 * @param array<string,mixed> $atts Composition attributes (story_source,
	 *                                  story_id, story_fallback, story_types,
	 *                                  story_categories, news_count, news_types,
	 *                                  news_categories, news_exclude_categories,
	 *                                  news_order).
	 * @return array{lead:array<string,mixed>|null,items:array<int,array<string,mixed>>}
	 */
	public static function select( array $atts ): array {
		$source = (string) ( $atts['story_source'] ?? 'latest' );

		$lead = null;

		if ( 'manual' === $source ) {
			$lead = self::manual_story( (string) ( $atts['story_id'] ?? '' ), $atts );

			if ( null === $lead ) {
				if ( 'latest' === (string) ( $atts['story_fallback'] ?? 'latest' ) ) {
					Diagnostics::note( __( 'The manually selected story is unavailable or no longer public; showing the latest eligible story instead.', 'emailexpert-events' ) );
					$lead = self::latest_story( $atts, false );
				} else {
					Diagnostics::note( __( 'The manually selected story is unavailable or no longer public, and the fallback is "no featured story".', 'emailexpert-events' ) );
				}
			}
		} elseif ( 'sticky' === $source ) {
			$lead = self::latest_story( $atts, true );

			if ( null === $lead ) {
				Diagnostics::note( __( 'No sticky post is available; showing the latest eligible story instead.', 'emailexpert-events' ) );
				$lead = self::latest_story( $atts, false );
			}
		} elseif ( 'latest' === $source ) {
			$lead = self::latest_story( $atts, false );
		}

		if ( null !== $lead ) {
			Diagnostics::note(
				sprintf(
					/* translators: %s: post title. */
					__( 'Featured story: %s.', 'emailexpert-events' ),
					(string) $lead['title']
				)
			);
		}

		$items = self::news_items( $atts, $lead );

		return [
			'lead'  => $lead,
			'items' => $items,
		];
	}

	/**
	 * The Latest News rows: the featured story removed first, the list
	 * refilled, then the limit.
	 *
	 * @param array<string,mixed>      $atts Attributes.
	 * @param array<string,mixed>|null $lead The featured story (excluded).
	 * @return array<int,array<string,mixed>>
	 */
	private static function news_items( array $atts, ?array $lead ): array {
		$count = min( 6, max( 2, (int) ( $atts['news_count'] ?? 4 ) ) );

		// A bounded recent pool: enough to refill after exclusions and to
		// balance categories, never an unbounded crawl.
		$pool = self::eligible_posts(
			(string) ( $atts['news_types'] ?? ( $atts['story_types'] ?? 'post' ) ),
			(string) ( $atts['news_categories'] ?? '' ),
			(string) ( $atts['news_exclude_categories'] ?? '' ),
			max( 12, $count * 4 )
		);

		if ( null !== $lead ) {
			$pool = array_values(
				array_filter(
					$pool,
					static fn( array $item ): bool => (int) $item['id'] !== (int) $lead['id']
				)
			);
		}

		if ( 'balanced' === (string) ( $atts['news_order'] ?? 'latest' ) ) {
			$pool = self::balance_categories( $pool, $count );
		}

		return array_slice( $pool, 0, $count );
	}

	/**
	 * Deterministic balanced-category ordering: prefer a different primary
	 * category for each initial slot where an eligible alternative exists,
	 * then fill the remaining spaces chronologically. No randomness; if
	 * every recent story shares one category, they simply run in date
	 * order — diversity is never manufactured.
	 *
	 * @param array<int,array<string,mixed>> $pool  Chronological candidates.
	 * @param int                            $count Slots to fill.
	 * @return array<int,array<string,mixed>>
	 */
	private static function balance_categories( array $pool, int $count ): array {
		$picked = [];
		$used   = [];
		$taken  = [];

		foreach ( $pool as $index => $item ) {
			if ( count( $picked ) >= $count ) {
				break;
			}

			$category = strtolower( (string) ( $item['category'] ?? '' ) );

			if ( '' === $category || ! isset( $used[ $category ] ) ) {
				$picked[]          = $item;
				$taken[ $index ]   = true;
				$used[ $category ] = true;
			}
		}

		// Chronological fill for whatever the category pass left open.
		foreach ( $pool as $index => $item ) {
			if ( count( $picked ) >= $count ) {
				break;
			}

			if ( ! isset( $taken[ $index ] ) ) {
				$picked[]        = $item;
				$taken[ $index ] = true;
			}
		}

		// Keep the tail so a later slice can still refill.
		foreach ( $pool as $index => $item ) {
			if ( ! isset( $taken[ $index ] ) ) {
				$picked[] = $item;
			}
		}

		return $picked;
	}

	/**
	 * The latest eligible story (optionally sticky-only).
	 *
	 * @param array<string,mixed> $atts        Attributes.
	 * @param bool                $sticky_only Restrict to sticky posts.
	 * @return array<string,mixed>|null
	 */
	private static function latest_story( array $atts, bool $sticky_only ): ?array {
		$pool = self::eligible_posts(
			(string) ( $atts['story_types'] ?? 'post' ),
			(string) ( $atts['story_categories'] ?? '' ),
			'',
			$sticky_only ? 24 : 5
		);

		if ( $sticky_only ) {
			$sticky = array_map( 'intval', (array) get_option( 'sticky_posts', [] ) );
			$pool   = array_values(
				array_filter(
					$pool,
					static fn( array $item ): bool => in_array( (int) $item['id'], $sticky, true )
				)
			);
		}

		return $pool[0] ?? null;
	}

	/**
	 * A manually selected story, only when still public and type-eligible.
	 *
	 * @param string              $ref  Post ID.
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<string,mixed>|null
	 */
	private static function manual_story( string $ref, array $atts ): ?array {
		$post_id = (int) $ref;

		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );

		if ( ! $post
			|| 'publish' !== (string) $post->post_status
			|| '' !== (string) ( $post->post_password ?? '' ) ) {
			return null;
		}

		$types = self::csv( (string) ( $atts['story_types'] ?? 'post' ) );

		if ( ! empty( $types ) && ! in_array( (string) $post->post_type, $types, true ) ) {
			return null;
		}

		return self::view_model( $post );
	}

	/**
	 * The bounded pool of public, eligible posts, newest first.
	 *
	 * @param string $types_csv    Post types (CSV, default 'post').
	 * @param string $include_csv  Category slugs to include (CSV).
	 * @param string $exclude_csv  Category slugs to exclude (CSV).
	 * @param int    $limit        Pool bound.
	 * @return array<int,array<string,mixed>>
	 */
	private static function eligible_posts( string $types_csv, string $include_csv, string $exclude_csv, int $limit ): array {
		$types = self::csv( $types_csv );
		if ( empty( $types ) ) {
			$types = [ 'post' ];
		}

		$args = [
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 50, $limit ) ),
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		/**
		 * Filter the editorial candidate query arguments.
		 *
		 * @param array<string,mixed> $args WP_Query-style arguments.
		 */
		$args = (array) apply_filters( 'eex_editorial_query_args', $args );

		$include = array_map( 'sanitize_title', self::csv( $include_csv ) );
		$exclude = array_map( 'sanitize_title', self::csv( $exclude_csv ) );

		$out = [];

		foreach ( (array) get_posts( $args ) as $post ) {
			// Belt-and-braces on eligibility: drafts, private and
			// password-protected posts never surface even if a filter
			// widened the query.
			if ( 'publish' !== (string) $post->post_status || '' !== (string) ( $post->post_password ?? '' ) ) {
				continue;
			}

			if ( ! empty( $include ) || ! empty( $exclude ) ) {
				$slugs = self::category_slugs( (int) $post->ID );

				if ( ! empty( $include ) && empty( array_intersect( $include, $slugs ) ) ) {
					continue;
				}

				if ( ! empty( $exclude ) && ! empty( array_intersect( $exclude, $slugs ) ) ) {
					continue;
				}
			}

			$out[] = self::view_model( $post );
		}

		// The stub layer and filters may return unsorted rows; the contract
		// here is strictly newest first.
		usort( $out, static fn( array $a, array $b ): int => (int) $b['timestamp'] <=> (int) $a['timestamp'] );

		return array_slice( $out, 0, max( 1, $limit ) );
	}

	/**
	 * The normalised story view model.
	 *
	 * @param \WP_Post|object $post Post object.
	 * @return array<string,mixed>
	 */
	private static function view_model( $post ): array {
		$post_id = (int) $post->ID;
		$content = (string) ( $post->post_content ?? '' );
		$excerpt = trim( (string) ( $post->post_excerpt ?? '' ) );

		if ( '' === $excerpt ) {
			$excerpt = wp_strip_all_tags( $content );
		} else {
			$excerpt = wp_strip_all_tags( $excerpt );
		}

		$words = str_word_count( wp_strip_all_tags( $content ) );

		$categories = self::category_names( $post_id );

		$image_id  = function_exists( 'get_post_thumbnail_id' ) ? (int) get_post_thumbnail_id( $post_id ) : 0;
		$image_url = function_exists( 'get_the_post_thumbnail_url' )
			? (string) ( get_the_post_thumbnail_url( $post_id, 'large' ) ?: '' )
			: '';

		$date = (string) ( $post->post_date_gmt ?? '' );
		if ( '' === $date || str_starts_with( $date, '0000' ) ) {
			$date = (string) ( $post->post_date ?? '' );
		}

		return [
			'id'           => $post_id,
			'title'        => (string) $post->post_title,
			'url'          => (string) get_permalink( $post_id ),
			'excerpt'      => $excerpt,
			'image_id'     => $image_id,
			'image'        => $image_url,
			'category'     => (string) ( $categories[0] ?? '' ),
			'categories'   => $categories,
			'date'         => $date,
			'timestamp'    => (int) strtotime( $date ),
			// Deterministic: word count over a fixed reading speed; 0 means
			// "too short to claim a reading time" and templates omit it.
			'reading_time' => $words >= self::MIN_WORDS_FOR_TIME ? max( 1, (int) round( $words / self::WORDS_PER_MINUTE ) ) : 0,
		];
	}

	/**
	 * A post's category names (primary first).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function category_names( int $post_id ): array {
		if ( ! function_exists( 'get_the_terms' ) ) {
			return [];
		}

		$terms = get_the_terms( $post_id, 'category' );

		if ( ! is_array( $terms ) ) {
			return [];
		}

		$names = [];
		foreach ( $terms as $term ) {
			$name = (string) ( $term->name ?? '' );
			if ( '' !== $name && 'Uncategorized' !== $name && 'Uncategorised' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * A post's category slugs.
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	private static function category_slugs( int $post_id ): array {
		if ( ! function_exists( 'wp_get_object_terms' ) ) {
			return [];
		}

		$slugs = wp_get_object_terms( [ $post_id ], 'category', [ 'fields' => 'slugs' ] );

		return is_array( $slugs ) ? array_map( 'strval', $slugs ) : [];
	}

	/**
	 * Split a CSV attribute.
	 *
	 * @param string $value CSV string.
	 * @return string[]
	 */
	private static function csv( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}
