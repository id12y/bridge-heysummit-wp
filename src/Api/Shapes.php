<?php
/**
 * Canonical assumed HeySummit API shapes.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for what the plugin assumes each HeySummit
 * resource looks like. The mappers and the runtime discovery diagnostic both
 * read from here, so an adjustment after live verification happens in one
 * place. See docs/api-notes.md for provenance of every assumption.
 */
final class Shapes {

	/**
	 * Expected fields per resource: name => type ('int|string' style unions allowed).
	 * Fields marked optional are absent from some records without a warning.
	 *
	 * @var array<string,array<string,array<string,mixed>>>
	 */
	public const RESOURCES = [
		'events'     => [
			'id'                         => [ 'type' => 'int|string' ],
			'title'                      => [ 'type' => 'string' ],
			'event_url'                  => [
				'type'     => 'string',
				'optional' => true,
			],
			'description'                => [
				'type'     => 'string',
				'optional' => true,
			],
			'timezone'                   => [
				'type'     => 'string',
				'optional' => true,
			],
			'first_talk_at'              => [
				'type'     => 'string',
				'optional' => true,
			],
			'last_talk_at'               => [
				'type'     => 'string',
				'optional' => true,
			],
			'is_live'                    => [
				'type'     => 'bool',
				'optional' => true,
			],
			'is_archived'                => [
				'type'     => 'bool',
				'optional' => true,
			],
			'is_evergreen'               => [
				'type'     => 'bool',
				'optional' => true,
			],
			'is_open_for_registrations'  => [
				'type'     => 'bool',
				'optional' => true,
			],
			'_is_open_for_registrations' => [
				'type'     => 'bool',
				'optional' => true,
			],
		],
		'sponsors'   => [
			'id'                     => [ 'type' => 'int|string' ],
			'title'                  => [
				'type'     => 'string',
				'optional' => true,
			],
			'url'                    => [
				'type'     => 'string',
				'optional' => true,
			],
			'logo'                   => [
				'type'     => 'string',
				'optional' => true,
			],
			'short_description'      => [
				'type'     => 'string',
				'optional' => true,
			],
			'long_description'       => [
				'type'     => 'string',
				'optional' => true,
			],
			'link_title'             => [
				'type'     => 'string',
				'optional' => true,
			],
			'is_active'              => [
				'type'     => 'bool',
				'optional' => true,
			],
			'is_main_sponsor'        => [
				'type'     => 'bool',
				'optional' => true,
			],
			'sponsor_categories'     => [
				'type'     => 'array',
				'optional' => true,
			],
			'show_on_landing_page'   => [
				'type'     => 'bool',
				'optional' => true,
			],
			'show_on_talk_pages'     => [
				'type'     => 'bool',
				'optional' => true,
			],
			'show_on_category_pages' => [
				'type'     => 'bool',
				'optional' => true,
			],
			'show_on_blog_posts'     => [
				'type'     => 'bool',
				'optional' => true,
			],
		],
		'talks'      => [
			'id'          => [ 'type' => 'int|string' ],
			'title'       => [ 'type' => 'string' ],
			'date'        => [
				'type'     => 'string',
				'optional' => true,
			],
			'starts_at'   => [
				'type'     => 'string',
				'optional' => true,
			],
			'ends_at'     => [
				'type'     => 'string',
				'optional' => true,
			],
			'event'       => [
				'type'     => 'int|string|array',
				'optional' => true,
			],
			'speakers'    => [
				'type'     => 'array',
				'optional' => true,
			],
			'categories'  => [
				'type'     => 'array',
				'optional' => true,
			],
			'is_active'   => [
				'type'     => 'bool',
				'optional' => true,
			],
			'is_featured' => [
				'type'     => 'bool',
				'optional' => true,
			],
		],
		'speakers'   => [
			'id'            => [ 'type' => 'int|string' ],
			'first_name'    => [
				'type'     => 'string',
				'optional' => true,
			],
			'last_name'     => [
				'type'     => 'string',
				'optional' => true,
			],
			'name'          => [
				'type'     => 'string',
				'optional' => true,
			],
			'company'       => [
				'type'     => 'string',
				'optional' => true,
			],
			'company_title' => [
				'type'     => 'string',
				'optional' => true,
			],
			'expert_creds'  => [
				'type'     => 'string',
				'optional' => true,
			],
			'headshot'      => [
				'type'     => 'string',
				'optional' => true,
			],
			'bio'           => [
				'type'     => 'string',
				'optional' => true,
			],
			'is_active'     => [
				'type'     => 'bool',
				'optional' => true,
			],
			'event'         => [
				'type'     => 'int|string|array',
				'optional' => true,
			],
		],
		'categories' => [
			'id'    => [ 'type' => 'int|string' ],
			'title' => [
				'type'     => 'string',
				'optional' => true,
			],
			'name'  => [
				'type'     => 'string',
				'optional' => true,
			],
		],
		'tickets'    => [
			'id'    => [ 'type' => 'int|string' ],
			'title' => [
				'type'     => 'string',
				'optional' => true,
			],
			'name'  => [
				'type'     => 'string',
				'optional' => true,
			],
			'price' => [
				'type'     => 'string|float',
				'optional' => true,
			],
			'event' => [
				'type'     => 'int|string|array',
				'optional' => true,
			],
		],
		'attendees'  => [
			'id'                  => [ 'type' => 'int|string' ],
			'email'               => [
				'type'     => 'string',
				'optional' => true,
			],
			'name'                => [
				'type'     => 'string',
				'optional' => true,
			],
			'registration_status' => [
				'type'     => 'string',
				'optional' => true,
			],
			'event_id'            => [
				'type'     => 'int|string',
				'optional' => true,
			],
			'event'               => [
				'type'     => 'int|string|array',
				'optional' => true,
			],
			'created_at'          => [
				'type'     => 'string',
				'optional' => true,
			],
			'utm_source'          => [
				'type'     => 'string',
				'optional' => true,
			],
			'utm_medium'          => [
				'type'     => 'string',
				'optional' => true,
			],
			'utm_campaign'        => [
				'type'     => 'string',
				'optional' => true,
			],
			'http_referer'        => [
				'type'     => 'string',
				'optional' => true,
			],
			'affiliate_email'     => [
				'type'     => 'string',
				'optional' => true,
			],
			'talks'               => [
				'type'     => 'array',
				'optional' => true,
			],
			'tickets'             => [
				'type'     => 'array',
				'optional' => true,
			],
		],
	];

	/**
	 * Whether a value matches a declared type union.
	 *
	 * @param mixed  $value Value from the API.
	 * @param string $type  Union such as 'int|string'.
	 */
	public static function matches_type( $value, string $type ): bool {
		foreach ( explode( '|', $type ) as $part ) {
			$ok = match ( $part ) {
				'int'    => is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ),
				'string' => is_string( $value ),
				'bool'   => is_bool( $value ),
				'float'  => is_float( $value ) || is_int( $value ),
				'array'  => is_array( $value ),
				default  => false,
			};

			if ( $ok ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Describe the runtime type of a value, for discovery reports.
	 *
	 * @param mixed $value Value.
	 */
	public static function describe_type( $value ): string {
		if ( is_array( $value ) ) {
			return array_is_list( $value ) ? 'list' : 'object';
		}

		return strtolower( gettype( $value ) );
	}
}
