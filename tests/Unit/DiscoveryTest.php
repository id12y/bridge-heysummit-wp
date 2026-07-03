<?php
/**
 * Runtime discovery diagnostic.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Api\Discovery;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Api\Discovery
 */
final class DiscoveryTest extends TestCase {

	public function test_reports_missing_and_unmapped_fields(): void {
		$this->mock_http( function ( $url ) {
			if ( str_contains( $url, 'events/' ) ) {
				// 'title' missing (required -> warning), 'surprise' unmapped.
				return self::json_response(
					[
						'results' => [
							[
								'id'       => 5,
								'surprise' => 'x',
								'is_live'  => 'yes', // wrong type: expected bool.
							],
						],
					]
				);
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$client = new HeySummitClient( 'k', 'conn1' );
		$report = Discovery::run( $client, 'conn1' );

		$this->assertContains( 'title', $report['events']['missing'] );
		$this->assertContains( 'surprise', $report['events']['unmapped'] );
		$this->assertArrayHasKey( 'is_live', $report['events']['type_mismatch'] );
		$this->assertSame( 'bool', $report['events']['type_mismatch']['is_live']['expected'] );

		// Optional missing fields are not warnings.
		$this->assertNotContains( 'event_url', $report['events']['missing'] );

		// Stored for the diagnostics panel.
		$this->assertSame( $report, Discovery::stored_report( 'conn1' ) );
	}

	public function test_never_stores_field_values(): void {
		$this->mock_http( fn( $url ) => str_contains( $url, 'attendees/' )
			? self::json_response( [ 'results' => [ [ 'id' => 1, 'email' => 'private@person.example' ] ] ] )
			: self::json_response( [ 'results' => [] ] ) );

		$client = new HeySummitClient( 'k', 'conn1' );
		$report = Discovery::run( $client, 'conn1' );

		$this->assertStringNotContainsString( 'private@person.example', json_encode( $report ) ); // phpcs:ignore
		$this->assertSame( 'string', $report['attendees']['found']['email'] );
	}

	public function test_api_error_is_recorded_not_fatal(): void {
		$this->mock_http( fn() => self::json_response( [], 500 ) );

		$client = new HeySummitClient( 'k', 'conn1' );
		$report = Discovery::run( $client, 'conn1' );

		$this->assertNotSame( '', $report['events']['error'] );
	}
}
