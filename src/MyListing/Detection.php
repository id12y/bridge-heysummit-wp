<?php
/**
 * Runtime MyListing structure detection.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\MyListing;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * MyListing's internals vary by version, so nothing is hardcoded: the
 * structure is discovered from the installed site rather than assumed.
 *
 * Detection is a ladder of independent evidence sources, not a single path
 * that must succeed end to end. Each rung contributes what it can and the
 * results are merged, so one unreadable piece never costs the whole picture:
 * the theme's own API is preferred, then the stored listing-type
 * configuration, then the listing-type posts themselves, and finally the
 * listings already in the database — which carry the type slugs and field
 * keys the site actually uses, whatever the theme calls them.
 *
 * Only a site with no recoverable structure at all falls through to the
 * operator's manual mapping. Detection re-runs itself when the site changes
 * (theme switch, update, a listing type saved) and on the daily maintenance
 * event, so a site that becomes readable later heals without anyone clicking
 * anything.
 */
final class Detection {

	private const OPTION       = 'eex_mylisting_detection';
	public const MANUAL_OPTION = 'eex_mylisting_manual';

	/** Bound on the exploratory queries; these run once per cache miss. */
	private const SAMPLE_LIMIT = 200;

	/**
	 * The detection result, cached until the site changes.
	 *
	 * An operator-supplied manual mapping always wins while it remains
	 * usable: the operator knows their site. A mapping that no longer
	 * describes the site (its post type has gone) is not allowed to keep the
	 * bridge pointed at nothing — detection falls back to automatic and says
	 * so in the log.
	 *
	 * @param bool $refresh Force a re-run of automatic detection.
	 * @return array<string,mixed> confident, source ('auto'|'manual'),
	 *                             post_type, type_meta_key, types, evidence.
	 */
	public static function get( bool $refresh = false ): array {
		$manual = self::manual();
		if ( null !== $manual ) {
			return $manual;
		}

		$salt   = (string) apply_filters( 'eex_mylisting_detection_salt', function_exists( 'wp_get_theme' ) ? (string) wp_get_theme()->get( 'Version' ) : '' );
		$cached = (array) get_option( self::OPTION, [] );

		if ( ! $refresh && ! empty( $cached ) && ( $cached['salt'] ?? null ) === $salt ) {
			return $cached;
		}

		$result         = self::run();
		$result['salt'] = $salt;

		update_option( self::OPTION, $result, false );

		Logger::log(
			Logger::CONTEXT_API,
			$result['confident'] ? 'info' : 'warning',
			$result['confident']
				? sprintf( 'discovery: MyListing detection found %d listing type(s).', count( $result['types'] ) )
				: 'discovery: MyListing structure could not be read from the theme, its configuration, or existing listings; bridge disabled.',
			[
				'flag'     => 'discovery',
				'evidence' => $result['evidence'] ?? [],
				'types'    => array_map(
					static fn( array $type ): array => [
						'slug'   => $type['slug'],
						'label'  => $type['label'],
						'fields' => array_column( $type['fields'], 'key' ),
					],
					$result['types']
				),
			]
		);

		return $result;
	}

	/**
	 * Register the hooks that keep detection current without an operator
	 * having to retry anything by hand. Called before the confidence check,
	 * so a site that is currently unreadable still heals when it changes.
	 */
	public static function register_maintenance(): void {
		// The structure can only change with the theme or its configuration.
		add_action( 'switch_theme', [ self::class, 'invalidate' ] );
		add_action( 'upgrader_process_complete', [ self::class, 'invalidate' ] );
		add_action( 'save_post', [ self::class, 'on_saved_post' ], 10, 2 );
		add_action( 'deleted_post', [ self::class, 'invalidate' ] );

		// Backstop: a site that becomes readable later recovers on its own.
		add_action( 'eex_daily_maintenance', [ self::class, 'heal' ] );
	}

	/**
	 * Drop the cached result so the next read re-runs detection.
	 */
	public static function invalidate(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Invalidate when a listing type, or a listing, is saved: both can
	 * introduce a type the previous run never saw.
	 *
	 * @param int      $post_id Saved post ID.
	 * @param \WP_Post $post    Saved post.
	 */
	public static function on_saved_post( $post_id, $post = null ): void {
		if ( ! is_object( $post ) || ! isset( $post->post_type ) ) {
			return;
		}

		$cached = (array) get_option( self::OPTION, [] );
		$type   = (string) $post->post_type;

		if ( self::looks_like_type_post_type( $type ) || (string) ( $cached['post_type'] ?? '' ) === $type ) {
			self::invalidate();
		}
	}

	/**
	 * Re-run detection when the cached answer is unusable. Logged only when
	 * the state actually changes, so a site that simply has no MyListing
	 * structure does not write a warning every day.
	 */
	public static function heal(): void {
		if ( null !== self::manual() ) {
			return; // The operator's mapping stands.
		}

		$before = (array) get_option( self::OPTION, [] );

		if ( ! empty( $before['confident'] ) ) {
			return; // Nothing to heal.
		}

		$after = self::get( true );

		if ( ! empty( $after['confident'] ) ) {
			Logger::log(
				Logger::CONTEXT_API,
				'info',
				sprintf( 'discovery: MyListing structure became readable on its own; the bridge recovered with %d listing type(s) and needs no manual mapping.', count( $after['types'] ) ),
				[ 'flag' => 'discovery' ]
			);
		}
	}

	/**
	 * The operator's manual mapping as a confident detection result, or
	 * null when none is stored or the stored one no longer fits the site.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manual(): ?array {
		$stored = (array) get_option( self::MANUAL_OPTION, [] );

		if ( empty( $stored['post_type'] ) || empty( $stored['types'] ) || ! is_array( $stored['types'] ) ) {
			return null;
		}

		// A mapping whose post type has gone describes a site that no longer
		// exists; automatic detection is a better answer than a dead target.
		if ( function_exists( 'post_type_exists' ) && ! post_type_exists( (string) $stored['post_type'] ) ) {
			Logger::log(
				Logger::CONTEXT_API,
				'warning',
				sprintf( 'discovery: the manual MyListing mapping points at post type %s, which is no longer registered; falling back to automatic detection.', (string) $stored['post_type'] ),
				[ 'flag' => 'discovery' ]
			);

			return null;
		}

		$fields = [];
		foreach ( (array) ( $stored['fields'] ?? [] ) as $field ) {
			if ( is_array( $field ) && '' !== (string) ( $field['key'] ?? '' ) ) {
				$fields[] = [
					'key'   => (string) $field['key'],
					'label' => (string) ( $field['label'] ?? $field['key'] ),
					'type'  => '',
				];
			}
		}

		$types = [];
		foreach ( (array) $stored['types'] as $type ) {
			if ( ! is_array( $type ) || '' === (string) ( $type['slug'] ?? '' ) ) {
				continue;
			}

			$types[] = [
				'id'         => 0,
				'slug'       => (string) $type['slug'],
				'label'      => (string) ( $type['label'] ?? $type['slug'] ),
				'fields'     => $fields,
				'taxonomies' => function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( (string) $stored['post_type'] ) ) : [],
			];
		}

		if ( empty( $types ) ) {
			return null;
		}

		return [
			'confident'     => true,
			'source'        => 'manual',
			'post_type'     => (string) $stored['post_type'],
			'type_meta_key' => (string) ( $stored['type_meta_key'] ?: '_case27_listing_type' ),
			'types'         => $types,
			'evidence'      => [ 'manual mapping supplied by the operator' ],
		];
	}

	/**
	 * Store or clear the manual mapping.
	 *
	 * @param array<string,mixed>|null $mapping Sanitised mapping, or null to
	 *                                          return to automatic detection.
	 */
	public static function save_manual( ?array $mapping ): void {
		if ( null === $mapping ) {
			delete_option( self::MANUAL_OPTION );
			self::invalidate();

			return;
		}

		update_option( self::MANUAL_OPTION, $mapping, false );
	}

	/**
	 * Discover the site's listing structure.
	 *
	 * @return array<string,mixed>
	 */
	private static function run(): array {
		/**
		 * Test/integration override supplying a complete detection result.
		 *
		 * @param array|null $detection Null to run real detection.
		 */
		$override = apply_filters( 'eex_mylisting_detection_override', null );
		if ( is_array( $override ) ) {
			return $override + [ 'confident' => false, 'source' => 'auto', 'post_type' => 'job_listing', 'type_meta_key' => '_case27_listing_type', 'types' => [], 'evidence' => [] ]; // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArrayItems.Found, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$evidence      = [];
		$post_type     = self::discover_listing_post_type( $evidence );
		$type_meta_key = self::discover_type_meta_key( $post_type, $evidence );
		$taxonomies    = function_exists( 'get_object_taxonomies' ) ? array_values( (array) get_object_taxonomies( $post_type ) ) : [];

		// Rung 1-3: the listing-type posts, with their fields where readable.
		$types = self::types_from_type_posts( $taxonomies, $evidence );

		// Rung 4: the listings themselves — the slugs the site really uses.
		$types = self::merge_types( $types, self::types_from_listings( $post_type, $type_meta_key, $taxonomies, $evidence ) );

		if ( empty( $types ) ) {
			$evidence[] = 'no listing types could be read from the theme, its configuration, or existing listings';

			return [
				'confident'     => false,
				'source'        => 'auto',
				'post_type'     => $post_type,
				'type_meta_key' => $type_meta_key,
				'types'         => [],
				'evidence'      => $evidence,
			];
		}

		// Types are usable without field definitions — the field map is
		// optional and can be filled in later — but a shared set of field
		// keys harvested from real listings gives the mapping table useful
		// targets when the theme's own configuration could not be read.
		if ( ! array_filter( array_column( $types, 'fields' ) ) ) {
			$harvested = self::fields_from_listings( $post_type, $evidence );

			if ( ! empty( $harvested ) ) {
				foreach ( $types as $index => $type ) {
					$types[ $index ]['fields'] = $harvested;
				}
			}
		}

		return [
			'confident'     => true,
			'source'        => 'auto',
			'post_type'     => $post_type,
			'type_meta_key' => $type_meta_key,
			'types'         => array_values( $types ),
			'evidence'      => $evidence,
		];
	}

	/**
	 * The post type holding listings. MyListing normally uses job_listing;
	 * a site that renamed it is found by looking for a registered post type
	 * whose name reads like a listing.
	 *
	 * @param array<int,string> $evidence Running account of what was found.
	 */
	private static function discover_listing_post_type( array &$evidence ): string {
		if ( ! function_exists( 'post_type_exists' ) || post_type_exists( 'job_listing' ) ) {
			$evidence[] = 'listing post type: job_listing';

			return 'job_listing';
		}

		foreach ( (array) get_post_types( [ 'public' => true ], 'names' ) as $candidate ) {
			$candidate = (string) $candidate;

			if ( 'attachment' === $candidate || ! preg_match( '/listing|directory/i', $candidate ) ) {
				continue;
			}

			$evidence[] = sprintf( 'listing post type discovered as %s (job_listing is not registered)', $candidate );

			return $candidate;
		}

		$evidence[] = 'listing post type: job_listing (assumed; nothing better found)';

		return 'job_listing';
	}

	/**
	 * The post meta key holding each listing's type slug. Falls back to
	 * reading the keys actually present on listings when MyListing's usual
	 * key is not among them.
	 *
	 * @param string            $post_type Listing post type.
	 * @param array<int,string> $evidence  Running account of what was found.
	 */
	private static function discover_type_meta_key( string $post_type, array &$evidence ): string {
		global $wpdb;

		$default = '_case27_listing_type';

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return $default;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- structure discovery, cached in an option.
		$keys = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.meta_key FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE p.post_type = %s AND m.meta_key LIKE %s LIMIT %d",
				$post_type,
				'%listing_type%',
				self::SAMPLE_LIMIT
			)
		);

		$keys = array_filter( array_map( 'strval', $keys ) );

		if ( empty( $keys ) || in_array( $default, $keys, true ) ) {
			$evidence[] = sprintf( 'listing-type meta key: %s', $default );

			return $default;
		}

		// Prefer the most MyListing-shaped key on offer.
		usort(
			$keys,
			static fn( string $a, string $b ): int => ( str_contains( $b, 'case27' ) ? 1 : 0 ) <=> ( str_contains( $a, 'case27' ) ? 1 : 0 )
		);

		$evidence[] = sprintf( 'listing-type meta key discovered as %s (%s is not in use)', $keys[0], $default );

		return $keys[0];
	}

	/**
	 * Listing types read from the listing-type posts.
	 *
	 * A type whose field definitions cannot be read is still a type: the
	 * slug and label are all the projection needs, and the field map is
	 * optional. Dropping such types was what forced the manual mapping.
	 *
	 * @param array<int,string> $taxonomies Listing taxonomies.
	 * @param array<int,string> $evidence   Running account of what was found.
	 * @return array<string,array<string,mixed>> Keyed by slug.
	 */
	private static function types_from_type_posts( array $taxonomies, array &$evidence ): array {
		$type_post_type = self::discover_type_post_type( $evidence );

		if ( '' === $type_post_type ) {
			return [];
		}

		$type_posts = get_posts(
			[
				'post_type'      => $type_post_type,
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			]
		);

		if ( empty( $type_posts ) ) {
			return [];
		}

		$types       = [];
		$with_fields = 0;

		foreach ( $type_posts as $type_post ) {
			$slug = (string) $type_post->post_name;

			if ( '' === $slug ) {
				continue;
			}

			$fields = self::fields_via_theme_api( $type_post );

			if ( empty( $fields ) ) {
				$fields = self::fields_via_stored_config( (int) $type_post->ID );
			}

			if ( ! empty( $fields ) ) {
				++$with_fields;
			}

			$types[ $slug ] = [
				'id'         => (int) $type_post->ID,
				'slug'       => $slug,
				'label'      => (string) ( $type_post->post_title ?: $slug ),
				'fields'     => $fields,
				'taxonomies' => $taxonomies,
			];
		}

		if ( ! empty( $types ) ) {
			$evidence[] = sprintf(
				'%d listing type(s) read from %s posts, %d with readable field definitions',
				count( $types ),
				$type_post_type,
				$with_fields
			);
		}

		return $types;
	}

	/**
	 * The post type holding listing-type definitions, which MyListing has
	 * spelled differently across versions.
	 *
	 * @param array<int,string> $evidence Running account of what was found.
	 */
	private static function discover_type_post_type( array &$evidence ): string {
		// case27_listing_type is the name MyListing actually registers; the
		// hyphenated spelling is kept as a fallback for older themes, and
		// was what this detection used to assume — on a site registering
		// only the underscored name that assumption found nothing at all.
		foreach ( [ 'case27_listing_type', 'case27-listing-type' ] as $known ) {
			if ( ! function_exists( 'post_type_exists' ) || post_type_exists( $known ) ) {
				return $known;
			}
		}

		foreach ( (array) get_post_types( [], 'names' ) as $candidate ) {
			if ( self::looks_like_type_post_type( (string) $candidate ) ) {
				$evidence[] = sprintf( 'listing-type post type discovered as %s', (string) $candidate );

				return (string) $candidate;
			}
		}

		$evidence[] = 'no listing-type post type is registered';

		return '';
	}

	/**
	 * Whether a post type name reads like MyListing's listing-type CPT.
	 */
	private static function looks_like_type_post_type( string $post_type ): bool {
		return 1 === preg_match( '/^case27[-_]/', $post_type )
			|| 1 === preg_match( '/listing[-_]?type/i', $post_type );
	}

	/**
	 * Listing types read from the listings themselves: whatever slugs are
	 * actually stored against real posts are, by definition, the slugs this
	 * site uses. This rung needs no theme API and no configuration format,
	 * so it survives MyListing changing either.
	 *
	 * @param string            $post_type     Listing post type.
	 * @param string            $type_meta_key Meta key holding the type slug.
	 * @param array<int,string> $taxonomies    Listing taxonomies.
	 * @param array<int,string> $evidence      Running account of what was found.
	 * @return array<string,array<string,mixed>> Keyed by slug.
	 */
	private static function types_from_listings( string $post_type, string $type_meta_key, array $taxonomies, array &$evidence ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- structure discovery, cached in an option.
		$slugs = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT m.meta_value FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value <> '' LIMIT %d",
				$post_type,
				$type_meta_key,
				self::SAMPLE_LIMIT
			)
		);

		$types = [];

		foreach ( $slugs as $slug ) {
			$slug = (string) $slug;

			// Only scalar slugs; a serialised value is not a type name.
			if ( '' === $slug || strlen( $slug ) > 100 || ! preg_match( '/^[A-Za-z0-9_-]+$/', $slug ) ) {
				continue;
			}

			$types[ $slug ] = [
				'id'         => 0,
				'slug'       => $slug,
				'label'      => self::humanise( $slug ),
				'fields'     => [],
				'taxonomies' => $taxonomies,
			];
		}

		if ( ! empty( $types ) ) {
			$evidence[] = sprintf( '%d listing type slug(s) in use on existing %s posts', count( $types ), $post_type );
		}

		return $types;
	}

	/**
	 * Field keys harvested from the listings themselves, used only when no
	 * type could offer real field definitions. Protected keys (leading
	 * underscore) are internal storage rather than mappable fields.
	 *
	 * @param string            $post_type Listing post type.
	 * @param array<int,string> $evidence  Running account of what was found.
	 * @return array<int,array<string,string>>
	 */
	private static function fields_from_listings( string $post_type, array &$evidence ): array {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return [];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- structure discovery, cached in an option.
		$keys = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.meta_key FROM {$wpdb->postmeta} m
				 INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE p.post_type = %s AND m.meta_key NOT LIKE %s
				 GROUP BY m.meta_key ORDER BY COUNT(*) DESC LIMIT %d",
				$post_type,
				'\_%',
				self::SAMPLE_LIMIT
			)
		);

		$fields = [];

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( '' === $key ) {
				continue;
			}

			$fields[] = [
				'key'   => $key,
				'label' => self::humanise( $key ),
				'type'  => '',
			];
		}

		if ( ! empty( $fields ) ) {
			$evidence[] = sprintf( '%d field key(s) harvested from existing %s posts (no readable field configuration)', count( $fields ), $post_type );
		}

		return $fields;
	}

	/**
	 * Merge discovered type sets, preferring the entry that carries more
	 * information (a real label and field definitions) for each slug.
	 *
	 * @param array<string,array<string,mixed>> $primary  Richer source.
	 * @param array<string,array<string,mixed>> $fallback Additional types.
	 * @return array<string,array<string,mixed>>
	 */
	private static function merge_types( array $primary, array $fallback ): array {
		foreach ( $fallback as $slug => $type ) {
			if ( ! isset( $primary[ $slug ] ) ) {
				$primary[ $slug ] = $type;
			}
		}

		return $primary;
	}

	/**
	 * A readable label for a slug or meta key, used where the site offers
	 * no label of its own.
	 */
	private static function humanise( string $slug ): string {
		$label = trim( (string) preg_replace( '/[_-]+/', ' ', $slug ) );
		$label = (string) preg_replace( '/^job /i', '', $label );

		return '' === $label ? $slug : ucfirst( $label );
	}

	/**
	 * Preferred path: the theme's own Listing_Type API.
	 *
	 * @param \WP_Post $type_post Listing type post.
	 * @return array<int,array<string,string>>
	 */
	private static function fields_via_theme_api( $type_post ): array {
		if ( ! class_exists( '\MyListing\Src\Listing_Type' ) ) {
			return [];
		}

		try {
			$type = \MyListing\Src\Listing_Type::get( $type_post );
			if ( ! $type || ! method_exists( $type, 'get_fields' ) ) {
				return [];
			}

			$fields = [];
			foreach ( (array) $type->get_fields() as $field ) {
				if ( ! is_object( $field ) || ! method_exists( $field, 'get_key' ) ) {
					continue;
				}
				$fields[] = [
					'key'   => (string) $field->get_key(),
					'label' => method_exists( $field, 'get_label' ) ? (string) $field->get_label() : (string) $field->get_key(),
					'type'  => method_exists( $field, 'get_type' ) ? (string) $field->get_type() : '',
				];
			}

			return $fields;
		} catch ( \Throwable $e ) {
			return [];
		}
	}

	/**
	 * Fallback path: the stored listing-type configuration. The meta key,
	 * the encoding (JSON or serialised) and the nesting have all moved
	 * between MyListing versions, so every stored value on the type post is
	 * considered and the field list is looked for wherever it sits.
	 *
	 * @param int $type_post_id Listing type post ID.
	 * @return array<int,array<string,string>>
	 */
	private static function fields_via_stored_config( int $type_post_id ): array {
		$known = [ 'case27-listing-type', 'case27_listing_type', '_case27_listing_type_config' ];
		$meta  = (array) get_post_meta( $type_post_id );
		$order = [];

		foreach ( $known as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				$order[ $key ] = $meta[ $key ];
			}
		}

		// Then anything else stored on the type post, in case the key moved.
		foreach ( $meta as $key => $value ) {
			$order[ $key ] = $value;
		}

		foreach ( $order as $value ) {
			// get_post_meta() returns each key's values as a list, but a
			// value can itself be an array; try both readings.
			$candidates = [ $value ];

			if ( is_array( $value ) && ! empty( $value ) ) {
				$candidates[] = reset( $value );
			}

			foreach ( $candidates as $raw ) {
				$decoded = self::decode_config( $raw );

				if ( ! is_array( $decoded ) ) {
					continue;
				}

				$fields = self::fields_from_config( $decoded );

				if ( ! empty( $fields ) ) {
					return $fields;
				}
			}
		}

		return [];
	}

	/**
	 * Decode a stored configuration value, whether JSON or serialised.
	 *
	 * @param mixed $raw Stored value.
	 * @return array<mixed>|null
	 */
	private static function decode_config( $raw ): ?array {
		if ( is_array( $raw ) ) {
			return $raw;
		}

		if ( ! is_string( $raw ) || '' === $raw ) {
			return null;
		}

		if ( function_exists( 'is_serialized' ) && is_serialized( $raw ) ) {
			$unserialised = maybe_unserialize( $raw );

			return is_array( $unserialised ) ? $unserialised : null;
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * Pull a field list out of a decoded configuration, wherever it sits.
	 *
	 * @param array<mixed> $config Decoded configuration.
	 * @param int          $depth  Recursion guard.
	 * @return array<int,array<string,string>>
	 */
	private static function fields_from_config( array $config, int $depth = 0 ): array {
		if ( $depth > 4 ) {
			return [];
		}

		foreach ( [ 'fields', 'listing_fields', 'form_fields', 'custom_fields' ] as $key ) {
			if ( isset( $config[ $key ] ) && is_array( $config[ $key ] ) ) {
				$fields = self::normalise_fields( $config[ $key ] );

				if ( ! empty( $fields ) ) {
					return $fields;
				}
			}
		}

		foreach ( $config as $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}

			$fields = self::fields_from_config( $value, $depth + 1 );

			if ( ! empty( $fields ) ) {
				return $fields;
			}
		}

		return [];
	}

	/**
	 * Normalise a configured field list, accepting both a list of field
	 * definitions and a map keyed by field key.
	 *
	 * @param array<mixed> $raw_fields Configured fields.
	 * @return array<int,array<string,string>>
	 */
	private static function normalise_fields( array $raw_fields ): array {
		$fields = [];

		foreach ( $raw_fields as $index => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$key = (string) ( $field['key'] ?? $field['name'] ?? ( is_string( $index ) ? $index : '' ) );

			if ( '' === $key ) {
				continue;
			}

			$fields[] = [
				'key'   => $key,
				'label' => (string) ( $field['label'] ?? $field['title'] ?? self::humanise( $key ) ),
				'type'  => (string) ( $field['type'] ?? '' ),
			];
		}

		return $fields;
	}
}
