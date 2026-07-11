<?php
/**
 * Read-only coupons fetcher: routes, caching, and the editor picker options.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Data\Coupons;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Data\Coupons
 */
final class CouponsTest extends TestCase {

	/**
	 * URLs the mock saw this test.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests = [];

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
	}

	/**
	 * Mock the coupons endpoints. Nested (events/<id>/coupons/) is the lead
	 * route; the top-level fallback answers only when $nested_status is an error.
	 *
	 * @param array<int,array<string,mixed>> $rows          Coupon rows to return.
	 * @param int                            $nested_status Status for the nested route.
	 */
	private function mock_coupons( array $rows, int $nested_status = 200 ): void {
		$this->mock_http(
			function ( $url ) use ( $rows, $nested_status ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'coupons/' ) ) {
					return null;
				}

				$this->requests[] = $url;

				$nested = str_contains( $url, 'events/101/coupons/' );

				if ( $nested && 200 !== $nested_status ) {
					return self::json_response( [ 'detail' => 'Forbidden.' ], $nested_status );
				}

				return self::json_response( [ 'results' => $rows ] );
			}
		);
	}

	public function test_raw_reads_the_nested_route_and_caches(): void {
		$this->mock_coupons(
			[
				[
					'id'          => 1,
					'coupon_code' => 'SAVE20',
					'title'       => 'Launch offer',
					'is_active'   => true,
				],
			]
		);

		$coupons = Coupons::raw( 'c1', '101' );

		$this->assertIsArray( $coupons );
		$this->assertCount( 1, $coupons );
		$this->assertSame( 'SAVE20', $coupons[0]['coupon_code'] );
		$this->assertStringContainsString( 'events/101/coupons/', $this->requests[0], 'the nested route leads' );

		// A second call is served from the 15-minute transient — no new HTTP.
		$before = count( $this->requests );
		Coupons::raw( 'c1', '101' );
		$this->assertCount( $before, $this->requests, 'the collection is cached' );
	}

	public function test_raw_falls_back_to_the_top_level_route(): void {
		// The nested route refuses (403); the top-level coupons/?event= answers.
		$this->mock_coupons(
			[
				[
					'id'          => 7,
					'coupon_code' => 'TOPLEVEL',
					'title'       => 'From the top-level route',
					'is_active'   => true,
				],
			],
			403
		);

		$coupons = Coupons::raw( 'c1', '101' );

		$this->assertIsArray( $coupons );
		$this->assertSame( 'TOPLEVEL', $coupons[0]['coupon_code'] );
		$this->assertTrue(
			(bool) array_filter( $this->requests, static fn( string $u ): bool => str_contains( $u, 'coupons/?' ) || ( str_contains( $u, 'coupons/' ) && ! str_contains( $u, 'events/101' ) ) ),
			'the top-level route was tried after the nested 403'
		);
	}

	public function test_code_options_lists_only_active_coded_coupons(): void {
		$this->mock_coupons(
			[
				[
					'id'          => 1,
					'coupon_code' => 'SAVE20',
					'title'       => 'Launch offer',
					'is_active'   => true,
				],
				[
					'id'          => 2,
					'coupon_code' => 'HIDDEN',
					'title'       => 'Retired',
					'is_active'   => false,
				],
				[
					'id'          => 3,
					'coupon_code' => '',
					'title'       => 'No code yet',
					'is_active'   => true,
				],
			]
		);

		$options = Coupons::code_options( 'c1', '101' );

		$this->assertSame(
			[
				[
					'id'    => '1',
					'code'  => 'SAVE20',
					'title' => 'Launch offer',
				],
			],
			$options,
			'inactive and codeless coupons are dropped; the rest are shaped {id,code,title}'
		);
	}

	public function test_code_options_titles_fall_back_to_description_then_code(): void {
		$this->mock_coupons(
			[
				[
					'id'          => 5,
					'coupon_code' => 'DESCONLY',
					'description' => 'Speaker promo',
				],
				[
					'id'          => 6,
					'coupon_code' => 'BARE',
				],
			]
		);

		$options = Coupons::code_options( 'c1', '101' );

		$this->assertSame( 'Speaker promo', $options[0]['title'], 'description stands in for a missing title' );
		$this->assertSame( 'BARE', $options[1]['title'], 'the code stands in when nothing else is given' );
	}

	public function test_raw_guards_missing_connection_or_event(): void {
		$this->assertInstanceOf( \WP_Error::class, Coupons::raw( '', '101' ) );
		$this->assertInstanceOf( \WP_Error::class, Coupons::raw( 'c1', '' ) );
		$this->assertInstanceOf( \WP_Error::class, Coupons::raw( 'nope', '101' ), 'an unknown connection is an error, not a fetch' );
	}
}
