<?php
/**
 * Community Hub API settings.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Tuning knobs for the Hub API, stored in their own non-autoloaded option:
 * they are read only on Hub requests, the sweep cron and the CLI. The
 * master switch lives in the ONE autoloaded settings option
 * (`eex_settings.hub_enabled`, default 0) so a disabled Hub costs ordinary
 * requests nothing — the module never even registers.
 */
final class Settings {

	public const OPTION = 'eex_hub_settings';

	/**
	 * Defaults. The community fact markers are CRM tag slugs and a CRM
	 * custom-field id — operator-controlled data in the CRM, never inferred
	 * from ticket purchases.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'rate_limit'   => 300,
			'rate_window'  => 300,
			'max_page'     => 100,
			'default_page' => 50,
			'ip_allowlist' => [],
			'tier_field'   => '_membership_tier',
			'tag_eligible' => 'community-eligible',
			'tag_opt_in'   => 'community-opt-in',
			'tag_invited'  => 'community-invited',
			'tag_admitted' => 'community-admitted',
		];
	}

	/**
	 * Whether the Hub API is enabled (the feature switch, default off).
	 */
	public static function enabled(): bool {
		return (bool) Options::setting( 'hub_enabled' );
	}

	/**
	 * One tuning value.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$settings = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );

		return $settings[ $key ] ?? null;
	}

	/**
	 * Merge new tuning values in.
	 *
	 * @param array<string,mixed> $values Values to merge.
	 */
	public static function update( array $values ): void {
		$settings = wp_parse_args( (array) get_option( self::OPTION, [] ), self::defaults() );

		update_option( self::OPTION, array_merge( $settings, $values ), false );
	}

	/**
	 * The clamped page size for a request.
	 *
	 * @param mixed $requested Raw limit parameter.
	 */
	public static function page_size( $requested ): int {
		$max = max( 1, (int) self::get( 'max_page' ) );

		if ( null === $requested || '' === $requested ) {
			return min( $max, max( 1, (int) self::get( 'default_page' ) ) );
		}

		return min( $max, max( 1, (int) $requested ) );
	}
}
