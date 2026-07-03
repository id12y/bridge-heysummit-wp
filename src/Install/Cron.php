<?php
/**
 * Central cron-hook inventory and clearing.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Every hook the plugin ever schedules, in one place, so deactivation,
 * uninstall and mode switches cannot silently miss one. Clearing uses
 * wp_unschedule_hook(), which removes events regardless of their args —
 * wp_clear_scheduled_hook() without args only removes no-arg events, and
 * almost every queued job here carries args.
 */
final class Cron {

	/**
	 * Recurring schedules.
	 */
	public const RECURRING = [ 'eex_sync_cron', 'eex_daily_maintenance', 'eex_weekly_digest' ];

	/**
	 * Queued single-event jobs (all carry args).
	 */
	public const JOBS = [
		'eex_sync_continue',
		'eex_async_sync',
		'eex_process_webhook',
		'eex_abandonment_check',
		'eex_relay_deliver',
		'eex_woo_push',
		'eex_accounts_push',
		'eex_accounts_backfill',
	];

	/**
	 * Hooks stopped by a switch to Lite: everything content- and
	 * webhook-driven. WooCommerce push jobs survive — the bridge works
	 * identically in both modes — and daily maintenance follows the tables.
	 */
	public const FULL_ONLY = [
		'eex_sync_cron',
		'eex_sync_continue',
		'eex_async_sync',
		'eex_weekly_digest',
		'eex_process_webhook',
		'eex_abandonment_check',
		'eex_relay_deliver',
		'eex_accounts_push',
		'eex_accounts_backfill',
	];

	/**
	 * Unschedule a set of hooks, args or not.
	 *
	 * @param string[] $hooks Hook names.
	 */
	public static function unschedule( array $hooks ): void {
		foreach ( $hooks as $hook ) {
			if ( function_exists( 'wp_unschedule_hook' ) ) {
				wp_unschedule_hook( $hook );
			} else {
				wp_clear_scheduled_hook( $hook );
			}
		}
	}

	/**
	 * Unschedule everything the plugin ever schedules.
	 */
	public static function unschedule_all(): void {
		self::unschedule( array_merge( self::RECURRING, self::JOBS ) );
	}
}
