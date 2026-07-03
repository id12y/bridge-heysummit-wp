<?php
/**
 * Activation and deactivation routines.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Activation does the minimum: register CPTs and taxonomies (so rewrites
 * flush correctly), seed the fixed terms, write the single autoloaded
 * settings option, and offer the setup wizard via a dismissible notice.
 * No tables (created on demand, see Tables), no cron (scheduled when the
 * first event is enabled), no redirect.
 */
final class Activator {

	/**
	 * Run on plugin activation.
	 */
	public static function activate(): void {
		$types = new \Emailexpert\Events\PostTypes\PostTypes();
		$types->register_types();

		$taxonomies = new \Emailexpert\Events\PostTypes\Taxonomies();
		$taxonomies->register_taxonomies();
		$taxonomies->seed_terms();

		flush_rewrite_rules();

		// The one small autoloaded settings option.
		if ( false === get_option( Options::SETTINGS ) ) {
			update_option( Options::SETTINGS, Options::defaults(), true );
		}

		// Offer (never force) the setup wizard.
		if ( ! get_option( 'eex_wizard_done' ) ) {
			update_option( 'eex_wizard_notice', 1, false );
		}

		update_option( 'eex_version', EEX_VERSION, false );
	}

	/**
	 * Run on plugin deactivation: unschedule everything.
	 */
	public static function deactivate(): void {
		foreach ( [ 'eex_sync_cron', 'eex_daily_maintenance', 'eex_sync_continue', 'eex_async_sync', 'eex_weekly_digest' ] as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		flush_rewrite_rules();
	}
}
