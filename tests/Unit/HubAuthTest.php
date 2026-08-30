<?php
/**
 * Default-deny authentication on every Hub route.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Hub\Auth;
use Emailexpert\Events\Hub\Credentials;
use Emailexpert\Events\Hub\Settings;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\HubTestCase;

/**
 * The permission callback rejects everything except a valid, active,
 * header-borne bearer token over HTTPS — and even then only while the
 * feature switch is on.
 *
 * @covers \Emailexpert\Events\Hub\Auth
 * @covers \Emailexpert\Events\Hub\Credentials
 */
final class HubAuthTest extends HubTestCase {

	public function test_disabled_feature_switch_denies_everything(): void {
		$token = $this->create_token();
		Options::update_settings( [ 'hub_enabled' => 0 ] );

		$result = ( new Auth() )->permit( $this->request( [], $token ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 404, $result->get_error_data()['status'] );
	}

	public function test_unauthenticated_requests_are_rejected(): void {
		$this->create_token();

		$result = ( new Auth() )->permit( $this->request() );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertNull( Auth::credential() );
	}

	public function test_an_invalid_token_is_rejected(): void {
		$this->create_token();

		$result = ( new Auth() )->permit( $this->request( [], 'eexhub_' . str_repeat( 'f', 64 ) ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_a_token_in_a_query_parameter_is_never_accepted(): void {
		$token = $this->create_token();

		// Same token, but as a parameter instead of a header.
		$result = ( new Auth() )->permit( $this->request( [ 'token' => $token ] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_a_valid_token_is_accepted_and_identified(): void {
		$token = $this->create_token( 'community-hub' );

		$result = ( new Auth() )->permit( $this->request( [], $token ) );

		$this->assertTrue( $result );
		$this->assertSame( 'community-hub', Auth::credential()['label'] );
	}

	public function test_the_alternate_header_works_too(): void {
		$token   = $this->create_token();
		$request = new \WP_REST_Request( [], [], [ 'X-EEX-Hub-Token' => $token ] );

		$this->assertTrue( ( new Auth() )->permit( $request ) );
	}

	public function test_a_revoked_token_is_rejected_immediately(): void {
		$credentials = new Credentials();
		$created     = $credentials->create( 'to-revoke' );

		$this->assertTrue( ( new Auth() )->permit( $this->request( [], $created['token'] ) ) );

		$credentials->revoke( $created['id'] );

		$result = ( new Auth() )->permit( $this->request( [], $created['token'] ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_plain_http_is_rejected(): void {
		$token = $this->create_token();

		$GLOBALS['eex_test_is_ssl'] = false;

		$result = ( new Auth() )->permit( $this->request( [], $token ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_the_ip_allowlist_is_additional_never_sufficient(): void {
		$token = $this->create_token();
		Settings::update( [ 'ip_allowlist' => [ '198.51.100.7' ] ] );

		// Right IP, no token: still rejected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$no_token               = ( new Auth() )->permit( $this->request() );
		$this->assertInstanceOf( \WP_Error::class, $no_token );
		$this->assertSame( 401, $no_token->get_error_data()['status'] );

		// Valid token, wrong IP: rejected.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
		$wrong_ip               = ( new Auth() )->permit( $this->request( [], $token ) );
		$this->assertInstanceOf( \WP_Error::class, $wrong_ip );
		$this->assertSame( 403, $wrong_ip->get_error_data()['status'] );

		// Valid token, allowed IP: accepted.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertTrue( ( new Auth() )->permit( $this->request( [], $token ) ) );
	}

	public function test_the_per_credential_rate_limit_returns_429(): void {
		$token = $this->create_token();
		Settings::update( [ 'rate_limit' => 2 ] );

		$auth = new Auth();

		$this->assertTrue( $auth->permit( $this->request( [], $token ) ) );
		$this->assertTrue( $auth->permit( $this->request( [], $token ) ) );

		$third = $auth->permit( $this->request( [], $token ) );

		$this->assertInstanceOf( \WP_Error::class, $third );
		$this->assertSame( 429, $third->get_error_data()['status'] );
	}

	public function test_tokens_are_stored_only_as_hashes_with_a_hint(): void {
		$created = ( new Credentials() )->create( 'hash-check' );

		global $wpdb;
		$rows = $wpdb->tables['wp_eex_hub_credentials'];

		$this->assertCount( 1, $rows );
		$this->assertArrayNotHasKey( 'token', $rows[0] );
		$this->assertNotSame( $created['token'], $rows[0]['token_hash'] );
		$this->assertSame( 64, strlen( $rows[0]['token_hash'] ) );
		$this->assertSame( substr( $created['token'], -4 ), $rows[0]['token_hint'] );

		// The listing never exposes hashes either.
		$listed = ( new Credentials() )->all()[0];
		$this->assertArrayNotHasKey( 'token_hash', $listed );
	}
}
