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
 * MyListing's internals vary by version, so nothing is hardcoded: listing
 * types and their fields are enumerated programmatically from the installed
 * theme, first through the theme's own API when it exists, then from the
 * stored listing-type configuration. The result is cached (non-autoloaded)
 * and logged flagged `discovery`. When neither path yields a usable
 * structure, `confident` is false and the bridge stands down.
 */
final class Detection {

	private const OPTION       = 'eex_mylisting_detection';
	public const MANUAL_OPTION = 'eex_mylisting_manual';

	/**
	 * The detection result, cached per theme version. An operator-supplied
	 * manual mapping (stored when automatic detection cannot read the
	 * theme's structure) always wins: the operator knows their site.
	 *
	 * @param bool $refresh Force a re-run of automatic detection.
	 * @return array<string,mixed> confident, source ('auto'|'manual'),
	 *                             post_type, type_meta_key, types.
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
				: 'discovery: MyListing structure could not be read confidently; bridge disabled.',
			[
				'flag'  => 'discovery',
				'types' => array_map(
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
	 * The operator's manual mapping as a confident detection result, or
	 * null when none is stored.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function manual(): ?array {
		$stored = (array) get_option( self::MANUAL_OPTION, [] );

		if ( empty( $stored['post_type'] ) || empty( $stored['types'] ) || ! is_array( $stored['types'] ) ) {
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

			return;
		}

		update_option( self::MANUAL_OPTION, $mapping, false );
	}

	/**
	 * Enumerate listing types and fields from the installed theme.
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
			return $override + [ 'confident' => false, 'post_type' => 'job_listing', 'type_meta_key' => '_case27_listing_type', 'types' => [] ]; // phpcs:ignore Universal.Arrays.MixedKeyedUnkeyedArrayItems.Found, WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}

		$empty = [
			'confident'     => false,
			'source'        => 'auto',
			'post_type'     => 'job_listing',
			'type_meta_key' => '_case27_listing_type',
			'types'         => [],
		];

		$type_posts = get_posts(
			[
				'post_type'      => 'case27-listing-type',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			]
		);

		if ( empty( $type_posts ) ) {
			return $empty;
		}

		$types = [];

		foreach ( $type_posts as $type_post ) {
			$fields = self::fields_via_theme_api( $type_post );

			if ( empty( $fields ) ) {
				$fields = self::fields_via_stored_config( (int) $type_post->ID );
			}

			if ( empty( $fields ) ) {
				continue; // This type could not be read; skip rather than guess.
			}

			$types[] = [
				'id'         => (int) $type_post->ID,
				'slug'       => (string) $type_post->post_name,
				'label'      => (string) $type_post->post_title,
				'fields'     => $fields,
				'taxonomies' => array_values( (array) get_object_taxonomies( 'job_listing' ) ),
			];
		}

		if ( empty( $types ) ) {
			return $empty;
		}

		return [
			'confident'     => true,
			'source'        => 'auto',
			'post_type'     => 'job_listing',
			'type_meta_key' => '_case27_listing_type',
			'types'         => $types,
		];
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
	 * Fallback path: the stored listing-type configuration JSON.
	 *
	 * @param int $type_post_id Listing type post ID.
	 * @return array<int,array<string,string>>
	 */
	private static function fields_via_stored_config( int $type_post_id ): array {
		foreach ( [ 'case27-listing-type', 'case27_listing_type', '_case27_listing_type_config' ] as $meta_key ) {
			$raw = get_post_meta( $type_post_id, $meta_key, true );

			if ( ! is_string( $raw ) || '' === $raw ) {
				continue;
			}

			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}

			$raw_fields = $decoded['fields'] ?? $decoded['listing_fields'] ?? null;
			if ( ! is_array( $raw_fields ) ) {
				continue;
			}

			$fields = [];
			foreach ( $raw_fields as $field ) {
				if ( is_array( $field ) && ! empty( $field['key'] ) ) {
					$fields[] = [
						'key'   => (string) $field['key'],
						'label' => (string) ( $field['label'] ?? $field['key'] ),
						'type'  => (string) ( $field['type'] ?? '' ),
					];
				}
			}

			if ( ! empty( $fields ) ) {
				return $fields;
			}
		}

		return [];
	}
}
