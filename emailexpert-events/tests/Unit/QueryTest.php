<?php
/**
 * Component query logic: upcoming/past split, evergreen rules, filters.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Frontend\Query;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Frontend\Query
 */
final class QueryTest extends TestCase {

	/**
	 * Create an event post.
	 *
	 * @param array<string,mixed> $meta Meta overrides.
	 */
	private function make_event( string $title, string $hs_id, array $meta = [] ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => $meta + [ '_eex_heysummit_id' => $hs_id ],
			]
		);
	}

	/**
	 * Create a talk post.
	 *
	 * @param array<string,mixed> $meta Meta overrides.
	 */
	private function make_talk( string $title, string $starts_at, array $meta = [], string $status = 'publish' ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => $status,
				'post_title'  => $title,
				'meta_input'  => $meta + [ '_eex_starts_at' => $starts_at ],
			]
		);
	}

	public function test_single_evergreen_event_talks_split_by_start_time(): void {
		$this->make_event( 'Hub', '101', [ '_eex_is_evergreen' => 1, '_eex_is_open_for_registrations' => 1 ] );

		$past_1   = $this->make_talk( 'Old A', gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS ), [ '_eex_source_event_id' => '101' ] );
		$past_2   = $this->make_talk( 'Old B', gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ), [ '_eex_source_event_id' => '101' ] );
		$future_1 = $this->make_talk( 'Soon', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [ '_eex_source_event_id' => '101' ] );
		$future_2 = $this->make_talk( 'Later', gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ), [ '_eex_source_event_id' => '101' ] );

		// `event` omitted: defaults to the sole synced event.
		$this->assertSame( [ $future_1, $future_2 ], Query::upcoming_talks( [] ), 'soonest first' );
		$this->assertSame( [ $past_2, $past_1 ], Query::past_talks( [] ), 'newest first' );
	}

	public function test_untimed_talks_appear_in_neither_list(): void {
		$this->make_talk( 'No time', '' );

		$this->assertSame( [], Query::upcoming_talks( [] ) );
		$this->assertSame( [], Query::past_talks( [] ) );
	}

	public function test_category_attribute_filters_both_lists(): void {
		wp_insert_term( 'Deliverability', 'eex_category' );
		wp_insert_term( 'Authentication', 'eex_category' );

		$future_deliv = $this->make_talk( 'F-Deliv', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ) );
		$future_auth  = $this->make_talk( 'F-Auth', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ) );
		$past_deliv   = $this->make_talk( 'P-Deliv', gmdate( 'Y-m-d\TH:i:s\Z', time() - HOUR_IN_SECONDS ) );

		wp_set_object_terms( $future_deliv, [ 'deliverability' ], 'eex_category' );
		wp_set_object_terms( $future_auth, [ 'authentication' ], 'eex_category' );
		wp_set_object_terms( $past_deliv, [ 'deliverability' ], 'eex_category' );

		$this->assertSame( [ $future_deliv ], Query::upcoming_talks( [ 'category' => 'deliverability' ] ) );
		$this->assertSame( [ $past_deliv ], Query::past_talks( [ 'category' => 'deliverability' ] ) );

		// Comma-separated slugs widen the filter.
		$this->assertCount( 2, Query::upcoming_talks( [ 'category' => 'deliverability,authentication' ] ) );
	}

	public function test_evergreen_event_always_upcoming_never_past(): void {
		$evergreen = $this->make_event(
			'Hub',
			'101',
			[
				'_eex_is_evergreen'              => 1,
				'_eex_is_open_for_registrations' => 1,
				'_eex_first_talk_at'             => gmdate( 'Y-m-d\TH:i:s\Z', time() - 300 * DAY_IN_SECONDS ),
				'_eex_last_talk_at'              => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
			]
		);
		$finished  = $this->make_event(
			'FORUM 2025',
			'202',
			[
				'_eex_first_talk_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 60 * DAY_IN_SECONDS ),
				'_eex_last_talk_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() - 59 * DAY_IN_SECONDS ),
			]
		);
		$future    = $this->make_event(
			'FORUM 2026',
			'303',
			[
				'_eex_first_talk_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 60 * DAY_IN_SECONDS ),
				'_eex_last_talk_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() + 61 * DAY_IN_SECONDS ),
			]
		);

		$upcoming = Query::upcoming_events( [] );
		$this->assertContains( $evergreen, $upcoming );
		$this->assertContains( $future, $upcoming );
		$this->assertNotContains( $finished, $upcoming );
		$this->assertSame( $future, $upcoming[0], 'dated events sort before evergreen' );

		$past = Query::past_events( [] );
		$this->assertSame( [ $finished ], $past, 'evergreen events never appear in past events' );
	}

	public function test_evergreen_event_with_registrations_closed_is_not_upcoming(): void {
		$this->make_event( 'Hub', '101', [ '_eex_is_evergreen' => 1, '_eex_is_open_for_registrations' => 0 ] );

		$this->assertSame( [], Query::upcoming_events( [] ) );
		$this->assertSame( [], Query::past_events( [] ) );
	}

	public function test_event_attribute_accepts_hs_id_and_restricts_talks(): void {
		$this->make_event( 'Hub', '101' );
		$this->make_event( 'FORUM', '202' );

		$hub_talk   = $this->make_talk( 'Hub talk', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [ '_eex_source_event_id' => '101' ] );
		$forum_talk = $this->make_talk( 'Forum talk', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [ '_eex_source_event_id' => '202' ] );

		$this->assertSame( [ $hub_talk ], Query::upcoming_talks( [ 'event' => '101' ] ) );
		$this->assertSame( [ $forum_talk ], Query::upcoming_talks( [ 'event' => '202' ] ) );

		// With two events, an empty attribute means no restriction.
		$this->assertCount( 2, Query::upcoming_talks( [] ) );
	}

	public function test_speakers_filtered_by_category_via_their_talks(): void {
		wp_insert_term( 'Deliverability', 'eex_category' );

		$speaker_a = wp_insert_post( [ 'post_type' => 'eex_speaker', 'post_status' => 'publish', 'post_title' => 'Anna' ] );
		$speaker_b = wp_insert_post( [ 'post_type' => 'eex_speaker', 'post_status' => 'publish', 'post_title' => 'Ben' ] );

		$talk_deliv = $this->make_talk( 'Deliv', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [ '_eex_speaker_ids' => [ $speaker_a ] ] );
		$this->make_talk( 'Other', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [ '_eex_speaker_ids' => [ $speaker_b ] ] );
		wp_set_object_terms( $talk_deliv, [ 'deliverability' ], 'eex_category' );

		$this->assertSame( [ $speaker_a ], Query::speakers( [ 'category' => 'deliverability' ] ) );
		$this->assertSame( [ $speaker_a, $speaker_b ], Query::speakers( [] ), 'alphabetical' );
	}

	public function test_draft_talks_are_invisible(): void {
		$this->make_talk( 'Hidden', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [], 'draft' );
		$this->make_talk( 'Pending', gmdate( 'Y-m-d\TH:i:s\Z', time() + HOUR_IN_SECONDS ), [], 'pending' );

		$this->assertSame( [], Query::upcoming_talks( [] ), 'pending-review items must not appear on the front end' );
	}
}
