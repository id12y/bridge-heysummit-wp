<?php
/**
 * Schema generation for complete and incomplete data.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Frontend\SchemaGenerator;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Frontend\SchemaGenerator
 */
final class SchemaGeneratorTest extends TestCase {

	private function make_event( array $meta, string $title = 'FORUM London' ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => $meta,
			]
		);
	}

	public function test_event_with_venue_is_business_event_with_postal_address(): void {
		$id = $this->make_event(
			[
				'_eex_heysummit_id'              => '202',
				'_eex_first_talk_at'             => '2026-09-15T08:30:00Z',
				'_eex_last_talk_at'              => '2026-09-16T17:00:00Z',
				'_eex_event_url'                 => 'https://forum.emailexpert.org',
				'_eex_is_open_for_registrations' => 1,
				'_eex_venue_name'                => 'The Brewery',
				'_eex_venue_street'              => '52 Chiswell Street',
				'_eex_venue_locality'            => 'London',
				'_eex_venue_postcode'            => 'EC1Y 4SD',
				'_eex_venue_country'             => 'GB',
			]
		);

		$schema = SchemaGenerator::event( $id );

		$this->assertSame( 'BusinessEvent', $schema['@type'] );
		$this->assertSame( 'FORUM London', $schema['name'] );
		$this->assertSame( '2026-09-15T08:30:00Z', $schema['startDate'] );
		$this->assertSame( '2026-09-16T17:00:00Z', $schema['endDate'] );
		$this->assertSame( 'https://schema.org/MixedEventAttendanceMode', $schema['eventAttendanceMode'] );
		$this->assertSame( 'emailexpert UK Ltd', $schema['organizer']['name'] );

		// Venue + virtual location.
		$this->assertCount( 2, $schema['location'] );
		$this->assertSame( 'The Brewery', $schema['location'][0]['name'] );
		$this->assertSame( 'EC1Y 4SD', $schema['location'][0]['address']['postalCode'] );

		// Offers: URL only, never a guessed price.
		$this->assertSame( 'https://forum.emailexpert.org', $schema['offers']['url'] );
		$this->assertArrayNotHasKey( 'price', $schema['offers'] );
	}

	public function test_online_event_uses_virtual_location(): void {
		$id = $this->make_event(
			[
				'_eex_first_talk_at' => '2026-08-01T15:00:00Z',
				'_eex_event_url'     => 'https://hub.emailexpert.org',
			],
			'Member Hub'
		);

		$schema = SchemaGenerator::event( $id );

		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $schema['eventAttendanceMode'] );
		$this->assertSame( 'VirtualLocation', $schema['location']['@type'] );
		$this->assertArrayNotHasKey( 'offers', $schema, 'no offers when registrations are not open' );
	}

	public function test_event_missing_required_fields_emits_nothing(): void {
		$id = $this->make_event( [], 'No dates yet' );

		$this->assertNull( SchemaGenerator::event( $id ) );
	}

	public function test_talk_schema_nests_super_event(): void {
		$event_id = $this->make_event(
			[
				'_eex_heysummit_id'  => '101',
				'_eex_first_talk_at' => '2026-08-01T15:00:00Z',
			],
			'Member Hub'
		);

		$talk_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Deliverability in 2026',
				'meta_input'  => [
					'_eex_starts_at'       => '2026-08-01T15:00:00Z',
					'_eex_ends_at'         => '2026-08-01T16:00:00Z',
					'_eex_source_event_id' => '101',
				],
			]
		);

		$schema = SchemaGenerator::talk( $talk_id );

		$this->assertSame( 'Event', $schema['@type'] );
		$this->assertSame( 'Member Hub', $schema['superEvent']['name'] );
		$this->assertSame( get_permalink( $event_id ) . '#event', $schema['superEvent']['@id'] );
	}

	public function test_video_object_for_past_talk_with_youtube_replay(): void {
		$talk_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'BIMI Workshop',
				'meta_input'  => [
					'_eex_starts_at'         => gmdate( 'Y-m-d\TH:i:s\Z', time() - 30 * DAY_IN_SECONDS ),
					'_eex_replay_url_synced' => 'https://www.youtube.com/watch?v=abc123def45',
					'_eex_description'       => 'Hands-on BIMI.',
				],
			]
		);

		$schema = SchemaGenerator::video( $talk_id );

		$this->assertSame( 'VideoObject', $schema['@type'] );
		$this->assertSame( 'BIMI Workshop', $schema['name'] );
		$this->assertSame( 'https://www.youtube.com/watch?v=abc123def45', $schema['contentUrl'] );
		$this->assertSame( 'https://www.youtube.com/embed/abc123def45', $schema['embedUrl'] );
		$this->assertSame( 'Hands-on BIMI.', $schema['description'] );
		$this->assertNotEmpty( $schema['uploadDate'] );
	}

	public function test_no_video_object_for_future_talk_or_missing_replay(): void {
		$future = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Future',
				'meta_input'  => [
					'_eex_starts_at'         => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
					'_eex_replay_url_synced' => 'https://youtu.be/xyz',
				],
			]
		);
		$no_url = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'No replay',
				'meta_input'  => [ '_eex_starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ],
			]
		);

		$this->assertNull( SchemaGenerator::video( $future ) );
		$this->assertNull( SchemaGenerator::video( $no_url ) );
	}

	public function test_manual_replay_url_wins_in_video_schema(): void {
		$talk_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Replay override',
				'meta_input'  => [
					'_eex_starts_at'         => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
					'_eex_replay_url'        => 'https://vimeo.com/123456',
					'_eex_replay_url_synced' => 'https://www.youtube.com/watch?v=synced00000',
				],
			]
		);

		$schema = SchemaGenerator::video( $talk_id );

		$this->assertSame( 'https://vimeo.com/123456', $schema['contentUrl'] );
		$this->assertSame( 'https://player.vimeo.com/video/123456', $schema['embedUrl'] );
	}

	public function test_speaker_person_schema(): void {
		$id = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Jane Sender',
				'meta_input'  => [
					'_eex_headline' => 'VP Deliverability',
					'_eex_company'  => 'Inbox Co',
					'_eex_links'    => [ 'https://linkedin.example/janesender' ],
				],
			]
		);

		$schema = SchemaGenerator::speaker( $id );

		$this->assertSame( 'Person', $schema['@type'] );
		$this->assertSame( 'VP Deliverability', $schema['jobTitle'] );
		$this->assertSame( 'Inbox Co', $schema['worksFor']['name'] );
		$this->assertSame( [ 'https://linkedin.example/janesender' ], $schema['sameAs'] );
	}

	public function test_speaker_schema_omits_empty_optionals_without_placeholders(): void {
		$id = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Minimal Speaker',
			]
		);

		$schema = SchemaGenerator::speaker( $id );

		$this->assertArrayNotHasKey( 'jobTitle', $schema );
		$this->assertArrayNotHasKey( 'worksFor', $schema );
		$this->assertArrayNotHasKey( 'image', $schema );
		$this->assertArrayNotHasKey( 'sameAs', $schema );
	}

	public function test_schema_filter_is_applied(): void {
		add_filter(
			'eex_schema_data',
			static function ( $schema ) {
				$schema['inLanguage'] = 'en-GB';

				return $schema;
			}
		);

		$id = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Filtered',
			]
		);

		$this->assertSame( 'en-GB', SchemaGenerator::speaker( $id )['inLanguage'] );
	}
}
