<?php
/**
 * Sync engine flow: filters, toggles, orphans, dedup.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\SyncEngine;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Sync\SyncEngine
 */
final class SyncEngineTest extends TestCase {

	/**
	 * The mutable API dataset served by the HTTP mock.
	 *
	 * @var array<string,mixed>
	 */
	private array $api = [];

	protected function setUp(): void {
		parent::setUp();

		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$this->configure_event( [] );

		$this->api = [
			'event'      => [
				'id'                        => 101,
				'title'                     => 'Member Hub',
				'is_evergreen'              => true,
				'is_open_for_registrations' => true,
			],
			'categories' => [
				[
					'id'    => 31,
					'title' => 'Deliverability',
				],
				[
					'id'    => 32,
					'title' => 'Authentication',
				],
			],
			'talks'      => [
				[
					'id'         => 9001,
					'title'      => 'Talk A',
					'event'      => 101,
					'starts_at'  => '2026-08-01T15:00:00Z',
					'speakers'   => [ 501 ],
					'categories' => [ [ 'id' => 31 ] ],
				],
				[
					'id'         => 9002,
					'title'      => 'Talk B',
					'event'      => 101,
					'starts_at'  => '2026-08-02T15:00:00Z',
					'speakers'   => [ 502 ],
					'categories' => [ [ 'id' => 32 ] ],
				],
			],
			'speakers'   => [
				[
					'id'      => 501,
					'name'    => 'Jane Sender',
					'company' => 'Inbox Co',
					'email'   => 'jane@inbox.example',
					'avatar'  => 'https://cdn.example/jane.jpg',
				],
				[
					'id'      => 502,
					'name'    => 'Sam Postmaster',
					'company' => 'BigMail',
				],
			],
		];

		$this->mock_http( function ( $url ) {
			// Specific routes first: the nested talks/categories/speakers
			// URLs also contain the event-detail prefix.
			if ( str_contains( $url, 'categories/' ) ) {
				return self::json_response( [ 'results' => $this->api['categories'] ] );
			}
			if ( str_contains( $url, 'talks/' ) ) {
				return self::json_response( [ 'results' => $this->api['talks'] ] );
			}
			if ( str_contains( $url, 'speakers/' ) ) {
				return self::json_response( [ 'results' => $this->api['speakers'] ] );
			}
			if ( str_contains( $url, 'events/101/' ) ) {
				return self::json_response( $this->api['event'] );
			}

			return self::json_response( [ 'results' => [] ] );
		} );
	}

	/**
	 * Store the event sync config, merged over defaults.
	 *
	 * @param array<string,mixed> $overrides Config overrides.
	 */
	private function configure_event( array $overrides ): void {
		update_option(
			Options::SYNCED_EVENTS,
			[
				'c1|101' => array_merge(
					[
						'enabled'  => 1,
						'talks'    => 1,
						'speakers' => 1,
						'photos'   => 1,
					],
					$overrides
				),
			]
		);
	}

	/**
	 * Run one sync of the configured event.
	 */
	private function sync( bool $force = false ): bool {
		return ( new SyncEngine() )->sync_event( 'c1', '101', $force );
	}

	public function test_full_sync_creates_event_talks_speakers_and_terms(): void {
		$this->assertTrue( $this->sync() );

		$events   = get_posts( [ 'post_type' => 'eex_event', 'post_status' => 'any' ] );
		$talks    = get_posts( [ 'post_type' => 'eex_talk', 'post_status' => 'any' ] );
		$speakers = get_posts( [ 'post_type' => 'eex_speaker', 'post_status' => 'any' ] );

		$this->assertCount( 1, $events );
		$this->assertCount( 2, $talks );
		$this->assertCount( 2, $speakers );

		// Category terms exist and are assigned.
		$talk_a = $talks[0];
		$this->assertSame( [ 'deliverability' ], wp_get_object_terms( [ $talk_a->ID ], 'eex_category', [ 'fields' => 'slugs' ] ) );

		// Speaker relationship resolved to WP post IDs.
		$speaker_ids = get_post_meta( $talk_a->ID, '_eex_speaker_ids', true );
		$this->assertCount( 1, $speaker_ids );
		$this->assertSame( 'Jane Sender', get_post( $speaker_ids[0] )->post_title );

		// Photo sideloaded with the speaker name and set as thumbnail.
		$this->assertNotEmpty( $GLOBALS['eex_test_sideloads'] ?? [] );
		$this->assertGreaterThan( 0, get_post_thumbnail_id( $speaker_ids[0] ) );
	}

	public function test_second_run_performs_zero_post_writes(): void {
		$this->sync();
		$writes = \EEX_Test_State::$post_write_count;

		$this->sync();

		$this->assertSame( $writes, \EEX_Test_State::$post_write_count );
	}

	public function test_category_exclude_filter_orphan_drafts_matching_talks(): void {
		$this->sync();

		// Exclude category 32: Talk B must be treated as nonexistent.
		$this->configure_event(
			[
				'cat_filter_mode' => 'exclude',
				'cat_filter'      => [ '32' ],
			]
		);
		$this->sync();

		$talk_b_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_talk', '9002' );
		$this->assertSame( 'draft', get_post_status( $talk_b_id ) );
		$this->assertSame( 1, get_post_meta( $talk_b_id, '_eex_orphaned', true ) );

		// And it does not return on subsequent runs.
		$this->sync( true );
		$this->assertSame( 'draft', get_post_status( $talk_b_id ) );
	}

	public function test_include_filter_imports_only_matching_talks(): void {
		$this->configure_event(
			[
				'cat_filter_mode' => 'include',
				'cat_filter'      => [ '31' ],
			]
		);
		$this->sync();

		$this->assertGreaterThan( 0, \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_talk', '9001' ) );
		$this->assertSame( 0, \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_talk', '9002' ) );
	}

	public function test_removed_talk_is_orphan_drafted_never_deleted(): void {
		$this->sync();

		$this->api['talks'] = [ $this->api['talks'][0] ]; // Talk B gone from HeySummit.
		$this->sync();

		$talk_b_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_talk', '9002' );
		$this->assertGreaterThan( 0, $talk_b_id, 'orphan must not be deleted' );
		$this->assertSame( 'draft', get_post_status( $talk_b_id ) );
	}

	public function test_speakers_toggle_off_touches_no_speaker_posts(): void {
		$this->sync();
		$speakers_before = count( get_posts( [ 'post_type' => 'eex_speaker', 'post_status' => 'any' ] ) );

		$this->configure_event( [ 'speakers' => 0 ] );
		$this->api['speakers'][0]['name'] = 'Renamed Jane';
		$writes                           = \EEX_Test_State::$post_write_count;
		$this->sync( true );

		$speakers_after = get_posts( [ 'post_type' => 'eex_speaker', 'post_status' => 'any' ] );
		$this->assertCount( $speakers_before, $speakers_after );

		$jane_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_speaker', '501' );
		$this->assertSame( 'Jane Sender', get_post( $jane_id )->post_title, 'speaker must be untouched while toggled off' );

		// Existing talk -> speaker relationships survive the toggle.
		$talk_a_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_talk', '9001' );
		$this->assertNotEmpty( get_post_meta( $talk_a_id, '_eex_speaker_ids', true ) );
	}

	public function test_speaker_deduplicated_across_events_by_email(): void {
		$this->sync();

		// The same human appears under event 202 with a different HS speaker ID.
		update_option(
			Options::SYNCED_EVENTS,
			[
				'c1|101' => [ 'enabled' => 1 ],
				'c1|202' => [ 'enabled' => 1 ],
			]
		);

		$this->mock_http( function ( $url ) {
			if ( str_contains( $url, 'events/202/' ) ) {
				return self::json_response( [ 'id' => 202, 'title' => 'FORUM London' ] );
			}
			if ( str_contains( $url, 'speakers/' ) && str_contains( $url, '202' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'      => 777,
								'name'    => 'Jane Sender',
								'company' => 'Inbox Co',
								'email'   => 'jane@inbox.example',
							],
						],
					]
				);
			}

			return null; // Fall through to the base mock.
		} );

		( new SyncEngine() )->sync_event( 'c1', '202' );

		// Still one Jane; the new HS ID is recorded as an alternate.
		$janes = array_filter(
			get_posts( [ 'post_type' => 'eex_speaker', 'post_status' => 'any' ] ),
			static fn( $p ) => 'Jane Sender' === $p->post_title
		);
		$this->assertCount( 1, $janes );

		$jane_id = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( 'eex_speaker', '501' );
		$this->assertContains( '777', array_map( 'strval', (array) get_post_meta( $jane_id, '_eex_hs_alt_ids', true ) ) );
	}

	public function test_auth_failure_aborts_cleanly_and_sets_notice(): void {
		$this->mock_http( fn() => self::json_response( [ 'detail' => 'bad token' ], 401 ) );

		$this->assertFalse( $this->sync() );

		$notices = (array) get_option( 'eex_notices', [] );
		$this->assertArrayHasKey( 'auth_c1', $notices );
		$this->assertStringContainsString( 'invalid or lacks access', $notices['auth_c1']['message'] );
	}

	public function test_consecutive_failures_escalate(): void {
		$this->mock_http( fn() => self::json_response( [], 500 ) );

		for ( $i = 0; $i < 6; $i++ ) {
			$this->sync();
		}

		$notices = (array) get_option( 'eex_notices', [] );
		$this->assertArrayHasKey( 'sync_failing_c1', $notices );
		$this->assertNotEmpty( \EEX_Test_State::$mail, 'sixth consecutive failure must email the admin' );
	}
}
