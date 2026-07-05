<?php
/**
 * Lite mode: footprint, live repository, budget, graceful failure, shared
 * render callbacks, mode switching.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Data\LiveCache;
use Emailexpert\Events\Data\LiveRepository;
use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\SyncedRepository;
use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Install\Activator;
use Emailexpert\Events\Install\Mode;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Data\LiveRepository
 * @covers \Emailexpert\Events\Data\LiveCache
 * @covers \Emailexpert\Events\Install\Mode
 */
final class LiteModeTest extends TestCase {

	/**
	 * HTTP requests seen by the mock, by URL.
	 *
	 * @var string[]
	 */
	private array $requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->requests                       = [];
		$GLOBALS['eex_test_dbdelta']          = [];
		$GLOBALS['eex_test_rewrites_flushed'] = false;

		// Tables uses a per-request static; reset it between tests.
		$reflection = new \ReflectionProperty( \Emailexpert\Events\Install\Tables::class, 'ensured' );
		$reflection->setValue( null, [] );
	}

	/**
	 * Put the plugin into Lite mode with one connection and one event.
	 */
	private function go_lite(): void {
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
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => [ 'c1|101' ],
			]
		);
		Repositories::reset();
	}

	/**
	 * Mock the API with one event and two future talks.
	 *
	 * @param bool $fail      Simulate an unreachable API.
	 * @param bool $with_past Add two past talks (505 with a replay, 506 older).
	 */
	private function mock_api( bool $fail = false, bool $with_past = false ): void {
		$past = ! $with_past ? [] : [
			[
				'id'         => 505,
				'title'      => 'Yesterday keynote',
				'starts_at'  => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
				'ends_at'    => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS + 3600 ),
				'event'      => 101,
				'replay_url' => 'https://replay.example.com/keynote',
				'categories' => [
					[
						'id'    => 3,
						'title' => 'Deliverability',
					],
				],
			],
			[
				'id'        => 506,
				'title'     => 'Old workshop',
				'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 2 * DAY_IN_SECONDS ),
				'event'     => 101,
			],
		];

		$this->mock_http(
			function ( $url ) use ( $fail, $past ) {
				$this->requests[] = (string) $url;

				if ( $fail ) {
					return new \WP_Error( 'http_request_failed', 'timeout' );
				}

				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => array_merge( $past, [
								[
									'id'        => 501,
									'title'     => 'Live session one',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'ends_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 ),
									'event'     => 101,
									'speakers'  => [
										[
											'id'     => 9,
											'name'   => 'Ada Speaker',
											'avatar' => 'https://cdn.example.com/ada.jpg',
										],
									],
									'categories' => [ [ 'id' => 3, 'title' => 'Deliverability' ] ],
								],
								[
									'id'        => 502,
									'title'     => 'Live session two',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 9000 ),
									'event'     => 101,
								],
								[
									'id'                      => 503,
									'title'                   => 'External masterclass',
									'starts_at'               => gmdate( 'Y-m-d\TH:i:s\Z', time() + 12000 ),
									'event'                   => 101,
									'external_url'            => 'https://elsewhere.example.com/masterclass',
									'primary_image'           => 'https://cdn.example.com/talk503.jpg',
									'inperson_available'      => true,
									'inperson_venue'          => 'The Roundhouse',
									'inperson_venue_area'     => 'Main Hall',
									'is_open_access'          => true,
									'custom_tag'              => 'Members favourite',
									'broadcast_duration_mins' => 45,
								],
								[
									'id'             => 504,
									'title'          => 'Cancelled workshop',
									'starts_at'      => gmdate( 'Y-m-d\TH:i:s\Z', time() + 15000 ),
									'event'          => 101,
									'talk_cancelled' => true,
								],
							] ),
						]
					);
				}

				if ( str_contains( (string) $url, 'events/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'                        => 101,
									'title'                     => 'Live Hub',
									'event_url'                 => 'https://summit.example.com/hub/',
									'first_talk_at'             => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'last_talk_at'              => gmdate( 'Y-m-d\TH:i:s\Z', time() + 9000 ),
									'timezone'                  => 'Europe/London',
									'is_open_for_registrations' => true,
								],
								[
									'id'    => 999,
									'title' => 'Unconfigured event',
								],
							],
						]
					);
				}

				return null;
			}
		);
	}

	// -- Criterion 2: footprint. --------------------------------------------

	public function test_lite_activation_writes_exactly_one_option_and_nothing_else(): void {
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
			]
		);
		$before = \EEX_Test_State::$options;

		Activator::activate();

		global $wpdb;
		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'] ?? [], 'no tables' );
		$this->assertSame( [], \EEX_Test_State::$scheduled, 'no cron' );
		$this->assertFalse( $GLOBALS['eex_test_rewrites_flushed'] ?? false, 'no rewrite flush' );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'no seeded terms' );

		$eex_options = array_filter( array_keys( \EEX_Test_State::$options ), static fn( $k ): bool => str_starts_with( (string) $k, 'eex_' ) );
		$this->assertSame( [ Options::SETTINGS ], array_values( $eex_options ), 'exactly the settings option' );
		$this->assertArrayNotHasKey( 'eex_wizard_notice', \EEX_Test_State::$options );
		$this->assertSame( EEX_VERSION, Options::setting( 'version' ), 'version rides inside the settings option' );
	}

	public function test_fresh_lite_choice_removes_the_full_shaped_activation_leftovers(): void {
		// Fresh install: Full-shaped activation. Series terms only seed
		// when site code provides them via the filter (no brands baked in).
		add_filter( 'eex_seed_series_terms', static fn(): array => [ 'My Summit Series' ] );
		Activator::activate();
		$this->assertNotEmpty( get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'filter-provided series seeded' );
		$this->assertSame( 1, get_option( 'eex_wizard_notice' ) );

		Mode::choose( 'lite' );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( [], get_terms( [ 'taxonomy' => 'eex_event_series' ] ), 'seeded terms removed' );
		$this->assertFalse( get_option( 'eex_wizard_notice' ), 'wizard notice option removed' );
		$this->assertFalse( get_option( 'eex_version' ), 'version option folded away' );
	}

	public function test_lite_logger_uses_ring_buffer_never_a_table(): void {
		$this->go_lite();

		\Emailexpert\Events\Logging\Logger::info( 'api', 'live fetch failed' );

		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'] ?? [], 'no log table created' );
		$ring = \Emailexpert\Events\Logging\Logger::ring();
		$this->assertCount( 1, $ring );
		$this->assertSame( 'live fetch failed', $ring[0]['message'] );
	}

	// -- Criterion 3: budget, cache, graceful failure. ------------------------

	public function test_first_view_fetches_within_budget_and_second_view_is_free(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Live session one', $html );
		$this->assertStringContainsString( 'Live session two', $html );
		$this->assertLessThanOrEqual( LiveCache::COLD_BUDGET, count( $this->requests ), 'at most the budgeted fetches' );

		// Second view within TTL: zero API calls (fragment + data cached).
		$this->requests = [];
		LiveCache::reset_request_state();
		$again = Components::render( 'upcoming-sessions', [] );

		$this->assertSame( $html, $again );
		$this->assertCount( 0, $this->requests, 'no API calls within TTL' );
	}

	public function test_rich_talk_fields_render_and_cancelled_talks_never_do(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render(
			'upcoming-sessions',
			[
				'show_image' => 1,
				'buttons'    => 'both',
			]
		);

		// Cancelled sessions never render anywhere.
		$this->assertStringNotContainsString( 'Cancelled workshop', $html );

		// The external session points BOTH buttons (and its title) elsewhere.
		$this->assertStringContainsString( 'elsewhere.example.com/masterclass', $html );
		$external_pos = strpos( $html, 'External masterclass' );
		$this->assertNotFalse( $external_pos );
		$this->assertGreaterThan(
			1,
			substr_count( $html, 'elsewhere.example.com/masterclass' ),
			'session and tickets buttons both use the external URL'
		);

		// Imagery, venue and status badges from the expanded serializer.
		$this->assertStringContainsString( 'cdn.example.com/talk503.jpg', $html, 'session image renders when enabled' );
		$this->assertStringContainsString( 'The Roundhouse, Main Hall', $html, 'venue line' );
		$this->assertStringContainsString( 'In person', $html );
		$this->assertStringContainsString( 'Open access', $html );
		$this->assertStringContainsString( 'Members favourite', $html, 'custom tag becomes a badge' );

		// Real duration feeds the end time (45 minutes, not the 1h default).
		$this->assertStringContainsString(
			'data-eex-end="' . gmdate( 'Y-m-d\TH:i:s\Z', time() + 12000 + 45 * 60 ) . '"',
			$html
		);

		// Images stay off by default.
		Cache::flush();
		$plain = Components::render( 'upcoming-sessions', [] );
		$this->assertStringNotContainsString( 'cdn.example.com/talk503.jpg', $plain );
	}

	public function test_lite_sessions_link_to_their_own_talk_pages(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		// The payload exposes no public talk URL: reconstructed from the
		// event site + the talk title, so buttons stop landing on the
		// generic event page.
		$this->assertStringContainsString( 'summit.example.com/hub/talks/live-session-one/', $html );

		// The tickets button lands on the operator-verified ticket page.
		$this->assertStringContainsString( 'summit.example.com/hub/checkout/select-tickets/', $html );
	}

	public function test_sponsor_wall_reads_the_api_and_keeps_manual_extras(): void {
		$this->go_lite();
		Options::update_settings(
			[
				'lite_sponsors' => [
					[
						'name'  => 'Acme',
						'url'   => 'https://acme.example.com',
						'tier'  => 'Gold',
						'blurb' => 'MANUAL ROW',
					],
					[
						'name' => 'Handmade Co',
						'tier' => 'Partner',
					],
				],
			]
		);
		$this->mock_api();
		$this->mock_http(
			static function ( $url ) {
				// The live payload sends bare category IDs; names come from
				// the sponsor-categories endpoint.
				if ( str_contains( (string) $url, 'sponsor-categories/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'    => 6708,
									'title' => 'Gold',
								],
								[
									'id'    => 6709,
									'title' => 'Media Partners',
								],
							],
						]
					);
				}

				if ( str_contains( (string) $url, 'sponsors/' ) ) {
					// The live v2 sponsor schema (operator-verified).
					return self::json_response(
						[
							'results' => [
								[
									'id'                   => 1,
									'title'                => 'Acme',
									'url'                  => 'https://acme.example.com',
									'logo'                 => 'https://cdn.example.com/acme.png',
									'short_description'    => 'Deliverability tools.',
									'long_description'     => '<p>Acme has shipped <strong>deliverability</strong> tools since 1999.</p><script>alert(1)</script>',
									'promo_banner'         => 'https://cdn.example.com/acme-banner.jpg',
									'intro_source_type'    => 'YouTube',
									'intro_video_id'       => 'dQw4w9WgXcQ',
									'intro_video_autoplay' => false,
									'link_title'           => 'Try Acme free',
									'slug'                 => 'acme',
									'books_url'            => 'https://meet.example.com/acme',
									'phone_number'         => '+44 20 7946 0000',
									'sponsor_categories'   => [ 6708 ],
									'is_main_sponsor'      => true,
									'show_on_landing_page' => true,
									'is_active'            => true,
								],
								[
									'id'                   => 2,
									'title'                => 'Beta Ltd',
									'sponsor_categories'   => [ 6709 ],
									'show_on_landing_page' => false,
									'show_on_talk_pages'   => true,
									'is_active'            => true,
								],
								[
									'id'                 => 3,
									'title'              => 'Unlisted Co',
									'sponsor_categories' => [ 999999 ],
									'is_active'          => true,
								],
								[
									'id'        => 4,
									'title'     => 'Hidden Corp',
									'is_active' => false,
								],
							],
						]
					);
				}

				return null;
			}
		);

		$html = Components::render( 'sponsors', [ 'show_blurb' => 1 ] );

		$this->assertStringContainsString( 'Acme', $html );
		$this->assertStringContainsString( 'Beta Ltd', $html );
		$this->assertStringContainsString( 'Gold', $html, 'category IDs resolve to real heading names' );
		$this->assertStringContainsString( 'Media Partners', $html );
		$this->assertStringNotContainsString( '6708', $html, 'a bare category ID never becomes a heading' );
		$this->assertStringNotContainsString( '999999', $html, 'unresolvable IDs are dropped, not displayed' );
		$this->assertStringContainsString( 'Unlisted Co', $html, 'the sponsor itself still renders (under Partner)' );
		$this->assertStringContainsString( 'cdn.example.com/acme.png', $html, 'API logo URL renders' );
		$this->assertStringContainsString( 'Deliverability tools.', $html, 'short_description is the blurb when enabled' );
		$this->assertStringContainsString( '--eex-sponsor-logo:3.25em', $html, 'logo size variable rides on the wall' );
		$this->assertStringContainsString( 'Handmade Co', $html, 'manual extras stay on the wall' );
		$this->assertStringNotContainsString( 'Hidden Corp', $html, 'inactive sponsors are skipped' );
		$this->assertStringNotContainsString( 'MANUAL ROW', $html, 'the API row wins over a same-name manual row' );

		// Element toggles and grouping.
		Cache::flush();
		$plain = Components::render(
			'sponsors',
			[
				'group_by'   => 'none',
				'show_blurb' => 0,
				'logo_size'  => 'large',
			]
		);
		$this->assertStringNotContainsString( 'eex-tier-heading', $plain, 'flat wall has no headings' );
		$this->assertStringNotContainsString( 'Deliverability tools.', $plain, 'blurbs off by default' );
		$this->assertStringContainsString( '--eex-sponsor-logo:5em', $plain );

		// Columns, limit and alphabetical order.
		Cache::flush();
		$shaped = Components::render(
			'sponsors',
			[
				'group_by' => 'none',
				'columns'  => 5,
				'limit'    => 2,
				'order'    => 'name',
			]
		);
		$this->assertStringContainsString( '--eex-columns:5', $shaped );
		$this->assertStringContainsString( 'Acme', $shaped, 'alphabetically first survives the cap' );
		$this->assertStringContainsString( 'Beta Ltd', $shaped );
		$this->assertStringNotContainsString( 'Unlisted Co', $shaped, 'the cap trims the tail' );

		// The spotlight uses the rich fields the wall has no room for.
		Cache::flush();
		$spotlight = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'    => '1',
				'layout'     => 'full',
				'show_books' => 1,
				'show_phone' => 1,
			]
		);
		$this->assertStringContainsString( 'eex-sponsor-spotlight-full', $spotlight );
		$this->assertStringContainsString( 'cdn.example.com/acme-banner.jpg', $spotlight, 'promo banner renders' );
		$this->assertStringContainsString( 'youtube-nocookie.com/embed/dQw4w9WgXcQ', $spotlight, 'intro video embeds privacy-friendly' );
		$this->assertStringContainsString( '<strong>deliverability</strong>', $spotlight, 'long description keeps safe markup' );
		$this->assertStringNotContainsString( '<script>', $spotlight );
		$this->assertStringContainsString( '>Try Acme free</a>', $spotlight, 'link_title is the button label' );
		$this->assertStringContainsString( 'meet.example.com/acme', $spotlight, 'booking link' );
		$this->assertStringContainsString( 'tel:+442079460000', $spotlight, 'phone link normalised' );

		// Link destinations, the strip marquee and the compact wall.
		Cache::flush();
		$hub_linked = Components::render(
			'sponsors',
			[
				'group_by'     => 'none',
				'sponsor_link' => 'hub',
			]
		);
		$this->assertStringContainsString( 'summit.example.com/hub/sponsors/acme/', $hub_linked, 'hub link built from the REAL API slug' );
		$this->assertStringContainsString( 'Beta Ltd', $hub_linked, 'slugless sponsors still render (website fallback)' );

		Cache::flush();
		$unlinked = Components::render(
			'sponsors',
			[
				'group_by'     => 'none',
				'sponsor_link' => 'none',
			]
		);
		$this->assertStringNotContainsString( 'acme.example.com', $unlinked, 'no-link mode removes sponsor URLs' );

		Cache::flush();
		$strip = Components::render( 'sponsors', [ 'layout' => 'strip' ] );
		$this->assertStringContainsString( 'eex-sponsor-strip', $strip );
		$this->assertStringContainsString( 'eex-strip-track', $strip );
		$this->assertSame( 2, substr_count( $strip, 'cdn.example.com/acme.png' ), 'the track is doubled for a seamless loop' );
		$this->assertStringContainsString( 'aria-hidden="true"', $strip, 'the duplicate copy is decorative' );

		Cache::flush();
		$compact = Components::render( 'sponsors', [ 'layout' => 'compact' ] );
		$this->assertStringContainsString( 'eex-sponsor-compact', $compact );

		// The spotlight button can point at the hub page too.
		Cache::flush();
		$hub_spot = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'      => '1',
				'sponsor_link' => 'hub',
			]
		);
		$this->assertStringContainsString( 'summit.example.com/hub/sponsors/acme/', $hub_spot );

		// Random constrained to a category: with one Gold sponsor the "random"
		// pick is deterministic, and a category with no members is empty.
		Cache::flush();
		$gold_only = Components::render( 'sponsor-spotlight', [ 'sponsor_category' => 'gold' ] );
		$this->assertStringContainsString( 'Acme', $gold_only );
		$this->assertStringNotContainsString( 'Beta Ltd', $gold_only );

		Cache::flush();
		$empty_cat = Components::render( 'sponsor-spotlight', [ 'sponsor_category' => 'platinum' ] );
		$this->assertStringContainsString( 'eex-empty', $empty_cat );

		// The spotlight pool can be scoped by surface, composing with the
		// category filter: Gold + landing = Acme alone (deterministic), and
		// a named sponsor hidden from the chosen surface yields the empty
		// state (Beta is flagged off the landing page).
		Cache::flush();
		$landing_spot = Components::render(
			'sponsor-spotlight',
			[
				'shown_on'         => 'landing',
				'sponsor_category' => 'gold',
			]
		);
		$this->assertStringContainsString( 'Acme', $landing_spot );
		$this->assertStringNotContainsString( 'Beta Ltd', $landing_spot );

		Cache::flush();
		$hidden_spot = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'  => '2',
				'shown_on' => 'landing',
			]
		);
		$this->assertStringContainsString( 'eex-empty', $hidden_spot, 'Beta is flagged off the landing page' );

		// A video spotlight never draws a videoless sponsor: only Acme has
		// one, so the "random" pick is deterministic — and naming a
		// videoless sponsor while requiring video yields the empty state.
		Cache::flush();
		$video_only = Components::render( 'sponsor-spotlight', [ 'require_video' => 1 ] );
		$this->assertStringContainsString( 'Acme', $video_only );
		$this->assertStringContainsString( 'youtube-nocookie.com', $video_only );

		Cache::flush();
		$video_missing = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'       => '2',
				'require_video' => 1,
			]
		);
		$this->assertStringContainsString( 'eex-empty', $video_missing );

		// The wall can hide individual sponsors by ID.
		Cache::flush();
		$without_acme = Components::render(
			'sponsors',
			[
				'group_by' => 'none',
				'exclude'  => '1',
			]
		);
		$this->assertStringNotContainsString( 'Acme', $without_acme );
		$this->assertStringContainsString( 'Beta Ltd', $without_acme );

		// A specific pick that does not exist falls to the empty state.
		Cache::flush();
		$missing = Components::render( 'sponsor-spotlight', [ 'sponsor' => '424242' ] );
		$this->assertStringContainsString( 'eex-empty', $missing );

		// Reverse alphabetical flips who survives.
		Cache::flush();
		$reversed = Components::render(
			'sponsors',
			[
				'group_by' => 'none',
				'limit'    => 2,
				'order'    => 'name-desc',
			]
		);
		$this->assertStringContainsString( 'Unlisted Co', $reversed );
		$this->assertStringNotContainsString( 'Acme', $reversed );

		// Main sponsors only.
		Cache::flush();
		$main = Components::render( 'sponsors', [ 'main_only' => 1 ] );
		$this->assertStringContainsString( 'Acme', $main );
		$this->assertStringNotContainsString( 'Beta Ltd', $main );
		$this->assertStringNotContainsString( 'Handmade Co', $main, 'manual rows are never main' );

		// Only sponsors flagged for the landing page.
		Cache::flush();
		$landing = Components::render( 'sponsors', [ 'shown_on' => 'landing' ] );
		$this->assertStringContainsString( 'Acme', $landing );
		$this->assertStringNotContainsString( 'Beta Ltd', $landing, 'hidden-from-landing sponsors filtered out' );
		$this->assertStringContainsString( 'Handmade Co', $landing, 'manual rows pass visibility filters' );

		// One sponsor category.
		Cache::flush();
		$gold = Components::render( 'sponsors', [ 'sponsor_category' => 'gold' ] );
		$this->assertStringContainsString( 'Acme', $gold );
		$this->assertStringNotContainsString( 'Beta Ltd', $gold );

		// Spotlight identity toggles: logo, name and short description can
		// each be hidden independently.
		Cache::flush();
		$logo_only = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'    => '1',
				'show_name'  => 0,
				'show_blurb' => 0,
			]
		);
		$this->assertStringContainsString( 'cdn.example.com/acme.png', $logo_only, 'logo stays' );
		$this->assertStringNotContainsString( 'eex-spotlight-name', $logo_only, 'name hidden' );
		$this->assertStringNotContainsString( 'Deliverability tools.', $logo_only, 'blurb hidden' );

		Cache::flush();
		$no_logo = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'   => '1',
				'show_logo' => 0,
			]
		);
		$this->assertStringNotContainsString( 'cdn.example.com/acme.png', $no_logo, 'logo hidden' );
		$this->assertStringContainsString( 'Acme', $no_logo, 'name stays' );
		$this->assertStringContainsString( 'Deliverability tools.', $no_logo, 'blurb stays' );

		// Character caps: the blurb trims on a word boundary with an
		// ellipsis; a capped full description drops its markup safely.
		Cache::flush();
		$short = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'      => '1',
				'blurb_length' => 15,
			]
		);
		$this->assertStringNotContainsString( 'Deliverability tools.', $short );
		$this->assertStringContainsString( 'Deliverability…', $short );

		Cache::flush();
		$capped_full = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'            => '1',
				'layout'             => 'full',
				'description_length' => 30,
			]
		);
		$this->assertStringNotContainsString( '<strong>', $capped_full, 'capped description renders plain text (no broken tags)' );
		$this->assertStringContainsString( '…', $capped_full );
		$this->assertStringContainsString( 'eex-spotlight-description', $capped_full );

		// Button labels: the operator's text outranks the vendor CTA, and
		// the booking button label is configurable too.
		Cache::flush();
		$labelled = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'      => '1',
				'show_books'   => 1,
				'website_text' => 'Meet our sponsor',
				'books_text'   => 'Grab a slot',
			]
		);
		$this->assertStringContainsString( '>Meet our sponsor</a>', $labelled, 'website_text outranks link_title' );
		$this->assertStringNotContainsString( 'Try Acme free', $labelled );
		$this->assertStringContainsString( '>Grab a slot</a>', $labelled );

		// The wall's short descriptions can be capped like the spotlight's.
		Cache::flush();
		$capped_wall = Components::render(
			'sponsors',
			[
				'group_by'     => 'none',
				'show_blurb'   => 1,
				'blurb_length' => 15,
			]
		);
		$this->assertStringNotContainsString( 'Deliverability tools.', $capped_wall );
		$this->assertStringContainsString( 'Deliverability…', $capped_wall );

		// A custom wall heading tops the wall one level above the category
		// headings, whose tag is itself configurable.
		Cache::flush();
		$headed = Components::render(
			'sponsors',
			[
				'heading'       => 'Our sponsors',
				'heading_level' => '3',
			]
		);
		$this->assertStringContainsString( '<h2 class="eex-wall-heading">Our sponsors</h2>', $headed );
		$this->assertStringContainsString( '<h3 class="eex-tier-heading">', $headed );

		Cache::flush();
		$headed_strip = Components::render(
			'sponsors',
			[
				'layout'        => 'strip',
				'heading'       => 'Partners',
				'heading_level' => '4',
			]
		);
		$this->assertStringContainsString( '<h3 class="eex-wall-heading">Partners</h3>', $headed_strip, 'strip and flat walls get the heading too' );

		Cache::flush();
		$level_two = Components::render(
			'sponsors',
			[
				'heading'       => 'Top',
				'heading_level' => '2',
			]
		);
		$this->assertStringContainsString( '<h2 class="eex-wall-heading">Top</h2>', $level_two, 'the wall heading never rises above h2' );
		$this->assertStringContainsString( '<h2 class="eex-tier-heading">', $level_two );

		// new_tab adds target="_blank" to sponsor anchors; default does not,
		// and the spotlight's tel: link never gets one.
		Cache::flush();
		$same_tab = Components::render( 'sponsors', [ 'group_by' => 'none' ] );
		$this->assertStringNotContainsString( 'target="_blank"', $same_tab );

		Cache::flush();
		$tabbed = Components::render(
			'sponsors',
			[
				'group_by' => 'none',
				'new_tab'  => 1,
			]
		);
		$this->assertStringContainsString( 'target="_blank"', $tabbed );
		$this->assertStringContainsString( 'rel="sponsored noopener"', $tabbed, 'noopener retained' );

		Cache::flush();
		$tabbed_strip = Components::render(
			'sponsors',
			[
				'layout'  => 'strip',
				'new_tab' => 1,
			]
		);
		$this->assertStringContainsString( 'target="_blank"', $tabbed_strip );

		Cache::flush();
		$tabbed_spot = Components::render(
			'sponsor-spotlight',
			[
				'sponsor'    => '1',
				'show_books' => 1,
				'show_phone' => 1,
				'new_tab'    => 1,
			]
		);
		$this->assertSame( 2, substr_count( $tabbed_spot, 'target="_blank"' ), 'website and booking buttons only — never the tel: link' );

		// UTM on sponsor links is opt-in per widget; hub links arrive
		// pre-tagged and are never double-tagged.
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'example-site',
				'utm_medium'  => 'events',
			]
		);

		Cache::flush();
		$untagged = Components::render( 'sponsors', [ 'group_by' => 'none' ] );
		$this->assertStringNotContainsString( 'acme.example.com?utm_source', $untagged, 'sponsor website links stay clean by default' );

		Cache::flush();
		$tagged = Components::render(
			'sponsors',
			[
				'group_by'  => 'none',
				'utm_links' => 1,
			]
		);
		$this->assertStringContainsString( 'acme.example.com?utm_source=example-site', $tagged );

		Cache::flush();
		$hub_tagged = Components::render(
			'sponsors',
			[
				'group_by'     => 'none',
				'sponsor_link' => 'hub',
				'utm_links'    => 1,
			]
		);
		$this->assertSame( 1, substr_count( $hub_tagged, 'sponsors/acme/?utm_source=' ), 'hub URL tagged exactly once' );
	}

	public function test_api_failure_serves_last_good_then_empty_state_never_fatal(): void {
		$this->go_lite();
		$this->mock_api();

		// Warm the cache.
		$warm = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Live session one', $warm );

		// Kill the API and expire the fresh copies (keep last-good).
		remove_all_filters( 'pre_http_request' );
		$this->mock_api( true );
		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			if ( str_starts_with( (string) $key, 'eex_live_' ) ) {
				unset( \EEX_Test_State::$transients[ $key ] );
			}
		}
		Cache::flush();
		LiveCache::reset_request_state();

		$stale = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Live session one', $stale, 'last-good copy served on failure' );

		// No last-good either: the empty state, never an error.
		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			if ( str_starts_with( (string) $key, 'eex_lg_' ) || str_starts_with( (string) $key, 'eex_live_' ) ) {
				unset( \EEX_Test_State::$transients[ $key ] );
			}
		}
		Cache::flush();
		LiveCache::reset_request_state();

		$empty = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'eex-empty', $empty );
		$this->assertStringContainsString( 'New sessions are announced soon.', $empty );

		// hide_empty: with the API still dead and no last-good copy, a
		// visitor gets nothing at all — while an admin gets the explanation.
		Cache::flush();
		LiveCache::reset_request_state();
		\EEX_Test_State::$user_can = false;
		$hidden                    = Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] );
		$this->assertSame( '', $hidden, 'visitors see nothing, never an unexplained empty box' );

		Cache::flush();
		LiveCache::reset_request_state();
		\EEX_Test_State::$user_can = true;
		$admin_view                = Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] );
		$this->assertStringNotContainsString( 'eex-empty', $admin_view );
		$this->assertStringContainsString( 'hidden by its hide_empty setting', $admin_view );
	}

	public function test_hide_empty_never_caches_the_blank(): void {
		$this->go_lite();
		$this->mock_api( true ); // Every fetch fails: guaranteed empty state.

		\EEX_Test_State::$user_can = false;
		$html                      = Components::render( 'upcoming-sessions', [ 'hide_empty' => 1 ] );
		$this->assertSame( '', $html );

		// The cache holds the REAL empty fragment under the 60-second
		// guardrail TTL — never '' and never the full display TTL — so the
		// serve-stale safety net and the empty-retry guardrail both survive.
		$cached = array_filter(
			\EEX_Test_State::$transients,
			static fn( $value, $key ): bool => str_starts_with( (string) $key, 'eex_c_' ) && is_string( $value ),
			ARRAY_FILTER_USE_BOTH
		);
		$this->assertNotEmpty( $cached, 'the fragment was cached despite being hidden' );

		foreach ( $cached as $key => $value ) {
			$this->assertStringContainsString( 'eex-empty', $value, 'the real empty fragment is cached, not the stripped blank' );
			$this->assertLessThanOrEqual( 60, \EEX_Test_State::$transient_ttls[ $key ] ?? 0, 'empty fragments keep the guardrail TTL' );
		}

		// The last-good slot was not clobbered with a blank.
		foreach ( \EEX_Test_State::$transients as $key => $value ) {
			if ( str_starts_with( (string) $key, 'eex_lg_' ) ) {
				$this->assertNotSame( '', $value, 'last-good copies are never blank' );
			}
		}
	}

	public function test_budget_caps_cold_fetches_per_request(): void {
		$this->go_lite();
		Options::update_settings( [ 'lite_events' => [ 'c1|101', 'c1|102', 'c1|103' ] ] );
		$this->mock_api();

		Components::render( 'upcoming-sessions', [] );

		$this->assertLessThanOrEqual( LiveCache::COLD_BUDGET, count( $this->requests ) );
	}

	public function test_stampede_lock_lets_exactly_one_concurrent_fetch_through(): void {
		$this->go_lite();

		// A concurrent request holds the lock for the events resource.
		$key = 'events|c1';
		add_option( 'eex_live_lock_' . md5( $key ), time(), '', false );

		$calls = 0;
		$value = LiveCache::remember(
			$key,
			static function () use ( &$calls ) {
				++$calls;

				return [ 'x' ];
			}
		);

		$this->assertSame( 0, $calls, 'locked: no duplicate fetch' );
		$this->assertNull( $value, 'no last-good: empty state' );

		// A stale lock (crashed request) is stolen.
		update_option( 'eex_live_lock_' . md5( $key ), time() - 3600, false );
		$value = LiveCache::remember(
			$key,
			static function () use ( &$calls ) {
				++$calls;

				return [ 'x' ];
			}
		);
		$this->assertSame( 1, $calls );
		$this->assertSame( [ 'x' ], $value );
	}

	// -- Criterion 4: server-side only. ---------------------------------------

	public function test_lite_markup_contains_no_browser_calls_to_heysummit_and_no_key(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringNotContainsString( 'api/v2', $html, 'no API URL reaches the browser' );
		$this->assertStringNotContainsString( 'app.heysummit.com/api', $html );
		$this->assertStringNotContainsString( 'k', array_reduce( $this->requests, static fn( $c, $u ) => $c, '' ), 'sanity' );
		$this->assertStringNotContainsString( 'Token', $html, 'no auth material in output' );
	}

	// -- Criterion 5: shared render callbacks, link-target difference only. ----

	public function test_lite_cards_link_to_heysummit_urls_with_utm(): void {
		$this->go_lite();
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'example.org',
			]
		);
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'summit.example.com', $html, 'links go to HeySummit' );
		$this->assertStringContainsString( 'utm_source=example.org', $html, 'UTM auto-tagging applies' );
	}

	public function test_render_callbacks_are_shared_one_code_path_two_repositories(): void {
		// Full: the synced repository; Lite: the live repository.
		$this->assertInstanceOf( SyncedRepository::class, Repositories::current() );

		$this->go_lite();
		Repositories::reset();
		$this->assertInstanceOf( LiveRepository::class, Repositories::current() );

		// Same markup skeleton from the same callback in both modes.
		$this->mock_api();
		$lite_html = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( '<ul class="eex-grid eex-talk-grid" role="list">', $lite_html );
		$this->assertStringContainsString( 'eex-card eex-card-talk', $lite_html );
		$this->assertStringContainsString( 'data-eex-session', $lite_html );
	}

	public function test_lite_past_sessions_work_but_past_events_stay_hidden(): void {
		$this->go_lite();
		$this->mock_api( false, true );

		$repo = Repositories::current();

		// Past SESSIONS surface from the same cached harvest, newest first.
		$past = $repo->past_talks( [] );
		$this->assertCount( 2, $past );
		$this->assertSame( 'Yesterday keynote', $past[0]['title'] );
		$this->assertSame( 'Old workshop', $past[1]['title'] );
		$this->assertSame( 2, $repo->past_talks_total( [ 'limit' => 1 ] ), 'total ignores limit' );

		// Past EVENTS remain empty: no live archive worth chasing.
		$this->assertSame( [], $repo->past_events( [] ) );
	}

	public function test_lite_past_sessions_render_newest_first_with_replay_cta(): void {
		$this->go_lite();
		$this->mock_api( false, true );

		$html = Components::render( 'past-sessions', [] );

		$this->assertStringContainsString( 'Yesterday keynote', $html );
		$this->assertStringContainsString( 'Old workshop', $html );
		$this->assertLessThan(
			strpos( $html, 'Old workshop' ),
			strpos( $html, 'Yesterday keynote' ),
			'newest first'
		);
		$this->assertStringContainsString( 'eex-cta-replay', $html );
		$this->assertStringContainsString( 'https://replay.example.com/keynote', $html );
		$this->assertStringNotContainsString( 'Live session one', $html, 'upcoming sessions stay out of the archive' );
	}

	public function test_lite_past_sessions_paginate_and_search(): void {
		$this->go_lite();
		$this->mock_api( false, true );

		// Page 2 at one per page shows the older talk.
		try {
			$_GET['eex_page'] = '2';
			$page_two         = Components::render(
				'past-sessions',
				[
					'limit'    => 1,
					'paginate' => 1,
				]
			);
		} finally {
			unset( $_GET['eex_page'] );
		}

		$this->assertStringContainsString( 'Old workshop', $page_two );
		$this->assertStringNotContainsString( 'Yesterday keynote', $page_two );

		// Text search filters and never mints cache rows.
		$fragments = static fn(): int => count(
			array_filter( array_keys( \EEX_Test_State::$transients ), static fn( $k ): bool => str_starts_with( (string) $k, 'eex_c_' ) )
		);
		$before    = $fragments();

		try {
			$_GET['eex_q'] = 'keynote';
			$searched      = Components::render( 'past-sessions', [] );
		} finally {
			unset( $_GET['eex_q'] );
		}

		$this->assertStringContainsString( 'Yesterday keynote', $searched );
		$this->assertStringNotContainsString( 'Old workshop', $searched );
		$this->assertSame( $before, $fragments(), 'searches never cache' );

		// The filter bar's no-JS category link filters server-side too,
		// without minting a cache row for the visitor-controlled value.
		$before = $fragments();
		try {
			$_GET['eex_cat'] = 'deliverability';
			$by_category     = Components::render( 'past-sessions', [] );
		} finally {
			unset( $_GET['eex_cat'] );
		}

		$this->assertStringContainsString( 'Yesterday keynote', $by_category );
		$this->assertStringNotContainsString( 'Old workshop', $by_category, 'uncategorised talk filtered out' );
		$this->assertSame( $before, $fragments(), 'GET-sourced category renders fresh' );
	}

	public function test_lite_feed_serves_from_live_data_without_rewrites(): void {
		$this->go_lite();
		$this->mock_api();

		$feeds = new \Emailexpert\Events\Frontend\Feeds();

		// No rewrite rules in Lite; the query-var URL is the subscribe URL.
		$GLOBALS['eex_test_rewrites'] = [];
		$feeds->add_rewrite();
		$this->assertSame( [], $GLOBALS['eex_test_rewrites'] );
		$this->assertStringContainsString( '?eex_feed=calendar', \Emailexpert\Events\Frontend\Feeds::url() );

		// The subscribe link on listings uses that URL.
		$listing = Components::render( 'upcoming-sessions', [ 'show_subscribe' => 1 ] );
		$this->assertStringContainsString( 'eex_feed=calendar', $listing );

		// The feed body builds from the live repository's data arrays.
		$ics = $feeds->build(
			[
				'event'    => '',
				'category' => '',
				'limit'    => 0,
			]
		);
		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringContainsString( 'SUMMARY:Live session one', $ics );
		$this->assertStringContainsString( 'SUMMARY:External masterclass', $ics );

		// Cache guard: configured events and live category slugs are
		// cacheable; junk never mints rows.
		$this->assertTrue( $feeds->should_cache( [ 'event' => '', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $feeds->should_cache( [ 'event' => '101', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $feeds->should_cache( [ 'event' => '', 'category' => 'deliverability' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $feeds->should_cache( [ 'event' => 'junk-4711', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $feeds->should_cache( [ 'event' => '', 'category' => 'no-such-slug' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_bare_api_timestamps_render_as_event_local_time_not_utc(): void {
		// The live bug: HeySummit sends starts_at WITHOUT an offset, in the
		// event's timezone (London). Treated as UTC, a 6pm London talk
		// rendered 8pm to a Madrid visitor instead of 7pm — one hour late
		// all summer. The event's timezone must disambiguate bare values.
		$this->go_lite();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 601,
									'title'     => 'London keynote',
									'starts_at' => '2035-07-07T18:00:00',
									'ends_at'   => '2035-07-07T19:00:00',
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
									'id'        => 101,
									'title'     => 'Live Hub',
									'event_url' => 'https://summit.example.com/hub/',
									'timezone'  => 'Europe/London',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$talks = Repositories::current()->upcoming_talks( [] );

		$this->assertCount( 1, $talks );
		$this->assertSame( '2035-07-07T17:00:00Z', $talks[0]['starts_at'], '6pm London BST is 5pm UTC' );
		$this->assertSame( '2035-07-07T18:00:00Z', $talks[0]['ends_at'] );

		// The rendered <time> tag carries the corrected UTC instant, so the
		// visitor-side JS converts to the RIGHT local time everywhere.
		$html = Components::render( 'next-session', [] );
		$this->assertStringContainsString( 'datetime="2035-07-07T17:00:00Z"', $html );
	}

	public function test_lite_session_filter_bar_renders_live_data_with_query_links(): void {
		$this->go_lite();
		$this->mock_api( false, true );

		$html = Components::render( 'session-filter', [ 'show_search' => 1 ] );

		$this->assertStringContainsString( 'data-eex-filter="1"', $html );
		$this->assertStringContainsString( 'data-eex-filter-cat="deliverability"', $html );
		$this->assertStringContainsString( 'eex_cat=deliverability', $html, 'URL-less live categories fall back to query-arg links' );
		$this->assertStringContainsString( 'Ada Speaker', $html );
		$this->assertStringContainsString( 'name="eex_q"', $html );
	}

	// -- Criterion 7: switching. ----------------------------------------------

	public function test_switch_to_lite_trashing_content_removes_posts_and_cron(): void {
		// Full site with content and scheduled sync.
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		\Emailexpert\Events\Sync\Scheduler::sync_schedule_state();
		$post_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Synced talk',
			]
		);
		$this->assertNotFalse( wp_next_scheduled( 'eex_sync_cron' ) );

		Mode::switch_to_lite( false );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( 'trash', get_post( $post_id )->post_status, 'content trashed (reversible)' );
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ), 'sync cron unscheduled' );
		$this->assertSame( 0, (int) Options::setting( 'lite_archive' ) );
	}

	public function test_switch_to_lite_keeping_content_freezes_the_archive(): void {
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => [ 'enabled' => 1 ] ] );
		\Emailexpert\Events\Sync\Scheduler::sync_schedule_state();
		$post_id = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Kept talk',
			]
		);

		Mode::switch_to_lite( true );

		$this->assertTrue( Options::is_lite() );
		$this->assertSame( 'publish', get_post( $post_id )->post_status, 'posts remain readable' );
		$this->assertSame( 1, (int) Options::setting( 'lite_archive' ) );
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_sync_cron' ), 'sync stays stopped' );
	}

	public function test_switch_back_to_full_loses_nothing(): void {
		$this->go_lite();

		Mode::switch_to_full();

		$this->assertFalse( Options::is_lite() );
		$this->assertSame( [ 'c1|101' ], (array) Options::setting( 'lite_events' ), 'lite settings retained for a later switch back' );
		$this->assertSame( 1, (int) Options::setting( 'flush_rewrites' ) );
	}

	// -- Criterion 5: byte-for-byte markup except link targets. ----------------

	/**
	 * Strip the two sanctioned differences: link targets and the inline
	 * JSON-LD block.
	 *
	 * @param string $html Rendered component HTML.
	 */
	private static function normalise_markup( string $html ): string {
		$html = (string) preg_replace( '/href="[^"]*"/', 'href=""', $html );
		$html = (string) preg_replace( '#<script type="application/ld\+json">.*?</script>#s', '', $html );

		return $html;
	}

	public function test_lite_markup_matches_full_byte_for_byte_except_link_targets(): void {
		$starts = gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 );
		$ends   = gmdate( 'Y-m-d\TH:i:s\Z', time() + 7200 );

		// Full mode: one synced talk.
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Same session',
				'meta_input'  => [
					'_eex_heysummit_id' => '501',
					'_eex_starts_at'    => $starts,
					'_eex_ends_at'      => $ends,
					'_eex_talk_url'     => 'https://summit.example.com/talks/501/',
				],
			]
		);

		$full = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Same session', $full );

		// Lite mode: the same session served live.
		$this->go_lite();
		Cache::flush();
		$this->mock_http(
			function ( $url ) use ( $starts, $ends ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Same session',
									'starts_at' => $starts,
									'ends_at'   => $ends,
									'talk_url'  => 'https://summit.example.com/talks/501/',
									'event'     => 101,
								],
							],
						]
					);
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'    => 101,
								'title' => 'Live Hub',
							],
						],
					]
				);
			}
		);

		$lite = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Same session', $lite );

		$this->assertSame(
			self::normalise_markup( $full ),
			self::normalise_markup( $lite ),
			'one code path: identical markup once link targets and inline schema are stripped'
		);
	}

	// -- Criterion 6: the WooCommerce bridge works identically in Lite. --------

	public function test_woo_push_in_lite_pushes_attendee_and_ticket_and_only_then_creates_the_table(): void {
		\EEX_Test_WC::reset();
		$this->go_lite();

		$posts = [];
		$this->mock_http(
			function ( $url, $args ) use ( &$posts ) {
				if ( 'POST' === ( $args['method'] ?? '' ) ) {
					$posts[] = [
						'url'  => (string) $url,
						'body' => (array) json_decode( (string) ( $args['body'] ?? '' ), true ),
					];

					return self::json_response(
						str_contains( (string) $url, 'attendees/' )
							? [
								'id'    => 55001,
								'email' => 'buyer@example.org',
							]
							: [ 'id' => 77001 ],
						201
					);
				}

				return self::json_response( [ 'results' => [] ] );
			}
		);

		$product_id = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Forum ticket',
			]
		);
		\Emailexpert\Events\WooCommerce\Module::store_mapping( $product_id, 'c1', '101', 'T-1' );

		$item_id = \EEX_Test_WC::$next_item_id++;
		$order   = new \WC_Order( 501 );
		$order->add_item( new \WC_Order_Item_Product( $item_id, $product_id, 0, 1, 100.00, 20.00 ) );
		$order->update_meta_data( '_eex_consent', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		\EEX_Test_WC::$orders[501] = $order;

		$this->assertSame( [], $GLOBALS['eex_test_dbdelta'], 'no table before the push' );

		$pusher = new \Emailexpert\Events\WooCommerce\Pusher();
		$pusher->queue_order( 501 );

		foreach ( \EEX_Test_State::$scheduled as $job ) {
			if ( 'eex_woo_push' === $job['hook'] ) {
				$pusher->run_job( ...$job['args'] );
			}
		}

		$attendee_calls = array_filter( $posts, static fn( $p ) => str_contains( $p['url'], 'events/101/attendees/' ) );

		$this->assertCount( 1, $attendee_calls, 'attendee pushed exactly as in Full' );
		$this->assertSame( 'T-1', array_values( $attendee_calls )[0]['body']['ticket_price_id'], 'ticket price rides in the create body' );

		// The push-record (attribution) table appears only now — and no log table.
		$this->assertContains( 'wp_eex_attribution', $GLOBALS['eex_test_dbdelta'] );
		$this->assertNotContains( 'wp_eex_log', $GLOBALS['eex_test_dbdelta'], 'Lite logging stays in the ring buffer' );
	}

	// -- Criterion 8: inline Event JSON-LD from Lite blocks. -------------------

	public function test_lite_blocks_emit_valid_inline_event_json_ld(): void {
		$this->go_lite();
		$this->mock_api();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertMatchesRegularExpression( '#<script type="application/ld\+json">.*</script>#s', $html );

		preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches );
		$payload = json_decode( (string) $matches[1], true );
		$this->assertIsArray( $payload );

		$events = isset( $payload[0] ) ? $payload : [ $payload ];
		$this->assertCount( 3, $events, 'one Event per rendered session (the cancelled one is excluded)' );

		foreach ( $events as $event ) {
			$this->assertSame( 'https://schema.org', $event['@context'] );
			$this->assertSame( 'Event', $event['@type'] );
			$this->assertNotSame( '', (string) $event['name'], 'name required by the Rich Results Test' );
			$this->assertNotSame( '', (string) $event['startDate'], 'startDate required by the Rich Results Test' );
			$this->assertSame( 'VirtualLocation', $event['location']['@type'] );
			$this->assertSame( 'https://summit.example.com/hub/', $event['location']['url'] );
			$this->assertSame( 'https://schema.org/OnlineEventAttendanceMode', $event['eventAttendanceMode'] );
		}

		// Full mode never emits the inline block: single pages carry schema.
		Options::update_settings( [ 'mode' => 'full' ] );
		\Emailexpert\Events\Data\Repositories::reset();
		Cache::flush();
		$full = Components::render( 'upcoming-sessions', [] );
		$this->assertStringNotContainsString( 'application/ld+json', $full );
	}

	// -- Capability matrix: hidden, not greyed out. ----------------------------

	public function test_full_only_components_are_absent_in_lite(): void {
		$this->go_lite();

		foreach ( Components::FULL_ONLY as $component ) {
			$this->assertSame( '', Components::render( $component, [] ), $component . ' renders nothing in Lite' );
			$this->assertArrayNotHasKey( $component, Components::available_definitions() );
		}

		$GLOBALS['eex_test_shortcodes'] = [];
		( new \Emailexpert\Events\Frontend\Shortcodes() )->register();
		$this->assertArrayNotHasKey( 'eex_past_events', $GLOBALS['eex_test_shortcodes'] ?? [] );
		$this->assertArrayNotHasKey( 'eex_reg_counter', $GLOBALS['eex_test_shortcodes'] ?? [] );
		$this->assertArrayHasKey( 'eex_upcoming_sessions', $GLOBALS['eex_test_shortcodes'] ?? [] );

		// Past sessions and the filter bar joined Lite (v1.19.0).
		$this->assertArrayHasKey( 'eex_past_sessions', $GLOBALS['eex_test_shortcodes'] ?? [] );
		$this->assertArrayHasKey( 'eex_session_filter', $GLOBALS['eex_test_shortcodes'] ?? [] );
	}

	public function test_hostile_api_urls_never_reach_lite_markup(): void {
		$this->go_lite();
		$this->mock_http(
			function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Hostile session',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'talk_url'  => 'javascript:alert(1)',
									'event'     => 101,
								],
							],
						]
					);
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'        => 101,
								'title'     => 'Hub',
								'event_url' => 'javascript:alert(2)',
							],
						],
					]
				);
			}
		);

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Hostile session', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_live_failures_record_a_debuggable_reason(): void {
		$this->go_lite();
		$this->mock_api( true );

		Components::render( 'upcoming-sessions', [] );

		$status = LiveCache::status();
		$this->assertNotSame( '', $status['last_failure'] );
		$this->assertStringContainsString( 'unreachable', $status['last_error'], 'the client error message survives' );
		// The collection fails first, then the targeted per-event fallback is
		// tried and fails too — the last failure names that resource key.
		$this->assertStringContainsString( '[event|c1|101]', $status['last_error'], 'the failing resource key is named' );
		$this->assertTrue( LiveCache::degraded() );

		// The ring buffer holds the request trail (no table in Lite).
		$messages = implode( ' ', array_column( \Emailexpert\Events\Logging\Logger::ring(), 'message' ) );
		$this->assertStringContainsString( 'transport error', $messages );
	}

	public function test_degraded_lite_renders_carry_an_admin_only_note_outside_the_cache(): void {
		$this->go_lite();
		$this->mock_api( true );

		// The stubbed current_user_can() returns true: this render is "an
		// administrator viewing the page".
		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'visible to administrators only', $html );
		$this->assertStringContainsString( 'last HeySummit fetch failed', $html );

		// The note must never enter the cached fragment a visitor receives.
		foreach ( \EEX_Test_State::$transients as $key => $value ) {
			if ( str_starts_with( (string) $key, 'eex_c_' ) && is_string( $value ) ) {
				$this->assertStringNotContainsString( 'administrators only', $value, 'debug note is not cached' );
			}
		}

		// A healthy fetch clears the degraded state and the note disappears.
		remove_all_filters( 'pre_http_request' );
		$this->mock_api();
		LiveCache::flush();
		Cache::flush();
		LiveCache::reset_request_state();

		$healthy = Components::render( 'upcoming-sessions', [] );
		$this->assertStringNotContainsString( 'administrators only', $healthy );
	}

	// -- Empty-state diagnosis (the "why is my block empty" report). -----------

	public function test_switching_to_lite_seeds_display_events_from_enabled_synced_events(): void {
		update_option( Options::CONNECTIONS, [ [ 'id' => 'c1', 'label' => 'Primary', 'api_key' => 'k' ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_option(
			Options::SYNCED_EVENTS,
			[
				'c1|101' => [ 'enabled' => 1 ],
				'c1|102' => [ 'enabled' => 0 ],
			]
		);

		Mode::switch_to_lite( false );

		$this->assertSame( [ 'c1|101' ], (array) Options::setting( 'lite_events' ), 'enabled synced events become the Lite display list' );
	}

	public function test_empty_lite_block_carries_a_diagnosis_for_admins(): void {
		// Lite with a connection but no display events chosen: the classic
		// switched-from-settings trap.
		update_option( Options::CONNECTIONS, [ [ 'id' => 'c1', 'label' => 'Primary', 'api_key' => 'k' ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Options::update_settings(
			[
				'mode'        => 'lite',
				'mode_chosen' => 1,
				'lite_events' => [],
			]
		);
		Repositories::reset();

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'New sessions are announced soon.', $html, 'visitors still get the polite empty state' );
		$this->assertStringContainsString( 'No events are chosen to display', $html, 'admins get the reason' );
		$this->assertStringContainsString( 'Choose events', $html );
	}

	public function test_diagnosis_names_each_pipeline_stage(): void {
		$this->go_lite();

		$repo = Repositories::current();
		$this->assertInstanceOf( LiveRepository::class, $repo );

		// Configured event that the API does not return.
		$this->mock_http( fn() => self::json_response( [ 'results' => [] ] ) );
		$this->assertStringContainsString( 'could not be fetched', $repo->diagnose() );

		// Event resolves but has no sessions.
		remove_all_filters( 'pre_http_request' );
		LiveCache::flush();
		Repositories::reset();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [] ] );
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);
		$this->assertStringContainsString( 'returned no sessions', Repositories::current()->diagnose() );

		// Sessions exist but are all past.
		remove_all_filters( 'pre_http_request' );
		LiveCache::flush();
		Repositories::reset();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Old session',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
									'event'     => 101,
								],
							],
						]
					);
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);
		$this->assertStringContainsString( 'none start in the future', Repositories::current()->diagnose() );
		$this->assertStringContainsString( 'the most recent was', Repositories::current()->diagnose(), 'the past-sessions diagnosis names the most recent date' );
		$this->assertStringContainsString( 'pages read', Repositories::current()->diagnose(), 'the diagnosis carries the harvest account' );

		// Sessions exist but none has a date at all.
		remove_all_filters( 'pre_http_request' );
		LiveCache::flush();
		Repositories::reset();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'    => 502,
									'title' => 'Undated session',
									'event' => 101,
								],
							],
						]
					);
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);
		$this->assertStringContainsString( 'none of them has a date set on HeySummit', Repositories::current()->diagnose() );

		// Healthy pipeline: no diagnosis.
		remove_all_filters( 'pre_http_request' );
		LiveCache::flush();
		Repositories::reset();
		$this->mock_api();
		$this->assertSame( '', Repositories::current()->diagnose() );
	}

	public function test_diagnose_reports_a_talks_fetch_failure_as_a_failure_not_an_empty_summit(): void {
		$this->go_lite();

		// Events resolve; the sessions request times out. "Confirm the event
		// has published talks" would send the operator to the wrong place.
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 3000 milliseconds with 0 bytes received' );
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);

		$diagnosis = Repositories::current()->diagnose();

		$this->assertStringContainsString( 'failed rather than returning empty', $diagnosis );
		$this->assertStringContainsString( 'cURL error 28', $diagnosis, 'the underlying transport error travels into the diagnosis' );
	}

	public function test_live_talks_harvest_the_last_pages_of_a_deep_history(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// Real accounts paginate talks 10 per page, oldest first, with no
		// date filter: a summit with hundreds of past talks keeps its
		// upcoming sessions on the LAST pages. The harvest must jump to the
		// end via the reported count instead of walking forward from 2020.
		// Six pages here (one talk per page); the middle must never be read.
		$this->mock_http(
			function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				$this->requests[] = $url;

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				$talk = static fn( int $id, string $title, string $date ): array => [
					'id'    => $id,
					'title' => $title,
					'date'  => $date,
					'event' => 101,
				];

				$rows = 6 === $page
					? [ $talk( 606, 'Fresh session', $future ) ]
					: [ $talk( 600 + $page, 'Ancient session ' . $page, '2020-12-10T16:00:00Z' ) ];

				return self::json_response(
					[
						'count'   => 6,
						'next'    => $page < 6 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=' . ( $page + 1 ) : null,
						'results' => $rows,
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Fresh session' ], $titles, 'the upcoming session on the last page is found' );

		$pages = array_map(
			static function ( string $url ): int {
				preg_match( '/[?&]page=(\d+)/', $url, $m );

				return isset( $m[1] ) ? (int) $m[1] : 1;
			},
			$this->requests
		);

		$this->assertSame( [ 1, 6, 5 ], $pages, 'page 1, jump to the end, one step back to confirm the boundary — the deep middle is never fetched' );
		$this->assertSame( '', Repositories::current()->diagnose(), 'a healthy paginated pipeline has no diagnosis' );
	}

	public function test_diagnose_names_sibling_events_that_might_hold_the_sessions(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + 7 * DAY_IN_SECONDS );

		// The configured event genuinely ended in 2020; the account's 2026
		// summit is a different event. The diagnosis must say both.
		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( str_contains( $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'    => 501,
									'title' => 'Old session',
									'date'  => '2020-12-10T16:00:00Z',
									'event' => 101,
								],
							],
						]
					);
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'            => 101,
								'title'         => 'Old summit',
								'first_talk_at' => '2020-05-01T10:00:00Z',
								'last_talk_at'  => '2020-12-10T16:00:00Z',
							],
							[
								'id'            => 202,
								'title'         => 'Summit 2026',
								'last_talk_at'  => $future,
							],
						],
					]
				);
			}
		);

		$diagnosis = Repositories::current()->diagnose();

		$this->assertStringContainsString( "HeySummit's own record for event 101", $diagnosis );
		$this->assertStringContainsString( 'sessions from 2020-05-01 to 2020-12-10', $diagnosis );
		$this->assertStringContainsString( 'Other events on this connection', $diagnosis );
		$this->assertStringContainsString( "202 'Summit 2026'", $diagnosis );
	}

	public function test_a_failed_deep_page_is_skipped_and_recorded_not_a_wall(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// Deep offsets are the slowest queries on a big account: the last
		// page times out here, but the one before it holds the upcoming
		// session. One timeout must not hide every upcoming session, and
		// the harvest record must name the failed page.
		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				if ( 6 === $page ) {
					return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
				}

				$rows = 5 === $page
					? [
						[
							'id'    => 605,
							'title' => 'Fresh session',
							'date'  => $future,
							'event' => 101,
						],
					]
					: [
						[
							'id'    => 600 + $page,
							'title' => 'Ancient session ' . $page,
							'date'  => '2020-12-10T16:00:00Z',
							'event' => 101,
						],
					];

				return self::json_response(
					[
						'count'   => 6,
						'next'    => $page < 6 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=' . ( $page + 1 ) : null,
						'results' => $rows,
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Fresh session' ], $titles, 'the upcoming session survives a timeout on the page after it' );

		$meta = LiveRepository::harvest_meta( 'c1|101' );
		$this->assertSame( 6, $meta['count'] );
		$this->assertArrayHasKey( 6, $meta['failed'], 'the failed page is named in the harvest record' );
		$this->assertStringContainsString( 'cURL error 28', (string) $meta['failed'][6] );
	}

	public function test_a_collection_not_ordered_by_date_is_swept_in_full(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// The API documents no ordering for the talks list. If it is not
		// chronological, the upcoming sessions sit on MIDDLE pages: both
		// ends are old, so the end-jump alone finds nothing. The harvest
		// must then sweep the remaining pages rather than give up.
		$this->mock_http(
			function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				$this->requests[] = $url;

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				$rows = 3 === $page
					? [
						[
							'id'    => 903,
							'title' => 'Mid-list future session',
							'date'  => $future,
							'event' => 101,
						],
					]
					: [
						[
							'id'    => 900 + $page,
							'title' => 'Old session ' . $page,
							'date'  => '2020-12-10T16:00:00Z',
							'event' => 101,
						],
					];

				return self::json_response(
					[
						'count'   => 4,
						'next'    => $page < 4 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=' . ( $page + 1 ) : null,
						'results' => $rows,
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Mid-list future session' ], $titles, 'the sweep reaches upcoming sessions hidden mid-list' );
	}

	public function test_a_shallow_sweep_never_blanks_what_a_deep_sweep_cached(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// Production event 181590: 273 sessions across 28 pages, and the list
		// is NOT ordered by date — its upcoming sessions sit on a MIDDLE page,
		// past both ends. A deep admin-budget sweep (40 pages) reaches them; a
		// shallow front-end sweep (12 pages) stops short and finds nothing
		// upcoming. Both fetches share one cache key, so the shallow sweep's
		// "nothing upcoming" used to overwrite the deep sweep's last-good copy
		// — the events showed at bedtime, vanished by morning, and only a
		// Flush live cache (which reloads the admin page at the deep budget)
		// brought them back. The shallow sweep must preserve, never erase.
		$this->mock_http(
			function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				$this->requests[] = $url;

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				// The one upcoming session is on page 20: beyond the 12-page
				// front-end budget, within the 40-page admin budget. Both ends
				// (pages 1 and 30) and everything the shallow sweep can reach
				// are old.
				$rows = 20 === $page
					? [ [ 'id' => 2000, 'title' => 'Upcoming keynote', 'date' => $future, 'event' => 101 ] ] // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					: [ [ 'id' => 1000 + $page, 'title' => 'Old session ' . $page, 'date' => '2020-12-10T16:00:00Z', 'event' => 101 ] ]; // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

				return self::json_response(
					[
						'count'   => 30,
						'next'    => $page < 30 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=' . ( $page + 1 ) : null,
						'results' => $rows,
					]
				);
			}
		);

		// The deep sweep reaches page 20 and caches the upcoming session as
		// both the fresh and the 24-hour last-good copy.
		add_filter( 'eex_live_max_pages', static fn(): int => 40 );

		$deep = array_map( static fn( array $t ): string => (string) $t['title'], Repositories::current()->upcoming_talks( [] ) );
		$this->assertSame( [ 'Upcoming keynote' ], $deep, 'the deep sweep finds the mid-list upcoming session' );

		// Overnight the fresh copy (15 min) expires; the last-good copy (24h)
		// survives. A visitor now triggers a shallow re-sweep.
		foreach ( array_keys( \EEX_Test_State::$transients ) as $key ) {
			if ( str_starts_with( (string) $key, 'eex_live_' ) ) {
				unset( \EEX_Test_State::$transients[ $key ] );
			}
		}
		LiveCache::reset_request_state();
		Repositories::reset();
		$this->requests = [];

		remove_all_filters( 'eex_live_max_pages' );
		add_filter( 'eex_live_max_pages', static fn(): int => 12 );

		$shallow = array_map( static fn( array $t ): string => (string) $t['title'], Repositories::current()->upcoming_talks( [] ) );

		// The shallow sweep never reached page 20...
		$reached = array_map(
			static function ( string $url ): int {
				preg_match( '/[?&]page=(\d+)/', $url, $m );

				return isset( $m[1] ) ? (int) $m[1] : 1;
			},
			$this->requests
		);
		$this->assertNotContains( 20, $reached, 'the shallow sweep is budget-capped before page 20' );

		// ...yet the upcoming session still shows, served from last-good.
		$this->assertSame( [ 'Upcoming keynote' ], $shallow, 'the shallow sweep preserves the cached upcoming session instead of blanking it' );

		$meta = LiveRepository::harvest_meta( 'c1|101' );
		$this->assertSame( 'last-good', $meta['served'] ?? '', 'the harvest record notes the fall back to last-good' );
		$this->assertStringContainsString( 'last complete result is being shown', $this->harvest_line(), 'the status row explains the truncation' );
	}

	/**
	 * The harvest account line for the sole configured event, for assertions.
	 */
	private function harvest_line(): string {
		$method = new \ReflectionMethod( LiveRepository::class, 'harvest_summary' );
		$method->setAccessible( true );

		return (string) $method->invoke( Repositories::current() );
	}

	public function test_the_diagnosis_shows_raw_wire_evidence_spans_drops_and_a_sample(): void {
		$this->go_lite();

		// Page 1: an old active session. Page 2 (the deepest): one session
		// marked inactive and dated in the future — fetched, then excluded.
		// The diagnosis must show the date spans, the exclusion count and a
		// raw sample so nobody has to infer what the API returned.
		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				$rows = 2 === $page
					? [
						[
							'id'        => 802,
							'title'     => 'Hidden future session',
							'date'      => $future,
							'event'     => 101,
							'is_active' => false,
						],
					]
					: [
						[
							'id'    => 801,
							'title' => 'Old session',
							'date'  => '2020-12-10T16:00:00Z',
							'event' => 101,
						],
					];

				return self::json_response(
					[
						'count'   => 2,
						'next'    => $page < 2 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=2' : null,
						'results' => $rows,
					]
				);
			}
		);

		$diagnosis = Repositories::current()->diagnose();

		$this->assertStringContainsString( 'paged by page', $diagnosis );
		$this->assertStringContainsString( 'dates 2020-12-10..2020-12-10', $diagnosis, 'page date spans are shown' );
		$this->assertStringContainsString( '2 row(s) fetched', $diagnosis );
		$this->assertStringContainsString( '1 marked inactive on HeySummit', $diagnosis, 'exclusions are counted with reasons' );
		$this->assertStringContainsString( 'Sample session from the deepest page read', $diagnosis );
		$this->assertStringContainsString( 'is_active=false', $diagnosis, 'the raw sample shows the wire fields' );
	}

	public function test_flush_live_cache_also_resets_the_harvest_record(): void {
		$this->go_lite();
		$this->mock_api();

		Repositories::current()->upcoming_talks( [] );
		$this->assertNotEmpty( LiveRepository::harvest_meta( 'c1|101' ), 'a harvest record exists after a fetch' );

		LiveCache::flush();

		$this->assertSame( [], LiveRepository::harvest_meta( 'c1|101' ), 'Flush live cache resets the diagnostics with the data' );
	}

	public function test_the_paging_scheme_is_learned_from_the_routes_own_next_link(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// The production talks route ignores ?page= and pages by
		// limit/offset — its next link says so. 273 sessions read as 10
		// until the harvest started speaking the route's own dialect.
		$this->mock_http(
			function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				$this->requests[] = $url;

				preg_match( '/[?&]offset=(\d+)/', $url, $m );
				$offset = isset( $m[1] ) ? (int) $m[1] : 0;

				$rows = 5 === $offset
					? [
						[
							'id'    => 705,
							'title' => 'Fresh session',
							'date'  => $future,
							'event' => 101,
						],
					]
					: [
						[
							'id'    => 700 + $offset,
							'title' => 'Ancient session ' . $offset,
							'date'  => '2020-12-10T16:00:00Z',
							'event' => 101,
						],
					];

				return self::json_response(
					[
						'count'   => 6,
						'next'    => $offset < 5 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?limit=1&offset=' . ( $offset + 1 ) : null,
						'results' => $rows,
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Fresh session' ], $titles, 'the upcoming session is reached via limit/offset paging' );
		$this->assertNotEmpty( array_filter( $this->requests, static fn( string $url ): bool => str_contains( $url, 'offset=5' ) ), 'the jump used the offset dialect' );
		$this->assertSame( [], array_filter( $this->requests, static fn( string $url ): bool => str_contains( $url, 'page=' ) ), 'no assumed ?page= requests were sent' );
	}

	public function test_a_route_that_ignores_paging_parameters_is_reported_not_trusted(): void {
		$this->go_lite();

		// Whatever parameters travel, the route echoes the first page back.
		// The harvest must say so instead of reporting one page as the
		// whole summit.
		$this->mock_http(
			static function ( $url ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				return self::json_response(
					[
						'count'   => 273,
						'next'    => \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=2',
						'results' => [
							[
								'id'    => 501,
								'title' => 'Old session',
								'date'  => '2020-12-10T16:00:00Z',
								'event' => 101,
							],
						],
					]
				);
			}
		);

		$diagnosis = Repositories::current()->diagnose();

		$this->assertStringContainsString( 'pages that failed', $diagnosis );
		$this->assertStringContainsString( 'returned the first page again', $diagnosis );

		$meta = LiveRepository::harvest_meta( 'c1|101' );
		$this->assertSame( 273, $meta['count'] );
		$this->assertCount( 1, $meta['failed'], 'the walk stops after the first echo instead of burning the budget' );
	}

	public function test_the_count_is_trusted_when_the_next_link_is_absent(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// Some routes report a count but omit the next link; the jump to the
		// last page must still happen.
		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				return self::json_response(
					[
						'count'   => 2,
						'results' => [
							2 === $page
								? [
									'id'    => 612,
									'title' => 'Fresh session',
									'date'  => $future,
									'event' => 101,
								]
								: [
									'id'    => 611,
									'title' => 'Ancient session',
									'date'  => '2020-12-10T16:00:00Z',
									'event' => 101,
								],
						],
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Fresh session' ], $titles, 'the harvest jumps by count even without a next link' );
	}

	public function test_live_talks_harvest_handles_newest_first_ordering(): void {
		$this->go_lite();

		$future = gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS );

		// A newest-first account: upcoming sessions start on page 1 and can
		// spill onto page 2; the old history sits at the end.
		$this->mock_http(
			static function ( $url ) use ( $future ) {
				$url = (string) $url;

				if ( ! str_contains( $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				preg_match( '/[?&]page=(\d+)/', $url, $m );
				$page = isset( $m[1] ) ? (int) $m[1] : 1;

				$by_page = [
					1 => [
						'id'    => 701,
						'title' => 'Soonest session',
						'date'  => $future,
						'event' => 101,
					],
					2 => [
						'id'    => 702,
						'title' => 'Later session',
						'date'  => $future,
						'event' => 101,
					],
					3 => [
						'id'    => 703,
						'title' => 'Ancient session',
						'date'  => '2020-12-10T16:00:00Z',
						'event' => 101,
					],
				];

				return self::json_response(
					[
						'count'   => 3,
						'next'    => $page < 3 ? \Emailexpert\Events\Api\HeySummitClient::BASE_URL . 'events/101/talks/?page=' . ( $page + 1 ) : null,
						'results' => [ $by_page[ min( 3, $page ) ] ],
					]
				);
			}
		);

		$titles = array_map( static fn( array $talk ): string => (string) $talk['title'], Repositories::current()->upcoming_talks( [] ) );

		$this->assertSame( [ 'Soonest session', 'Later session' ], $titles, 'upcoming sessions beyond page 1 of a newest-first account are found' );
	}

	public function test_talks_filter_falls_back_to_event_id_parameter(): void {
		$this->go_lite();
		$this->mock_http(
			function ( $url ) {
				$this->requests[] = (string) $url;

				// ?event= is ignored by this "API" (returns an unrelated
				// event's talks); ?event_id= filters correctly.
				if ( str_contains( (string) $url, 'talks/' ) && str_contains( (string) $url, 'event_id=101' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Filtered by event_id',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'event'     => 101,
								],
							],
						]
					);
				}

				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 999, 'title' => 'Other event talk', 'event' => 999 ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				return self::json_response( [ 'results' => [ [ 'id' => 101, 'title' => 'Hub' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Filtered by event_id', $html );
	}

	public function test_nested_talks_route_serves_accounts_that_refuse_top_level( ): void {
		// Verified live: events/ works but top-level talks/ answers 403;
		// the same data is served nested under the event.
		$this->go_lite();
		$this->mock_http(
			function ( $url ) {
				$this->requests[] = (string) $url;

				if ( str_contains( (string) $url, 'events/101/talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Nested-route session',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'event'     => 101,
								],
							],
						]
					);
				}

				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response( [ 'detail' => 'You do not have permission to perform this action.' ], 403 );
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'                          => 101,
								'title'                       => 'Hub',
								'event_url'                   => 'https://summit.example.com/hub/',
								'_is_open_for_registrations'  => true,
							],
						],
					]
				);
			}
		);

		$html = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'Nested-route session', $html );

		// The working style is remembered: after a cache flush the next
		// fetch leads with the nested route instead of re-trying 403s.
		$this->requests = [];
		LiveCache::flush();
		Cache::flush();
		LiveCache::reset_request_state();
		Repositories::reset();

		Components::render( 'upcoming-sessions', [] );

		$talk_requests = array_values( array_filter( $this->requests, static fn( $u ): bool => str_contains( (string) $u, 'talks/' ) ) );
		$this->assertNotEmpty( $talk_requests );
		$this->assertStringContainsString( 'events/101/talks/', (string) $talk_requests[0], 'nested route tried first once learnt' );
		$this->assertCount( 1, $talk_requests, 'no wasted calls on refused routes' );
	}

	public function test_underscore_prefixed_open_flag_is_recognised(): void {
		// Verified live: the events resource exposes
		// _is_open_for_registrations (leading underscore).
		$this->go_lite();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response( [ 'results' => [] ] );
				}

				return self::json_response(
					[
						'results' => [
							[
								'id'                          => 101,
								'title'                       => 'Evergreen hub',
								'event_url'                   => 'https://summit.example.com/hub/',
								'is_evergreen'                => true,
								'_is_open_for_registrations'  => true,
							],
						],
					]
				);
			}
		);

		$events = Repositories::current()->upcoming_events( [] );

		$this->assertCount( 1, $events, 'an open evergreen event is upcoming — the underscore flag must be read' );
		$this->assertTrue( (bool) $events[0]['open'] );
	}

	public function test_configured_event_beyond_the_first_page_is_fetched_directly(): void {
		$this->go_lite();
		$this->mock_http(
			function ( $url ) {
				$this->requests[] = (string) $url;

				if ( str_contains( (string) $url, 'events/101/' ) && ! str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'id'        => 101,
							'title'     => 'Second-page hub',
							'event_url' => 'https://summit.example.com/hub/',
						]
					);
				}

				if ( str_contains( (string) $url, 'talks/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'        => 501,
									'title'     => 'Deep-page session',
									'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + 3600 ),
									'event'     => 101,
								],
							],
						]
					);
				}

				// The events collection page does NOT contain event 101.
				return self::json_response( [ 'results' => [ [ 'id' => 999, 'title' => 'Unrelated' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}
		);

		// Budget: collection + targeted event + talks = 3 fetches; allow them.
		add_filter( 'eex_live_budget', static fn() => 5 );

		$html = Components::render( 'upcoming-sessions', [] );

		$this->assertStringContainsString( 'Deep-page session', $html );
	}

	// -- Cache-stuffing guards. -------------------------------------------------

	public function test_search_queries_never_mint_cache_entries(): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Searchable',
				'meta_input'  => [ '_eex_starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - 3600 ) ],
			]
		);

		$fragments = static fn(): int => count(
			array_filter( array_keys( \EEX_Test_State::$transients ), static fn( $k ): bool => str_starts_with( (string) $k, 'eex_c_' ) )
		);

		// A plain render caches one fragment.
		Components::render( 'past-sessions', [] );
		$this->assertSame( 1, $fragments() );

		// Visitor-typed searches render fresh: no new rows per unique string.
		try {
			foreach ( [ 'aaa', 'bbb', 'ccc' ] as $q ) {
				$_GET['eex_q'] = $q;
				Components::render( 'past-sessions', [] );
			}
		} finally {
			unset( $_GET['eex_q'] );
		}

		$this->assertSame( 1, $fragments(), 'no transient per search string' );
	}

	public function test_feed_caches_only_known_entity_parameters(): void {
		$feeds = new \Emailexpert\Events\Frontend\Feeds();

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [ '_eex_heysummit_id' => '101' ],
			]
		);

		$this->assertTrue( $feeds->should_cache( [ 'event' => '', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertTrue( $feeds->should_cache( [ 'event' => '101', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $feeds->should_cache( [ 'event' => 'random-junk-9137', 'category' => '' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertFalse( $feeds->should_cache( [ 'event' => '', 'category' => 'no-such-category' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
	}

	public function test_visitor_controlled_ics_refs_cannot_trigger_per_id_api_fetches(): void {
		$this->go_lite();
		$this->mock_api();

		$repo = Repositories::current();

		// Unknown ID: resolved against the cached collections only — no
		// talks/<id>/ request, no per-ID cache row.
		$this->assertNull( $repo->known_talk( '999999' ) );
		$this->assertSame( [], array_filter( $this->requests, static fn( $u ): bool => str_contains( (string) $u, 'talks/999999' ) ) );

		// A talk that exists in the configured events resolves fine.
		$known = $repo->known_talk( '501' );
		$this->assertNotNull( $known );
		$this->assertSame( 'Live session one', $known['title'] );
	}

	public function test_rate_limiter_caps_per_ip_per_minute(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( \Emailexpert\Events\RateLimiter::allow( 'test-bucket', 5 ) );
		}

		$this->assertFalse( \Emailexpert\Events\RateLimiter::allow( 'test-bucket', 5 ), 'sixth request in the window is refused' );
		$this->assertTrue( \Emailexpert\Events\RateLimiter::allow( 'other-bucket', 5 ), 'buckets are independent' );
	}

	// -- Per-session .ics from live data. --------------------------------------

	public function test_live_ics_builds_from_repository_data(): void {
		$this->go_lite();
		$this->mock_api();

		$data = Repositories::current()->talk( '501' );
		$this->assertNotNull( $data );
		$this->assertSame( 501, $data['ics_ref'] );

		$ics = \Emailexpert\Events\Frontend\Ics::calendar_from_data( [ $data ] );

		$this->assertStringContainsString( 'BEGIN:VCALENDAR', $ics );
		$this->assertStringContainsString( 'UID:eex-talk-501@', $ics );
		$this->assertStringContainsString( 'SUMMARY:Live session one', $ics );
		$this->assertStringContainsString( 'DTSTART:', $ics );
	}

	public function test_lite_sponsor_rows_render_on_the_wall(): void {
		$this->go_lite();
		Options::update_settings(
			[
				'lite_sponsors' => [
					[
						'name'       => 'Acme Deliverability',
						'url'        => 'https://acme.example.com/',
						'logo_url'   => 'https://cdn.example.com/acme.png',
						'tier'       => 'Gold',
						'tier_order' => 1,
						'blurb'      => 'Inbox specialists.',
					],
				],
			]
		);

		$html = Components::render( 'sponsors', [] );

		$this->assertStringContainsString( 'Acme Deliverability', $html );
		$this->assertStringContainsString( 'Gold', $html );
		$this->assertStringContainsString( 'acme.png', $html );
	}

	public function test_sponsor_csv_import_and_media_id_logos(): void {
		$rows = \Emailexpert\Events\Admin\SettingsPage::parse_sponsor_csv(
			"Acme Corp, https://acme.example.com/, https://cdn.example.com/acme.png, Gold, 1, Inbox specialists\n" .
			"\"Send, Deliver\", https://send.example.com/, 4321, Silver, 2\n" .
			"\n" .
			"Name Only"
		);

		$this->assertCount( 3, $rows );
		$this->assertSame( 'Acme Corp', $rows[0]['name'] );
		$this->assertSame( 'https://cdn.example.com/acme.png', $rows[0]['logo_url'] );
		$this->assertSame( 0, $rows[0]['logo_id'] );
		$this->assertSame( 'Send, Deliver', $rows[1]['name'], 'quoted commas survive' );
		$this->assertSame( 4321, $rows[1]['logo_id'], 'a numeric logo value is a media attachment ID' );
		$this->assertSame( '', $rows[1]['logo_url'] );
		$this->assertSame( 'Name Only', $rows[2]['name'] );
		$this->assertSame( 99, $rows[2]['tier_order'] );
	}

	public function test_empty_sponsor_wall_explains_itself_to_admins(): void {
		$this->go_lite();

		$html = Components::render( 'sponsors', [] );

		$this->assertStringContainsString( 'eex-empty', $html );
		$this->assertStringContainsString( 'no sponsors came back from the HeySummit API', $html );
		$this->assertStringContainsString( 'CSV import', $html );

		// With hide_empty, the visible empty state disappears but the admin
		// explanation survives — a blank sidebar stays debuggable.
		Cache::flush();
		$hidden = Components::render( 'sponsors', [ 'hide_empty' => 1 ] );
		$this->assertStringNotContainsString( 'eex-empty', $hidden );
		$this->assertStringContainsString( 'hidden by its hide_empty setting', $hidden );
		$this->assertStringContainsString( 'no sponsors came back from the HeySummit API', $hidden, 'the diagnosis rides along' );
	}
}
