<?php
/**
 * Boot smoke test: every non-Elementor class loads and the service wiring
 * runs without touching anything it should not.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Plugin;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Plugin
 * @covers \Emailexpert\Events\Autoloader
 */
final class BootSmokeTest extends TestCase {

	public function test_no_translations_load_before_init(): void {
		// WP 6.7+ warns when translations load before the init action, and
		// Options::defaults() / Options::connections() run from
		// plugins_loaded (Upgrade::check, service boot). A translated string
		// inside either is the field-reported "_load_textdomain_just_in_time
		// called incorrectly" notice. Grep-harness style: prove the
		// convention holds at the source level.
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Options.php' );

		preg_match( '/function defaults\(\).*?\n\t\}/s', $source, $defaults );
		$this->assertNotEmpty( $defaults, 'defaults() found' );
		$this->assertStringNotContainsString( '__(', $defaults[0], 'defaults() must not translate: it runs before init' );

		preg_match( '/function connections\(\).*?\n\t\}/s', $source, $connections );
		$this->assertNotEmpty( $connections, 'connections() found' );
		$this->assertStringNotContainsString( '__(', $connections[0], 'connections() must not translate: it can run before init' );
	}

	public function test_the_woo_consent_wording_defaults_lazily(): void {
		$this->assertSame( '', \Emailexpert\Events\Options::defaults()['woo_consent_text'], 'the stored default is empty' );
		$this->assertStringContainsString( 'Register me for the event', \Emailexpert\Events\Options::woo_consent_text(), 'the translated default resolves at read time' );

		\Emailexpert\Events\Options::update_settings( [ 'woo_consent_text' => 'Custom wording.' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'Custom wording.', \Emailexpert\Events\Options::woo_consent_text() );
	}

	public function test_every_class_loads(): void {
		$src = dirname( __DIR__, 2 ) . '/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			// Elementor classes extend Elementor base classes that only exist
			// when Elementor is active; they are syntax-checked instead. The
			// forms action likewise extends Elementor Pro's Action_Base and
			// is only autoloaded behind its registrar's class_exists guard.
			if ( str_contains( $file->getPathname(), '/Elementor/' )
				|| str_ends_with( $file->getPathname(), 'Forms/Adapters/ElementorAction.php' ) ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $src ) + 1, -4 );
			$class    = 'Emailexpert\\Events\\' . str_replace( '/', '\\', $relative );

			$this->assertTrue( class_exists( $class ) || interface_exists( $class ) || trait_exists( $class ), "Class {$class} must load" );
		}
	}

	public function test_plugin_boot_registers_services_without_side_effects(): void {
		Plugin::boot();

		$this->assertNotNull( Plugin::instance() );

		// Booting must not write options, posts or HTTP.
		$this->assertCount( 0, get_posts( [ 'post_type' => 'any', 'post_status' => 'any' ] ) );

		// Key hooks are wired.
		$this->assertArrayHasKey( 'rest_api_init', \EEX_Test_State::$filters );
		$this->assertArrayHasKey( 'init', \EEX_Test_State::$filters );
		$this->assertArrayHasKey( 'eex_sync_cron', \EEX_Test_State::$filters );
		$this->assertArrayHasKey( 'eex_process_webhook', \EEX_Test_State::$filters );
		$this->assertArrayHasKey( 'elementor/init', \EEX_Test_State::$filters );
	}
}
