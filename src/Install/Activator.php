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
	 *
	 * In Lite mode activation touches exactly one option — the settings
	 * option — and nothing else: no post types, no terms, no rewrite flush,
	 * no tables, no cron. A fresh install (mode not yet chosen) takes the
	 * Full-shaped path; wizard step 0 undoes it when Lite is chosen
	 * (docs/decisions.md D34).
	 */
	public static function activate(): void {
		// The one small autoloaded settings option.
		if ( false === get_option( Options::SETTINGS ) ) {
			update_option( Options::SETTINGS, Options::defaults(), true );
		}

		if ( Options::is_lite() ) {
			// Version tracking rides inside the settings option so the
			// footprint stays at a single option.
			Options::update_settings( [ 'version' => EEX_VERSION ] );

			return;
		}

		$types = new \Emailexpert\Events\PostTypes\PostTypes();
		$types->register_types();

		$taxonomies = new \Emailexpert\Events\PostTypes\Taxonomies();
		$taxonomies->register_taxonomies();
		$taxonomies->seed_terms();

		flush_rewrite_rules();

		// Offer (never force) the setup wizard.
		if ( ! get_option( 'eex_wizard_done' ) ) {
			update_option( 'eex_wizard_notice', 1, false );
		}

		update_option( 'eex_version', EEX_VERSION, false );
	}

	/**
	 * Run on plugin deactivation: unschedule everything and drop the live
	 * cache (transients are all Lite keeps).
	 */
	public static function deactivate(): void {
		Cron::unschedule_all();

		\Emailexpert\Events\Data\LiveCache::flush();
		delete_transient( 'eex_lite_log' );

		flush_rewrite_rules();
	}
}
