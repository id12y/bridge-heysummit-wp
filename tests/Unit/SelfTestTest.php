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

	/**
	 * The flattening behind the session-image check. This is the part with
	 * real logic — the probe around it is one API call.
	 */
	public function test_image_fields_finds_nested_and_ignores_non_imagery(): void {
		$fields = SelfTest::image_fields(
			[
				'id'                         => 777,
				'title'                      => 'A session',
				// Not imagery, even though it is a URL.
				'talk_url'                   => 'https://summit.example.com/talks/a/',
				'custom_promo_image_primary' => 'https://cdn.example.org/uploads/cropped.png',
				// Nested under an imagery key: every URL inside counts, even
				// though "full_size" does not read as imagery by itself.
				'thumbnails'                 => [
					'full_size' => 'https://cdn.example.org/thumbnails/original_full_size.png',
					'small'     => 'https://cdn.example.org/thumbnails/small.png',
				],
				// Imagery-named but empty, so there is nothing to offer.
				'primary_image'              => '',
			]
		);

		$this->assertSame(
			[
				'custom_promo_image_primary'  => 'https://cdn.example.org/uploads/cropped.png',
				'thumbnails.full_size'        => 'https://cdn.example.org/thumbnails/original_full_size.png',
				'thumbnails.small'            => 'https://cdn.example.org/thumbnails/small.png',
			],
			$fields
		);

		$this->assertArrayNotHasKey( 'talk_url', $fields, 'a URL is not imagery just because it is a URL' );
		$this->assertArrayNotHasKey( 'primary_image', $fields, 'an empty field offers nothing to render' );
	}

	/**
	 * The regression this check shipped with: an empty 200 from the first
	 * route style was accepted as the answer, so the probe reported "no
	 * image field" while the cards were rendering images perfectly well.
	 * A route that filters on the wrong parameter name returns a cheerful
	 * empty page; the sessions are under one of the other styles.
	 */
	public function test_session_image_check_falls_through_an_empty_route(): void {
		$this->go_lite();

		$this->mock_http(
			static function ( $url ) {
				$url = (string) $url;

				// Flat styles answer 200 with nothing at all.
				if ( false !== strpos( $url, 'talks/?' ) || false !== strpos( $url, 'talks/&' ) ) {
					return self::json_response(
						[
							'count'   => 0,
							'next'    => null,
							'results' => [],
						]
					);
				}

				if ( false !== strpos( $url, '/talks/' ) ) {
					return self::json_response(
						[
							'count'   => 1,
							'next'    => null,
							'results' => [
								[
									'id'                         => 777,
									'title'                      => 'A session',
									'talk_url'                   => 'https://summit.example.com/talks/a/',
									'custom_promo_image_primary' => 'https://cdn.example.org/uploads/cropped.png',
									'thumbnails'                 => [ 'full_size' => 'https://cdn.example.org/thumbnails/original_full_size.png' ],
								],
							],
						]
					);
				}

				return self::json_response(
					[
						'count'   => 1,
						'next'    => null,
						'results' => [ [ 'id' => 101 ] ],
					]
				);
			}
		);

		$row = $this->row( SelfTest::checks( true ), 'api_talk_images_101' );

		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'Found on session 1 of the first page.', $row['detail'] );
		$this->assertStringContainsString( 'custom_promo_image_primary [RENDERED]', $row['detail'] );
		$this->assertStringContainsString( 'thumbnails.full_size', $row['detail'], 'the uncropped sibling must be visible beside the one we render' );
		$this->assertStringNotContainsString( 'talk_url', $row['detail'] );
	}

	/**
	 * The case that actually bit: an event with hundreds of sessions whose
	 * first list page carries no imagery, while the session on screen does.
	 * Scanning page one and reporting "none carried an image field" says
	 * nothing about the artwork in question, so the check asks for the
	 * rendered session by ID.
	 */
	public function test_session_image_check_inspects_the_session_on_screen(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				// The rendered session, asked for by ID: this one has imagery.
				if ( false !== strpos( $url, '/talks/777/' ) ) {
					return self::json_response(
						[
							'id'                         => 777,
							'title'                      => 'The session on screen',
							'starts_at'                  => $future,
							'custom_promo_image_primary' => 'https://cdn.example.org/uploads/cropped.png',
							'thumbnails'                 => [ 'full_size' => 'https://cdn.example.org/thumbnails/original_full_size.png' ],
						]
					);
				}

				// The list page: the same session, carrying no imagery at all.
				if ( false !== strpos( $url, '/talks/' ) || false !== strpos( $url, 'talks/?' ) ) {
					return self::json_response(
						[
							'count'   => 1,
							'next'    => null,
							'results' => [
								[
									'id'        => 777,
									'title'     => 'The session on screen',
									'starts_at' => $future,
								],
							],
						]
					);
				}

				return self::json_response(
					[
						'count'   => 1,
						'next'    => null,
						'results' => [ [ 'id' => 101 ] ],
					]
				);
			}
		);

		$row = $this->row( SelfTest::checks( true ), 'api_talk_images_101' );

		$this->assertSame( 'pass', $row['status'] );
		$this->assertStringContainsString( 'Next session on screen', $row['detail'] );
		$this->assertStringContainsString( 'custom_promo_image_primary [RENDERED]', $row['detail'] );
		$this->assertStringContainsString( 'thumbnails.full_size', $row['detail'] );
	}
}
