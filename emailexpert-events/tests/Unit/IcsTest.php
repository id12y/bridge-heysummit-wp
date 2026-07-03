<?php
/**
 * ICS generation: RFC 5545 escaping, folding and document structure.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Frontend\Ics
 */
final class IcsTest extends TestCase {

	private function make_talk(): int {
		$speaker = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Jane Sender',
			]
		);

		return wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Deliverability; 2026, and beyond',
				'meta_input'  => [
					'_eex_heysummit_id' => '9001',
					'_eex_starts_at'    => '2026-08-01T15:00:00Z',
					'_eex_ends_at'      => '2026-08-01T16:00:00Z',
					'_eex_speaker_ids'  => [ $speaker ],
				],
			]
		);
	}

	public function test_calendar_document_structure(): void {
		$ics = Ics::calendar( [ $this->make_talk() ], 'Test Calendar' );

		$this->assertStringStartsWith( "BEGIN:VCALENDAR\r\n", $ics );
		$this->assertStringEndsWith( "END:VCALENDAR\r\n", $ics );
		$this->assertStringContainsString( 'VERSION:2.0', $ics );
		$this->assertStringContainsString( 'BEGIN:VEVENT', $ics );
		$this->assertStringContainsString( 'DTSTART:20260801T150000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260801T160000Z', $ics );
		$this->assertStringContainsString( 'UID:eex-talk-9001@example.test', $ics );
		// Commas and semicolons in the title are escaped.
		$this->assertStringContainsString( 'Deliverability\\; 2026\\, and beyond', $ics );
		// Speakers land in the description.
		$this->assertStringContainsString( 'Jane Sender', $ics );

		// Every line respects the 75-octet limit.
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
		}
	}

	public function test_missing_end_time_defaults_to_one_hour(): void {
		$talk = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Open ended',
				'meta_input'  => [ '_eex_starts_at' => '2026-08-01T15:00:00Z' ],
			]
		);

		$lines = Ics::vevent( $talk );
		$this->assertContains( 'DTEND:20260801T160000Z', $lines );
	}

	public function test_untimed_talk_produces_no_vevent(): void {
		$talk = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'No time',
			]
		);

		$this->assertSame( [], Ics::vevent( $talk ) );
	}

	public function test_folding_long_lines(): void {
		$folded = Ics::fold( 'SUMMARY:' . str_repeat( 'a', 200 ) );
		$lines  = explode( "\r\n", $folded );

		$this->assertGreaterThan( 1, count( $lines ) );
		foreach ( $lines as $index => $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ) );
			if ( $index > 0 ) {
				$this->assertStringStartsWith( ' ', $line, 'continuation lines start with a space' );
			}
		}

		// Unfolding restores the original content.
		$this->assertSame( 'SUMMARY:' . str_repeat( 'a', 200 ), str_replace( "\r\n ", '', $folded ) );
	}

	public function test_escape(): void {
		$this->assertSame( 'a\\\\b\\;c\\,d\\ne', Ics::escape( "a\\b;c,d\ne" ) );
	}

	public function test_google_url(): void {
		$url = Ics::google_url(
			[
				'title'     => 'My Session',
				'starts_at' => '2026-08-01T15:00:00Z',
				'ends_at'   => '2026-08-01T16:00:00Z',
				'permalink' => 'https://example.test/sessions/my-session/',
			]
		);

		$this->assertStringContainsString( 'calendar.google.com', $url );
		$this->assertStringContainsString( '20260801T150000Z%2F20260801T160000Z', $url );
	}
}
