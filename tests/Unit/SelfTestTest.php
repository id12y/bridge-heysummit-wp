<?php
/**
 * The admin self-test engine: every check, both modes, pass and fail paths.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Admin\SelfTest;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Admin\SelfTest
 */
final class SelfTestTest extends TestCase {

	/**
	 * Find one check row by ID.
	 *
	 * @param array<int,array<string,string>> $rows Check rows.
	 * @param string                          $id   Check ID.
	 * @return array<string,string>
	 */
	private function row( array $rows, string $id ): array {
		foreach ( $rows as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}

		$this->fail( sprintf( 'check "%s" missing from the results', $id ) );
	}

	/**
	 * A Lite install with one keyed connection and one display event.
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
				'version'     => EEX_VERSION,
			]
		);
		Repositories::reset();
	}

	/**
	 * Mock the API: events, talks, tickets, coupons, and the generator.
	 *
	 * @param bool $generator_works The checkout-link POST succeeds.
	 */
	private function mock_api( bool $generator_works = true ): void {
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		$this->mock_http(
			static function ( $url, $args ) use ( $future, $generator_works ) {
				$url = (string) $url;

				if ( 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
					return $generator_works
						? self::json_response( [ 'checkout_link' => 'https://hub.example.com/checkout/ticket/9001-abc/' ] ) // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						: self::json_response( [ 'detail' => 'Unknown coupon.' ], 400 );
				}

				if ( str_contains( $url, 'coupons/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'          => 71,
									'coupon_code' => 'SUMMER20',
									'title'       => 'Summer promo',
									'is_active'   => true,
								],
							],
						]
					);
				}

				if ( str_contains( $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'Free pass',
									'is_paid'       => 'false',
									'checkout_link' => 'https://hub.example.com/checkout/ticket/9001-xyz/',
									'prices'        => '[{"id": 501, "title": "Guest", "price": "0.00"}]',
								],
							],
						]
					);
				}

				if ( str_contains( $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'    => 501,
									'title' => 'Live session',
									'date'  => $future,
									'event' => 101,
								],
							],
						]
					);
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);
	}

	public function test_unconfigured_install_fails_the_connection_check(): void {
		$rows = SelfTest::checks( false );

		$this->assertSame( 'fail', $this->row( $rows, 'connection' )['status'] );
		$this->assertSame( 'warn', $this->row( $rows, 'events_chosen' )['status'] );
	}

	public function test_configured_lite_install_passes_the_cheap_checks(): void {
		$this->go_lite();

		$rows = SelfTest::checks( false );

		foreach ( [ 'connection', 'events_chosen', 'persistence', 'upgrade', 'allowlist' ] as $id ) {
			$this->assertSame( 'pass', $this->row( $rows, $id )['status'], $id . ' should pass' );
		}

		// Cheap tier never probes: no display check, no API rows, no HTTP.
		foreach ( $rows as $row ) {
			$this->assertStringStartsNotWith( 'api_', $row['id'], 'no API probe without an explicit run' );
		}
	}

	public function test_version_mismatch_is_flagged_until_the_upgrade_runs(): void {
		$this->go_lite();
		Options::update_settings( [ 'version' => '0.0.1' ] );

		$this->assertSame( 'warn', $this->row( SelfTest::checks( false ), 'upgrade' )['status'] );
	}

	public function test_the_allowlist_check_covers_all_four_registration_writes(): void {
		$this->go_lite();

		$row = $this->row( SelfTest::checks( false ), 'allowlist' );

		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'session attach', $row['detail'], 'the newest write is part of the guarantee' );
	}

	public function test_full_probe_exercises_events_tickets_coupons_and_the_generator(): void {
		$this->go_lite();
		$this->mock_api();

		$rows = SelfTest::checks( true );

		$this->assertSame( 'pass', $this->row( $rows, 'api_events_c1' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'api_tickets_101' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'api_coupons_101' )['status'] );
		$this->assertSame( 'pass', $this->row( $rows, 'api_generator_101' )['status'], 'the generator is exercised through the real coupon path' );
		$this->assertSame( 'pass', $this->row( $rows, 'display' )['status'], 'the display pipeline diagnosis rides along' );
	}

	public function test_a_dead_generator_warns_but_never_fails_the_run(): void {
		$this->go_lite();
		$this->mock_api( false );

		$row = $this->row( SelfTest::checks( true ), 'api_generator_101' );

		$this->assertSame( 'warn', $row['status'], 'deep links degrade to the plain checkout — a warning, not an outage' );
	}

	public function test_an_unreachable_api_fails_the_events_probe_with_the_reason(): void {
		$this->go_lite();
		$this->mock_http( static fn() => new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );

		$row = $this->row( SelfTest::checks( true ), 'api_events_c1' );

		$this->assertSame( 'fail', $row['status'] );
		$this->assertStringContainsString( 'cURL error 28', $row['detail'], 'the transport reason reaches the operator' );
	}

	public function test_site_health_registers_in_lite_where_no_test_existed(): void {
		$this->go_lite();

		$selftest = new SelfTest();
		$tests    = $selftest->register_site_health( [ 'direct' => [] ] );

		$this->assertArrayHasKey( 'eex_integration', $tests['direct'] );

		$result = $selftest->site_health_test();
		$this->assertSame( 'good', $result['status'], 'a healthy configured install reads good' );
	}

	public function test_site_health_goes_critical_on_a_failing_check(): void {
		// No connections at all: the connection check fails.
		$result = ( new SelfTest() )->site_health_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'emailexpert-events-health', $result['description'], 'points at the full-check page' );
	}

	public function test_a_stored_run_is_timestamped_and_replayable(): void {
		$this->go_lite();
		$this->mock_api();

		$this->assertSame( '', SelfTest::last_run()['at'], 'no run recorded yet' );

		SelfTest::store_run();

		$stored = SelfTest::last_run();
		$this->assertNotSame( '', $stored['at'] );
		$this->assertNotEmpty( $stored['results'] );
		$this->assertSame( 'pass', $this->row( $stored['results'], 'api_events_c1' )['status'], 'probe results are stored for the page' );
	}
}
