<?php
/**
 * Typed access to plugin options.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Central accessor for the plugin's options. Everything is stored under a
 * handful of eex_ options; this class is the only place that knows their
 * shapes and defaults.
 */
final class Options {

	public const CONNECTIONS   = 'eex_connections';
	public const SYNCED_EVENTS = 'eex_synced_events';
	public const SETTINGS      = 'eex_settings';
	public const SECRET        = 'eex_webhook_secret';

	/**
	 * Default general settings.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'mode'                  => 'full',
			'frequency'             => 'hourly',
			'schema_enabled'        => 1,
			'schema_event'          => 1,
			'schema_person'         => 1,
			'schema_video'          => 1,
			'og_fallback'           => 1,
			'date_format'           => '',
			'series_colours'        => [],
			'wh_checkout'           => 1,
			'wh_started'            => 1,
			'wh_talk'               => 1,
			'wh_capture'            => 0,
			'notify_checkout_email' => 0,
			'health_email'          => 1,
			'retention_months'      => 24,
			'uninstall_delete'      => 0,
			'woo_push_processing'   => 0,
			'woo_consent_text'      => __( 'Register me for the event and send me event-related emails.', 'emailexpert-events' ),
			'utm_enabled'           => 1,
			'utm_source'            => '',
			'utm_medium'            => 'web',
			'purge_enabled'         => 0,
			'digest_enabled'        => 0,
			'accounts_enabled'      => 0,
		];
	}

	/**
	 * The operating mode: 'full' (synced local content) or 'lite' (live
	 * display only). Anything unrecognised is treated as Full.
	 */
	public static function mode(): string {
		return 'lite' === (string) self::setting( 'mode' ) ? 'lite' : 'full';
	}

	/**
	 * Whether Lite mode is active.
	 */
	public static function is_lite(): bool {
		return 'lite' === self::mode();
	}

	/**
	 * Get one general setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function setting( string $key ) {
		$settings = wp_parse_args( (array) get_option( self::SETTINGS, [] ), self::defaults() );

		return $settings[ $key ] ?? null;
	}

	/**
	 * Update a subset of general settings.
	 *
	 * @param array<string,mixed> $values Values to merge in.
	 */
	public static function update_settings( array $values ): void {
		$settings = wp_parse_args( (array) get_option( self::SETTINGS, [] ), self::defaults() );
		// The one autoloaded option: small, read on every request.
		update_option( self::SETTINGS, array_merge( $settings, $values ), true );
	}

	/**
	 * Generate the webhook secret on first use (not at activation).
	 */
	public static function ensure_webhook_secret(): string {
		$secret = self::webhook_secret();

		if ( '' === $secret ) {
			$secret = wp_generate_password( 40, false, false );
			update_option( self::SECRET, $secret, false );
		}

		return $secret;
	}

	/**
	 * All configured API connections.
	 *
	 * Each connection: [ 'id' => string, 'label' => string, 'api_key' => string ].
	 * The EEX_HEYSUMMIT_API_KEY constant, when defined, overrides the key of
	 * the first connection.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function connections(): array {
		$connections = (array) get_option( self::CONNECTIONS, [] );
		$connections = array_values( array_filter( $connections, 'is_array' ) );

		if ( empty( $connections ) && defined( 'EEX_HEYSUMMIT_API_KEY' ) ) {
			$connections[] = [
				'id'      => 'primary',
				'label'   => __( 'Primary', 'emailexpert-events' ),
				'api_key' => '',
			];
		}

		if ( ! empty( $connections ) && defined( 'EEX_HEYSUMMIT_API_KEY' ) ) {
			$connections[0]['api_key']       = (string) EEX_HEYSUMMIT_API_KEY;
			$connections[0]['from_constant'] = true;
		}

		return $connections;
	}

	/**
	 * Find a connection by ID.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array<string,string>|null
	 */
	public static function connection( string $connection_id ): ?array {
		foreach ( self::connections() as $connection ) {
			if ( ( $connection['id'] ?? '' ) === $connection_id ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Per-event sync configuration, keyed "connectionId|heysummitEventId".
	 *
	 * Each row: enabled, talks, speakers, categories, photos, import_status,
	 * cat_filter_mode ('', 'include', 'exclude'), cat_filter (array of HS
	 * category IDs), title (cached label).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function synced_events(): array {
		return (array) get_option( self::SYNCED_EVENTS, [] );
	}

	/**
	 * Configuration row for one synced event.
	 *
	 * @param string     $connection_id Connection ID.
	 * @param string|int $event_id      HeySummit event ID.
	 * @return array<string,mixed>|null
	 */
	public static function event_config( string $connection_id, $event_id ): ?array {
		$events = self::synced_events();
		$key    = $connection_id . '|' . $event_id;

		return isset( $events[ $key ] ) ? self::normalise_event_config( $events[ $key ] ) : null;
	}

	/**
	 * Fill an event config row with defaults.
	 *
	 * @param array<string,mixed> $config Raw row.
	 * @return array<string,mixed>
	 */
	public static function normalise_event_config( array $config ): array {
		return wp_parse_args(
			$config,
			[
				'enabled'         => 0,
				'talks'           => 1,
				'speakers'        => 1,
				'categories'      => 1,
				'photos'          => 1,
				'import_status'   => 'publish',
				'cat_filter_mode' => '',
				'cat_filter'      => [],
				'future_mode'     => 'all',
				'past_mode'       => 'all',
				'past_n'          => 20,
				'past_since'      => '',
				'title'           => '',
			]
		);
	}

	/**
	 * The webhook shared secret.
	 */
	public static function webhook_secret(): string {
		return (string) get_option( self::SECRET, '' );
	}
}
