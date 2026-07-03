<?php
/**
 * On-demand table creation.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

defined( 'ABSPATH' ) || exit;

/**
 * The custom tables are created lazily: the log table on first write, the
 * attribution table when webhooks are first enabled (with a write-time
 * guard as belt and braces). Creation is guarded by a stored schema version
 * plus a per-request static, so it is never attempted per request.
 */
final class Tables {

	/**
	 * Bump when a table definition changes; dbDelta reconciles.
	 */
	public const LOG_VERSION         = 1;
	public const ATTRIBUTION_VERSION = 2; // v2: order_id column for Woo-originated rows.

	private const OPTION = 'eex_schema_versions';

	/**
	 * Per-request guard: table name => true once ensured.
	 *
	 * @var array<string,bool>
	 */
	private static array $ensured = [];

	/**
	 * Ensure the log table exists at the current schema version.
	 */
	public static function ensure_log(): void {
		self::ensure( 'log', self::LOG_VERSION );
	}

	/**
	 * Ensure the attribution table exists at the current schema version.
	 */
	public static function ensure_attribution(): void {
		self::ensure( 'attribution', self::ATTRIBUTION_VERSION );
	}

	/**
	 * Whether a table has been created (without creating it).
	 *
	 * @param string $table 'log' or 'attribution'.
	 */
	public static function exists( string $table ): bool {
		$versions = (array) get_option( self::OPTION, [] );

		return (int) ( $versions[ $table ] ?? 0 ) > 0;
	}

	/**
	 * Create or upgrade one table when the stored version is behind.
	 *
	 * @param string $table   'log' or 'attribution'.
	 * @param int    $version Target schema version.
	 */
	private static function ensure( string $table, int $version ): void {
		if ( ! empty( self::$ensured[ $table ] ) ) {
			return;
		}
		self::$ensured[ $table ] = true;

		$versions = (array) get_option( self::OPTION, [] );

		if ( (int) ( $versions[ $table ] ?? 0 ) >= $version ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( self::schema( $table ) );

		$versions[ $table ] = $version;
		update_option( self::OPTION, $versions, false );

		// The tables need daily retention pruning; schedule it with the
		// first table and not before.
		if ( ! wp_next_scheduled( 'eex_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eex_daily_maintenance' );
		}
	}

	/**
	 * The CREATE TABLE statement for a table.
	 *
	 * @param string $table 'log' or 'attribution'.
	 */
	private static function schema( string $table ): string {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		if ( 'log' === $table ) {
			return "CREATE TABLE {$wpdb->prefix}eex_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				context varchar(20) NOT NULL DEFAULT 'sync',
				level varchar(10) NOT NULL DEFAULT 'info',
				message text NOT NULL,
				data longtext NULL,
				PRIMARY KEY  (id),
				KEY context (context),
				KEY level (level),
				KEY created_at (created_at)
			) {$charset};";
		}

		return "CREATE TABLE {$wpdb->prefix}eex_attribution (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			event_hs_id varchar(64) NOT NULL DEFAULT '',
			attendee_hs_id varchar(64) NOT NULL DEFAULT '',
			email_hash char(64) NOT NULL DEFAULT '',
			status varchar(20) NOT NULL DEFAULT 'started',
			utm_source varchar(191) NOT NULL DEFAULT '',
			utm_medium varchar(191) NOT NULL DEFAULT '',
			utm_campaign varchar(191) NOT NULL DEFAULT '',
			referer_domain varchar(191) NOT NULL DEFAULT '',
			affiliate_email varchar(191) NOT NULL DEFAULT '',
			ticket_name varchar(191) NOT NULL DEFAULT '',
			amount_gross varchar(32) NOT NULL DEFAULT '',
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY event_hs_id (event_hs_id),
			KEY attendee_hs_id (attendee_hs_id),
			KEY email_hash (email_hash),
			KEY status (status),
			KEY created_at (created_at)
		) {$charset};";
	}
}
