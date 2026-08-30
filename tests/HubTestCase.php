<?php
/**
 * Shared fixture for Hub API tests.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests;

use Emailexpert\Events\Hub\Credentials;
use Emailexpert\Events\Hub\Schema;
use Emailexpert\Events\Hub\Sweeper;
use Emailexpert\Events\Options;
use WP_REST_Request;

/**
 * Boots the Hub against the stub layer: fake CRM classes, Hub tables in
 * the fake wpdb, feature switch on, HTTPS on.
 */
abstract class HubTestCase extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		require_once __DIR__ . '/hub-crm-stubs.php';

		$GLOBALS['eex_test_is_ssl'] = true;
		$_SERVER['REMOTE_ADDR']     = '203.0.113.10';

		Options::update_settings( [ 'hub_enabled' => 1 ] );
		Schema::ensure();
	}

	/**
	 * Create a credential and return its raw token.
	 */
	protected function create_token( string $label = 'test' ): string {
		return ( new Credentials() )->create( $label )['token'];
	}

	/**
	 * A request with (or without) a bearer token.
	 *
	 * @param array<string,mixed> $params Query params.
	 * @param string|null         $token  Bearer token; null = no auth header.
	 */
	protected function request( array $params = [], ?string $token = null ): WP_REST_Request {
		$headers = null !== $token ? [ 'Authorization' => 'Bearer ' . $token ] : [];

		return new WP_REST_Request( $params, [], $headers );
	}

	/**
	 * Run one full sweep.
	 *
	 * @return array<string,int>
	 */
	protected function sweep(): array {
		return ( new Sweeper() )->run();
	}
}
