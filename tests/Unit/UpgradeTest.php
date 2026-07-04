<?php
/**
 * Runtime upgrade detection: a version change flushes the caches once.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Install\Upgrade;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Install\Upgrade
 */
final class UpgradeTest extends TestCase {

	public function test_version_change_flushes_display_and_live_caches(): void {
		Options::update_settings( [ 'version' => '0.9.0' ] );
		$display = (int) get_option( 'eex_cache_generation', 0 );
		$live    = (int) get_option( 'eex_live_generation', 0 );

		Upgrade::check();

		$this->assertSame( EEX_VERSION, Options::setting( 'version' ), 'the new version is stored' );
		$this->assertGreaterThan( $display, (int) get_option( 'eex_cache_generation', 0 ), 'display fragments are invalidated' );
		$this->assertGreaterThan( $live, (int) get_option( 'eex_live_generation', 0 ), 'live data is invalidated' );
	}

	public function test_matching_version_is_a_no_op(): void {
		Options::update_settings( [ 'version' => EEX_VERSION ] );
		$display = (int) get_option( 'eex_cache_generation', 0 );

		Upgrade::check();

		$this->assertSame( $display, (int) get_option( 'eex_cache_generation', 0 ) );
	}
}
