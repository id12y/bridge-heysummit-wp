<?php
/**
 * Component rendering: shared callbacks, empty states, caching, escaping.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
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
}
