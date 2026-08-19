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
	 * Per-request memo of eligible pools, keyed by filter set, so the lead,
	 * the secondary story and Latest News share one bounded query instead of
	 * issuing one query per story.
	 *
	 * @var array<string,array{limit:int,rows:array<int,array<string,mixed>>}>
	 */
	private static array $pools = [];

	/**
	 * Reset the per-request memo (tests, long-running processes).
	 */
	public static function reset_request_state(): void {
		self::$pools = [];
	}

	/**
	 * One pool bound for every selection this render makes: enough for the
	 * two features, the news list, refilling after exclusions and category
	 * balancing — never an unbounded crawl.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function pool_bound( array $atts ): int {
		$count = min( 12, max( 2, (int) ( $atts['news_count'] ?? 4 ) ) );

		return min( 50, max( 26, $count * 2 + 6 ) );
	}

	/**
	 * Select the featured stories and the Latest News items.
	 *
	 * @param array<string,mixed> $atts Composition attributes (story_source,
	 *                                  story_id, story_fallback, story_types,
	 *                                  story_categories, story_count,
	 *                                  story2_source, story2_id, news_count,
	 *                                  news_types, news_categories,
	 *                                  news_exclude_categories, news_order).
	 * @return array{lead:array<string,mixed>|null,second:array<string,mixed>|null,items:array<int,array<string,mixed>>}
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

		$second = null !== $lead ? self::second_story( $atts, $lead ) : null;

		if ( null !== $second ) {
			Diagnostics::note(
				sprintf(
					/* translators: %s: post title. */
					__( 'Secondary featured story: %s.', 'emailexpert-events' ),
					(string) $second['title']
				)
			);
		}

		$items = self::news_items( $atts, $lead, $second );

		return [
			'lead'   => $lead,
			'second' => $second,
			'items'  => $items,
		];
	}

	/**
	 * The secondary featured story (two-story frontage). Manual picks fall
	 * back to the next eligible story; the lead is always excluded, so the
	 * two features can never duplicate.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @param array<string,mixed> $lead The lead story (excluded).
	 * @return array<string,mixed>|null
	 */
	private static function second_story( array $atts, array $lead ): ?array {
		if ( 2 !== (int) ( $atts['story_count'] ?? 1 ) ) {
			return null;
		}

		if ( 'manual' === (string) ( $atts['story2_source'] ?? 'auto' ) ) {
			$second = self::manual_story( (string) ( $atts['story2_id'] ?? '' ), $atts );

			if ( null !== $second && (int) $second['id'] !== (int) $lead['id'] ) {
				return $second;
			}

			Diagnostics::note( __( 'The manually selected secondary story is unavailable, no longer public or duplicates the lead; showing the next eligible story instead.', 'emailexpert-events' ) );
		}

		// The next eligible story after the lead, from the same bounded pool.
		$pool = self::eligible_posts(
			(string) ( $atts['story_types'] ?? 'post' ),
			(string) ( $atts['story_categories'] ?? '' ),
			'',
			self::pool_bound( $atts )
		);

		foreach ( $pool as $item ) {
			if ( (int) $item['id'] !== (int) $lead['id'] ) {
				return $item;
			}
		}

		Diagnostics::note( __( 'No eligible secondary story exists beyond the lead; the two-story layout falls back to one.', 'emailexpert-events' ) );

		return null;
	}

	/**
	 * The Latest News rows: both featured stories removed first, the list
	 * refilled, then the limit.
	 *
	 * @param array<string,mixed>      $atts   Attributes.
	 * @param array<string,mixed>|null $lead   The featured story (excluded).
	 * @param array<string,mixed>|null $second The secondary story (excluded).
	 * @return array<int,array<string,mixed>>
	 */
	private static function news_items( array $atts, ?array $lead, ?array $second = null ): array {
		$count = min( 12, max( 2, (int) ( $atts['news_count'] ?? 4 ) ) );

		// A bounded recent pool: enough to refill after exclusions and to
		// balance categories, never an unbounded crawl.
		$pool = self::eligible_posts(
			(string) ( $atts['news_types'] ?? ( $atts['story_types'] ?? 'post' ) ),
			(string) ( $atts['news_categories'] ?? '' ),
			(string) ( $atts['news_exclude_categories'] ?? '' ),
			self::pool_bound( $atts )
		);

		$excluded = array_filter( [ null !== $lead ? (int) $lead['id'] : 0, null !== $second ? (int) $second['id'] : 0 ] );

		if ( ! empty( $excluded ) ) {
			$pool = array_values(
				array_filter(
					$pool,
					static fn( array $item ): bool => ! in_array( (int) $item['id'], $excluded, true )
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
			self::pool_bound( $atts )
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
		$memo = $types_csv . '#' . $include_csv . '#' . $exclude_csv;

		// Reusable when the stored pool was fetched at this bound or wider
		// (fewer rows than its own bound means the site itself ran out).
		if ( isset( self::$pools[ $memo ] ) && self::$pools[ $memo ]['limit'] >= min( 50, max( 1, $limit ) ) ) {
			return array_slice( self::$pools[ $memo ]['rows'], 0, max( 1, $limit ) );
		}

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

		$out = array_slice( $out, 0, max( 1, $limit ) );

		self::$pools[ $memo ] = [
			'limit' => min( 50, max( 1, $limit ) ),
			'rows'  => $out,
		];

		return $out;
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

		$categories    = self::category_names( $post_id );
		$category_link = self::primary_category_link( $post_id, (string) ( $categories[0] ?? '' ) );

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
			// The primary category's archive URL ('' when the term has no
			// valid archive), so templates can offer the label as navigation.
			'category_link' => $category_link,
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
	 * The archive URL of the post's primary displayed category — the same
	 * term the view model names first, resolved through get_term_link so
	 * URLs are never constructed by hand. '' when the term has no valid
	 * archive (the label then renders as plain text, never a broken link).
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $primary_name The first displayed category name.
	 */
	private static function primary_category_link( int $post_id, string $primary_name ): string {
		if ( '' === $primary_name || ! function_exists( 'get_the_terms' ) || ! function_exists( 'get_term_link' ) ) {
			return '';
		}

		$terms = get_the_terms( $post_id, 'category' );

		if ( ! is_array( $terms ) ) {
			return '';
		}

		foreach ( $terms as $term ) {
			if ( (string) ( $term->name ?? '' ) !== $primary_name ) {
				continue;
			}

			$link = get_term_link( $term, 'category' );

			return is_string( $link ) ? $link : '';
		}

		return '';
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
