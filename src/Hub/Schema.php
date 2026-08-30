<?php
/**
 * Hub API tables.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Three additive tables, all owned by the Hub layer — the CRM plugin's
 * schema is never touched, so rollback is DROP TABLE plus the feature
 * switch. Creation happens only from explicit admin/CLI actions (enable,
 * upgrade), never during a front-end request; every Hub read path fails
 * closed when a table is missing.
 *
 * - eex_hub_contacts: the identity registry. Maps a CRM subscriber id to
 *   an immutable opaque UUID with a monotonic revision; a deleted contact
 *   keeps only a minimal tombstone row (uuid, revision, deleted_at) with
 *   the subscriber id cleared — no personal data, so legally required
 *   erasure is never blocked.
 * - eex_hub_journal: an append-only change journal; its auto-increment
 *   seq is the replay-safe cursor for /hub/changes. Rows carry no
 *   personal data (uuid, op, revision, timestamp only).
 * - eex_hub_credentials: Hub service credentials, stored as HMAC hashes
 *   only, revocable via a status flag.
 */
final class Schema {

	public const VERSION = 1;

	private const OPTION = 'eex_hub_schema_versions';

	/**
	 * Whether the Hub tables exist (without creating them).
	 */
	public static function ready(): bool {
		$versions = (array) get_option( self::OPTION, [] );

		return (int) ( $versions['hub'] ?? 0 ) >= self::VERSION;
	}

	/**
	 * Create or upgrade all Hub tables. Explicit contexts only (CLI
	 * enable, admin upgrade) — never called from a front-end request.
	 */
	public static function ensure(): void {
		$versions = (array) get_option( self::OPTION, [] );

		if ( (int) ( $versions['hub'] ?? 0 ) >= self::VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}

		$versions['hub'] = self::VERSION;
		update_option( self::OPTION, $versions, false );
	}

	/**
	 * The registry table name.
	 */
	public static function contacts_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eex_hub_contacts';
	}

	/**
	 * The journal table name.
	 */
	public static function journal_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eex_hub_journal';
	}

	/**
	 * The credentials table name.
	 */
	public static function credentials_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'eex_hub_credentials';
	}

	/**
	 * CREATE TABLE statements.
	 *
	 * @return array<int,string>
	 */
	private static function statements(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();

		return [
			'CREATE TABLE ' . self::contacts_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscriber_id bigint(20) unsigned NULL,
				uuid char(36) NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				projection_hash char(64) NOT NULL DEFAULT '',
				state varchar(10) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				deleted_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY uuid (uuid),
				UNIQUE KEY subscriber_id (subscriber_id),
				KEY state (state)
			) {$charset};",
			'CREATE TABLE ' . self::journal_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				uuid char(36) NOT NULL,
				object_type varchar(20) NOT NULL DEFAULT 'contact',
				op varchar(10) NOT NULL DEFAULT 'upsert',
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY uuid (uuid)
			) {$charset};",
			'CREATE TABLE ' . self::credentials_table() . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				label varchar(100) NOT NULL DEFAULT '',
				token_hash char(64) NOT NULL,
				token_hint varchar(4) NOT NULL DEFAULT '',
				status varchar(10) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				last_used_at datetime NULL,
				revoked_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash)
			) {$charset};",
		];
	}
}
