<?php
/**
 * Event and editorial selection acceptance scenarios (v1.50.0).
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Data\EventPresentation;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Frontend\Selection\EditorialSelector;
use Emailexpert\Events\Frontend\Selection\EventIdentity;
use Emailexpert\Events\Frontend\Selection\EventLifecycle;
use Emailexpert\Events\Frontend\Selection\EventSelector;
use Emailexpert\Events\Frontend\Selection\FeatureTargetResolver;
use Emailexpert\Events\Options;
use Emailexpert\Events\Support\Clock;
use Emailexpert\Events\Tests\TestCase;

/**
 * The deterministic acceptance scenarios: the four-event calendar (Double
 * Optin, Deliverability Roundtable, Email Cruise, London Forum), automatic
 * rollover, manual pinning, promotion, lifecycle, and the editorial side.
 *
 * @covers \Emailexpert\Events\Frontend\Selection\EventSelector
 * @covers \Emailexpert\Events\Frontend\Selection\FeatureTargetResolver
 * @covers \Emailexpert\Events\Frontend\Selection\EventLifecycle
 * @covers \Emailexpert\Events\Frontend\Selection\EditorialSelector
 * @covers \Emailexpert\Events\Frontend\Selection\EventIdentity
 * @covers \Emailexpert\Events\Data\EventPresentation
 */
final class SelectionTest extends TestCase {

	/**
	 * The frozen "19 August" reference moment.
	 *
	 * @var int
	 */
	private int $t0;

	protected function setUp(): void {
		parent::setUp();
		$this->t0 = time();
		Clock::freeze( $this->t0 );
	}

	protected function tearDown(): void {
		Clock::reset();
		parent::tearDown();
	}

	private function iso( int $ts ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}

	private function make_event( string $title, string $hs_id, int $start_days, ?int $end_days = null, array $meta = [] ): int {
		$start = $this->t0 + $start_days * DAY_IN_SECONDS;
		$end   = null !== $end_days ? $this->t0 + $end_days * DAY_IN_SECONDS : $start + 2 * HOUR_IN_SECONDS;

		return wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => $meta + [
					'_eex_heysummit_id'              => $hs_id,
					'_eex_connection_id'             => 'c1',
					'_eex_first_talk_at'             => $this->iso( $start ),
					'_eex_last_talk_at'              => $this->iso( $end ),
					'_eex_event_url'                 => 'https://hs.example/' . sanitize_title( $title ) . '/',
					'_eex_is_open_for_registrations' => 1,
				],
			]
		);
	}

	/**
	 * The acceptance calendar: 28 Aug Double Optin, 1 Sep Roundtable,
	 * 4 Sep Email Cruise, 16 Nov London Forum (two days).
	 *
	 * @return array<string,int> Title => post ID.
	 */
	private function calendar(): array {
		return [
			'Double Optin'              => $this->make_event( 'Double Optin', '101', 9 ),
			'Deliverability Roundtable' => $this->make_event( 'Deliverability Roundtable', '102', 13 ),
			'Email Cruise'              => $this->make_event( 'Email Cruise', '103', 16 ),
			'London Forum'              => $this->make_event( 'London Forum', '104', 89, 90 ),
		];
	}

	private function titles( array $events ): array {
		return array_map( static fn( array $event ): string => (string) $event['title'], $events );
	}

	// ---- Event selection -------------------------------------------------

	public function test_automatic_mode_features_the_next_eligible_event(): void {
		$this->calendar();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );

		$this->assertSame( 'Double Optin', (string) $target['event']['title'], 'automatic mode on 19 Aug features Double Optin' );
		$this->assertSame( 'automatic', $target['source'] );
	}

	public function test_more_events_returns_the_rest_without_the_featured_event(): void {
		$this->calendar();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$more   = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );

		$this->assertSame(
			[ 'Deliverability Roundtable', 'Email Cruise', 'London Forum' ],
			$this->titles( $more ),
			'More Events returns Roundtable, Email Cruise and London Forum'
		);
		$this->assertNotContains( 'Double Optin', $this->titles( $more ), 'Double Optin does not appear twice' );
	}

	public function test_after_the_featured_event_ends_the_next_becomes_featured(): void {
		$this->calendar();

		Clock::freeze( $this->t0 + 10 * DAY_IN_SECONDS ); // Double Optin has ended.
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );

		$this->assertSame( 'Deliverability Roundtable', (string) $target['event']['title'], 'after Double Optin ends, Roundtable is featured' );

		$more = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );
		$this->assertSame( [ 'Email Cruise', 'London Forum' ], $this->titles( $more ), 'the list refills with the remaining events' );
	}

	public function test_manual_selection_features_london_forum_and_keeps_the_chronology_intact(): void {
		$this->calendar();

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '104',
			]
		);

		$this->assertSame( 'London Forum', (string) $target['event']['title'], 'manual London Forum features London Forum' );

		$more = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );
		$this->assertSame(
			[ 'Double Optin', 'Deliverability Roundtable', 'Email Cruise' ],
			$this->titles( $more ),
			'a manually featured future event must not remove the first chronological event'
		);
	}

	public function test_after_featured_mode_excludes_events_before_the_featured_one(): void {
		$this->calendar();

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '104',
			]
		);

		$more = EventSelector::more_events( 'after_featured', 3, (array) $target['event'] );

		$this->assertSame( [], $this->titles( $more ), 'nothing starts after London Forum, so after_featured is empty' );
	}

	public function test_a_manually_featured_session_excludes_its_owning_event(): void {
		$this->calendar();

		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Forum keynote',
				'meta_input'  => [
					'_eex_heysummit_id'    => '9001',
					'_eex_source_event_id' => '104',
					'_eex_starts_at'       => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS ),
					'_eex_ends_at'         => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS + 3600 ),
				],
			]
		);

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source'  => 'manual_session',
				'featured_session' => '9001',
			]
		);

		$this->assertSame( 'session', $target['kind'] );
		$this->assertSame( 'London Forum', (string) $target['event']['title'], 'a session target resolves its owning event' );
		$this->assertSame( EventIdentity::of( $target['event'] ), $target['identity'], 'the target inherits the owning event identity' );

		$more = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );
		$this->assertSame(
			[ 'Double Optin', 'Deliverability Roundtable', 'Email Cruise' ],
			$this->titles( $more ),
			'the owning London Forum event is excluded from More Events'
		);
	}

	public function test_upcoming_sessions_mode_lists_sessions_excluding_the_featured_one(): void {
		$this->calendar();

		$make_talk = function ( string $title, string $hs_id, string $event_hs_id, int $days ): void {
			wp_insert_post(
				[
					'post_type'   => 'eex_talk',
					'post_status' => 'publish',
					'post_title'  => $title,
					'meta_input'  => [
						'_eex_heysummit_id'    => $hs_id,
						'_eex_source_event_id' => $event_hs_id,
						'_eex_starts_at'       => $this->iso( $this->t0 + $days * DAY_IN_SECONDS ),
						'_eex_ends_at'         => $this->iso( $this->t0 + $days * DAY_IN_SECONDS + 3600 ),
					],
				]
			);
		};

		// One long-running event (101) holding several sessions — the
		// calendar shape where distinct-event modes have nothing to list.
		$make_talk( 'RFP panel', '501', '101', 9 );
		$make_talk( 'Deliverability clinic', '502', '101', 12 );
		$make_talk( 'Roundtable session', '601', '102', 13 );

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source'  => 'manual_session',
				'featured_session' => '501',
			]
		);

		$more = EventSelector::more_sessions( 3, (array) $target['session'] );

		$this->assertSame(
			[ 'Deliverability clinic', 'Roundtable session' ],
			array_map( static fn( array $row ): string => (string) $row['title'], $more ),
			'upcoming sessions mode lists the following sessions, featured one excluded — including siblings from the same event'
		);
		$this->assertNotSame( '', (string) $more[0]['first_talk_at'], 'rows carry the session start for the compact row date' );
		$this->assertNotSame( '', (string) $more[0]['url'], 'rows link to the session' );

		$limited = EventSelector::more_sessions( 1, (array) $target['session'] );
		$this->assertCount( 1, $limited, 'the row limit still applies after exclusion' );
	}

	public function test_exclusion_happens_before_the_limit_so_the_list_refills(): void {
		$this->calendar();

		// Featured is the FIRST chronological event: a naive limit-then-
		// exclude would return two rows; the contract is three.
		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$more   = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );

		$this->assertCount( 3, $more, 'exclusion happens before the limit and the list refills' );
	}

	public function test_a_missing_manual_selection_follows_the_configured_fallback(): void {
		$this->calendar();

		$auto = FeatureTargetResolver::resolve(
			[
				'featured_source'   => 'manual_event',
				'featured_event'    => 'does-not-exist',
				'pin_expiry_action' => 'auto',
			]
		);
		$this->assertSame( 'Double Optin', (string) $auto['event']['title'], 'missing manual selection falls back to automatic' );
		$this->assertSame( 'fallback_automatic', $auto['source'] );

		$hidden = FeatureTargetResolver::resolve(
			[
				'featured_source'   => 'manual_event',
				'featured_event'    => 'does-not-exist',
				'pin_expiry_action' => 'hide',
			]
		);
		$this->assertNull( $hidden, 'fallback "hide" hides the featured area' );
	}

	public function test_a_pin_expiring_at_a_configured_time_returns_to_automatic(): void {
		$this->calendar();

		$atts = [
			'featured_source'   => 'manual_event',
			'featured_event'    => '104',
			'pin_duration'      => 'until_date',
			'pin_until'         => $this->iso( $this->t0 + 300 ),
			'pin_expiry_action' => 'auto',
		];

		$pinned = FeatureTargetResolver::resolve( $atts );
		$this->assertSame( 'London Forum', (string) $pinned['event']['title'], 'before expiry the pin holds' );

		Clock::freeze( $this->t0 + 400 );
		EventSelector::reset_request_state();

		$after = FeatureTargetResolver::resolve( $atts );
		$this->assertSame( 'Double Optin', (string) $after['event']['title'], 'after expiry selection returns to automatic' );
	}

	public function test_a_two_day_event_remains_current_between_first_and_final_session(): void {
		$this->calendar();

		Clock::freeze( $this->t0 + 89 * DAY_IN_SECONDS + 12 * HOUR_IN_SECONDS ); // Mid-Forum.
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );

		$this->assertSame( 'London Forum', (string) $target['event']['title'], 'a multi-day event does not disappear at the start of day one' );
		$this->assertSame( 'live', (string) $target['lifecycle']['status'] );
	}

	public function test_an_evergreen_open_event_remains_eligible(): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Evergreen Hub',
				'meta_input'  => [
					'_eex_heysummit_id'              => '200',
					'_eex_connection_id'             => 'c1',
					'_eex_is_evergreen'              => 1,
					'_eex_is_open_for_registrations' => 1,
					'_eex_event_url'                 => 'https://hs.example/hub/',
				],
			]
		);
		$this->make_event( 'Dated Event', '201', 5 );

		$titles = $this->titles( EventSelector::candidates() );
		$this->assertContains( 'Evergreen Hub', $titles, 'an evergreen open event stays eligible' );

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$this->assertSame( 'Dated Event', (string) $target['event']['title'], 'dated events lead; evergreen events sort last' );
	}

	public function test_a_cancelled_event_reads_cancelled_and_registration_closes(): void {
		$events = $this->calendar();

		EventPresentation::save_full(
			$events['Double Optin'],
			[
				'status'         => 'cancelled',
				'status_message' => 'See you next year.',
			]
		);
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '101',
			]
		);

		$this->assertSame( 'cancelled', (string) $target['lifecycle']['status'], 'a manually featured cancelled event shows a cancelled state' );
		$this->assertFalse( (bool) $target['lifecycle']['registration_open'], 'no registration language on a cancelled event' );
		$this->assertSame( 'See you next year.', (string) $target['lifecycle']['notice'] );
	}

	public function test_a_postponed_event_carries_the_configured_notice(): void {
		$events = $this->calendar();

		EventPresentation::save_full(
			$events['Email Cruise'],
			[
				'status'         => 'postponed',
				'status_message' => 'New dates announced shortly.',
			]
		);
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '103',
			]
		);

		$this->assertSame( 'postponed', (string) $target['lifecycle']['status'] );
		$this->assertSame( 'New dates announced shortly.', (string) $target['lifecycle']['notice'], 'a postponed event displays the configured notice' );
	}

	public function test_promotion_windows_are_respected_in_automatic_mode(): void {
		$events = $this->calendar();

		// London Forum is flagship only inside a window that has not opened.
		EventPresentation::save_full(
			$events['London Forum'],
			[
				'level'       => 'flagship',
				'promo_start' => $this->iso( $this->t0 + 5 * DAY_IN_SECONDS ),
			]
		);
		EventSelector::reset_request_state();

		$before = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto', 'selection_strategy' => 'priority' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'Double Optin', (string) $before['event']['title'], 'outside its window the flagship level does not apply' );

		Clock::freeze( $this->t0 + 6 * DAY_IN_SECONDS ); // Window open, nothing ended yet.
		EventSelector::reset_request_state();

		$during = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto', 'selection_strategy' => 'priority' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'London Forum', (string) $during['event']['title'], 'inside its window the flagship leads under priority' );
	}

	public function test_chronological_strategy_ignores_promotion_level(): void {
		$events = $this->calendar();

		EventPresentation::save_full( $events['London Forum'], [ 'level' => 'flagship' ] );
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto', 'selection_strategy' => 'chronological' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'Double Optin', (string) $target['event']['title'], 'chronological mode ignores promotion levels' );
	}

	public function test_priority_strategy_honours_flagship_then_dates(): void {
		$events = $this->calendar();

		EventPresentation::save_full( $events['London Forum'], [ 'level' => 'flagship' ] );
		EventPresentation::save_full( $events['Email Cruise'], [ 'level' => 'flagship' ] );
		EventPresentation::save_full( $events['Deliverability Roundtable'], [ 'level' => 'featured' ] );
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto', 'selection_strategy' => 'priority' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'Email Cruise', (string) $target['event']['title'], 'flagship beats featured; the soonest flagship wins' );
	}

	public function test_ineligible_and_do_not_promote_events_are_never_auto_selected(): void {
		$events = $this->calendar();

		EventPresentation::save_full( $events['Double Optin'], [ 'feature_eligible' => 0 ] );
		EventPresentation::save_full( $events['Deliverability Roundtable'], [ 'level' => 'none' ] );
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );

		$this->assertSame( 'Email Cruise', (string) $target['event']['title'], 'ineligible and do-not-promote events are skipped automatically' );
	}

	public function test_more_events_eligibility_switch_excludes_an_event(): void {
		$events = $this->calendar();

		EventPresentation::save_full( $events['Email Cruise'], [ 'more_eligible' => 0 ] );
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$more   = EventSelector::more_events( 'all_upcoming', 3, (array) $target['event'] );

		$this->assertSame( [ 'Deliverability Roundtable', 'London Forum' ], $this->titles( $more ) );
	}

	public function test_same_series_mode_filters_by_the_featured_events_series(): void {
		$events = $this->calendar();

		wp_insert_term( 'Forums', 'eex_event_series' );
		wp_set_object_terms( $events['London Forum'], [ 'forums' ], 'eex_event_series' );
		wp_set_object_terms( $events['Double Optin'], [ 'forums' ], 'eex_event_series' );
		EventSelector::reset_request_state();

		$target = FeatureTargetResolver::resolve(
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '104',
			]
		);

		$more = EventSelector::more_events( 'same_series', 3, (array) $target['event'] );

		$this->assertSame( [ 'Double Optin' ], $this->titles( $more ), 'same-series mode keeps series peers and still excludes the featured event' );
	}

	public function test_automatic_mode_does_not_select_an_unconfigured_lite_event(): void {
		update_option( Options::CONNECTIONS, [ [ 'id' => 'c1', 'label' => 'Primary', 'api_key' => 'k' ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => [ 'c1|101' ],
			]
		);
		Repositories::reset();
		EventSelector::reset_request_state();

		$t0 = $this->t0;
		$this->mock_http( function ( $url ) use ( $t0 ) {
			if ( str_contains( (string) $url, 'talks/' ) ) {
				return self::json_response( [ 'results' => [] ] );
			}
			if ( str_contains( (string) $url, 'events/' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'                        => 101,
								'title'                     => 'Configured Event',
								'starts_at'                 => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS ),
								'ends_at'                   => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS + 7200 ),
								'is_open_for_registrations' => true,
								'event_url'                 => 'https://hs.example/configured/',
							],
							[
								'id'                        => 999,
								'title'                     => 'Unconfigured Sibling',
								'starts_at'                 => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 2 * DAY_IN_SECONDS ),
								'is_open_for_registrations' => true,
								'event_url'                 => 'https://hs.example/sibling/',
							],
						],
					]
				);
			}

			return null;
		} );

		$target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );

		$this->assertSame( 'Configured Event', (string) $target['event']['title'], 'only operator-configured Lite events are candidates' );
		$this->assertNotContains( 'Unconfigured Sibling', $this->titles( EventSelector::candidates() ) );
	}

	public function test_full_and_lite_repositories_share_the_selection_semantics(): void {
		// Full mode first.
		$this->calendar();

		$full_target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$full_more   = $this->titles( EventSelector::more_events( 'all_upcoming', 3, (array) $full_target['event'] ) );

		// The same calendar through the Lite repository.
		\EEX_Test_State::reset();
		Repositories::reset();
		EventSelector::reset_request_state();
		Clock::freeze( $this->t0 );

		update_option( Options::CONNECTIONS, [ [ 'id' => 'c1', 'label' => 'Primary', 'api_key' => 'k' ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => [ 'c1|101', 'c1|102', 'c1|103', 'c1|104' ],
			]
		);

		$t0       = $this->t0;
		$calendar = [
			[ 101, 'Double Optin', 9, null ],
			[ 102, 'Deliverability Roundtable', 13, null ],
			[ 103, 'Email Cruise', 16, null ],
			[ 104, 'London Forum', 89, 90 ],
		];

		$rows = [];
		foreach ( $calendar as [ $id, $title, $start_days, $end_days ] ) {
			$rows[] = [
				'id'                        => $id,
				'title'                     => $title,
				'starts_at'                 => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + $start_days * DAY_IN_SECONDS ),
				'ends_at'                   => gmdate( 'Y-m-d\TH:i:s\Z', null !== $end_days ? $t0 + $end_days * DAY_IN_SECONDS : $t0 + $start_days * DAY_IN_SECONDS + 7200 ),
				'is_open_for_registrations' => true,
				'event_url'                 => 'https://hs.example/' . $id . '/',
			];
		}

		$this->mock_http( function ( $url ) use ( $rows ) {
			if ( str_contains( (string) $url, 'talks/' ) ) {
				return self::json_response( [ 'results' => [] ] );
			}
			if ( str_contains( (string) $url, 'events/' ) ) {
				return self::json_response( [ 'results' => $rows ] );
			}

			return null;
		} );

		$lite_target = FeatureTargetResolver::resolve( [ 'featured_source' => 'auto' ] );
		$lite_more   = $this->titles( EventSelector::more_events( 'all_upcoming', 3, (array) $lite_target['event'] ) );

		$this->assertSame( (string) $full_target['event']['title'], (string) $lite_target['event']['title'], 'both repositories pick the same featured event' );
		$this->assertSame( $full_more, $lite_more, 'both repositories produce the same More Events list' );
	}

	public function test_lifecycle_states_read_from_canonical_timestamps(): void {
		$event = [
			'title'         => 'X',
			'first_talk_at' => $this->iso( $this->t0 + 30 * MINUTE_IN_SECONDS ),
			'last_talk_at'  => $this->iso( $this->t0 + 90 * MINUTE_IN_SECONDS ),
			'open'          => true,
		];

		$this->assertSame( 'starting_soon', EventLifecycle::resolve( $event, null, EventPresentation::defaults() )['status'] );

		Clock::freeze( $this->t0 + 40 * MINUTE_IN_SECONDS );
		$this->assertSame( 'live', EventLifecycle::resolve( $event, null, EventPresentation::defaults() )['status'] );

		Clock::freeze( $this->t0 + 4 * HOUR_IN_SECONDS );
		$this->assertSame( 'ended', EventLifecycle::resolve( $event, null, EventPresentation::defaults() )['status'] );
	}

	// ---- Editorial selection ---------------------------------------------

	private function make_story( string $title, int $age_seconds, array $overrides = [] ): int {
		return wp_insert_post(
			$overrides + [
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_content'  => str_repeat( 'Substantial words for the reading time. ', 40 ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', $this->t0 - $age_seconds ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $this->t0 - $age_seconds ),
			]
		);
	}

	public function test_the_latest_story_becomes_the_featured_story(): void {
		$this->make_story( 'Older story', 7200 );
		$this->make_story( 'Newest story', 3600 );

		$selection = EditorialSelector::select( [ 'story_source' => 'latest' ] );

		$this->assertSame( 'Newest story', (string) $selection['lead']['title'] );
	}

	public function test_the_featured_story_is_removed_from_latest_news_and_the_list_refills(): void {
		foreach ( [ 'One', 'Two', 'Three', 'Four', 'Five' ] as $index => $title ) {
			$this->make_story( $title, ( $index + 1 ) * 3600 );
		}

		$selection = EditorialSelector::select(
			[
				'story_source' => 'latest',
				'news_count'   => 3,
			]
		);

		$titles = array_map( static fn( array $item ): string => (string) $item['title'], $selection['items'] );

		$this->assertSame( 'One', (string) $selection['lead']['title'] );
		$this->assertNotContains( 'One', $titles, 'the featured story never repeats in Latest News' );
		$this->assertSame( [ 'Two', 'Three', 'Four' ], $titles, 'the list refills to its requested count after the exclusion' );
	}

	public function test_manual_story_selection_works(): void {
		$this->make_story( 'Newest story', 3600 );
		$manual = $this->make_story( 'Hand-picked', 7200 );

		$selection = EditorialSelector::select(
			[
				'story_source' => 'manual',
				'story_id'     => (string) $manual,
			]
		);

		$this->assertSame( 'Hand-picked', (string) $selection['lead']['title'] );
	}

	public function test_a_missing_manual_story_follows_the_configured_fallback(): void {
		$this->make_story( 'Newest story', 3600 );

		$latest = EditorialSelector::select(
			[
				'story_source'   => 'manual',
				'story_id'       => '999999',
				'story_fallback' => 'latest',
			]
		);
		$this->assertSame( 'Newest story', (string) $latest['lead']['title'], 'missing manual story falls back to the latest' );

		$none = EditorialSelector::select(
			[
				'story_source'   => 'manual',
				'story_id'       => '999999',
				'story_fallback' => 'none',
			]
		);
		$this->assertNull( $none['lead'], 'fallback "none" shows no featured story' );
	}

	public function test_password_protected_and_non_public_posts_are_excluded(): void {
		$this->make_story( 'Public story', 7200 );
		$this->make_story( 'Secret', 3600, [ 'post_password' => 'x' ] );
		$this->make_story( 'Draft', 1800, [ 'post_status' => 'draft' ] );

		$selection = EditorialSelector::select( [ 'story_source' => 'latest', 'news_count' => 4 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'Public story', (string) $selection['lead']['title'] );
		$this->assertSame( [], $selection['items'], 'protected and draft posts never surface' );

		$manual_secret = EditorialSelector::select(
			[
				'story_source'   => 'manual',
				'story_id'       => (string) $this->make_story( 'Secret 2', 900, [ 'post_password' => 'x' ] ),
				'story_fallback' => 'none',
			]
		);
		$this->assertNull( $manual_secret['lead'], 'a manually selected protected post is refused' );
	}

	public function test_balanced_category_mode_is_deterministic_and_prefers_variety(): void {
		wp_insert_term( 'Deliverability', 'category' );
		wp_insert_term( 'Design', 'category' );

		$a1 = $this->make_story( 'Deliv A', 1000 );
		$a2 = $this->make_story( 'Deliv B', 2000 );
		$b1 = $this->make_story( 'Design A', 3000 );
		$a3 = $this->make_story( 'Deliv C', 4000 );

		foreach ( [ $a1, $a2, $a3 ] as $post_id ) {
			wp_set_object_terms( $post_id, [ 'deliverability' ], 'category' );
		}
		wp_set_object_terms( $b1, [ 'design' ], 'category' );

		$atts = [
			'story_source' => 'none',
			'news_count'   => 3,
			'news_order'   => 'balanced',
		];

		$first  = array_map( static fn( array $item ): string => (string) $item['title'], EditorialSelector::select( $atts )['items'] );
		$second = array_map( static fn( array $item ): string => (string) $item['title'], EditorialSelector::select( $atts )['items'] );

		$this->assertSame( $first, $second, 'balanced mode is deterministic' );
		$this->assertSame( [ 'Deliv A', 'Design A', 'Deliv B' ], $first, 'each initial slot prefers an unseen category, then chronology fills' );
	}

	public function test_balanced_mode_falls_back_to_chronological_when_one_category_dominates(): void {
		wp_insert_term( 'Deliverability', 'category' );

		foreach ( [ 'One', 'Two', 'Three' ] as $index => $title ) {
			$post_id = $this->make_story( $title, ( $index + 1 ) * 1000 );
			wp_set_object_terms( $post_id, [ 'deliverability' ], 'category' );
		}

		$selection = EditorialSelector::select(
			[
				'story_source' => 'none',
				'news_count'   => 3,
				'news_order'   => 'balanced',
			]
		);

		$titles = array_map( static fn( array $item ): string => (string) $item['title'], $selection['items'] );

		$this->assertSame( [ 'One', 'Two', 'Three' ], $titles, 'a single-category site shows its stories chronologically — diversity is never manufactured' );
	}

	public function test_insufficient_stories_produce_fewer_items_not_placeholders(): void {
		$this->make_story( 'Only story', 3600 );

		$selection = EditorialSelector::select(
			[
				'story_source' => 'latest',
				'news_count'   => 4,
			]
		);

		$this->assertNotNull( $selection['lead'] );
		$this->assertSame( [], $selection['items'], 'no placeholders are invented' );
	}

	public function test_reading_time_is_deterministic_and_omitted_when_too_short(): void {
		$long  = $this->make_story( 'Long read', 3600 );
		$short = $this->make_story( 'Short note', 7200, [ 'post_content' => 'Just a line.' ] );

		$selection = EditorialSelector::select( [ 'story_source' => 'none', 'news_count' => 2 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$by_title = [];
		foreach ( $selection['items'] as $item ) {
			$by_title[ (string) $item['title'] ] = (int) $item['reading_time'];
		}

		$this->assertGreaterThan( 0, $by_title['Long read'] );
		$this->assertSame( 0, $by_title['Short note'], 'insufficient content claims no reading time' );
		unset( $long, $short );
	}
}
