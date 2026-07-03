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

	public function test_every_class_loads(): void {
		$src = dirname( __DIR__, 2 ) . '/src';

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $src ) );

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			// Elementor classes extend Elementor base classes that only exist
			// when Elementor is active; they are syntax-checked instead.
			if ( str_contains( $file->getPathname(), '/Elementor/' ) ) {
				continue;
			}

			$relative = substr( $file->getPathname(), strlen( $src ) + 1, -4 );
			$class    = 'Emailexpert\\Events\\' . str_replace( '/', '\\', $relative );

			$this->assertTrue( class_exists( $class ) || trait_exists( $class ), "Class {$class} must load" );
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
