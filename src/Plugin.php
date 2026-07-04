<?php
/**
 * Plugin service bootstrap.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events;

defined( 'ABSPATH' ) || exit;

/**
 * Wires every service onto WordPress hooks. Services are small classes with
 * a register() method; nothing runs at construction time.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Boot the plugin on plugins_loaded.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		self::$instance = new self();
		self::$instance->register_services();
	}

	/**
	 * Retrieve the booted instance.
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Register all services.
	 *
	 * The mode gate reads only the autoloaded settings option. Lite loads
	 * the shared display layer and the admin surface; everything that turns
	 * HeySummit data into WordPress content (post types, sync, webhooks,
	 * feeds, attribution) stays unloaded — except the frozen-archive case,
	 * where the post types remain registered so kept content stays readable.
	 */
	private function register_services(): void {
		// A version change means new templates/CSS/JS: drop cached fragments
		// (and stale live data) so the new build shows immediately.
		Install\Upgrade::check();

		$lite = Options::is_lite();

		// Shared by both modes: the display components and their delivery.
		$services = [
			new Frontend\Assets(),
			new Frontend\Blocks(),
			new Frontend\Shortcodes(),
			new Frontend\IcsDownload(),
			new Rest\RegisterController(), // The ticket drawer's free-ticket form, both modes.
			new Logging\Retention(), // Handler only; the cron exists only once a table does.
		];

		if ( ! $lite || (bool) Options::setting( 'lite_archive' ) ) {
			// Content post types: Full mode, or Lite keeping a frozen archive.
			$services[] = new PostTypes\PostTypes();
			$services[] = new PostTypes\Taxonomies();
			$services[] = new PostTypes\Meta();
			$services[] = new Frontend\TemplateLoader();
			$services[] = new Frontend\SchemaOutput();
		}

		if ( ! $lite ) {
			$services[] = new Sync\Scheduler();
			$services[] = new Sync\Health();
			$services[] = new Webhooks\RestController();
			$services[] = new Webhooks\Processor();
			$services[] = new Webhooks\Privacy();
			$services[] = new Rest\CounterController();
			$services[] = new Frontend\Feeds();
			$services[] = new Frontend\CacheFlush();
			$services[] = new Frontend\PurgeIntegration();
			$services[] = new Webhooks\Relay();
			$services[] = new Admin\Digest();
		}

		if ( is_admin() ) {
			$services[] = new Admin\SettingsPage();
			$services[] = new Admin\Wizard();
			$services[] = new Admin\BridgePage();
			$services[] = new Admin\Ajax();
			$services[] = new Admin\Notices();
			$services[] = new Admin\Dashboard();
			$services[] = new Admin\ExportImport();

			if ( ! $lite ) {
				$services[] = new Admin\AttributionReport();
				$services[] = new PostTypes\SyncModeUi();
			}
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$services[] = new Cli\Commands();
		}

		// Accounts module: Full mode only, gated by the master toggle in the
		// autoloaded settings option — off means nothing in src/Accounts/
		// ever loads.
		if ( ! $lite && (bool) Options::setting( 'accounts_enabled' ) ) {
			Accounts\Module::register();
		}

		// Optional modules: each loads zero code unless its host announces
		// itself (Elementor via elementor/init, WooCommerce via
		// woocommerce_loaded, MyListing via a cheap inline theme check after
		// the theme has loaded). The WooCommerce bridge works identically in
		// both modes; MyListing is Full-only.
		add_action( 'elementor/init', [ Elementor\Module::class, 'register' ] );
		add_action( 'woocommerce_loaded', [ WooCommerce\Module::class, 'register' ] );

		if ( ! $lite ) {
			add_action(
				'after_setup_theme',
				static function (): void {
					if ( class_exists( '\MyListing\App' ) || defined( 'CASE27_THEME_DIR' ) || apply_filters( 'eex_mylisting_present', false ) ) {
						MyListing\Module::register();
					}
				}
			);
		}

		foreach ( $services as $service ) {
			$service->register();
		}

		// A mode switch changes which rewrite rules exist; flush once.
		if ( (bool) Options::setting( 'flush_rewrites' ) ) {
			add_action(
				'init',
				static function (): void {
					flush_rewrite_rules();
					Options::update_settings( [ 'flush_rewrites' => 0 ] );
				},
				99
			);
		}

		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'emailexpert-events', false, dirname( plugin_basename( EEX_PLUGIN_FILE ) ) . '/languages' );
	}
}
