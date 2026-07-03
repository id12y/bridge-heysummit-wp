<?php
/**
 * Activation and deactivation routines.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Creates tables, generates the webhook secret and schedules cron on
 * activation; unschedules cron on deactivation.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		self::create_tables();

		if ( '' === Options::webhook_secret() ) {
			update_option( Options::SECRET, wp_generate_password( 40, false, false ), false );
		}

		Scheduler::schedule( (string) Options::setting( 'frequency' ) );

		if ( ! wp_next_scheduled( 'eex_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'eex_daily_maintenance' );
		}

		// Register CPTs so flush_rewrite_rules knows about them.
		( new \Emailexpert\Events\PostTypes\PostTypes() )->register_types();
		( new \Emailexpert\Events\PostTypes\Taxonomies() )->register_taxonomies();
		flush_rewrite_rules();

		update_option( 'eex_db_version', EEX_VERSION, false );
	}

	/**
	 * Run on plugin deactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'eex_sync_cron' );
		wp_clear_scheduled_hook( 'eex_daily_maintenance' );
		wp_clear_scheduled_hook( 'eex_sync_continue' );
		flush_rewrite_rules();
	}

	/**
	 * Create or update the custom tables.
	 */
	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}eex_log (
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
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}eex_attribution (
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
				PRIMARY KEY  (id),
				KEY event_hs_id (event_hs_id),
				KEY email_hash (email_hash),
				KEY status (status),
				KEY created_at (created_at)
			) {$charset};"
		);
	}
}
