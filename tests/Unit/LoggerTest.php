<?php
/**
 * Logger behaviour, particularly email redaction.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Logging\Logger
 */
final class LoggerTest extends TestCase {

	public function test_log_inserts_row(): void {
		$id = Logger::info( 'sync', 'hello', [ 'a' => 1 ] );

		$this->assertSame( 1, $id );

		global $wpdb;
		$row = $wpdb->tables['wp_eex_log'][0];
		$this->assertSame( 'sync', $row['context'] );
		$this->assertSame( 'info', $row['level'] );
		$this->assertSame( 'hello', $row['message'] );
	}

	public function test_emails_in_data_are_redacted_but_correlatable(): void {
		Logger::info( 'webhook', 'receipt', [ 'attendee' => [ 'email' => 'Jane.Doe@Example.com' ] ] );

		global $wpdb;
		$stored = (string) $wpdb->tables['wp_eex_log'][0]['data'];

		$this->assertStringNotContainsString( 'Jane.Doe@', $stored );
		$this->assertStringContainsString( '@Example.com', $stored );

		// The hash prefix must match the full-address hash used for attribution lookups.
		$expected = substr( hash( 'sha256', 'jane.doe@example.com' ), 0, 12 );
		$this->assertStringContainsString( $expected, $stored );
	}

	public function test_redaction_walks_nested_structures(): void {
		$out = Logger::redact(
			[
				'text'   => 'contact a@b.co or c@d.io',
				'nested' => [ [ 'email' => 'deep@deep.example' ] ],
				'number' => 42,
			]
		);

		$this->assertStringNotContainsString( 'a@b.co', $out['text'] );
		$this->assertStringNotContainsString( 'c@d.io', $out['text'] );
		$this->assertStringNotContainsString( 'deep@deep.example', $out['nested'][0]['email'] );
		$this->assertSame( 42, $out['number'] );
	}
}
