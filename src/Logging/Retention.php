<?php
/**
 * Daily maintenance: prune the log and attribution tables.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Logging;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Runs retention pruning on the eex_daily_maintenance cron event.
 */
final class Retention {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_daily_maintenance', [ $this, 'run' ] );
	}

	/**
	 * Prune logs (30 days) and attribution rows (configurable, default 24 months).
	 */
	public function run(): void {
		global $wpdb;

		if ( \Emailexpert\Events\Install\Tables::exists( 'log' ) ) {
			Logger::prune();
		}

		if ( \Emailexpert\Events\Install\Tables::exists( 'attribution' ) ) {
			$months = max( 1, (int) Options::setting( 'retention_months' ) );
			$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$months} months" ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->query(
				$wpdb->prepare( "DELETE FROM {$wpdb->prefix}eex_attribution WHERE created_at < %s", $cutoff )
			);
		}
	}
}
