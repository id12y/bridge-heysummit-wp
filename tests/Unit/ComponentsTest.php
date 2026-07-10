<?php
/**
 * Component rendering: shared callbacks, empty states, caching, escaping.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Options;
use Emailexpert\Events\Frontend\Shortcodes;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Frontend\Components
 */
final class ComponentsTest extends TestCase {

	private function make_talk( string $title, int $offset_seconds ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => [
					'_eex_starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + $offset_seconds ),
					'_eex_ends_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + $offset_seconds + 3600 ),
				],
			]
		);
	}

	public function test_empty_listing_renders_empty_state_not_a_void(): void {
		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'eex-empty', $html );
		$this->assertStringContainsString( 'New sessions are announced soon.', $html );

		// hide_empty (Full mode): visitors get nothing at all; admins get an
		// explanatory comment instead of an unexplained void.
		\EEX_Test_State::$user_can = false;
		$this->assertSame( '', Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] ) );

		\EEX_Test_State::$user_can = true;
		$admin_view                = Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] );
		$this->assertStringNotContainsString( 'eex-empty', $admin_view );
		$this->assertStringContainsString( 'hidden by its hide_empty setting', $admin_view );
	}

	public function test_custom_empty_text_is_escaped(): void {
		$html = Components::render( 'upcoming-sessions', [ 'empty_text' => '<script>alert(1)</script>' ] );

		$this->assertStringNotContainsString( '<script>', $html );
	}

	public function test_upcoming_sessions_renders_cards_with_session_attrs(): void {
		$this->make_talk( 'Card session', 3600 );

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Card session', $html );
		$this->assertStringContainsString( 'data-eex-session', $html );
		$this->assertStringContainsString( 'data-eex-start', $html );
		$this->assertStringContainsString( 'Add to calendar', $html );
		$this->assertStringContainsString( 'eex_ics=', $html );
	}

	public function test_past_sessions_show_replay_cta_when_replay_exists(): void {
		$talk = $this->make_talk( 'Replayed', -7200 );
		update_post_meta( $talk, '_eex_replay_url_synced', 'https://www.youtube.com/watch?v=abc' );

		$html = Components::render( 'past-sessions', [] );

		$this->assertStringContainsString( 'Watch replay', $html );
		$this->assertStringContainsString( 'youtube.com', $html );
	}

	public function test_talk_titles_are_escaped_in_output(): void {
		$this->make_talk( 'XSS <script>alert(1)</script>', 3600 );

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_shortcode_output_matches_block_render_callback(): void {
		$this->make_talk( 'Shared render', 3600 );

		( new Shortcodes() )->register();
		$shortcode_callback = $GLOBALS['eex_test_shortcodes']['eex_upcoming_sessions'];

		$via_shortcode = $shortcode_callback( [ 'limit' => 6 ] );
		$via_component = Components::render( 'upcoming-sessions', [ 'limit' => 6 ] );

		$this->assertSame( $via_component, $via_shortcode );

		// The new layout and toggle attributes travel identically.
		$atts          = [
			'layout'   => 'agenda',
			'show_ics' => 0,
		];
		$via_shortcode = $shortcode_callback( $atts );
		$via_component = Components::render( 'upcoming-sessions', $atts );

		$this->assertSame( $via_component, $via_shortcode );
		$this->assertStringContainsString( 'eex-agenda-day', $via_shortcode );
		$this->assertStringNotContainsString( 'eex_ics=', $via_shortcode );
	}

	public function test_render_uses_cache_until_flush(): void {
		$this->make_talk( 'Cache probe', 3600 );

		$first = Components::render( 'upcoming-sessions', [] );

		// New content appears only after a flush (sync/webhook do this).
		$this->make_talk( 'Added later', 7200 );
		$cached = Components::render( 'upcoming-sessions', [] );
		$this->assertSame( $first, $cached );
		$this->assertStringNotContainsString( 'Added later', $cached );

		Cache::flush();
		$fresh = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Added later', $fresh );
	}

	public function test_reg_counter_respects_threshold_and_uses_rest_refresh(): void {
		$event = wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id'       => '101',
					'_eex_registration_count' => 30,
				],
			]
		);

		$this->assertStringNotContainsString( 'data-eex-counter', Components::render( 'reg-counter', [ 'event' => '101' ] ), 'hidden below the threshold' );

		update_post_meta( $event, '_eex_registration_count', 120 );
		Cache::flush();

		$html = Components::render( 'reg-counter', [ 'event' => '101' ] );
		$this->assertStringContainsString( '120', $html );
		$this->assertStringContainsString( 'data-eex-counter="101"', $html );
	}

	public function test_countdown_renders_utc_target_and_no_live_claim(): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_first_talk_at' => '2027-01-01T10:00:00Z',
				],
			]
		);

		$html = Components::render( 'countdown', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'data-eex-countdown="2027-01-01T10:00:00Z"', $html );
		$this->assertStringNotContainsString( 'Live now', $html, 'server HTML never claims live state' );
		$this->assertStringContainsString( '<time datetime="2027-01-01T10:00:00Z"', $html );
	}

	public function test_schedule_groups_by_day_in_order(): void {
		$this->make_talk( 'Day two talk', 2 * DAY_IN_SECONDS );
		$this->make_talk( 'Day one talk', DAY_IN_SECONDS );

		$html = Components::render( 'schedule', [] );

		$this->assertStringContainsString( 'eex-schedule-day', $html );
		$this->assertLessThan(
			strpos( $html, 'Day two talk' ),
			strpos( $html, 'Day one talk' ),
			'chronological order'
		);
	}

	public function test_unknown_component_renders_nothing(): void {
		$this->assertSame( '', Components::render( 'not-a-component', [] ) );
	}

	public function test_past_sessions_pagination_is_cache_keyed_per_page(): void {
		$this->make_talk( 'Older talk', -2 * DAY_IN_SECONDS );
		$this->make_talk( 'Newer talk', -1 * DAY_IN_SECONDS );

		$atts = [
			'limit'    => 1,
			'paginate' => 1,
		];

		try {
			$page_one = Components::render( 'past-sessions', $atts );
			$this->assertStringContainsString( 'Newer talk', $page_one, 'newest first on page 1' );
			$this->assertStringNotContainsString( 'Older talk', $page_one );

			// Page 2 arrives via ?eex_page= on an already-cached page 1: it
			// must render page 2, not serve the cached page-1 fragment.
			$_GET['eex_page'] = '2';
			$page_two         = Components::render( 'past-sessions', $atts );
			$this->assertStringContainsString( 'Older talk', $page_two );
			$this->assertStringNotContainsString( 'Newer talk', $page_two );

			// And back: page 1 is still page 1.
			unset( $_GET['eex_page'] );
			$this->assertSame( $page_one, Components::render( 'past-sessions', $atts ) );
		} finally {
			unset( $_GET['eex_page'] );
		}
	}

	public function test_enum_attributes_are_whitelisted(): void {
		$schema = Components::definitions()['upcoming-sessions']['atts'];

		$out = Components::sanitise_atts( $schema, [ 'layout' => '<script>alert(1)</script>' ] );
		$this->assertSame( 'cards', $out['layout'], 'a bogus layout falls back to the default' );

		$out = Components::sanitise_atts( $schema, [ 'layout' => 'agenda' ] );
		$this->assertSame( 'agenda', $out['layout'] );

		$speakers = Components::definitions()['speakers']['atts'];
		$out      = Components::sanitise_atts( $speakers, [ 'photo_shape' => 'bogus' ] );
		$this->assertSame( 'rounded', $out['photo_shape'], 'a bogus photo shape falls back to the default' );
	}

	public function test_default_attributes_preserve_existing_markup(): void {
		$this->make_talk( 'Baseline session', 3600 );

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'eex-grid eex-talk-grid', $html );
		$this->assertStringNotContainsString( 'eex-talk-list', $html );
		$this->assertStringNotContainsString( 'eex-agenda', $html );
		$this->assertStringNotContainsString( 'eex-talk-compact', $html );
	}

	public function test_layouts_render_their_own_markup_and_keep_the_js_contract(): void {
		$this->make_talk( 'Layout session', 3600 );

		$list = Components::render( 'upcoming-sessions', [ 'layout' => 'list' ] );
		$this->assertStringContainsString( 'eex-talk-list', $list );
		$this->assertStringNotContainsString( 'eex-talk-grid', $list );
		$this->assertStringContainsString( 'data-eex-session', $list );
		$this->assertStringContainsString( 'data-eex-title', $list, 'the filter bar contract survives the list layout' );

		Cache::flush();
		$compact = Components::render( 'upcoming-sessions', [ 'layout' => 'compact' ] );
		$this->assertStringContainsString( 'eex-talk-compact', $compact );
		$this->assertStringContainsString( 'data-eex-title', $compact );

		Cache::flush();
		$agenda = Components::render( 'upcoming-sessions', [ 'layout' => 'agenda' ] );
		$this->assertStringContainsString( 'eex-agenda-day', $agenda );
		$this->assertStringContainsString( 'eex-agenda-heading', $agenda );
		$this->assertStringContainsString( 'Online', $agenda, 'the agenda badge is present' );
		$this->assertStringContainsString( 'data-eex-session', $agenda );
		$this->assertStringContainsString( 'data-eex-title', $agenda );
		$this->assertStringContainsString( gmdate( 'j F Y', time() + 3600 ), $agenda, 'the day heading uses the compact date format' );
	}

	public function test_layouts_key_the_cache_separately(): void {
		$this->make_talk( 'Cache layout session', 3600 );

		$cards = Components::render( 'upcoming-sessions', [] );
		$list  = Components::render( 'upcoming-sessions', [ 'layout' => 'list' ] );

		$this->assertStringContainsString( 'eex-talk-grid', $cards );
		$this->assertStringContainsString( 'eex-talk-list', $list, 'a layout change must never serve the cached other layout' );
	}

	public function test_display_toggles_remove_exactly_their_markup(): void {
		$talk_id = $this->make_talk( 'Toggle session', 3600 );
		wp_insert_term( 'Deliverability', 'eex_category' );
		wp_set_object_terms( $talk_id, [ 'deliverability' ], 'eex_category' );

		$speaker_id = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Toggle Speaker',
			]
		);
		update_post_meta( $talk_id, '_eex_speaker_ids', [ $speaker_id ] );

		$all = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'eex_ics=', $all );
		$this->assertStringContainsString( 'google.com/calendar', $all );
		$this->assertStringContainsString( 'eex-chip', $all );
		$this->assertStringContainsString( 'eex-badge', $all );

		Cache::flush();
		$html = Components::render(
			'upcoming-sessions',
			[
				'show_ics'        => 0,
				'show_google'     => 0,
				'show_speakers'   => 0,
				'show_categories' => 0,
			]
		);

		$this->assertStringNotContainsString( 'eex_ics=', $html );
		$this->assertStringNotContainsString( 'google.com/calendar', $html );
		$this->assertStringNotContainsString( 'eex-chip', $html );
		$this->assertStringNotContainsString( 'eex-badge', $html );
		$this->assertStringContainsString( 'eex-card-talk', $html, 'the card itself is untouched by the toggles' );

		Cache::flush();
		$schedule = Components::render(
			'schedule',
			[
				'show_speakers'   => 0,
				'show_categories' => 0,
			]
		);
		$this->assertStringNotContainsString( 'eex-chip', $schedule );
		$this->assertStringNotContainsString( 'eex-badge', $schedule );
	}

	private function make_speaker( string $name ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => $name,
			]
		);
	}

	public function test_speaker_layout_and_photo_shape_classes(): void {
		$talk_id = $this->make_talk( 'Speaker host session', 3600 );
		update_post_meta( $talk_id, '_eex_speaker_ids', [ $this->make_speaker( 'Grid Speaker' ) ] );

		$grid = Components::render( 'speakers', [] );
		$this->assertStringContainsString( 'eex-speaker-grid', $grid );
		$this->assertStringContainsString( 'style="--eex-columns:4"', $grid, 'the default columns are unchanged' );
		$this->assertStringNotContainsString( 'eex-photos-', $grid, 'the default photo shape adds no class' );

		Cache::flush();
		$list = Components::render(
			'speakers',
			[
				'layout'      => 'list',
				'photo_shape' => 'circle',
			]
		);
		$this->assertStringContainsString( 'eex-speaker-list', $list );
		$this->assertStringContainsString( 'eex-photos-circle', $list );
		$this->assertStringNotContainsString( 'eex-speaker-grid', $list );

		Cache::flush();
		$auto = Components::render( 'speakers', [ 'columns' => 0 ] );
		$this->assertStringNotContainsString( '--eex-columns', $auto, 'columns 0 hands the variable to the stylesheet or widget' );
	}

	public function test_speakers_paginate_via_their_own_query_var(): void {
		$talk_id = $this->make_talk( 'Paginated speakers session', 3600 );
		update_post_meta(
			$talk_id,
			'_eex_speaker_ids',
			[
				$this->make_speaker( 'Speaker Alpha' ),
				$this->make_speaker( 'Speaker Beta' ),
			]
		);

		$atts = [
			'paginate' => 1,
			'limit'    => 1,
		];

		$page_one = Components::render( 'speakers', $atts );
		$this->assertStringContainsString( 'Speaker Alpha', $page_one );
		$this->assertStringNotContainsString( 'Speaker Beta', $page_one );
		$this->assertStringContainsString( 'eex_speaker_page', $page_one, 'the pagination uses its own query var' );

		$_GET['eex_speaker_page'] = '2';
		try {
			$page_two = Components::render( 'speakers', $atts );
			$this->assertStringContainsString( 'Speaker Beta', $page_two );
			$this->assertStringNotContainsString( 'Speaker Alpha', $page_two, 'page two never serves the cached page one' );
		} finally {
			unset( $_GET['eex_speaker_page'] );
		}
	}

	public function test_event_list_layout(): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Listed event',
				'meta_input'  => [
					'_eex_first_talk_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
					'_eex_last_talk_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() + 2 * DAY_IN_SECONDS ),
				],
			]
		);

		$html = Components::render( 'upcoming-events', [ 'layout' => 'list' ] );

		$this->assertStringContainsString( 'eex-event-list', $html );
		$this->assertStringContainsString( 'Listed event', $html );
		$this->assertStringNotContainsString( 'eex-event-grid', $html );
	}

	public function test_speaker_order_options_and_view_all_link(): void {
		$talk_id = $this->make_talk( 'Order host session', 3600 );
		update_post_meta(
			$talk_id,
			'_eex_speaker_ids',
			[
				$this->make_speaker( 'Alpha Speaker' ),
				$this->make_speaker( 'Beta Speaker' ),
				$this->make_speaker( 'Gamma Speaker' ),
			]
		);

		// Reverse alphabetical.
		$desc = Components::render( 'speakers', [ 'order' => 'name-desc' ] );
		$this->assertGreaterThan(
			strpos( $desc, 'Gamma Speaker' ),
			strpos( $desc, 'Alpha Speaker' ),
			'reverse alphabetical renders Gamma before Alpha'
		);

		// Random respects the limit, disables pagination, and is stable
		// within one cache lifetime.
		Cache::flush();
		$atts   = [
			'order'    => 'random',
			'limit'    => 2,
			'paginate' => 1,
		];
		$random = Components::render( 'speakers', $atts );
		$this->assertSame( 2, substr_count( $random, 'eex-card-speaker' ), 'random shows exactly the limit' );
		$this->assertStringNotContainsString( 'eex-pagination', $random, 'a random sample has no stable pages' );
		$this->assertSame( $random, Components::render( 'speakers', $atts ), 'the selection is cache-stable until a refresh' );

		// Unknown order snaps back to alphabetical.
		Cache::flush();
		$bogus = Components::render( 'speakers', [ 'order' => 'bogus' ] );
		$this->assertLessThan(
			strpos( $bogus, 'Gamma Speaker' ),
			strpos( $bogus, 'Alpha Speaker' ),
			'an unknown order falls back to alphabetical'
		);

		// View-all link: hidden by default, escaped when set.
		$this->assertStringNotContainsString( 'eex-view-all', $bogus );
		Cache::flush();
		$with_link = Components::render(
			'speakers',
			[
				'all_url'  => 'https://example.test/speakers/"><script>',
				'all_text' => 'View all speakers',
			]
		);
		$this->assertStringContainsString( 'eex-view-all', $with_link );
		$this->assertStringContainsString( 'View all speakers', $with_link );
		$this->assertStringNotContainsString( '<script>', $with_link );
	}

	public function test_register_text_is_customisable_and_escaped(): void {
		$talk_id = $this->make_talk( 'CTA session', 3600 );
		update_post_meta( $talk_id, '_eex_talk_url', 'https://summit.example.com/talk/' );

		$default = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( '>Get tickets</a>', $default );
		$this->assertStringContainsString( '>View session</a>', $default );

		Cache::flush();
		$custom = Components::render(
			'upcoming-sessions',
			[
				'register_text' => 'Save my seat <script>',
				'session_text'  => 'Talk details <script>',
			]
		);
		$this->assertStringContainsString( 'Save my seat', $custom );
		$this->assertStringContainsString( 'Talk details', $custom );
		$this->assertStringNotContainsString( '<script>', $custom );
		$this->assertStringNotContainsString( '>Get tickets</a>', $custom );

		Cache::flush();
		$agenda = Components::render(
			'upcoming-sessions',
			[
				'layout'        => 'agenda',
				'register_text' => 'Join us',
			]
		);
		$this->assertStringContainsString( '>Join us</a>', $agenda );
	}

	public function test_next_session_hero_renders_the_soonest_session(): void {
		$this->make_talk( 'Later session', 7200 );
		$soon_id = $this->make_talk( 'Soonest session', 3600 );
		update_post_meta( $soon_id, '_eex_talk_url', 'https://summit.example.com/talk/' );

		$html = Components::render( 'next-session', [] );

		$this->assertStringContainsString( 'eex-hero', $html );
		$this->assertStringContainsString( 'eex-hero-main', $html );
		$this->assertStringContainsString( 'eex-hero-aside', $html );
		$this->assertStringContainsString( 'Soonest session', $html );
		$this->assertStringNotContainsString( 'Later session', $html );
		$this->assertStringContainsString( 'data-eex-countdown', $html );
		$this->assertStringContainsString( 'data-eex-session', $html );

		Cache::flush();
		$plain = Components::render( 'next-session', [ 'show_countdown' => 0 ] );
		$this->assertStringNotContainsString( 'data-eex-countdown', $plain );
	}

	public function test_pricing_table_expands_prices_and_flags(): void {
		$event_id = wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Priced event',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_connection_id' => 'c1',
					'_eex_event_url'     => 'https://summit.example.com/',
				],
			]
		);

		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'                  => 9001,
									'title'               => 'All access',
									'description'         => '<p>Everything included.</p><script>alert(1)</script>',
									'is_paid'             => 'true',
									'mark_as_popular'     => true,
									'quantity_remaining'  => '12',
									'apply_to_broadcasts' => true,
									'apply_to_replays'    => true,
									'prices'              => '[{"id": 501, "title": "Standard", "price": "\u00a399"}]',
								],
								[
									'id'      => 9002,
									'title'   => 'Free pass',
									'is_paid' => 'false',
									'prices'  => '[{"id": 502, "title": "Guest", "price": "0.00"}]',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render( 'pricing', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'eex-pricing', $html );
		$this->assertStringContainsString( 'All access', $html );
		$this->assertStringContainsString( 'Standard', $html );
		$this->assertStringContainsString( 'Most popular', $html );
		$this->assertStringContainsString( 'Only 12 left', $html );
		$this->assertStringContainsString( 'Replays', $html );

		// Descriptions keep their own markup (safe subset), never literal tags.
		$this->assertStringContainsString( '<p>Everything included.</p>', $html );
		$this->assertStringNotContainsString( '&lt;p&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		// A 0.00 price reads as Free.
		$this->assertStringContainsString( '>Free<', $html );
	}

	public function test_speaker_spotlight_and_hub_links(): void {
		$talk_id = $this->make_talk( 'Spotlight host', 3600 );
		$speaker = $this->make_speaker( 'Spot Speaker' );
		update_post_meta( $talk_id, '_eex_speaker_ids', [ $speaker ] );
		update_post_meta( $talk_id, '_eex_source_event_id', '101' );

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub event',
				'meta_input'  => [
					'_eex_heysummit_id' => '101',
					'_eex_event_url'    => 'https://summit.example.com/',
				],
			]
		);

		$html = Components::render( 'speaker-spotlight', [] );
		$this->assertStringContainsString( 'eex-spotlight-speaker', $html );
		$this->assertStringContainsString( 'Spot Speaker', $html );

		// Hub links rewrite speaker URLs to the HeySummit hub.
		Cache::flush();
		$hub = Components::render(
			'speakers',
			[
				'speaker_link' => 'hub',
				'event'        => '101',
			]
		);
		$this->assertStringContainsString( 'summit.example.com/speakers/spot-speaker/', $hub );

		// No link at all.
		Cache::flush();
		$none = Components::render( 'speakers', [ 'speaker_link' => 'none' ] );
		$this->assertStringNotContainsString( '<a href', $none );
		$this->assertStringContainsString( 'Spot Speaker', $none );
	}

	public function test_events_portfolio_filters_by_status(): void {
		foreach ( [
			[ 'Live event', 1, 0 ],
			[ 'Archived event', 1, 1 ],
		] as [ $title, $live, $archived ] ) {
			wp_insert_post(
				[
					'post_type'   => 'eex_event',
					'post_status' => 'publish',
					'post_title'  => $title,
					'meta_input'  => [
						'_eex_heysummit_id' => $title,
						'_eex_is_live'      => $live,
						'_eex_is_archived'  => $archived,
					],
				]
			);
		}

		$live = Components::render( 'events-portfolio', [] );
		$this->assertStringContainsString( 'Live event', $live );
		$this->assertStringNotContainsString( 'Archived event', $live );

		Cache::flush();
		$archived = Components::render( 'events-portfolio', [ 'status' => 'archived' ] );
		$this->assertStringContainsString( 'Archived event', $archived );
		$this->assertStringNotContainsString( 'Live event', $archived );
	}

	public function test_live_now_bar_renders_hidden_with_session_data(): void {
		$this->make_talk( 'Imminent session', 600 );

		$html = Components::render( 'live-now', [] );

		$this->assertStringContainsString( 'data-eex-live-bar', $html );
		$this->assertStringContainsString( 'hidden', $html, 'cached HTML never claims live state' );
		$this->assertStringContainsString( 'data-eex-bar-title="Imminent session"', $html );
		$this->assertStringContainsString( 'data-eex-session', $html );
	}

	public function test_pricing_granular_filters_and_hero_ticket(): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Filtered event',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_connection_id' => 'c1',
					'_eex_event_url'     => 'https://summit.example.com/',
				],
			]
		);
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$this->mock_http(
			static function ( $url ) {
				if ( ! str_contains( (string) $url, 'tickets/' ) ) {
					return null;
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'                 => 9001,
								'title'              => 'All access',
								'is_paid'            => 'true',
								'prices'             => '[{"id": 501, "title": "Standard", "price": "99"}]',
								'quantity_remaining' => '0',
							],
							[
								'id'      => 9002,
								'title'   => 'Free pass',
								'is_paid' => 'false',
								'prices'  => '[]',
							],
							[
								'id'                 => 9003,
								'title'              => 'VIP',
								'is_paid'            => 'true',
								'prices'             => '[{"id": 502, "title": "VIP", "price": "299"}]',
								'quantity_remaining' => '5',
							],
						],
					]
				);
			}
		);

		// Whitelist + hero + custom ribbon.
		$html = Components::render(
			'pricing',
			[
				'event'       => '101',
				'tickets'     => '9001, 9003',
				'featured'    => '9003',
				'ribbon_text' => 'Best value',
			]
		);
		$this->assertStringContainsString( 'All access', $html );
		$this->assertStringContainsString( 'VIP', $html );
		$this->assertStringNotContainsString( 'Free pass', $html );
		$this->assertStringContainsString( 'eex-pricing-hero', $html );
		$this->assertStringContainsString( 'Best value', $html );
		$this->assertSame( 1, substr_count( $html, 'eex-badge-popular' ), 'only the hero carries the ribbon' );

		// Exclude + hide free + hide sold out.
		Cache::flush();
		$html = Components::render(
			'pricing',
			[
				'event'        => '101',
				'exclude'      => '9003',
				'show_free'    => 0,
				'hide_soldout' => 1,
			]
		);
		$this->assertStringContainsString( 'eex-empty', $html, '9001 is sold out, 9002 is free, 9003 excluded' );

		// Covers toggle off removes the coverage badges.
		Cache::flush();
		$html = Components::render(
			'pricing',
			[
				'event'       => '101',
				'show_covers' => 0,
			]
		);
		$this->assertStringNotContainsString( 'Live sessions', $html );
	}

	/**
	 * A talk linked to a synced event with a public URL, for register-link
	 * and drawer coverage.
	 */
	private function make_linked_talk(): int {
		$talk_id = $this->make_talk( 'Linked session', 3600 );
		update_post_meta( $talk_id, '_eex_source_event_id', '101' );
		update_post_meta( $talk_id, '_eex_talk_url', 'https://summit.example.com/talks/linked-session/' );
		update_post_meta( $talk_id, '_eex_heysummit_id', '777' );

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Linked event',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_connection_id' => 'c1',
					'_eex_event_url'     => 'https://summit.example.com/',
				],
			]
		);

		return $talk_id;
	}

	public function test_hero_styles_are_selectable_and_bogus_values_fall_back(): void {
		$this->make_talk( 'Styled session', 3600 );

		$this->assertStringContainsString( 'eex-hero-panel', Components::render( 'next-session', [] ) );

		Cache::flush();
		$this->assertStringContainsString( 'eex-hero-spotlight', Components::render( 'next-session', [ 'layout' => 'spotlight' ] ) );

		Cache::flush();
		$this->assertStringContainsString( 'eex-hero-banner', Components::render( 'next-session', [ 'layout' => 'banner' ] ) );

		Cache::flush();
		$this->assertStringContainsString( 'eex-hero-minimal', Components::render( 'next-session', [ 'layout' => 'minimal' ] ) );

		Cache::flush();
		$this->assertStringContainsString( 'eex-hero-panel', Components::render( 'next-session', [ 'layout' => 'bogus' ] ), 'unknown styles snap to the default' );
	}

	public function test_session_buttons_offer_tickets_and_the_talk_page(): void {
		$this->make_linked_talk();

		// Default: both buttons — tickets to the operator-verified
		// ticket-selection page, session to the talk's own landing page.
		$html = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'summit.example.com/checkout/select-tickets/', $html );
		$this->assertStringContainsString( 'eex-cta-session', $html );
		$this->assertStringContainsString( 'summit.example.com/talks/linked-session/', $html );

		// Session button only.
		Cache::flush();
		$session = Components::render( 'upcoming-sessions', [ 'buttons' => 'session' ] );
		$this->assertStringNotContainsString( 'select-tickets', $session );
		$this->assertStringContainsString( 'summit.example.com/talks/linked-session/', $session );

		// Tickets button only.
		Cache::flush();
		$tickets = Components::render( 'upcoming-sessions', [ 'buttons' => 'tickets' ] );
		$this->assertStringContainsString( 'summit.example.com/checkout/select-tickets/', $tickets );
		$this->assertStringNotContainsString( 'eex-cta-session', $tickets );

		// External ticketing replaces the checkout link.
		Cache::flush();
		$external = Components::render( 'upcoming-sessions', [ 'register_url' => 'https://tickets.example.org/buy' ] );
		$this->assertStringContainsString( 'https://tickets.example.org/buy', $external );
		$this->assertStringNotContainsString( '/checkout/', $external );
	}

	public function test_pricing_buttons_preselect_on_the_ticket_page(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render( 'pricing', [ 'event' => '101' ] );

		// The paid ticket carries the API's dedicated checkout link; the free
		// one has none, so it keeps the constructed select-tickets fallback.
		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $html );
		$this->assertStringNotContainsString( 'select-tickets/?ticket=9001', $html );
		$this->assertStringContainsString( 'checkout/select-tickets/?ticket=9002', $html );

		// A ticket price mapped to a WooCommerce product can sell on THIS
		// site — but only when the operator opts in via buy_on.
		wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Associate Membership (Woo)',
				'meta_input'  => [ '_eex_hs_ticket' => '501' ],
			]
		);
		Cache::flush();
		delete_transient( 'eex_tickets_' . md5( 'v2|c1|101' ) );

		$default = Components::render( 'pricing', [ 'event' => '101' ] );
		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $default, 'HeySummit checkout stays the default despite the mapping' );

		Cache::flush();
		$woo = Components::render(
			'pricing',
			[
				'event'  => '101',
				'buy_on' => 'woo',
			]
		);
		$this->assertStringNotContainsString( 'checkout/ticket/9001-abc/', $woo, 'the mapping outranks the API checkout link' );
		$this->assertStringContainsString( '?p=', $woo, 'opted in: the mapped ticket links to the local product' );
	}

	public function test_ticket_checkout_links_carry_the_pages_utm_query(): void {
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'example.org',
				'utm_medium'  => 'events',
			]
		);
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render( 'pricing', [ 'event' => '101' ] );

		// The API link arrives untagged; the page's attribution rides along
		// exactly as it would have on the constructed URL.
		$this->assertStringContainsString( 'checkout/ticket/9001-abc/?utm_source=example.org', $html );
		$this->assertStringContainsString( 'utm_medium=events', $html );
	}

	public function test_a_checkout_links_own_query_is_never_clobbered(): void {
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'example.org',
				'utm_medium'  => 'events',
			]
		);
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-abc/?utm_source=already',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render( 'pricing', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'utm_source=already', $html );
		$this->assertStringNotContainsString( 'utm_source=example.org', $html );
		$this->assertStringContainsString( 'utm_medium=events', $html, 'params the link lacks are still carried over' );
	}

	public function test_a_non_web_checkout_link_falls_back_to_the_constructed_url(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'javascript:alert(1)',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render( 'pricing', [ 'event' => '101' ] );

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
		$this->assertStringContainsString( 'checkout/select-tickets/?ticket=9001', $html );
	}

	public function test_external_ticketing_overrides_the_checkout_link(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render(
			'pricing',
			[
				'event'        => '101',
				'register_url' => 'https://tickets.example.org/buy',
			]
		);

		$this->assertStringContainsString( 'https://tickets.example.org/buy', $html );
		$this->assertStringNotContainsString( 'checkout/ticket/9001-abc/', $html );
	}

	public function test_a_coupon_bakes_into_ticket_checkout_links_and_the_link_is_cached(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$posts = 0;
		$this->mock_http(
			function ( $url, $args ) use ( &$posts ) {
				// The generator route also contains "tickets/" — match it first.
				if ( str_contains( (string) $url, 'checkout-link/' ) ) {
					++$posts;
					$this->assertSame( 'POST', (string) ( $args['method'] ?? '' ) );
					$this->assertStringContainsString( 'events/101/tickets/9001/checkout-link/', (string) $url );
					$this->assertSame( '{"coupon":"SAVE20"}', (string) ( $args['body'] ?? '' ) );

					return self::json_response( [ 'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-COUPONED/' ] );
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-abc/',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render(
			'pricing',
			[
				'event'  => '101',
				'coupon' => 'SAVE20',
			]
		);

		$this->assertStringContainsString( 'checkout/ticket/9001-COUPONED/', $html, 'the couponed link replaces the plain one' );
		$this->assertStringNotContainsString( 'checkout/ticket/9001-abc/', $html );
		$this->assertSame( 1, $posts );

		// A second render reuses the cached link: no second POST.
		Cache::flush();
		$again = Components::render(
			'pricing',
			[
				'event'  => '101',
				'coupon' => 'SAVE20',
			]
		);
		$this->assertStringContainsString( 'checkout/ticket/9001-COUPONED/', $again );
		$this->assertSame( 1, $posts, 'generated links are cached per ticket+coupon' );
	}

	public function test_a_failed_coupon_generation_keeps_the_plain_link_and_is_not_retried(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$posts = 0;
		$this->mock_http(
			function ( $url ) use ( &$posts ) {
				if ( str_contains( (string) $url, 'checkout-link/' ) ) {
					++$posts;

					return self::json_response( [ 'detail' => 'Coupon not found.' ], 404 );
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-abc/',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render(
			'pricing',
			[
				'event'  => '101',
				'coupon' => 'EXPIRED',
			]
		);

		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $html, 'a bad coupon costs the discount, never the button' );
		$this->assertStringNotContainsString( 'COUPONED', $html );
		$this->assertSame( 1, $posts );

		// The failure is negative-cached: the next render does not re-POST.
		Cache::flush();
		Components::render(
			'pricing',
			[
				'event'  => '101',
				'coupon' => 'EXPIRED',
			]
		);
		$this->assertSame( 1, $posts, 'failures are negative-cached' );
	}

	public function test_external_ticketing_overrides_a_couponed_link_too(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'checkout-link/' ) ) {
					return self::json_response( [ 'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-COUPONED/' ] );
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'      => 9001,
									'title'   => 'All access',
									'is_paid' => 'true',
									'prices'  => '[]',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render(
			'pricing',
			[
				'event'        => '101',
				'coupon'       => 'SAVE20',
				'register_url' => 'https://tickets.example.org/buy',
			]
		);

		$this->assertStringContainsString( 'https://tickets.example.org/buy', $html );
		$this->assertStringNotContainsString( 'COUPONED', $html );
	}

	public function test_register_panel_renders_the_ticket_drawer(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render(
			'upcoming-sessions',
			[
				'register_action' => 'panel',
				'event'           => '101',
			]
		);

		$this->assertStringContainsString( 'data-eex-drawer="eex-drawer-', $html, 'the Register button points at the panel' );
		$this->assertStringContainsString( 'eex-drawer-panel', $html );
		$this->assertStringContainsString( 'role="dialog"', $html );
		$this->assertStringContainsString( 'All access', $html, 'the drawer lists the tickets' );

		// The free ticket registers in the drawer; the paid one deep-links
		// to its own checkout.
		$this->assertStringContainsString( 'data-eex-reg="1"', $html, 'free ticket gets the in-drawer form' );
		$this->assertStringContainsString( 'name="consent"', $html );
		$this->assertStringContainsString( 'name="website"', $html, 'honeypot present' );
		$this->assertSame( 1, substr_count( $html, 'data-eex-reg="1"' ), 'only the free ticket gets a form' );
		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $html, 'the paid ticket links to its API checkout link' );

		// The drawer honours the ticket filters.
		Cache::flush();
		$filtered = Components::render(
			'upcoming-sessions',
			[
				'register_action' => 'panel',
				'event'           => '101',
				'exclude'         => '9002',
			]
		);
		$this->assertStringContainsString( 'All access', $filtered );
		$this->assertStringNotContainsString( 'Free pass', $filtered, 'excluded tickets stay out of the panel' );

		// Plain links by default: no drawer markup at all.
		Cache::flush();
		$plain = Components::render( 'upcoming-sessions', [ 'event' => '101' ] );
		$this->assertStringNotContainsString( 'eex-drawer', $plain );
	}

	public function test_the_drawer_bakes_coupons_and_gets_its_own_id(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'checkout-link/' ) ) {
					return self::json_response( [ 'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-COUPONED/' ] );
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-abc/',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$plain = Components::render(
			'upcoming-sessions',
			[
				'register_action' => 'panel',
				'event'           => '101',
			]
		);
		Cache::flush();
		$couponed = Components::render(
			'upcoming-sessions',
			[
				'register_action' => 'panel',
				'event'           => '101',
				'coupon'          => 'SAVE20',
			]
		);

		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $plain );
		$this->assertStringContainsString( 'checkout/ticket/9001-COUPONED/', $couponed );

		preg_match( '/data-eex-drawer="(eex-drawer-[0-9a-f]+)"/', $plain, $a );
		preg_match( '/data-eex-drawer="(eex-drawer-[0-9a-f]+)"/', $couponed, $b );
		$this->assertNotEmpty( $a[1] ?? '' );
		$this->assertNotEmpty( $b[1] ?? '' );
		$this->assertNotSame( $a[1], $b[1], 'coupon variants get distinct drawer ids' );
	}

	public function test_a_failed_refetch_serves_the_last_good_fragment(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$good = Components::render( 'pricing', [ 'event' => '101' ] );
		$this->assertStringContainsString( 'All access', $good );

		// The display cache is flushed, the ticket cache has expired, and
		// the API is now down: the last good fragment must be served, not a
		// fresh empty state.
		Cache::flush();
		delete_transient( 'eex_tickets_' . md5( 'v2|c1|101' ) );
		\EEX_Test_State::$filters['pre_http_request'] = [];
		$this->mock_http( static fn() => new \WP_Error( 'http_request_failed', 'timed out' ) );

		$again = Components::render( 'pricing', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'All access', $again, 'the last good fragment survives the failure' );
		$this->assertStringNotContainsString( 'eex-empty', $again );
	}

	public function test_empty_states_are_never_cached_for_the_full_display_ttl(): void {
		Options::update_settings( [ 'cache_ttl' => 1440 ] );

		Components::render( 'upcoming-sessions', [] );

		$ttls = array_filter(
			\EEX_Test_State::$transient_ttls,
			static fn( $key ): bool => str_starts_with( (string) $key, 'eex_c_' ),
			ARRAY_FILTER_USE_KEY
		);

		$this->assertNotEmpty( $ttls );
		$this->assertLessThanOrEqual( MINUTE_IN_SECONDS, max( $ttls ), 'an empty state must be retried within a minute' );

		// hide_empty changes what visitors see, never what is cached: the
		// real empty fragment still lands under the guardrail TTL.
		\EEX_Test_State::$user_can = false;
		$this->assertSame( '', Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] ) );

		$hidden_fragments = array_filter(
			\EEX_Test_State::$transients,
			static fn( $value, $key ): bool => str_starts_with( (string) $key, 'eex_c_' ) && is_string( $value ) && str_contains( $value, 'eex-empty' ),
			ARRAY_FILTER_USE_BOTH
		);
		$this->assertNotEmpty( $hidden_fragments, 'the real empty fragment is cached even when hidden' );
	}

	public function test_register_bar_renders_countdown_live_slot_and_cta(): void {
		$this->make_linked_talk();

		$html = Components::render( 'register-bar', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'eex-register-bar', $html );
		$this->assertStringContainsString( 'eex-bar-bottom', $html, 'pins to the bottom by default' );
		$this->assertStringContainsString( 'data-eex-bar-offset="400"', $html );
		$this->assertStringContainsString( 'Linked event', $html, 'the bar text defaults to the event title' );
		$this->assertStringContainsString( 'summit.example.com/checkout/select-tickets/', $html );
		$this->assertStringContainsString( 'data-eex-countdown', $html, 'the countdown chip rides along by default' );
		$this->assertStringContainsString( 'data-eex-session="1"', $html, 'session attributes power the live flip' );
		$this->assertStringContainsString( 'data-eex-bar-dismiss', $html, 'dismissible by default' );
		$this->assertStringContainsString( 'role="region"', $html );

		// Options: top position, no extras, not dismissible, custom text.
		Cache::flush();
		$plain = Components::render(
			'register-bar',
			[
				'event'          => '101',
				'position'       => 'top',
				'text'           => 'Last chance!',
				'show_countdown' => 0,
				'show_live'      => 0,
				'dismissible'    => 0,
			]
		);
		$this->assertStringContainsString( 'eex-bar-top', $plain );
		$this->assertStringContainsString( 'Last chance!', $plain );
		$this->assertStringNotContainsString( 'data-eex-countdown', $plain );
		$this->assertStringNotContainsString( 'data-eex-session', $plain );
		$this->assertStringNotContainsString( 'data-eex-bar-dismiss', $plain );
	}

	public function test_register_bar_panel_mode_carries_the_ticket_drawer(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render(
			'register-bar',
			[
				'event'           => '101',
				'register_action' => 'panel',
			]
		);

		$this->assertStringContainsString( 'data-eex-drawer="eex-drawer-', $html );
		$this->assertStringContainsString( 'eex-drawer-panel', $html );
	}

	public function test_register_inline_renders_the_free_form_or_a_paid_cta(): void {
		$this->make_linked_talk();
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);
		$this->mock_ticket_endpoint();

		$html = Components::render(
			'register-inline',
			[
				'event'   => '101',
				'heading' => 'Join us free',
			]
		);

		$this->assertStringContainsString( 'Join us free', $html );
		$this->assertStringContainsString( 'data-eex-reg="1"', $html );
		$this->assertStringNotContainsString( '<form class="eex-reg-form" data-eex-reg="1" hidden>', $html, 'the inline form is visible, not toggled' );
		$this->assertStringContainsString( 'name="ticket" value="9002"', $html, 'the free ticket is picked automatically' );
		$this->assertStringContainsString( 'name="website"', $html, 'honeypot present' );
		$this->assertStringContainsString( 'name="consent"', $html );

		// Paid-only event: a checkout CTA, never a dead form.
		Cache::flush();
		delete_transient( 'eex_tickets_' . md5( 'v2|c1|101' ) );
		\EEX_Test_State::$filters['pre_http_request'] = [];
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'            => 9001,
									'title'         => 'All access',
									'is_paid'       => 'true',
									'prices'        => '[]',
									'checkout_link' => 'https://summit.example.com/checkout/ticket/9001-abc/',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$paid = Components::render( 'register-inline', [ 'event' => '101' ] );
		$this->assertStringNotContainsString( 'data-eex-reg', $paid );
		$this->assertStringContainsString( 'checkout/ticket/9001-abc/', $paid, 'paid-only events fall back to the checkout link' );
	}

	public function test_stats_strip_counts_and_suppresses_zeroes(): void {
		$talk_id = $this->make_linked_talk();
		update_post_meta( $talk_id, '_eex_speaker_ids', [ $this->make_speaker( 'Stat Speaker' ) ] );

		$html = Components::render(
			'stats',
			[
				'event' => '101',
				'items' => 'speakers,sessions,days,registered',
			]
		);

		$this->assertStringContainsString( 'eex-stats', $html );
		$this->assertStringContainsString( 'Speakers', $html );
		$this->assertStringContainsString( 'Sessions', $html );
		$this->assertStringContainsString( 'Days', $html );
		$this->assertStringNotContainsString( 'Registered', $html, 'a zero stat renders nothing' );
		$this->assertStringNotContainsString( 'data-eex-countup', $html, 'animation is opt-in' );

		Cache::flush();
		$animated = Components::render(
			'stats',
			[
				'event'   => '101',
				'items'   => 'sessions',
				'animate' => 1,
			]
		);
		$this->assertStringContainsString( 'data-eex-countup="1"', $animated );
	}

	public function test_replay_gallery_lists_only_sessions_with_replays(): void {
		$this->make_linked_talk();

		$with = $this->make_talk( 'Recorded session', -7200 );
		update_post_meta( $with, '_eex_source_event_id', '101' );
		update_post_meta( $with, '_eex_replay_url', 'https://videos.example.com/recorded-session' );

		$without = $this->make_talk( 'Unrecorded session', -7200 );
		update_post_meta( $without, '_eex_source_event_id', '101' );

		$html = Components::render( 'replay-gallery', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'eex-replay-grid', $html );
		$this->assertStringContainsString( 'Recorded session', $html );
		$this->assertStringNotContainsString( 'Unrecorded session', $html );
		$this->assertStringContainsString( 'Watch the replay', $html );
		$this->assertStringContainsString( 'eex-replay-play', $html, 'the play overlay renders with the image slot' );
	}

	public function test_venue_card_renders_address_and_is_absent_in_lite(): void {
		$this->make_linked_talk();

		$event_post = get_posts(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			]
		)[0];
		update_post_meta( $event_post, '_eex_venue_name', 'The Exchange' );
		update_post_meta( $event_post, '_eex_venue_street', '1 Exchange Square' );
		update_post_meta( $event_post, '_eex_venue_locality', 'Croydon' );
		update_post_meta( $event_post, '_eex_venue_postcode', 'CR0 1UH' );

		$html = Components::render( 'venue', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'eex-venue-card', $html );
		$this->assertStringContainsString( 'The Exchange', $html );
		$this->assertStringContainsString( '1 Exchange Square', $html );
		$this->assertStringContainsString( 'google.com/maps/search', $html );
		$this->assertStringContainsString( 'Directions', $html );

		// A Full-only component: Lite offers and renders nothing.
		Options::update_settings( [ 'mode' => 'lite' ] );
		$this->assertSame( '', Components::render( 'venue', [ 'event' => '101' ] ) );
	}

	public function test_featured_session_shows_location_and_compact_view(): void {
		$talk_id = $this->make_linked_talk();
		update_post_meta( $talk_id, '_eex_talk_venue', 'Main Stage, The Exchange' );
		update_post_meta( $talk_id, '_eex_inperson', 1 );

		$event_post = get_posts(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'any',
				'numberposts' => 1,
				'fields'      => 'ids',
			]
		)[0];
		update_post_meta( $event_post, '_eex_venue_name', 'The Exchange' );
		update_post_meta( $event_post, '_eex_venue_locality', 'Croydon' );

		// Default: the next upcoming session, wide card view.
		$html = Components::render( 'featured-session', [ 'event' => '101' ] );

		$this->assertStringContainsString( 'eex-feature-card', $html );
		$this->assertStringContainsString( 'Linked session', $html );
		$this->assertStringContainsString( 'Main Stage, The Exchange', $html );
		$this->assertStringContainsString( 'In person', $html );
		$this->assertStringContainsString( 'Croydon', $html, 'the event venue address joins the card' );
		$this->assertStringContainsString( 'google.com/maps/search', $html );
		$this->assertStringContainsString( 'data-eex-session="1"', $html );
		$this->assertStringContainsString( 'summit.example.com/checkout/select-tickets/', $html );

		// Compact sidebar view, hand-picked by HeySummit ID.
		Cache::flush();
		$compact = Components::render(
			'featured-session',
			[
				'talk' => '777',
				'view' => 'compact',
			]
		);
		$this->assertStringContainsString( 'eex-feature-compact', $compact );
		$this->assertStringContainsString( 'Linked session', $compact );
	}

	public function test_schedule_extras_are_absent_until_opted_in(): void {
		$this->make_linked_talk();
		$second = $this->make_talk( 'Second day session', 90000 );
		update_post_meta( $second, '_eex_source_event_id', '101' );

		$plain = Components::render( 'schedule', [ 'event' => '101' ] );
		$this->assertStringNotContainsString( 'eex-day-nav', $plain );
		$this->assertStringNotContainsString( 'eex-tz-toggle', $plain );
		$this->assertStringNotContainsString( ' id="eex-day-', $plain );

		Cache::flush();
		$extras = Components::render(
			'schedule',
			[
				'event'          => '101',
				'day_nav'        => 1,
				'show_tz_toggle' => 1,
			]
		);
		$this->assertStringContainsString( 'eex-day-nav', $extras );
		$this->assertStringContainsString( '#eex-day-', $extras );
		$this->assertStringContainsString( ' id="eex-day-', $extras, 'day sections gain jump anchors' );
		$this->assertStringContainsString( 'data-eex-tz-toggle', $extras );
		$this->assertStringContainsString( 'hidden', $extras, 'the toggle stays hidden until JS reveals it' );
	}

	public function test_speaker_links_render_only_when_opted_in(): void {
		$talk_id    = $this->make_talk( 'Social session', 3600 );
		$speaker_id = $this->make_speaker( 'Networked Speaker' );
		update_post_meta( $speaker_id, '_eex_links', [ 'https://www.linkedin.com/in/networked', 'https://example.org/blog' ] );
		update_post_meta( $talk_id, '_eex_speaker_ids', [ $speaker_id ] );

		$plain = Components::render( 'speakers', [] );
		$this->assertStringNotContainsString( 'eex-speaker-links', $plain );

		Cache::flush();
		$linked = Components::render( 'speakers', [ 'show_links' => 1 ] );
		$this->assertStringContainsString( 'eex-speaker-links', $linked );
		$this->assertStringContainsString( 'LinkedIn', $linked );
		$this->assertStringContainsString( 'example.org', $linked, 'unknown hosts fall back to their domain' );
		$this->assertStringContainsString( 'rel="noopener"', $linked );
		$this->assertStringContainsString( 'Networked Speaker on LinkedIn', $linked, 'accessible labels name speaker and network' );
	}

	/**
	 * Two tickets on event 101: 9001 (paid, popular) and 9002 (free).
	 */
	private function mock_ticket_endpoint(): void {
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'                 => 9001,
									'title'              => 'All access',
									'is_paid'            => 'true',
									'mark_as_popular'    => true,
									'quantity_remaining' => '12',
									'prices'             => '[{"id": 501, "title": "Standard", "price": "99"}]',
									'checkout_link'      => 'https://summit.example.com/checkout/ticket/9001-abc/',
								],
								[
									'id'      => 9002,
									'title'   => 'Free pass',
									'is_paid' => 'false',
									'prices'  => '[]',
								],
							],
						]
					);
				}

				return null;
			}
		);
	}
}
