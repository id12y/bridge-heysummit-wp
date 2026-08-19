<?php
/**
 * Composition rendering, cache and time acceptance scenarios (v1.50.0).
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Data\EventPresentation;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Frontend\Blocks;
use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Selection\EventSelector;
use Emailexpert\Events\Frontend\Shortcodes;
use Emailexpert\Events\Frontend\Compositions\EventLanding;
use Emailexpert\Events\Options;
use Emailexpert\Events\Support\Clock;
use Emailexpert\Events\Tests\TestCase;

/**
 * The Homepage Editorial Hero and Event Landing Page through the real
 * render pipeline: registration surfaces, hierarchy, deduplication in
 * markup, media priority, empty states, admin preview isolation, schema,
 * and the boundary-aware cache.
 *
 * @covers \Emailexpert\Events\Frontend\Compositions\HomepageHero
 * @covers \Emailexpert\Events\Frontend\Compositions\EventLanding
 * @covers \Emailexpert\Events\Frontend\Compositions\CtaResolver
 * @covers \Emailexpert\Events\Frontend\Compositions\MediaResolver
 * @covers \Emailexpert\Events\Frontend\Compositions\CompositionSchema
 * @covers \Emailexpert\Events\Frontend\Cache
 */
final class CompositionsTest extends TestCase {

	/**
	 * The frozen reference moment.
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

	private function make_event( string $title, string $hs_id, int $start_offset, ?int $end_offset = null, array $meta = [] ): int {
		$start = $this->t0 + $start_offset;
		$end   = null !== $end_offset ? $this->t0 + $end_offset : $start + 2 * HOUR_IN_SECONDS;

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

	private function make_talk( string $title, string $hs_id, string $event_hs_id, int $start_offset, array $meta = [] ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => $title,
				'meta_input'  => $meta + [
					'_eex_heysummit_id'    => $hs_id,
					'_eex_source_event_id' => $event_hs_id,
					'_eex_starts_at'       => $this->iso( $this->t0 + $start_offset ),
					'_eex_ends_at'         => $this->iso( $this->t0 + $start_offset + 3600 ),
				],
			]
		);
	}

	private function make_story( string $title, int $age_seconds, array $overrides = [] ): int {
		return wp_insert_post(
			$overrides + [
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_title'    => $title,
				'post_content'  => str_repeat( 'Editorial body copy. ', 60 ),
				'post_date'     => gmdate( 'Y-m-d H:i:s', $this->t0 - $age_seconds ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', $this->t0 - $age_seconds ),
			]
		);
	}

	private function fixture(): void {
		$this->make_event( 'Double Optin', '101', 9 * DAY_IN_SECONDS );
		$this->make_event( 'London Forum', '104', 89 * DAY_IN_SECONDS, 90 * DAY_IN_SECONDS );
		$this->make_talk( 'Opening keynote', '501', '101', 9 * DAY_IN_SECONDS );
		$this->make_story( 'Gmail tightens the rules', 3600 );
		$this->make_story( 'DMARC adoption doubles', 7200 );
		$this->make_story( 'BIMI in the wild', 10800 );
	}

	private function go_lite( array $lite_events = [ 'c1|101' ] ): void {
		update_option( Options::CONNECTIONS, [ [ 'id' => 'c1', 'label' => 'Primary', 'api_key' => 'secret-key-123' ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => $lite_events,
			]
		);
		Repositories::reset();
		EventSelector::reset_request_state();
	}

	/**
	 * A Lite HTTP mock serving one configured event with one talk.
	 *
	 * @param bool $fail Serve WP_Error instead.
	 */
	private function mock_lite_api( bool $fail = false ): void {
		$t0 = $this->t0;
		$this->mock_http( function ( $url ) use ( $fail, $t0 ) {
			if ( $fail ) {
				return new \WP_Error( 'http_request_failed', 'timeout' );
			}
			if ( str_contains( (string) $url, 'tickets/' ) ) {
				return self::json_response( [ 'results' => [] ] );
			}
			if ( str_contains( (string) $url, 'talks/' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'        => 501,
								'title'     => 'Live keynote',
								'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS ),
								'ends_at'   => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS + 3600 ),
								'event'     => 101,
							],
						],
					]
				);
			}
			if ( str_contains( (string) $url, 'events/' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'                        => 101,
								'title'                     => 'Lite Summit',
								'starts_at'                 => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS ),
								'ends_at'                   => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS + 7200 ),
								'is_open_for_registrations' => true,
								'event_url'                 => 'https://hs.example/lite-summit/',
								'feature_image'             => 'https://cdn.example/lite-summit.jpg',
							],
						],
					]
				);
			}

			return null;
		} );
	}

	// ---- Rendering -------------------------------------------------------

	public function test_the_new_blocks_register_with_enum_validated_attributes(): void {
		$GLOBALS['eex_test_blocks'] = [];

		( new Blocks() )->register_blocks();

		$this->assertArrayHasKey( 'eex/homepage-hero', $GLOBALS['eex_test_blocks'], 'the homepage hero block registers' );
		$this->assertArrayHasKey( 'eex/event-landing', $GLOBALS['eex_test_blocks'], 'the event landing block registers' );

		$hero_atts = (array) $GLOBALS['eex_test_blocks']['eex/homepage-hero']['attributes'];
		$this->assertSame(
			[ 'auto', 'manual_event', 'manual_session', 'none' ],
			$hero_atts['featured_source']['enum'],
			'enum attributes validate server-side'
		);
	}

	public function test_the_new_shortcodes_register_in_both_modes(): void {
		$GLOBALS['eex_test_shortcodes'] = [];
		( new Shortcodes() )->register();

		$this->assertArrayHasKey( 'eex_homepage_hero', $GLOBALS['eex_test_shortcodes'] );
		$this->assertArrayHasKey( 'eex_event_landing', $GLOBALS['eex_test_shortcodes'] );

		// And in Lite: no composite is Full-only.
		$this->go_lite();
		$GLOBALS['eex_test_shortcodes'] = [];
		( new Shortcodes() )->register();

		$this->assertArrayHasKey( 'eex_homepage_hero', $GLOBALS['eex_test_shortcodes'], 'the hero is first-class in Lite' );
		$this->assertArrayHasKey( 'eex_event_landing', $GLOBALS['eex_test_shortcodes'], 'the landing page is first-class in Lite' );
	}

	public function test_compositions_are_flagged_and_never_full_only(): void {
		$definitions = Components::definitions();

		foreach ( Components::COMPOSITES as $component ) {
			$this->assertTrue( ! empty( $definitions[ $component ]['composite'] ), $component . ' carries the composite flag for the dedicated Elementor widgets' );
			$this->assertNotContains( $component, Components::FULL_ONLY, $component . ' works in Lite' );
		}
	}

	public function test_shortcode_output_matches_the_component_render(): void {
		$this->fixture();

		( new Shortcodes() )->register();

		$via_component = Components::render( 'homepage-hero', [] );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$via_shortcode = call_user_func( $GLOBALS['eex_test_shortcodes']['eex_homepage_hero'], [] );

		$this->assertSame( $via_component, $via_shortcode, 'one renderer behind every surface' );
	}

	public function test_hide_empty_hides_an_empty_composition_for_visitors(): void {
		\EEX_Test_State::$user_can = false;

		$html = Components::render( 'homepage-hero', [ 'hide_empty' => 1, 'story_source' => 'none', 'featured_source' => 'none', 'news_show' => 0, 'more_events' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( '', $html, 'hide_empty removes the empty composition entirely' );
	}

	public function test_heading_context_controls_the_h1(): void {
		$this->fixture();

		$page = Components::render( 'homepage-hero', [ 'heading_context' => 'page' ] );
		$this->assertSame( 1, substr_count( $page, '<h1' ), 'page-hero context renders exactly one H1' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$embedded = Components::render( 'homepage-hero', [] );
		$this->assertSame( 0, substr_count( $embedded, '<h1' ), 'the embedded default never forces an H1' );
	}

	public function test_the_embedded_event_landing_does_not_force_an_h1_and_the_page_context_has_one(): void {
		$this->fixture();

		$embedded = Components::render( 'event-landing', [ 'event_source' => 'manual', 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 0, substr_count( $embedded, '<h1' ), 'an embedded landing uses H2' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$page = Components::render(
			'event-landing',
			[
				'event_source'    => 'manual',
				'event'           => '101',
				'heading_context' => 'page',
			]
		);
		$this->assertSame( 1, substr_count( $page, '<h1' ), 'the single-event template context uses one H1' );
	}

	public function test_the_featured_event_is_absent_from_more_events_markup(): void {
		$this->fixture();
		\EEX_Test_State::$user_can = false; // The visitor view, without editor comments.

		$html = Components::render( 'homepage-hero', [] );

		$this->assertStringContainsString( 'Opening keynote', $html, 'the featured target renders (Double Optin via its next session)' );
		$this->assertStringContainsString( 'London Forum', $html, 'the other event fills More Events' );

		$more = substr( $html, (int) strpos( $html, 'eex-hh__more' ) );
		$this->assertStringNotContainsString( 'Double Optin', $more, 'the featured event never repeats in the More Events markup' );
	}

	/**
	 * A Lite mock with a configurable ticket list, so the auto registration
	 * resolution can be exercised against free, paid and absent tickets.
	 *
	 * @param array<int,array<string,mixed>> $tickets Ticket rows for event 101.
	 */
	private function mock_lite_api_with_tickets( array $tickets ): void {
		$t0 = $this->t0;
		$this->mock_http( function ( $url ) use ( $tickets, $t0 ) {
			if ( str_contains( (string) $url, 'tickets/' ) ) {
				return self::json_response( [ 'results' => $tickets ] );
			}
			if ( str_contains( (string) $url, 'talks/' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'        => 501,
								'title'     => 'Live keynote',
								'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS ),
								'ends_at'   => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS + 3600 ),
								'event'     => 101,
							],
						],
					]
				);
			}
			if ( str_contains( (string) $url, 'events/' ) ) {
				return self::json_response(
					[
						'results' => [
							[
								'id'                        => 101,
								'title'                     => 'Lite Summit',
								'starts_at'                 => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS ),
								'ends_at'                   => gmdate( 'Y-m-d\TH:i:s\Z', $t0 + 5 * DAY_IN_SECONDS + 7200 ),
								'is_open_for_registrations' => true,
								'event_url'                 => 'https://hs.example/lite-summit/',
							],
						],
					]
				);
			}

			return null;
		} );
	}

	public function test_rich_session_rows_register_through_the_shared_rsvp_implementation(): void {
		$this->go_lite();
		$this->mock_lite_api_with_tickets(
			[
				[
					'id'      => 11,
					'title'   => 'Free pass',
					'is_paid' => false,
					'prices'  => [ [ 'id' => 111, 'price' => '0.00' ] ],
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		// Featured presents the event itself, so its session fills the rich
		// strip; presentation auto resolves to rich horizontal there.
		$html = Components::render(
			'homepage-hero',
			[
				'story_source'             => 'none',
				'news_show'                => 0,
				'event_presentation'       => 'event',
				'more_events_mode'         => 'upcoming_sessions',
				'more_events_presentation' => 'auto',
			]
		);

		$this->assertStringContainsString( 'eex-compact-event--rich', $html, 'auto resolves session rows to the rich treatment' );
		$this->assertStringContainsString( 'eex-hh__more--lay-rich-h', $html, 'the strip takes the horizontal arrangement' );

		$more = substr( $html, (int) strpos( $html, 'eex-hh__more' ) );
		$this->assertStringContainsString( '>Register<', $more, 'a free session says Register, not Get tickets' );
		$this->assertStringContainsString( 'data-eex-reg-toggle="1"', $more, 'the shared RSVP toggle contract' );
		$this->assertStringContainsString( 'data-eex-reg="1"', $more, 'the shared register-form part renders (hidden) in the row' );
		$this->assertStringContainsString( 'name="ticket" value="11"', $more, 'the same free ticket the classic widgets register' );
		$this->assertStringContainsString( 'name="talk" value="501"', $more, 'the session lands on the schedule through the same form' );
		$this->assertStringContainsString( 'Online', $more, 'the meta line carries the format' );
	}

	public function test_rich_session_rows_use_the_shared_ticket_panel_for_paid_events(): void {
		$this->go_lite();
		$this->mock_lite_api_with_tickets(
			[
				[
					'id'      => 22,
					'title'   => 'Full pass',
					'is_paid' => true,
					'prices'  => [ [ 'id' => 221, 'price' => '199.00' ] ],
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		$html = Components::render(
			'homepage-hero',
			[
				'story_source'             => 'none',
				'news_show'                => 0,
				'event_presentation'       => 'event',
				'more_events_mode'         => 'upcoming_sessions',
				'more_events_presentation' => 'rich_horizontal',
			]
		);

		$more = substr( $html, (int) strpos( $html, 'eex-hh__more' ) );
		$this->assertStringContainsString( '>Get tickets<', $more, 'a ticketed session says Get tickets' );
		$this->assertStringContainsString( 'data-eex-drawer=', $more, 'the row opens the shared ticket drawer' );
		$this->assertStringContainsString( 'eex-drawer', $html, 'the shared drawer markup renders once for the owning event' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$details = Components::render(
			'homepage-hero',
			[
				'story_source'             => 'none',
				'news_show'                => 0,
				'event_presentation'       => 'event',
				'more_events_mode'         => 'upcoming_sessions',
				'more_events_presentation' => 'rich_horizontal',
				'more_events_interaction'  => 'details',
			]
		);
		$this->assertStringContainsString( 'View session', $details, 'details-only interaction links to the session' );
		$this->assertStringNotContainsString( 'data-eex-drawer=', substr( $details, (int) strpos( $details, 'eex-hh__more' ) ), 'no registration surfaces in details-only mode' );
	}

	public function test_urls_never_determine_format(): void {
		$this->fixture();
		// A venue-less talk with an external landing page stays Online; a
		// talk whose owning event has a venue is In person regardless of any
		// URL on either record.
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'External online session',
				'meta_input'  => [
					'_eex_heysummit_id'    => '901',
					'_eex_source_event_id' => '101',
					'_eex_starts_at'       => $this->iso( $this->t0 + 3 * DAY_IN_SECONDS ),
					'_eex_ends_at'         => $this->iso( $this->t0 + 3 * DAY_IN_SECONDS + 3600 ),
					'_eex_external_url'    => 'https://external.example/landing/',
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		$html = Components::render(
			'homepage-hero',
			[
				'story_source'             => 'none',
				'news_show'                => 0,
				'featured_source'          => 'none',
				'more_events_mode'         => 'upcoming_sessions',
				'more_events_presentation' => 'rich_horizontal',
				'more_events_interaction'  => 'details',
			]
		);

		preg_match( '/<article[^>]*data-eex-event-id="901".*?<\/article>/s', $html, $m );
		$row = (string) ( $m[0] ?? '' );
		$this->assertStringContainsString( 'Online', $row, 'an external URL never makes a session in-person' );
		$this->assertStringNotContainsString( 'In person', $row );
	}

	public function test_format_override_and_inheritance(): void {
		$this->fixture();
		$forum = null;
		foreach ( get_posts( [ 'post_type' => 'eex_event', 'numberposts' => -1 ] ) as $eex_post ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			if ( 'London Forum' === $eex_post->post_title ) {
				$forum = $eex_post->ID;
				update_post_meta( $eex_post->ID, '_eex_venue_locality', 'London' );
			}
		}
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Forum session',
				'meta_input'  => [
					'_eex_heysummit_id'    => '801',
					'_eex_source_event_id' => '104',
					'_eex_starts_at'       => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS ),
					'_eex_ends_at'         => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS + 3600 ),
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		$atts = [
			'story_source'             => 'none',
			'news_show'                => 0,
			'featured_source'          => 'none',
			'more_events_mode'         => 'upcoming_sessions',
			'more_events_presentation' => 'rich_horizontal',
			'more_events_interaction'  => 'details',
		];

		$html = Components::render( 'homepage-hero', $atts );
		preg_match( '/<article[^>]*data-eex-event-id="801".*?<\/article>/s', $html, $m );
		$this->assertStringContainsString( 'In person · London', (string) ( $m[0] ?? '' ), 'a venue-less session inherits the owning event\'s location' );

		// A deliberate hybrid override on the owning event wins.
		\Emailexpert\Events\Data\EventPresentation::save_full( (int) $forum, [ 'format' => 'hybrid' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$html = Components::render( 'homepage-hero', $atts );
		preg_match( '/<article[^>]*data-eex-event-id="801".*?<\/article>/s', $html, $m );
		$this->assertStringContainsString( 'Hybrid', (string) ( $m[0] ?? '' ), 'the presentation format override claims Hybrid' );
	}

	public function test_local_speaker_assignment_uses_canonical_records_and_fails_safely(): void {
		$this->fixture();
		$speaker = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Laura Atkins',
				'meta_input'  => [ '_eex_headline' => 'Word to the Wise' ],
			]
		);
		$talk    = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'External session needing a speaker',
				'meta_input'  => [
					'_eex_heysummit_id'    => '901',
					'_eex_source_event_id' => '101',
					'_eex_starts_at'       => $this->iso( $this->t0 + 3 * DAY_IN_SECONDS ),
					'_eex_ends_at'         => $this->iso( $this->t0 + 3 * DAY_IN_SECONDS + 3600 ),
					'_eex_external_url'    => 'https://external.example/landing/',
				],
			]
		);

		// Local assignment: a reference to the canonical record plus one
		// stale reference that must be skipped, never fatal.
		\Emailexpert\Events\Data\EventPresentation::save_talk(
			$talk,
			[
				'source' => 'local',
				'refs'   => [ (string) $speaker, '999999' ],
			]
		);
		\EEX_Test_State::$user_can = false;

		$atts = [
			'story_source'             => 'none',
			'news_show'                => 0,
			'featured_source'          => 'none',
			'more_events_mode'         => 'upcoming_sessions',
			'more_events_presentation' => 'rich_horizontal',
			'more_events_interaction'  => 'details',
		];

		$html = Components::render( 'homepage-hero', $atts );
		preg_match( '/<article[^>]*data-eex-event-id="901".*?<\/article>/s', $html, $m );
		$row = (string) ( $m[0] ?? '' );
		$this->assertStringContainsString( 'Laura Atkins', $row, 'the locally assigned canonical speaker renders' );

		// The canonical record changes; every consumer sees the new value —
		// nothing was copied.
		wp_update_post(
			[
				'ID'         => $speaker,
				'post_title' => 'Laura Atkins (Word to the Wise)',
			]
		);
		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$html = Components::render( 'homepage-hero', $atts );
		$this->assertStringContainsString( 'Laura Atkins (Word to the Wise)', $html, 'the reference resolves the updated canonical record' );

		// HeySummit-only ignores the local assignment (this talk has no
		// HeySummit speakers, so it simply shows none).
		\Emailexpert\Events\Data\EventPresentation::save_talk(
			$talk,
			[
				'source' => 'heysummit',
				'refs'   => [ (string) $speaker ],
			]
		);
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$html = Components::render( 'homepage-hero', $atts );
		preg_match( '/<article[^>]*data-eex-event-id="901".*?<\/article>/s', $html, $m );
		$this->assertStringNotContainsString( 'Laura Atkins', (string) ( $m[0] ?? '' ), 'HeySummit-only mode ignores the local assignment' );
	}

	public function test_details_url_override_wins_for_the_featured_card(): void {
		$this->fixture();
		$optin = null;
		foreach ( get_posts( [ 'post_type' => 'eex_event', 'numberposts' => -1 ] ) as $eex_post ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			if ( 'Double Optin' === $eex_post->post_title ) {
				$optin = $eex_post->ID;
			}
		}
		\Emailexpert\Events\Data\EventPresentation::save_full( (int) $optin, [ 'details_url' => 'https://good.example/landing/' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		\EEX_Test_State::$user_can = false;

		$html = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0, 'more_events' => 0, 'event_presentation' => 'event' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'https://good.example/landing/', $html, 'Details goes to the configured destination, not a generic landing page' );
	}

	public function test_free_event_shares_one_rsvp_renderer_across_old_and_new_surfaces(): void {
		$this->go_lite();
		$this->mock_lite_api_with_tickets(
			[
				[
					'id'      => 11,
					'title'   => 'Free pass',
					'is_paid' => false,
					'prices'  => [ [ 'id' => 111, 'price' => '0.00' ] ],
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		// The existing widget, configured for the inline form.
		$bar = Components::render( 'register-bar', [ 'event' => '101', 'register_action' => 'form' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		// The compositions, on their default (auto) — the existing system
		// answers "form" because its own free-ticket rules apply.
		$hero = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0, 'more_events' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$landing = Components::render( 'event-landing', [ 'event_source' => 'manual', 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		// One shared implementation: the same register-form part (the same
		// hidden inputs for the same free ticket, the same consent wording),
		// toggled by the same JS contract, on all three surfaces.
		foreach ( [ 'register-bar' => $bar, 'homepage-hero' => $hero, 'event-landing' => $landing ] as $surface => $html ) {
			$this->assertStringContainsString( 'data-eex-reg="1"', $html, $surface . ' renders the shared register-form part' );
			$this->assertStringContainsString( 'name="ticket" value="11"', $html, $surface . ' registers the same free ticket' );
			$this->assertStringContainsString( 'data-eex-reg-toggle="1"', $html, $surface . ' uses the shared toggle contract' );
			$this->assertStringContainsString( 'name="consent"', $html, $surface . ' carries the required consent checkbox' );
			$this->assertStringContainsString( esc_html( \Emailexpert\Events\Frontend\Components::consent_disclosure_text() ), $html, $surface . ' shows the shared disclosure wording' );
		}

		$this->assertStringContainsString( 'RSVP free', $hero, 'the hero CTA reads RSVP free when the form applies' );
	}

	public function test_ticketed_event_shares_the_existing_ticket_panel(): void {
		$this->go_lite();
		$this->mock_lite_api_with_tickets(
			[
				[
					'id'      => 22,
					'title'   => 'Full pass',
					'is_paid' => true,
					'prices'  => [ [ 'id' => 221, 'price' => '199.00' ] ],
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		// The existing widget, configured for the panel.
		$bar = Components::render( 'register-bar', [ 'event' => '101', 'register_action' => 'panel' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		// The hero on auto: no free ticket, so the existing system answers
		// "panel" — the same drawer, not a new implementation.
		$hero = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0, 'more_events' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		foreach ( [ 'register-bar' => $bar, 'homepage-hero' => $hero ] as $surface => $html ) {
			$this->assertStringContainsString( 'eex-drawer', $html, $surface . ' renders the shared ticket drawer' );
			$this->assertStringContainsString( 'data-eex-drawer=', $html, $surface . ' opens it through the shared JS contract' );
			$this->assertStringContainsString( 'Full pass', $html, $surface . ' lists the same ticket' );
		}

		$this->assertStringContainsString( 'Get tickets', $hero, 'the hero CTA reads Get tickets for paid-only events' );
	}

	public function test_explicit_link_behaviour_is_unchanged(): void {
		$this->go_lite();
		$this->mock_lite_api();
		\EEX_Test_State::$user_can = false;

		$hero = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0, 'more_events' => 0, 'register_action' => 'link' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'checkout/select-tickets/', $hero, 'the explicit link mode keeps the existing checkout destination' );
		$this->assertStringNotContainsString( 'data-eex-reg-toggle', $hero, 'no RSVP toggle in link mode' );
		$this->assertStringNotContainsString( 'data-eex-drawer=', $hero, 'no drawer in link mode' );
	}

	public function test_two_story_frontage_renders_and_one_story_reserves_no_space(): void {
		$this->fixture();
		$this->make_story( 'Fourth story', 14400 );
		\EEX_Test_State::$user_can = false;

		$two = Components::render( 'homepage-hero', [ 'story_count' => '2' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'eex-hh--two-story', $two, 'the two-story modifier is present' );
		$this->assertStringContainsString( 'eex-hh__story-second--beneath', $two, 'auto placement resolves to beneath' );
		$this->assertStringContainsString( 'DMARC adoption doubles', $two, 'the next eligible story is the secondary feature' );

		$news = substr( $two, (int) strpos( $two, 'eex-hh__news' ) );
		$this->assertStringNotContainsString( 'DMARC adoption doubles', $news, 'the secondary feature is excluded from Latest News' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$one = Components::render( 'homepage-hero', [] );
		$this->assertStringNotContainsString( 'eex-hh__story-second', $one, 'one-story mode reserves no space for a second' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$side = Components::render( 'homepage-hero', [ 'story_count' => '2', 'story2_placement' => 'side' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'eex-hh__story--with-side', $side, 'side placement marks the story column' );
		$this->assertStringContainsString( 'eex-hh__story-second--side', $side );
	}

	public function test_latest_news_columns_follow_the_item_count(): void {
		$this->fixture();
		for ( $i = 4; $i <= 12; $i++ ) {
			$this->make_story( 'Extra story ' . $i, $i * 3600 );
		}
		\EEX_Test_State::$user_can = false;

		$eight = Components::render( 'homepage-hero', [ 'news_count' => 8 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'eex-hh__news-list--cols-4', $eight, 'eight items take four desktop columns' );
		$this->assertSame( 8, substr_count( $eight, 'eex-hh__news-item' ), 'eight items render' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$three = Components::render( 'homepage-hero', [ 'news_count' => 3 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'eex-hh__news-list--cols-3', $three, 'three items take three columns' );
		$this->assertSame( 3, substr_count( $three, 'eex-hh__news-item' ) );
	}

	public function test_news_images_render_above_the_headline_when_configured(): void {
		$this->fixture();
		foreach ( get_posts( [ 'post_type' => 'post', 'numberposts' => -1 ] ) as $eex_post ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			update_post_meta( $eex_post->ID, '_eex_test_thumbnail', 'https://cdn.example/thumb-' . $eex_post->ID . '.jpg' );
		}
		\EEX_Test_State::$user_can = false;

		$html = Components::render(
			'homepage-hero',
			[
				'news_image_position' => 'above',
				'news_image_size'     => 'large',
			]
		);

		$this->assertStringContainsString( 'eex-hh__news-card--stacked', $html, 'stacked news cards render' );
		$this->assertStringContainsString( 'eex-hh__news-card--large', $html, 'the emphasis class is present' );
		$this->assertStringContainsString( 'eex-hh__news-media', $html, 'the media frame wraps the image' );
		$this->assertStringContainsString( 'loading="lazy"', $html, 'news media lazy-loads' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$off = Components::render( 'homepage-hero', [ 'news_show_image' => 0, 'news_image_position' => 'above' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'eex-hh__news-media', $off, 'the image flag still switches every treatment off' );
	}

	public function test_news_categories_and_images_link_by_default_and_can_be_switched_off(): void {
		$this->fixture();
		wp_insert_term( 'Deliverability', 'category' );
		foreach ( get_posts( [ 'post_type' => 'post', 'numberposts' => -1 ] ) as $eex_post ) { // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			update_post_meta( $eex_post->ID, '_eex_test_thumbnail', 'https://cdn.example/thumb.jpg' );
			wp_set_object_terms( $eex_post->ID, [ 'deliverability' ], 'category' );
		}
		\EEX_Test_State::$user_can = false;

		$on = Components::render( 'homepage-hero', [ 'news_image_position' => 'above' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertMatchesRegularExpression(
			'/eex-hh__news-category"><a href="[^"]*term[^"]*"/',
			$on,
			'category labels link to the real term archive by default'
		);
		$this->assertStringContainsString( 'eex-hh__news-imglink', $on, 'images link to the article by default' );
		$this->assertStringContainsString( 'tabindex="-1" aria-hidden="true"', $on, 'the image link stays out of the tab order and accessibility tree' );
		$this->assertStringNotContainsString( '<a', (string) preg_replace( '/.*?(<a[^>]*eex-hh__news-imglink[^>]*>).*/s', '', $on ), 'sanity' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$off = Components::render( 'homepage-hero', [ 'news_image_position' => 'above', 'news_link_categories' => 0, 'news_link_images' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'eex-hh__news-imglink', $off, 'image links switch off' );
		$this->assertDoesNotMatchRegularExpression( '/eex-hh__news-category"><a /', $off, 'category links switch off; the label stays as plain text' );
		$this->assertStringContainsString( 'eex-hh__news-category', $off, 'the label itself still renders' );
	}

	public function test_view_all_news_falls_back_to_the_posts_page_and_can_be_hidden(): void {
		$this->fixture();
		\EEX_Test_State::$user_can = false;

		$none = Components::render( 'homepage-hero', [] );
		$this->assertStringNotContainsString( 'eex-hh__news-all', $none, 'no destination configured and no posts page: no link, never a broken one' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$news_page = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'News',
			]
		);
		update_option( 'page_for_posts', $news_page );

		$fallback = Components::render( 'homepage-hero', [] );
		$this->assertStringContainsString( 'eex-hh__news-all', $fallback, 'the posts page becomes the All-news destination' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$hidden = Components::render( 'homepage-hero', [ 'news_all_show' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'eex-hh__news-all', $hidden, 'the link can be switched off' );

		delete_option( 'page_for_posts' );
	}

	public function test_more_events_speaker_and_people_presentations(): void {
		$this->fixture();

		$speaker = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Lauren Meyer',
				'meta_input'  => [ '_eex_headline' => 'CMO, SocketLabs' ],
			]
		);
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Forum keynote',
				'meta_input'  => [
					'_eex_heysummit_id'    => '801',
					'_eex_source_event_id' => '104',
					'_eex_starts_at'       => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS ),
					'_eex_ends_at'         => $this->iso( $this->t0 + 89 * DAY_IN_SECONDS + 3600 ),
					'_eex_speaker_ids'     => [ $speaker ],
				],
			]
		);
		\EEX_Test_State::$user_can = false;

		$speakers = Components::render( 'homepage-hero', [ 'more_events_presentation' => 'speakers' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$more     = substr( $speakers, (int) strpos( $speakers, 'eex-hh__more' ) );
		$this->assertStringContainsString( 'eex-compact-event__speaker-name', $more, 'the London Forum row carries its speaker' );
		$this->assertStringContainsString( 'Lauren Meyer', $more );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$people = Components::render( 'homepage-hero', [ 'more_events_presentation' => 'people' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'eex-hh__person-name', $people, 'people mode renders person rows' );
		$this->assertStringContainsString( 'Coming up', $people, 'people mode takes the Coming up label' );
		$this->assertStringContainsString( 'Forum keynote', $people, 'each person carries their session context' );
	}

	public function test_more_events_whisper_shows_format_or_location(): void {
		$this->fixture();
		$forum = get_posts( [ 'post_type' => 'eex_event', 's' => '', 'numberposts' => -1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		foreach ( $forum as $eex_post ) {
			if ( 'London Forum' === $eex_post->post_title ) {
				update_post_meta( $eex_post->ID, '_eex_venue_name', 'The Brewery' );
				update_post_meta( $eex_post->ID, '_eex_venue_locality', 'London' );
				update_post_meta( $eex_post->ID, '_eex_venue_country', 'United Kingdom' );
			}
		}
		\EEX_Test_State::$user_can = false;

		$format = Components::render( 'homepage-hero', [ 'more_events_whisper' => 'format' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'eex-compact-event__whisper', $format );
		$this->assertStringContainsString( 'In person', $format, 'a venue means In person' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$location = Components::render( 'homepage-hero', [ 'more_events_whisper' => 'location' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'London, United Kingdom', $location, 'location whisper names the city and country' );

		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		\Emailexpert\Events\Frontend\Selection\EditorialSelector::reset_request_state();

		$none = Components::render( 'homepage-hero', [] );
		$this->assertStringNotContainsString( 'eex-compact-event__whisper', $none, 'the default stays whisper-free' );
	}

	public function test_the_featured_story_is_absent_from_latest_news_markup(): void {
		$this->fixture();
		\EEX_Test_State::$user_can = false; // The visitor view, without editor comments.

		$html = Components::render( 'homepage-hero', [] );

		$news = substr( $html, (int) strpos( $html, 'eex-hh__news' ) );

		$this->assertStringContainsString( 'DMARC adoption doubles', $news );
		$this->assertStringNotContainsString( 'Gmail tightens the rules', $news, 'the lead story never repeats in Latest News' );
	}

	public function test_exactly_one_image_receives_high_priority(): void {
		$this->fixture();

		// The story has an image, and the featured session carries artwork.
		$posts = get_posts( [ 'post_type' => 'post', 'posts_per_page' => 1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( (int) $posts[0]->ID, '_eex_test_thumbnail', 'https://cdn.example/story.jpg' );

		$talks = get_posts( [ 'post_type' => 'eex_talk', 'posts_per_page' => 1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( (int) $talks[0]->ID, '_eex_test_thumbnail', 'https://cdn.example/talk.jpg' );

		$html = Components::render( 'homepage-hero', [ 'event_media' => 'session' ] );

		$this->assertStringContainsString( 'https://cdn.example/story.jpg', $html );
		$this->assertStringContainsString( 'https://cdn.example/talk.jpg', $html );
		$this->assertSame( 1, substr_count( $html, 'fetchpriority="high"' ), 'exactly one LCP candidate loads eagerly' );
	}

	public function test_missing_media_renders_no_broken_image_element(): void {
		$this->fixture();

		$html = Components::render( 'homepage-hero', [] );

		$this->assertStringNotContainsString( 'src=""', $html, 'no empty image sources' );
		$this->assertStringNotContainsString( '<img class="eex-hh__story-img"', $html, 'an imageless story renders text-led' );
	}

	public function test_lite_failure_serves_the_last_good_composition(): void {
		$this->go_lite();
		$this->mock_lite_api();

		// Event-only composition: when the API dies, a fresh render would be
		// the empty state, which is exactly when the last-good tier serves.
		$atts = [
			'story_source' => 'none',
			'news_show'    => 0,
		];

		$good = Components::render( 'homepage-hero', $atts );
		$this->assertStringContainsString( 'Live keynote', $good );

		// Everything flushed hard, the API now failing: the composition's
		// six-hour last-good copy keeps the homepage populated.
		Cache::flush();
		\Emailexpert\Events\Data\LiveCache::flush();
		\Emailexpert\Events\Data\LiveCache::reset_request_state();
		Components::reset_request_state();
		EventSelector::reset_request_state();
		Repositories::reset();
		remove_all_filters( 'pre_http_request' );
		$this->mock_lite_api( true );

		$stale = Components::render( 'homepage-hero', $atts );
		$this->assertStringContainsString( 'Live keynote', $stale, 'the last-good fragment covers an API outage' );
		$this->assertStringContainsString( 'lite-summit', $stale, 'the served copy is the real cached composition' );
	}

	public function test_admin_preview_never_reaches_public_visitors_or_the_public_cache(): void {
		$this->fixture();

		// An editor previews far in the future: London Forum leads there.
		\EEX_Test_State::$user_can = true;

		$preview = Components::render( 'homepage-hero', [ 'preview_at' => $this->iso( $this->t0 + 60 * DAY_IN_SECONDS ) ] );

		$this->assertStringContainsString( 'eex-preview-notes', $preview, 'editors see the selection explanation' );
		$this->assertStringContainsString( 'London Forum', $preview );

		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			$this->assertStringNotContainsString( 'eex_c_', (string) $key, 'a preview render never writes a public fragment' );
		}

		// A visitor passing the same attribute is ignored entirely.
		\EEX_Test_State::$user_can = false;
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$public = Components::render( 'homepage-hero', [ 'preview_at' => $this->iso( $this->t0 + 60 * DAY_IN_SECONDS ) ] );

		$this->assertStringNotContainsString( 'eex-preview-notes', $public, 'no diagnostic output for visitors' );
		$this->assertStringContainsString( 'Opening keynote', $public, 'visitors get the real selection, not the previewed time' );
	}

	public function test_public_output_contains_no_credentials(): void {
		$this->make_story( 'A story', 3600 );
		$this->go_lite();
		$this->mock_lite_api();
		\EEX_Test_State::$user_can = false;

		$html = Components::render( 'homepage-hero', [] );

		$this->assertStringNotContainsString( 'secret-key-123', $html, 'the API key never reaches output' );
		$this->assertStringNotContainsString( 'api/v2', $html, 'no API URLs in public markup' );
	}

	public function test_lite_schema_is_emitted_once_and_never_duplicated(): void {
		$this->go_lite();
		$this->mock_lite_api();

		$html = Components::render( 'event-landing', [ 'event_source' => 'manual', 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 1, substr_count( $html, 'application/ld+json' ), 'one Event schema block, nested components suppressed' );
		$this->assertStringContainsString( '"@type":"Event"', $html );
	}

	public function test_full_mode_landing_emits_no_inline_schema(): void {
		$this->fixture();

		$html = Components::render( 'event-landing', [ 'event_source' => 'manual', 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 0, substr_count( $html, 'application/ld+json' ), 'Full single-event pages keep the existing schema integration' );
	}

	public function test_a_cancelled_event_renders_its_state_and_no_registration_cta(): void {
		$events_id = $this->make_event( 'Cancelled Summit', '300', 5 * DAY_IN_SECONDS );
		EventPresentation::save_full(
			$events_id,
			[
				'status'         => 'cancelled',
				'status_message' => 'See you next year.',
			]
		);
		EventSelector::reset_request_state();

		$html = Components::render(
			'homepage-hero',
			[
				'featured_source' => 'manual_event',
				'featured_event'  => '300',
				'story_source'    => 'none',
				'news_show'       => 0,
			]
		);

		$this->assertStringContainsString( 'Cancelled', $html, 'the cancelled state is stated in text' );
		$this->assertStringContainsString( 'See you next year.', $html );
		$this->assertStringNotContainsString( 'eex-cta-register', $html, 'no misleading registration CTA on a cancelled event' );
	}

	public function test_the_post_event_landing_transforms_and_never_stays_a_dead_registration_page(): void {
		$this->make_event( 'Finished Summit', '400', -10 * DAY_IN_SECONDS, -9 * DAY_IN_SECONDS );
		$this->make_talk(
			'Recorded keynote',
			'901',
			'400',
			-10 * DAY_IN_SECONDS,
			[ '_eex_replay_url' => 'https://replay.example/keynote' ]
		);
		EventSelector::reset_request_state();

		$html = Components::render( 'event-landing', [ 'event_source' => 'manual', 'event' => '400' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'Watch replays', $html, 'the primary CTA becomes the replays' );
		$this->assertStringContainsString( 'eex-landing-replays', $html, 'the replay gallery anchors the page' );
		$this->assertStringNotContainsString( 'eex-el__section--tickets', $html, 'the dead tickets section drops out automatically' );

		// The transformed order: replays outrank the programme sections.
		$order = EventLanding::sections(
			[
				'sections'   => 'hero,status,stats,intro,sessions,speakers,schedule,tickets,venue,sponsors,replays,more_events,final_cta',
				'post_event' => 'auto',
			],
			[
				'status'            => 'replay',
				'notice'            => '',
				'registration_open' => false,
			]
		);

		$this->assertSame( [ 'hero', 'status', 'replays', 'intro', 'stats', 'speakers', 'sponsors', 'more_events', 'final_cta' ], $order );
	}

	public function test_section_order_is_sanitised_and_honoured(): void {
		$lifecycle = [
			'status'            => 'scheduled',
			'notice'            => '',
			'registration_open' => true,
		];

		$order = EventLanding::sections(
			[
				'sections'   => 'speakers, hero, bogus, speakers, tickets',
				'post_event' => 'auto',
			],
			$lifecycle
		);

		$this->assertSame( [ 'speakers', 'hero', 'tickets' ], $order, 'unknown sections drop, duplicates collapse, order holds' );
	}

	public function test_a_status_notice_is_appended_when_omitted(): void {
		$lifecycle = [
			'status'            => 'postponed',
			'notice'            => 'New dates soon.',
			'registration_open' => false,
		];

		$order = EventLanding::sections(
			[
				'sections'   => 'hero,intro',
				'post_event' => 'auto',
			],
			$lifecycle
		);

		$this->assertSame( [ 'hero', 'status', 'intro' ], $order, 'a live status notice is required content while one exists' );
	}

	// ---- Cache and time --------------------------------------------------

	/**
	 * The transient TTL recorded for the composition fragment.
	 */
	private function fragment_ttl(): ?int {
		foreach ( \EEX_Test_State::$transient_ttls as $key => $ttl ) {
			if ( str_starts_with( (string) $key, 'eex_c_' ) ) {
				return (int) $ttl;
			}
		}

		return null;
	}

	public function test_an_imminent_lifecycle_boundary_shortens_the_fragment_ttl(): void {
		$this->make_event( 'Soon Event', '500', 90 ); // Starts in 90 seconds.
		$this->make_story( 'A story', 3600 );

		Components::render( 'homepage-hero', [] );

		$ttl = $this->fragment_ttl();
		$this->assertNotNull( $ttl, 'the fragment was cached' );
		$this->assertLessThanOrEqual( 120, $ttl, 'the TTL cannot outlive the event start' );
		$this->assertGreaterThanOrEqual( 60, $ttl, 'the one-minute floor prevents cache churn' );
	}

	public function test_a_distant_boundary_leaves_the_normal_display_ttl(): void {
		$this->make_event( 'Distant Event', '501', 30 * DAY_IN_SECONDS );
		$this->make_story( 'A story', 3600 );

		Components::render( 'homepage-hero', [] );

		$this->assertSame( 5 * MINUTE_IN_SECONDS, $this->fragment_ttl(), 'no near boundary means the operator-set lifetime applies' );
	}

	public function test_a_promotion_window_boundary_shortens_the_effective_ttl(): void {
		$event_id = $this->make_event( 'Windowed Event', '502', 30 * DAY_IN_SECONDS );
		$this->make_event( 'Other Event', '503', 40 * DAY_IN_SECONDS );

		EventPresentation::save_full(
			$event_id,
			[
				'level'       => 'flagship',
				'promo_start' => $this->iso( $this->t0 + 150 ),
			]
		);
		EventSelector::reset_request_state();

		Components::render( 'homepage-hero', [ 'selection_strategy' => 'priority', 'story_source' => 'none', 'news_show' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$ttl = $this->fragment_ttl();
		$this->assertNotNull( $ttl );
		$this->assertLessThanOrEqual( 150, $ttl, 'the fragment expires by the promotion window opening' );
	}

	public function test_automatic_selection_changes_after_an_event_boundary(): void {
		$this->make_event( 'First', '600', 5 * DAY_IN_SECONDS );
		$this->make_event( 'Second', '601', 15 * DAY_IN_SECONDS );

		$before = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringContainsString( 'First', $before );

		// Past the first event's end (its cached fragment would also have
		// expired by then; the flush stands in for that passage of time).
		Clock::freeze( $this->t0 + 6 * DAY_IN_SECONDS );
		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$after = Components::render( 'homepage-hero', [ 'story_source' => 'none', 'news_show' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'data-eex-event-id="600"', $after, 'the ended event no longer features' );
		$this->assertStringContainsString( 'Second', $after, 'selection rolled forward without page editing' );
	}

	public function test_manual_pin_expiry_changes_the_rendered_selection(): void {
		$this->make_event( 'Auto Event', '700', 5 * DAY_IN_SECONDS );
		$this->make_event( 'Pinned Event', '701', 50 * DAY_IN_SECONDS );

		$atts = [
			'story_source'      => 'none',
			'news_show'         => 0,
			'featured_source'   => 'manual_event',
			'featured_event'    => '701',
			'pin_duration'      => 'until_date',
			'pin_until'         => $this->iso( $this->t0 + 300 ),
			'pin_expiry_action' => 'auto',
		];

		$pinned = Components::render( 'homepage-hero', $atts );
		$this->assertStringContainsString( 'Pinned Event', $pinned );

		$this->assertLessThanOrEqual( 300, (int) $this->fragment_ttl(), 'the pin expiry bounds the cache' );

		Clock::freeze( $this->t0 + 400 );
		Cache::flush();
		Components::reset_request_state();
		EventSelector::reset_request_state();

		$after = Components::render( 'homepage-hero', $atts );
		$this->assertStringContainsString( 'Auto Event', $after, 'after the pin expires selection returns to automatic' );
	}

	public function test_presentation_saves_flush_the_display_cache_generation(): void {
		$event_id = $this->make_event( 'Any Event', '800', 5 * DAY_IN_SECONDS );

		$generation = (int) get_option( 'eex_cache_generation', 0 );

		EventPresentation::save_full( $event_id, [ 'level' => 'flagship' ] );

		$this->assertSame( $generation + 1, (int) get_option( 'eex_cache_generation', 0 ), 'saving presentation settings invalidates cached output' );
	}
}
