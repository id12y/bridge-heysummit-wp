<?php
/**
 * Base test case: resets the stub state between tests.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;

/**
 * Shared base for all unit tests.
 */
abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		\EEX_Test_State::reset();
		$GLOBALS['eex_test_actions']   = [];
		$GLOBALS['eex_test_users']     = [];
		$GLOBALS['eex_test_user_meta'] = [];

		\Emailexpert\Events\Data\Repositories::reset();
		\Emailexpert\Events\Data\LiveCache::reset_request_state();
		\Emailexpert\Events\Data\CategoryTitles::reset_request_state();
		\Emailexpert\Events\Data\EventTitles::reset_request_state();
		\Emailexpert\Events\Frontend\Components::reset_request_state();

		// Make retries instantaneous in tests.
		add_filter( 'eex_http_retry_delay', static fn() => 0 );
	}

	/**
	 * Install an HTTP mock. The handler receives ($url, $args) and returns a
	 * WP-style response array, a WP_Error, or null to fall through.
	 *
	 * @param callable $handler Handler.
	 */
	protected function mock_http( callable $handler ): void {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $handler ) {
				$result = $handler( $url, $args );

				return null === $result ? $pre : $result;
			},
			10,
			3
		);
	}

	/**
	 * Build a JSON response array.
	 *
	 * @param mixed $body   Body to encode.
	 * @param int   $status HTTP status.
	 * @return array<string,mixed>
	 */
	protected static function json_response( $body, int $status = 200 ): array {
		return [
			'response' => [ 'code' => $status ],
			'body'     => json_encode( $body ), // phpcs:ignore
		];
	}
}
