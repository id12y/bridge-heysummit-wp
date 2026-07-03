<?php
/**
 * Test connection AJAX: testing the key as typed, save-on-success.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Admin\Ajax;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Admin\Ajax
 */
final class AjaxConnectionTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['connection'], $_POST['api_key'] );
		parent::tearDown();
	}

	/**
	 * Invoke the handler and capture the emulated JSON exit.
	 *
	 * @param array<string,string> $post POST fields.
	 */
	private function run_test_connection( array $post ): \EEX_Test_Ajax_Exit {
		$_POST = $post + [ 'nonce' => 'n' ];

		try {
			( new Ajax() )->test_connection();
			$this->fail( 'wp_send_json_* must terminate the handler' );
		} catch ( \EEX_Test_Ajax_Exit $response ) {
			return $response;
		}
	}

	/**
	 * The API answers success for key "good", 401 otherwise.
	 */
	private function mock_api(): void {
		$this->mock_http(
			static function ( $url, $args ) {
				if ( 'Token good' !== (string) ( $args['headers']['Authorization'] ?? '' ) ) {
					return self::json_response( [ 'detail' => 'Invalid token.' ], 401 );
				}

				return self::json_response(
					[
						'count'   => 2,
						'results' => [ [ 'id' => 101, 'title' => 'Hub' ], [ 'id' => 102, 'title' => 'Other' ] ], // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					]
				);
			}
		);
	}

	public function test_fresh_install_tests_the_typed_key_and_saves_it_on_success(): void {
		// The exact reported scenario: no saved connection, key pasted,
		// Test connection clicked.
		$this->mock_api();

		$response = $this->run_test_connection(
			[
				'connection' => '',
				'api_key'    => 'good',
			]
		);

		$this->assertTrue( $response->success );
		$this->assertStringContainsString( 'Connection succeeded. 2 event(s) visible.', $response->payload['message'] );
		$this->assertStringContainsString( 'The key has been saved.', $response->payload['message'] );

		// A connection now exists with the verified key…
		$connections = Options::connections();
		$this->assertCount( 1, $connections );
		$this->assertSame( 'good', $connections[0]['api_key'] );
		$this->assertSame( $response->payload['connection'], $connections[0]['id'] );

		// …and discovery ran and stored its report under the new ID.
		$this->assertNotEmpty( \Emailexpert\Events\Api\Discovery::stored_report( (string) $connections[0]['id'] ) );
	}

	public function test_a_failing_key_is_reported_with_detail_and_never_stored(): void {
		$this->mock_api();

		$response = $this->run_test_connection(
			[
				'connection' => '',
				'api_key'    => 'wrong',
			]
		);

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'invalid or lacks access', $response->payload['message'] );
		$this->assertStringContainsString( 'Invalid token.', $response->payload['message'], "the API's own reason is shown" );
		$this->assertSame( [], Options::connections(), 'a key that does not authenticate is never saved' );
	}

	public function test_no_key_anywhere_gets_an_instructive_message(): void {
		$response = $this->run_test_connection( [ 'connection' => '' ] );

		$this->assertFalse( $response->success );
		$this->assertStringContainsString( 'paste your HeySummit API key', $response->payload['message'] );
	}

	public function test_existing_connection_tests_stored_key_when_nothing_is_typed(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'good',
				],
			]
		);
		$this->mock_api();

		$response = $this->run_test_connection( [ 'connection' => 'c1' ] );

		$this->assertTrue( $response->success );
		$this->assertStringNotContainsString( 'has been saved', $response->payload['message'], 'nothing was re-saved' );
	}

	public function test_typing_a_new_key_over_an_existing_connection_replaces_it_on_success(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'old-key',
				],
			]
		);
		$this->mock_api();

		$response = $this->run_test_connection(
			[
				'connection' => 'c1',
				'api_key'    => 'good',
			]
		);

		$this->assertTrue( $response->success );
		$connections = Options::connections();
		$this->assertCount( 1, $connections, 'updated in place, not duplicated' );
		$this->assertSame( 'good', $connections[0]['api_key'] );
		$this->assertSame( 'c1', $connections[0]['id'] );
	}
}
