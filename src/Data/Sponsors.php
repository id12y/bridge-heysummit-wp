<?php
/**
 * Shared HeySummit sponsor fetching.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Api\PathStyles;
use Emailexpert\Events\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The sponsors endpoint arrived long after the rest of the API (the wall
 * was manual-only until then), so everything here is deliberately
 * defensive: field names are guessed across the API's known spellings,
 * unknown shapes degrade to skipped rows, and a failed or missing endpoint
 * yields an empty list — the wall then falls back to the operator's manual
 * rows exactly as before.
 */
final class Sponsors {

	/**
	 * Raw sponsor rows for one event, cached for 15 minutes.
	 *
	 * Tries the top-level route then the nested one, remembering which
	 * worked (the same account-shape split as tickets).
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function raw( string $connection_id, string $event_id ) {
		if ( '' === $connection_id || '' === $event_id ) {
			return new WP_Error( 'eex_sponsors_args', __( 'Choose a connection and event first.', 'emailexpert-events' ) );
		}

		$key    = 'eex_sponsors_' . md5( $connection_id . '|' . $event_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return new WP_Error( 'eex_sponsors_connection', __( 'Connection not found or has no API key saved.', 'emailexpert-events' ) );
		}

		$client   = HeySummitClient::for_connection( $connection );
		$sponsors = $client->get_all( 'sponsors/', [ 'event' => $event_id ] );

		if ( is_wp_error( $sponsors ) ) {
			$nested = $client->get_all( 'events/' . rawurlencode( $event_id ) . '/sponsors/' );

			if ( ! is_wp_error( $nested ) ) {
				PathStyles::remember( $client->connection_id(), 'sponsors', 'nested' );
				$sponsors = $nested;
			}
		}

		if ( is_wp_error( $sponsors ) ) {
			return $sponsors;
		}

		$sponsors = array_values(
			array_filter(
				$sponsors,
				static fn( $sponsor ): bool => is_array( $sponsor ) && isset( $sponsor['id'] )
			)
		);

		set_transient( $key, $sponsors, 15 * MINUTE_IN_SECONDS );

		return $sponsors;
	}

	/**
	 * Sponsor category names seen on any fetch, for the editor's dropdown.
	 * Non-autoloaded, capped.
	 *
	 * @param array<int,array<string,mixed>> $sponsors Raw sponsor rows.
	 */
	private static function remember_categories( array $names ): void {
		$known = self::known_categories();

		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' !== $name && ! ctype_digit( $name ) && ! in_array( $name, $known, true ) ) {
				$known[] = $name;
			}
		}

		update_option( 'eex_sponsor_categories', array_slice( $known, -50 ), false );
	}

	/**
	 * Every sponsor category name this site has seen.
	 *
	 * @return array<int,string>
	 */
	public static function known_categories(): array {
		$known = get_option( 'eex_sponsor_categories', [] );

		if ( ! is_array( $known ) ) {
			return [];
		}

		// Older builds remembered raw category IDs; a bare number is never a
		// pickable name. Filtering on read heals polluted options in place.
		return array_values(
			array_filter(
				array_map( 'strval', $known ),
				static fn( string $name ): bool => '' !== $name && ! ctype_digit( $name )
			)
		);
	}

	/**
	 * The event's sponsor categories (id => title): the sponsor rows carry
	 * bare category IDs, so headings and filters need this map. Same
	 * route dialect as sponsors; cached alongside them; empty (and cached
	 * empty) when the endpoint is missing.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<string,string>
	 */
	public static function categories_map( string $connection_id, string $event_id ): array {
		if ( '' === $connection_id || '' === $event_id ) {
			return [];
		}

		$key    = 'eex_sponsor_cats_' . md5( $connection_id . '|' . $event_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return [];
		}

		$client = HeySummitClient::for_connection( $connection );
		$rows   = $client->get_all( 'sponsor-categories/', [ 'event' => $event_id ] );

		if ( is_wp_error( $rows ) ) {
			$rows = $client->get_all( 'events/' . rawurlencode( $event_id ) . '/sponsor-categories/' );
		}

		$map = [];

		if ( ! is_wp_error( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					$title = self::first_string( $row, [ 'title', 'name' ] );
					if ( '' !== $title ) {
						$map[ (string) $row['id'] ] = $title;
					}
				}
			}
		}

		// An errored fetch must not block category names for a quarter hour:
		// negative-cache it briefly and retry.
		set_transient( $key, $map, is_wp_error( $rows ) ? 2 * MINUTE_IN_SECONDS : 15 * MINUTE_IN_SECONDS );

		return $map;
	}

	/**
	 * Display-shaped sponsor rows for the wall, mapped from the live v2
	 * schema (see docs/api-notes.md): title, short/long descriptions,
	 * sponsor_categories as the tier-style grouping, is_main_sponsor, and
	 * per-surface visibility flags the components can filter on. Empty on
	 * any failure.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_display( string $connection_id, string $event_id, string $event_url = '' ): array {
		$sponsors = self::raw( $connection_id, $event_id );

		if ( is_wp_error( $sponsors ) ) {
			return [];
		}

		$out = [];
		$map = self::categories_map( $connection_id, $event_id );

		foreach ( $sponsors as $sponsor ) {
			if ( isset( $sponsor['is_active'] ) && false === $sponsor['is_active'] ) {
				continue;
			}

			$name = self::first_string( $sponsor, [ 'title', 'name', 'company_name' ] );
			if ( '' === $name ) {
				continue;
			}

			$main = ! empty( $sponsor['is_main_sponsor'] );
			$tier = self::tier_of( $sponsor, $map );

			if ( '' === $tier['name'] && $main ) {
				$tier = [
					'name'  => __( 'Main sponsor', 'emailexpert-events' ),
					'order' => 0,
				];
			}

			$out[] = [
				'id'                   => (int) $sponsor['id'],
				'name'                 => $name,
				'url'                  => self::first_url( $sponsor, [ 'url', 'website', 'website_url', 'link' ] ),
				'link_title'           => self::first_string( $sponsor, [ 'link_title' ] ),
				'logo_id'              => 0,
				'logo_url'             => self::first_url( $sponsor, [ 'logo', 'logo_url', 'image' ] ),
				'blurb'                => self::first_string( $sponsor, [ 'short_description', 'description', 'blurb', 'long_description' ] ),
				'tier_name'            => '' !== $tier['name'] ? $tier['name'] : __( 'Partner', 'emailexpert-events' ),
				'tier_order'           => $main ? 0 : $tier['order'],
				'main'                 => $main,
				'sponsor_categories'   => self::category_names( $sponsor, $map ),
				'sponsor_category_ids' => self::category_ids( $sponsor ),
				'slug'                 => sanitize_title( self::first_string( $sponsor, [ 'slug' ] ) ),
				'hub_url'              => self::hub_url( $event_url, self::first_string( $sponsor, [ 'slug' ] ) ),
				'banner'               => self::first_url( $sponsor, [ 'promo_banner', 'custom_promo_image_primary', 'page_header_graphic' ] ),
				'long_blurb'           => self::first_html( $sponsor, [ 'long_description' ] ),
				'video'                => [
					'type'     => strtolower( self::first_string( $sponsor, [ 'intro_source_type' ] ) ),
					'id'       => self::first_string( $sponsor, [ 'intro_video_id' ] ),
					'autoplay' => ! empty( $sponsor['intro_video_autoplay'] ),
				],
				'books_url'            => self::first_url( $sponsor, [ 'books_url' ] ),
				'phone'                => self::first_string( $sponsor, [ 'phone_number' ] ),
				'booth'                => ! empty( $sponsor['booth_enabled'] ),
				'show'                 => [
					'landing'    => ! isset( $sponsor['show_on_landing_page'] ) || ! empty( $sponsor['show_on_landing_page'] ),
					'talks'      => ! isset( $sponsor['show_on_talk_pages'] ) || ! empty( $sponsor['show_on_talk_pages'] ),
					'categories' => ! isset( $sponsor['show_on_category_pages'] ) || ! empty( $sponsor['show_on_category_pages'] ),
					'blog'       => ! isset( $sponsor['show_on_blog_posts'] ) || ! empty( $sponsor['show_on_blog_posts'] ),
				],
			];
		}

		// The editor memory learns from the categories endpoint AND from the
		// names resolved on rows — accounts whose categories endpoint is
		// missing still get a working picker.
		self::remember_categories( array_merge( array_values( $map ), ...array_map( static fn( array $row ): array => (array) $row['sponsor_categories'], $out ) ) );
		self::remember_names( $out );

		return $out;
	}

	/**
	 * Sponsor names seen on any fetch (id => name), for the editor's
	 * spotlight picker. Non-autoloaded, capped.
	 *
	 * @param array<int,array<string,mixed>> $rows Display-shaped rows.
	 */
	private static function remember_names( array $rows ): void {
		$names = self::known_names();

		foreach ( $rows as $row ) {
			$names[ (string) $row['id'] ] = (string) $row['name'];
		}

		update_option( 'eex_sponsor_names', array_slice( $names, -100, null, true ), false );
	}

	/**
	 * Every sponsor this site has seen (id => name).
	 *
	 * @return array<string,string>
	 */
	public static function known_names(): array {
		$names = get_option( 'eex_sponsor_names', [] );

		return is_array( $names ) ? array_map( 'strval', $names ) : [];
	}

	/**
	 * The first non-empty value among candidate keys, keeping its HTML for
	 * a later wp_kses_post at output (long descriptions carry markup).
	 *
	 * @param array<string,mixed> $row  Raw row.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function first_html( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_string( $row[ $key ] ) && '' !== trim( $row[ $key ] ) ) {
				return trim( $row[ $key ] );
			}
		}

		return '';
	}

	/**
	 * The sponsor's page on the HeySummit hub, from its REAL slug (the API
	 * provides it — nothing reconstructed) under the event site's /sponsors/
	 * path. Verify once on the live hub; '' when either part is missing.
	 *
	 * @param string $event_url Raw event URL.
	 * @param string $slug      Sponsor slug from the API.
	 */
	private static function hub_url( string $event_url, string $slug ): string {
		if ( '' === $event_url || '' === $slug ) {
			return '';
		}

		return \Emailexpert\Events\Frontend\Utm::tag( trailingslashit( $event_url ) . 'sponsors/' . rawurlencode( sanitize_title( $slug ) ) . '/' );
	}

	/**
	 * The sponsor's category names (the "Gold" / "Media partner" style
	 * grouping), tolerating strings or objects.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<int,string>
	 */
	private static function category_names( array $row, array $map = [] ): array {
		$names = [];

		foreach ( (array) ( $row['sponsor_categories'] ?? [] ) as $category ) {
			$name = '';

			if ( is_array( $category ) ) {
				$name = self::first_string( $category, [ 'title', 'name' ] );
			} elseif ( is_scalar( $category ) ) {
				$value = trim( (string) $category );

				// The live payload sends bare category IDs; a raw number must
				// never become a heading — resolve it or drop it.
				if ( isset( $map[ $value ] ) ) {
					$name = $map[ $value ];
				} elseif ( ! ctype_digit( $value ) ) {
					$name = sanitize_text_field( $value );
				}
			}

			if ( '' !== $name ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * The sponsor's raw category IDs, whatever shape they arrive in —
	 * filters accept an ID as well as a name.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<int,string>
	 */
	private static function category_ids( array $row ): array {
		$ids = [];

		foreach ( (array) ( $row['sponsor_categories'] ?? [] ) as $category ) {
			if ( is_array( $category ) && isset( $category['id'] ) && is_scalar( $category['id'] ) ) {
				$ids[] = (string) $category['id'];
			} elseif ( is_scalar( $category ) && '' !== trim( (string) $category ) ) {
				$ids[] = trim( (string) $category );
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * The first non-empty string among candidate keys.
	 *
	 * @param array<string,mixed> $row  Raw row.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function first_string( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return sanitize_text_field( (string) $row[ $key ] );
			}
		}

		return '';
	}

	/**
	 * The first http(s) URL among candidate keys.
	 *
	 * @param array<string,mixed> $row  Raw row.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function first_url( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';

			if ( '' !== $value && preg_match( '#^https?://#i', $value ) ) {
				return esc_url_raw( $value );
			}
		}

		return '';
	}

	/**
	 * The sponsorship tier, tolerating a plain string, a nested object, or
	 * nothing at all.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array{name:string,order:int}
	 */
	private static function tier_of( array $row, array $map = [] ): array {
		foreach ( [ 'sponsor_categories', 'tier', 'tier_name', 'level', 'sponsorship_level' ] as $key ) {
			if ( 'sponsor_categories' === $key ) {
				$names = self::category_names( $row, $map );

				if ( ! empty( $names ) ) {
					return [
						'name'  => $names[0],
						'order' => max( 0, (int) ( $row['tier_order'] ?? $row['order'] ?? $row['position'] ?? 99 ) ),
					];
				}

				continue;
			}

			$value = $row[ $key ] ?? null;

			if ( is_array( $value ) ) {
				return [
					'name'  => self::first_string( $value, [ 'title', 'name' ] ),
					'order' => max( 0, (int) ( $value['order'] ?? $value['position'] ?? 99 ) ),
				];
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return [
					'name'  => sanitize_text_field( (string) $value ),
					'order' => max( 0, (int) ( $row['tier_order'] ?? $row['order'] ?? $row['position'] ?? 99 ) ),
				];
			}
		}

		return [
			'name'  => '',
			'order' => max( 0, (int) ( $row['tier_order'] ?? $row['order'] ?? $row['position'] ?? 99 ) ),
		];
	}
}
