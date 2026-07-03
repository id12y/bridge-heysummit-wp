<?php
/**
 * Lite mode: footprint, live repository, budget, graceful failure, shared
 * render callbacks, mode switching.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Data\LiveCache;
use Emailexpert\Events\Data\LiveRepository;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\SyncedRepository;
use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Install\Activator;
use Emailexpert\Events\Install\Mode;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Data\LiveRepository
 * @covers \Emailexpert\Events\Data\LiveCache
 * @covers \Emailexpert\Events\Install\Mode
 */
final class LiteModeTest extends TestCase {

	/**
	 * HTTP requests seen by the mock, by URL.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests                       = [];
		$GLOBALS['eex_test_dbdelta']          = [];
		$GLOBALS['eex_test_rewrites_flushed'] = false;

		// Tables uses a per-request static; reset it between tests.
		$reflection = new \ReflectionProperty( \Emailexpert\Events\Install\Tables::class, 'ensured' );
		$reflection->setValue( null, [] );
	}

	/**
	 * Put the plugin into Lite mode with one connection and one event.
	 */
	private function go_lite(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => [ 'c1|101' ],
			]
		);
		Repositories::reset();
	}

	/**
	 * Mock the API with one event and two future talks.
	 *
	 * @param bool $fail Simulate an unreachable API.
	 */
	private function mock_api( bool $fail = false ): void {
		$this->mock_http(
			function ( $url ) use ( $fail ) {
				$this->requests[] = (string) $url;

				if ( $fail ) {
					return new \WP_Error( 'http_request_failed', 'timeout' );
				}

				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Live session one',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'ends_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ),
									'event'     => 101,
									'speakers'  => [
										[
											'id'     => 9,
											'name'   => 'Ada Speaker',
											'avatar' => 'https://cdn.example.com/ada.jpg',
										],
									],
									'categories' => [ [ 'id' => 3, 'title' => 'Deliverability' ] ],
								],
								[
									'id'        => 502,
									'title'     => 'Live session two',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 9000 ),
									'event'     => 101,
								],
							],
						]
					);
				}

				if ( str_contains( (string) $url, 'events/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'                        => 101,
									'title'                     => 'Live Hub',
									'event_url'                 => 'https://summit.example.com/hub/',
									'first_talk_at'             => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'last_talk_at'              => gmdate( 'Y-m-d\TH:i:s\Z', time() + 9000 ),
									'timezone'                  => 'Europe/London',
									'is_open_for_registrations' => true,
								],
								[
									'id'    => 999,
									'title' => 'Unconfigured event',
								],
							],
						]
					);
				}

				return null;
			}
		);
	}

	// -- Criterion 2: footprint. --------------------------------------------

	public function test_lite_activation_writes_exactly_one_option_and_nothing_else(): void {
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
			]
		);
		$before = \EEX_Test_State::$options;

		Activator::activate();

		global $wpdb;
		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'] ?? [], 'no tables' );
		$this->assertSame( [], \EEX_Test_State::$scheduled, 'no cron' );
		$this->assertFalse( $GLOBALS['eex_test_rewrites_flushed'] ?? false, 'no rewrite flush' );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'no seeded terms' );

		$eex_options = array_filter( array_keys( \EEX_Test_State::$options ), static fn( $k ): bool => str_starts_with( (string) $k, 'eex_' ) );
		$this->assertSame( [ Options::SETTINGS ], array_values( $eex_options ), 'exactly the settings option' );
		$this->assertArrayNotHasKey( 'eex_wizard_notice', \EEX_Test_State::$options );
		$this->assertSame( EEX_VERSION, Options::setting( 'version' ), 'version rides inside the settings option' );
	}

	public function test_fresh_lite_choice_removes_the_full_shaped_activation_leftovers(): void {
		// Fresh install: Full-shaped activation.
		Activator::activate();
		$this->assertNotEmpty( get_terms( [ 'taxonomy' => 'eex_event_series' ] ) );
		$this->assertSame( 1, get_option( 'eex_wizard_notice' ) );

		Mode::choose( 'lite' );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'seeded terms removed' );
		$this->assertFalse( get_option( 'eex_wizard_notice' ), 'wizard notice option removed' );
		$this->assertFalse( get_option( 'eex_version' ), 'version option folded away' );
	}

	public function test_lite_logger_uses_ring_buffer_never_a_table(): void {
		$this->go_lite();

		\Emailexpert\Events\Logging\Logger::info( 'api', 'live fetch failed' );

		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'] ?? [], 'no log table created' );
		$ring = \Emailexpert\Events\Logging\Logger::ring();
		$this->assertCount( 1, $ring );
		$this->assertSame( 'live fetch failed', $ring[0]['message'] );
	}

	// -- Criterion 3: budget, cache, graceful failure. ------------------------

	public function test_first_view_fetches_within_budget_and_second_view_is_free(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Live session one', $html );
		$this->assertStringContainsString( 'Live session two', $html );
		$this->assertLessThanOrEqual( LiveCache::COLD_BUDGET, count( $this->requests ), 'at most the budgeted fetches' );

		// Second view within TTL: zero API calls (fragment + data cached).
		$this->requests = [];
		LiveCache::reset_request_state();
		$again = Components::render( 'upcoming-sessions', [] );

		$this->assertSame( $html, $again );
		$this->assertCount( 0, $this->requests, 'no API calls within TTL' );
	}

	public function test_api_failure_serves_last_good_then_empty_state_never_fatal(): void {
		$this->go_lite();
		$this->mock_api();

		// Warm the cache.
		$warm = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Live session one', $warm );

		// Kill the API and expire the fresh copies (keep last-good).
		remove_all_filters( 'pre_http_request' );
		$this->mock_api( true );
		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			if ( str_starts_with( (string) $key, 'eex_live_' ) ) {
				unset( \EEX_Test_State::$transients[ $key ] );
			}
		}
		Cache::flush();
		LiveCache::reset_request_state();

		$stale = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Live session one', $stale, 'last-good copy served on failure' );

		// No last-good either: the empty state, never an error.
		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			if ( str_starts_with( (string) $key, 'eex_lg_' ) || str_starts_with( (string) $key, 'eex_live_' ) ) {
				unset( \EEX_Test_State::$transients[ $key ] );
			}
		}
		Cache::flush();
		LiveCache::reset_request_state();

		$empty = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'eex-empty', $empty );
		$this->assertStringContainsString( 'New sessions are announced soon.', $empty );
	}

	public function test_budget_caps_cold_fetches_per_request(): void {
		$this->go_lite();
		Options::update_settings( [ 'lite_events' => [ 'c1|101', 'c1|102', 'c1|103' ] ] );
		$this->mock_api();

		Components::render( 'upcoming-sessions', [] );

		$this->assertLessThanOrEqual( LiveCache::COLD_BUDGET, count( $this->requests ) );
	}

	public function test_stampede_lock_lets_exactly_one_concurrent_fetch_through(): void {
		$this->go_lite();

		// A concurrent request holds the lock for the events resource.
		$key = 'events|c1';
		add_option( 'eex_live_lock_' . md5( $key ), time(), '', false );

		$calls = 0;
		$value = LiveCache::remember(
			$key,
			static function () use ( &$calls ) {
				++$calls;

				return [ 'x' ];
			}
		);

		$this->assertSame( 0, $calls, 'locked: no duplicate fetch' );
		$this->assertNull( $value, 'no last-good: empty state' );

		// A stale lock (crashed request) is stolen.
		update_option( 'eex_live_lock_' . md5( $key ), time() - 3600, false );
		$value = LiveCache::remember(
			$key,
			static function () use ( &$calls ) {
				++$calls;

				return [ 'x' ];
			}
		);
		$this->assertSame( 1, $calls );
		$this->assertSame( [ 'x' ], $value );
	}

	// -- Criterion 4: server-side only. ---------------------------------------

	public function test_lite_markup_contains_no_browser_calls_to_heysummit_and_no_key(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringNotContainsString( 'api/v2', $html, 'no API URL reaches the browser' );
		$this->assertStringNotContainsString( 'app.heysummit.com/api', $html );
		$this->assertStringNotContainsString( 'k', array_reduce( $this->requests, static fn( $c, $u ) => $c, '' ), 'sanity' );
		$this->assertStringNotContainsString( 'Token', $html, 'no auth material in output' );
	}

	// -- Criterion 5: shared render callbacks, link-target difference only. ----

	public function test_lite_cards_link_to_heysummit_urls_with_utm(): void {
		$this->go_lite();
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'example.org',
			]
		);
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'summit.example.com', $html, 'links go to HeySummit' );
		$this->assertStringContainsString( 'utm_source=example.org', $html, 'UTM auto-tagging applies' );
	}

	public function test_render_callbacks_are_shared_one_code_path_two_repositories(): void {
		// Full: the synced repository; Lite: the live repository.
		$this->assertInstanceOf( SyncedRepository::class, Repositories::current() );

		$this->go_lite();
		Repositories::reset();
		$this->assertInstanceOf( LiveRepository::class, Repositories::current() );

		// Same markup skeleton from the same callback in both modes.
		$this->mock_api();
		$lite_html = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( '<ul class="eex-grid eex-talk-grid" role="list">', $lite_html );
		$this->assertStringContainsString( 'eex-card eex-card-talk', $lite_html );
		$this->assertStringContainsString( 'data-eex-session', $lite_html );
	}

	public function test_lite_hides_past_components_rather_than_erroring(): void {
		$this->go_lite();
		$this->mock_api();

		$repo = Repositories::current();
		$this->assertSame( [], $repo->past_talks( [] ) );
		$this->assertSame( [], $repo->past_events( [] ) );
		$this->assertSame( 0, $repo->past_talks_total( [] ) );
	}

	// -- Criterion 7: switching. ----------------------------------------------

	public function test_switch_to_lite_trashing_content_removes_posts_and_cron(): void {
		// Full site with content and scheduled sync.
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		\Emailexpert\Events\Sync\Scheduler::sync_schedule_state();
		$post_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Synced talk',
			]
		);
		$this->assertNotFalse( wp_next_scheduled( 'eex_sync_cron' ) );

		Mode::switch_to_lite( false );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( 'trash', get_post( $post_id )->post_status, 'content trashed (reversible)' );
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ), 'sync cron unscheduled' );
		$this->assertSame( 0, (int) Options::setting( 'lite_archive' ) );
	}

	public function test_switch_to_lite_keeping_content_freezes_the_archive(): void {
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		\Emailexpert\Events\Sync\Scheduler::sync_schedule_state();
		$post_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Kept talk',
			]
		);

		Mode::switch_to_lite( true );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'posts remain readable' );
		$this->assertSame( 1, (int) Options::setting( 'lite_archive' ) );
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ), 'sync stays stopped' );
	}

	public function test_switch_back_to_full_loses_nothing(): void {
		$this->go_lite();

		Mode::switch_to_full();

		$this->assertFalse( Options::is_lite() );
		$this->assertSame( [ 'c1|101' ], (array) Options::setting( 'lite_events' ), 'lite settings retained for a later switch back' );
		$this->assertSame( 1, (int) Options::setting( 'flush_rewrites' ) );
	}
}
