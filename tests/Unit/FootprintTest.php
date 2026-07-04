<?php
/**
 * Lazy initialisation: minimal activation, tables and cron on demand.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Install\Activator;
use Emailexpert\Events\Install\Tables;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Scheduler;
use Emailexpert\Events\Tests\TestCase;
use Emailexpert\Events\Webhooks\Attribution;

/**
 * @covers \Emailexpert\Events\Install\Activator
 * @covers \Emailexpert\Events\Install\Tables
 */
final class FootprintTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['eex_test_dbdelta'] = [];

		// Tables uses a per-request static; reset it between tests.
		$reflection = new \ReflectionProperty( Tables::class, 'ensured' );
		$reflection->setValue( null, [] );
	}

	public function test_activation_creates_no_tables_and_schedules_no_cron(): void {
		Activator::activate();

		global $wpdb;
		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'], 'no dbDelta at activation' );
		$this->assertArrayNotHasKey( 'wp_eex_log', $wpdb->tables );
		$this->assertArrayNotHasKey( 'wp_eex_attribution', $wpdb->tables );
		$this->assertSame( [], \EEX_Test_State::$scheduled, 'no cron at activation' );

		// The minimum it does do: settings option, tier terms, rewrites,
		// wizard offer. Series terms are site-specific brands and are NOT
		// seeded unless the eex_seed_series_terms filter provides them.
		$this->assertIsArray( get_option( Options::SETTINGS ) );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'no brand names baked in' );
		$this->assertNotEmpty( get_terms( [ 'taxonomy' => 'eex_sponsor_tier' ] ), 'generic tier terms still seed' );
		$this->assertTrue( $GLOBALS['eex_test_rewrites_flushed'] ?? false );
		$this->assertSame( 1, get_option( 'eex_wizard_notice' ) );
	}

	public function test_log_table_created_on_first_write_only(): void {
		Logger::info( 'sync', 'first write' );

		$this->assertSame( [ 'wp_eex_log' ], $GLOBALS['eex_test_dbdelta'] );
		$this->assertTrue( Tables::exists( 'log' ) );

		Logger::info( 'sync', 'second write' );
		$this->assertCount( 1, $GLOBALS['eex_test_dbdelta'], 'creation never re-attempted' );

		// A fresh request (static reset) still skips creation via the stored version.
		$reflection = new \ReflectionProperty( Tables::class, 'ensured' );
		$reflection->setValue( null, [] );
		Logger::info( 'sync', 'third write' );
		$this->assertCount( 1, $GLOBALS['eex_test_dbdelta'], 'stored schema version short-circuits' );
	}

	public function test_attribution_table_created_on_first_insert(): void {
		$this->assertFalse( Tables::exists( 'attribution' ) );

		Attribution::insert( [ 'hs_id' => '1', 'email_hash' => hash( 'sha256', 'a@b.c' ) ], '101', 'started' );

		$this->assertTrue( Tables::exists( 'attribution' ) );
		$this->assertContains( 'wp_eex_attribution', $GLOBALS['eex_test_dbdelta'] );
	}

	public function test_first_table_schedules_daily_maintenance(): void {
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_daily_maintenance' ) );

		Logger::info( 'sync', 'write' );

		$this->assertNotFalse( wp_next_scheduled( 'eex_daily_maintenance' ) );
	}

	public function test_sync_cron_follows_enabled_events(): void {
		// No events enabled: nothing scheduled.
		Scheduler::sync_schedule_state();
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ) );

		// Enabling an event schedules the sync.
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		Scheduler::sync_schedule_state();
		$this->assertNotFalse( wp_next_scheduled( 'eex_sync_cron' ) );

		// Disabling the last event unschedules.
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 0 ] ] );
		Scheduler::sync_schedule_state();
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ) );
	}

	public function test_deactivation_unschedules_everything(): void {
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		Scheduler::sync_schedule_state();
		Logger::info( 'sync', 'creates maintenance schedule' );

		// Queued jobs carry args; clearing must remove them regardless.
		wp_schedule_single_event( time() + 60, 'eex_woo_push', [ 501, 1000 ] );
		wp_schedule_single_event( time() + 60, 'eex_accounts_push', [ 7, '101', 'r1', 1 ] );
		wp_schedule_single_event( time() + 60, 'eex_process_webhook', [ [ 'action' => 'x' ] ] );

		Activator::deactivate();

		$this->assertSame( [], \EEX_Test_State::$scheduled );
	}

	public function test_switching_to_lite_clears_full_only_jobs_but_keeps_woo_pushes(): void {
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		Scheduler::sync_schedule_state();
		wp_schedule_single_event( time() + 60, 'eex_accounts_push', [ 7, '101', 'r1', 1 ] );
		wp_schedule_single_event( time() + 60, 'eex_woo_push', [ 501, 1000 ] );

		\Emailexpert\Events\Install\Mode::switch_to_lite( false );

		$hooks = array_column( \EEX_Test_State::$scheduled, 'hook' );
		$this->assertNotContains( 'eex_sync_cron', $hooks );
		$this->assertNotContains( 'eex_accounts_push', $hooks, 'accounts is Full-only' );
		$this->assertContains( 'eex_woo_push', $hooks, 'the Woo bridge works in both modes: its queue survives' );
	}

	public function test_webhook_secret_generated_on_demand_not_at_activation(): void {
		Activator::activate();
		$this->assertSame( '', Options::webhook_secret() );

		$secret = Options::ensure_webhook_secret();
		$this->assertGreaterThanOrEqual( 40, strlen( $secret ) );
		$this->assertSame( $secret, Options::ensure_webhook_secret(), 'stable once generated' );
	}
}
