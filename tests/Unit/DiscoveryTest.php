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

	public function test_write_schema_keeps_type_required_and_choices(): void {
		$this->mock_http( function ( $url, $args ) {
			if ( 'OPTIONS' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
				return self::json_response(
					[
						'actions' => [
							'POST' => [
								'email'                     => [ 'type' => 'email', 'required' => true ],
								'communication_preferences' => [ 'type' => 'boolean', 'required' => false ],
								'registration_status'       => [
									'type'    => 'choice',
									'choices' => [
										[ 'value' => 'complete', 'display_name' => 'Complete' ],
										[ 'value' => 'pending', 'display_name' => 'Pending' ],
									],
								],
							],
						],
					]
				);
			}

			if ( str_contains( (string) $url, 'events/' ) && ! str_contains( (string) $url, '/attendees' ) ) {
				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub', 'event_url' => 'https://x.example/', 'is_live' => false ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$client = new HeySummitClient( 'k', 'conn1' );
		Discovery::run( $client, 'conn1' );

		// The diagnostics can now say WHAT to send, not just that the
		// field exists — and the request builder reads the same answer.
		$field = Discovery::write_field( 'conn1', 'write:attendees', 'communication_preferences' );
		$this->assertSame( 'boolean', $field['type'] );
		$this->assertFalse( $field['required'] );

		$status = Discovery::write_field( 'conn1', 'write:attendees', 'registration_status' );
		$this->assertSame( 'choice', $status['type'] );
		$this->assertSame( [ 'complete', 'pending' ], $status['choices'] );

		$email = Discovery::write_field( 'conn1', 'write:attendees', 'email' );
		$this->assertTrue( $email['required'] );
	}

	public function test_write_field_tolerates_legacy_bare_type_snapshots(): void {
		// Reports stored by builds before 1.37 kept a bare type string.
		update_option( 'eex_discovery_conn1', [ 'write:attendees' => [ 'found' => [ 'communication_preferences' => 'boolean' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$field = Discovery::write_field( 'conn1', 'write:attendees', 'communication_preferences' );

		$this->assertSame( 'boolean', $field['type'] );
		$this->assertSame( [], $field['choices'] );

		// And nothing stored answers empty, never an error.
		$this->assertSame( '', Discovery::write_field( 'conn9', 'write:attendees', 'communication_preferences' )['type'] );
	}

	public function test_api_error_is_recorded_not_fatal(): void {
		$this->mock_http( fn() => self::json_response( [], 500 ) );

		$client = new HeySummitClient( 'k', 'conn1' );
		$report = Discovery::run( $client, 'conn1' );

		$this->assertNotSame( '', $report['events']['error'] );
	}
}
