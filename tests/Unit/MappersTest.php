<?php
/**
 * Mapper output for each resource, against the fixture shapes.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Mappers\AttendeeMapper;
use Emailexpert\Events\Mappers\CategoryMapper;
use Emailexpert\Events\Mappers\EventMapper;
use Emailexpert\Events\Mappers\SpeakerMapper;
use Emailexpert\Events\Mappers\TalkMapper;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Mappers\EventMapper
 * @covers \Emailexpert\Events\Mappers\TalkMapper
 * @covers \Emailexpert\Events\Mappers\SpeakerMapper
 * @covers \Emailexpert\Events\Mappers\CategoryMapper
 * @covers \Emailexpert\Events\Mappers\AttendeeMapper
 */
final class MappersTest extends TestCase {

	/**
	 * Load a fixture's results list.
	 *
	 * @param string $name Fixture basename.
	 * @return array<int,array<string,mixed>>
	 */
	private static function fixture( string $name ): array {
		$json = file_get_contents( __DIR__ . '/../fixtures/' . $name . '.json' );

		return json_decode( (string) $json, true )['results'];
	}

	public function test_event_mapper_full_record(): void {
		$mapped = EventMapper::map( self::fixture( 'events' )[0] );

		$this->assertSame( '101', $mapped['hs_id'] );
		$this->assertSame( 'emailexpert Member Hub', $mapped['title'] );
		$this->assertSame( 'Europe/London', $mapped['timezone'] );
		$this->assertSame( '2026-01-10T14:00:00Z', $mapped['first_talk_at'] );
		$this->assertTrue( $mapped['is_evergreen'] );
		$this->assertTrue( $mapped['is_open_for_registrations'] );
		$this->assertFalse( $mapped['is_archived'] );
	}

	public function test_event_mapper_tolerates_string_ids_offsets_and_extras(): void {
		$mapped = EventMapper::map( self::fixture( 'events' )[1] );

		$this->assertSame( '202', $mapped['hs_id'] );
		// +01:00 offset normalised to UTC.
		$this->assertSame( '2026-09-15T07:30:00Z', $mapped['first_talk_at'] );
		$this->assertFalse( $mapped['is_evergreen'] );
	}

	public function test_event_mapper_rejects_record_without_id(): void {
		$this->assertNull( EventMapper::map( [ 'title' => 'No ID' ] ) );
	}

	public function test_talk_mapper_nested_speakers_and_categories(): void {
		$mapped = TalkMapper::map( self::fixture( 'talks' )[0] );

		$this->assertSame( '9001', $mapped['hs_id'] );
		$this->assertSame( '101', $mapped['event_hs_id'] );
		$this->assertSame( [ '501', '502' ], $mapped['speaker_hs_ids'] );
		$this->assertSame( [ '31' ], $mapped['category_hs_ids'] );
		$this->assertSame( 'Deliverability', $mapped['categories'][0]['title'] );
	}

	public function test_talk_mapper_event_as_object_and_scalar_categories(): void {
		$mapped = TalkMapper::map( self::fixture( 'talks' )[1] );

		$this->assertSame( '101', $mapped['event_hs_id'] );
		$this->assertSame( [ '32' ], $mapped['category_hs_ids'] );
		$this->assertSame( 'https://www.youtube.com/watch?v=abc123def45', $mapped['replay_url'] );
	}

	public function test_talk_mapper_minimal_record(): void {
		$mapped = TalkMapper::map( self::fixture( 'talks' )[2] );

		$this->assertSame( '9003', $mapped['hs_id'] );
		$this->assertSame( '101', $mapped['event_hs_id'] ); // via event_id fallback.
		$this->assertSame( '', $mapped['starts_at'] );
		$this->assertSame( [], $mapped['speaker_hs_ids'] );
	}

	public function test_bare_timestamps_are_event_local_not_utc(): void {
		// HeySummit serialises times in the EVENT's timezone without an
		// offset. 7 July, 6pm London (BST, GMT+1) is 5pm UTC — treating it
		// as UTC showed every session one hour late in summer.
		$summer = TalkMapper::map(
			[
				'id'        => 9010,
				'starts_at' => '2027-07-07T18:00:00',
				'ends_at'   => '2027-07-07T19:00:00',
			],
			'Europe/London'
		);
		$this->assertSame( '2027-07-07T17:00:00Z', $summer['starts_at'] );
		$this->assertSame( '2027-07-07T18:00:00Z', $summer['ends_at'] );

		// In winter London is GMT+0 — the same code path must not shift.
		$winter = TalkMapper::map(
			[
				'id'        => 9011,
				'starts_at' => '2027-01-07T18:00:00',
			],
			'Europe/London'
		);
		$this->assertSame( '2027-01-07T18:00:00Z', $winter['starts_at'] );

		// An explicit offset or Z is unambiguous: the hint is ignored.
		$zulu = TalkMapper::map(
			[
				'id'        => 9012,
				'starts_at' => '2027-07-07T17:00:00Z',
			],
			'Europe/London'
		);
		$this->assertSame( '2027-07-07T17:00:00Z', $zulu['starts_at'] );

		$offset = TalkMapper::map(
			[
				'id'        => 9013,
				'starts_at' => '2027-07-07T19:00:00+02:00',
			],
			'America/New_York'
		);
		$this->assertSame( '2027-07-07T17:00:00Z', $offset['starts_at'] );

		// No timezone known, or an unknown zone string: the old UTC
		// assumption stands rather than erroring.
		$no_zone = TalkMapper::map(
			[
				'id'        => 9014,
				'starts_at' => '2027-07-07T18:00:00',
			]
		);
		$this->assertSame( '2027-07-07T18:00:00Z', $no_zone['starts_at'] );

		$bad_zone = TalkMapper::map(
			[
				'id'        => 9015,
				'starts_at' => '2027-07-07T18:00:00',
			],
			'Narnia/Lantern'
		);
		$this->assertSame( '2027-07-07T18:00:00Z', $bad_zone['starts_at'] );

		// The event mapper localises its own range fields by its own zone.
		$event = EventMapper::map(
			[
				'id'            => 300,
				'timezone'      => 'Europe/London',
				'first_talk_at' => '2027-07-07T18:00:00',
			]
		);
		$this->assertSame( '2027-07-07T17:00:00Z', $event['first_talk_at'] );
	}

	public function test_speaker_mapper_string_avatar(): void {
		$mapped = SpeakerMapper::map( self::fixture( 'speakers' )[0] );

		$this->assertSame( 'Jane Sender', $mapped['name'] );
		$this->assertSame( 'https://cdn.heysummit.example/jane.jpg', $mapped['photo_url'] );
		$this->assertSame( 'jane@inbox.example', $mapped['email'] );
		$this->assertSame( [ 'https://linkedin.example/janesender' ], $mapped['links'] );
	}

	public function test_speaker_mapper_split_name_object_avatar_and_network_links(): void {
		$mapped = SpeakerMapper::map( self::fixture( 'speakers' )[1] );

		$this->assertSame( 'Sam Postmaster', $mapped['name'] );
		$this->assertSame( 'https://cdn.heysummit.example/sam.png', $mapped['photo_url'] );
		$this->assertContains( 'https://twitter.example/sampostmaster', $mapped['links'] );
	}

	public function test_category_mapper_title_or_name(): void {
		$fixtures = self::fixture( 'categories' );

		$this->assertSame( 'Deliverability', CategoryMapper::map( $fixtures[0] )['title'] );
		$this->assertSame( 'Authentication', CategoryMapper::map( $fixtures[1] )['title'] );
		$this->assertSame( '32', CategoryMapper::map( $fixtures[1] )['hs_id'] );
	}

	public function test_attendee_mapper_full_record(): void {
		$mapped = AttendeeMapper::map( self::fixture( 'attendees' )[0] );

		$this->assertSame( '7001', $mapped['hs_id'] );
		$this->assertSame( 'attendee@example.org', $mapped['email'] );
		$this->assertSame( hash( 'sha256', 'attendee@example.org' ), $mapped['email_hash'] );
		$this->assertSame( '101', $mapped['event_hs_id'] );
		$this->assertSame( 'newsletter', $mapped['utm_source'] );
		$this->assertSame( 'emailexpert.com', $mapped['referer_domain'] );
		$this->assertSame( 'Member', $mapped['ticket_name'] );
		$this->assertSame( '0.00', $mapped['amount_gross'] );
		$this->assertSame( [ '9001' ], $mapped['talk_hs_ids'] );
	}

	public function test_attendee_mapper_flat_payload_variant(): void {
		$mapped = AttendeeMapper::map(
			[
				'attendee_id' => '77',
				'email'       => 'FLAT@EXAMPLE.ORG',
				'event'       => [ 'id' => 5 ],
				'ticket_name' => 'VIP',
			]
		);

		$this->assertSame( '77', $mapped['hs_id'] );
		$this->assertSame( 'flat@example.org', $mapped['email'] );
		$this->assertSame( '5', $mapped['event_hs_id'] );
		$this->assertSame( 'VIP', $mapped['ticket_name'] );
	}

	public function test_attendee_mapper_rejects_unidentifiable_record(): void {
		$this->assertNull( AttendeeMapper::map( [ 'name' => 'ghost' ] ) );
	}

	public function test_mappers_reject_non_http_urls_from_the_api(): void {
		// Third-party URLs end up in data attributes that client code
		// assigns to href: hostile schemes must die at the mapping layer.
		$talk = TalkMapper::map(
			[
				'id'         => 1,
				'title'      => 'Hostile',
				'talk_url'   => 'javascript:alert(1)',
				'replay_url' => 'data:text/html,x',
			]
		);

		$this->assertSame( '', $talk['talk_url'] );
		$this->assertSame( '', $talk['replay_url'] );

		$event = \Emailexpert\Events\Mappers\EventMapper::map(
			[
				'id'        => 2,
				'title'     => 'Hostile hub',
				'event_url' => 'javascript:alert(1)',
			]
		);

		$this->assertSame( '', $event['event_url'] );

		// Real URLs still pass untouched.
		$ok = TalkMapper::map(
			[
				'id'       => 3,
				'title'    => 'Fine',
				'talk_url' => 'https://summit.example.com/talks/3/',
			]
		);
		$this->assertSame( 'https://summit.example.com/talks/3/', $ok['talk_url'] );
	}
}
