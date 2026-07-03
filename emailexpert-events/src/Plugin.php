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
		];

		if ( is_admin() ) {
			$services[] = new Admin\SettingsPage();
			$services[] = new Admin\Ajax();
			$services[] = new Admin\Notices();
			$services[] = new Admin\AttributionReport();
			$services[] = new PostTypes\SyncModeUi();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$services[] = new Cli\Commands();
		}

		// Elementor module: loads nothing unless Elementor announces itself.
		add_action( 'elementor/init', [ Elementor\Module::class, 'register' ] );

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
