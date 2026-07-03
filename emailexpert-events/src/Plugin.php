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
	 */
	private function register_services(): void {
		$services = [
			new PostTypes\PostTypes(),
			new PostTypes\Taxonomies(),
			new PostTypes\Meta(),
			new Sync\Scheduler(),
			new Sync\Health(),
			new Logging\Retention(),
			new Webhooks\RestController(),
			new Webhooks\Processor(),
			new Webhooks\Privacy(),
			new Rest\CounterController(),
			new Frontend\Assets(),
			new Frontend\Blocks(),
			new Frontend\Shortcodes(),
			new Frontend\TemplateLoader(),
			new Frontend\SchemaOutput(),
			new Frontend\Feeds(),
			new Frontend\IcsDownload(),
			new Frontend\CacheFlush(),
			new Frontend\PurgeIntegration(),
			new Webhooks\Relay(),
			new Admin\Digest(),
		];

		if ( is_admin() ) {
			$services[] = new Admin\SettingsPage();
			$services[] = new Admin\Wizard();
			$services[] = new Admin\BridgePage();
			$services[] = new Admin\Ajax();
			$services[] = new Admin\Notices();
			$services[] = new Admin\AttributionReport();
			$services[] = new Admin\Dashboard();
			$services[] = new Admin\ExportImport();
			$services[] = new PostTypes\SyncModeUi();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$services[] = new Cli\Commands();
		}

		// Optional modules: each loads zero code unless its host announces
		// itself (Elementor via elementor/init, WooCommerce via
		// woocommerce_loaded, MyListing via a cheap inline theme check after
		// the theme has loaded).
		add_action( 'elementor/init', [ Elementor\Module::class, 'register' ] );
		add_action( 'woocommerce_loaded', [ WooCommerce\Module::class, 'register' ] );
		add_action(
			'after_setup_theme',
			static function (): void {
				if ( class_exists( '\MyListing\App' ) || defined( 'CASE27_THEME_DIR' ) || apply_filters( 'eex_mylisting_present', false ) ) {
					MyListing\Module::register();
				}
			}
		);

		foreach ( $services as $service ) {
			$service->register();
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
