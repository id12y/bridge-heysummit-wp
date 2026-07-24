<?php
/**
 * The self-only my-schedule lookup.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Rest\MyScheduleController;
use Emailexpert\Events\Tests\TestCase;
use WP_REST_Request;

/**
 * GET /eex/v1/my-schedule answers only about the AUTHENTICATED user's
 * email — the request carries no email at all, so it cannot be used to
 * probe other people's registration state.
 */
final class MyScheduleControllerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_connection_id' => 'c1',
					'_eex_event_url'     => 'https://summit.example.com/',
				],
			]
		);
		update_option(
			'eex_connections',
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
	 * A my-schedule request for one event.
	 *
	 * @param string $event Event ID.
	 */
	private function request( string $event = '101' ): WP_REST_Request {
		return new WP_REST_Request( [ 'event' => $event ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_a_logged_in_member_gets_their_own_schedule(): void {
		eex_test_create_user( 'pat', 'pat@example.org' );

		$queried = [];
		$this->mock_http(
			static function ( $url, $args ) use ( &$queried ) {
				if ( str_contains( (string) $url, 'attendees/' ) ) {
					$queried[] = (string) $url;

					return self::json_response(
						[
							'results' => [
								[
									'id'    => 8123,
									'email' => 'pat@example.org',
									'talks' => [ 7001, 7002 ],
								],
							],
						]
					);
				}

				return null;
			}
		);

		$data = ( new MyScheduleController() )->lookup( $this->request() )->get_data();

		$this->assertTrue( $data['registered'] );
		$this->assertSame( [ '7001', '7002' ], $data['talks'] );

		// The email sent to HeySummit is the account's own — the request
		// itself never carried one.
		$this->assertStringContainsString( 'email=pat%40example.org', (string) $queried[0] );

		// Second call answers from the transient: no second API roundtrip.
		( new MyScheduleController() )->lookup( $this->request() );
		$this->assertCount( 1, $queried, 'the lookup is cached per user and event' );
	}

	public function test_an_anonymous_visitor_gets_nothing(): void {
		// No test user: wp_get_current_user() is the anonymous user. (On a
		// live site the permission_callback already rejects with 401; this
		// asserts the second line of defence inside the handler.)
		$called = false;
		$this->mock_http(
			static function () use ( &$called ) {
				$called = true;

				return self::json_response( [ 'results' => [] ] );
			}
		);

		$data = ( new MyScheduleController() )->lookup( $this->request() )->get_data();

		$this->assertFalse( $data['registered'] );
		$this->assertSame( [], $data['talks'] );
		$this->assertFalse( $called, 'no API call is made for an anonymous visitor' );
	}

	public function test_an_unconfigured_event_is_refused_without_a_lookup(): void {
		eex_test_create_user( 'pat', 'pat@example.org' );

		$called = false;
		$this->mock_http(
			static function () use ( &$called ) {
				$called = true;

				return self::json_response( [ 'results' => [] ] );
			}
		);

		$data = ( new MyScheduleController() )->lookup( $this->request( '999999' ) )->get_data();

		$this->assertFalse( $data['registered'] );
		$this->assertFalse( $called, 'only events this site is configured for are ever queried' );
	}
}
