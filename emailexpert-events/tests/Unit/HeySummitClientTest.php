<?php
/**
 * Client behaviour: pagination, retries, auth failures.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Api\HeySummitClient
 */
final class HeySummitClientTest extends TestCase {

	public function test_get_decodes_json(): void {
		$this->mock_http( fn() => self::json_response( [ 'count' => 1, 'results' => [ [ 'id' => 7 ] ] ] ) );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get( 'events/' );

		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['count'] );
	}

	public function test_get_sends_token_header_and_never_logs_key(): void {
		$seen_headers = [];
		$this->mock_http( function ( $url, $args ) use ( &$seen_headers ) {
			$seen_headers = $args['headers'];

			return self::json_response( [ 'results' => [] ] );
		} );

		$client = new HeySummitClient( 'SECRETKEY123', 'c1' );
		$client->get( 'events/' );

		$this->assertSame( 'Token SECRETKEY123', $seen_headers['Authorization'] );

		global $wpdb;
		$logged = json_encode( $wpdb->tables['wp_eex_log'] ?? [] ); // phpcs:ignore
		$this->assertStringNotContainsString( 'SECRETKEY123', $logged );
	}

	public function test_pagination_follows_next_until_exhausted(): void {
		$calls = 0;
		$this->mock_http( function ( $url ) use ( &$calls ) {
			++$calls;
			if ( str_contains( $url, 'page=2' ) ) {
				return self::json_response(
					[
						'count'   => 3,
						'next'    => null,
						'results' => [ [ 'id' => 3 ] ],
					]
				);
			}

			return self::json_response(
				[
					'count'   => 3,
					'next'    => HeySummitClient::BASE_URL . 'talks/?page=2',
					'results' => [ [ 'id' => 1 ], [ 'id' => 2 ] ],
				]
			);
		} );

		$client  = new HeySummitClient( 'k', 'c1' );
		$results = $client->get_all( 'talks/' );

		$this->assertSame( 2, $calls );
		$this->assertCount( 3, $results );
		$this->assertSame( 3, $results[2]['id'] );
	}

	public function test_pagination_cap_stops_runaway_collections(): void {
		add_filter( 'eex_max_pages', static fn() => 3 );

		$calls = 0;
		$this->mock_http( function () use ( &$calls ) {
			++$calls;

			return self::json_response(
				[
					'next'    => HeySummitClient::BASE_URL . 'talks/?page=' . ( $calls + 1 ),
					'results' => [ [ 'id' => $calls ] ],
				]
			);
		} );

		$client  = new HeySummitClient( 'k', 'c1' );
		$results = $client->get_all( 'talks/' );

		$this->assertSame( 3, $calls );
		$this->assertCount( 3, $results );
	}

	public function test_never_follows_next_link_off_the_api_host(): void {
		$this->mock_http( fn() => self::json_response(
			[
				'next'    => 'https://evil.example/steal?page=2',
				'results' => [ [ 'id' => 1 ] ],
			]
		) );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get_all( 'talks/' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'eex_pagination', $result->get_error_code() );
	}

	public function test_retries_twice_on_5xx_then_fails(): void {
		$calls = 0;
		$this->mock_http( function () use ( &$calls ) {
			++$calls;

			return self::json_response( [], 502 );
		} );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get( 'events/' );

		$this->assertSame( 3, $calls );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'eex_unreachable', $result->get_error_code() );
	}

	public function test_5xx_then_success_recovers(): void {
		$calls = 0;
		$this->mock_http( function () use ( &$calls ) {
			++$calls;

			return 1 === $calls
				? self::json_response( [], 500 )
				: self::json_response( [ 'results' => [ [ 'id' => 9 ] ] ] );
		} );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get( 'events/' );

		$this->assertSame( 2, $calls );
		$this->assertIsArray( $result );
	}

	public function test_no_retry_on_4xx(): void {
		$calls = 0;
		$this->mock_http( function () use ( &$calls ) {
			++$calls;

			return self::json_response( [], 404 );
		} );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get( 'events/1234/' );

		$this->assertSame( 1, $calls );
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'eex_http', $result->get_error_code() );
	}

	public function test_401_maps_to_auth_error(): void {
		$this->mock_http( fn() => self::json_response( [ 'detail' => 'Invalid token.' ], 401 ) );

		$client = new HeySummitClient( 'bad', 'c1' );
		$result = $client->get( 'events/' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'eex_auth', $result->get_error_code() );
	}

	public function test_invalid_json_is_an_error(): void {
		$this->mock_http( fn() => [
			'response' => [ 'code' => 200 ],
			'body'     => '<html>not json</html>',
		] );

		$client = new HeySummitClient( 'k', 'c1' );
		$result = $client->get( 'events/' );

		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'eex_json', $result->get_error_code() );
	}
}
