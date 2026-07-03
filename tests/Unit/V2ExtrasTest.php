<?php
/**
 * V2 extras: UTM tagging, relay, filter bar, dashboard data, export/import,
 * purge, digest.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Admin\Digest;
use Emailexpert\Events\Admin\ExportImport;
use Emailexpert\Events\Frontend\Cache;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Frontend\PurgeIntegration;
use Emailexpert\Events\Frontend\Utm;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;
use Emailexpert\Events\Webhooks\Relay;

/**
 * @covers \Emailexpert\Events\Frontend\Utm
 * @covers \Emailexpert\Events\Webhooks\Relay
 * @covers \Emailexpert\Events\Admin\ExportImport
 * @covers \Emailexpert\Events\Admin\Digest
 * @covers \Emailexpert\Events\Frontend\PurgeIntegration
 */
final class V2ExtrasTest extends TestCase {

	private function enable_utm(): void {
		Options::update_settings(
			[
				'utm_enabled' => 1,
				'utm_source'  => 'emailexpert.com',
				'utm_medium'  => 'web',
			]
		);
	}

	public function test_utm_inactive_without_a_configured_source(): void {
		Options::update_settings( [ 'utm_enabled' => 1, 'utm_source' => '' ] );

		$this->assertSame( 'https://hs.example/e/1', Utm::tag( 'https://hs.example/e/1' ) );
	}

	public function test_utm_tags_with_source_medium_and_campaign(): void {
		$this->enable_utm();

		$tagged = Utm::tag( 'https://hs.example/e/1', 0, 'my-page' );

		$this->assertStringContainsString( 'utm_source=emailexpert.com', $tagged );
		$this->assertStringContainsString( 'utm_medium=web', $tagged );
		$this->assertStringContainsString( 'utm_campaign=my-page', $tagged );
	}

	public function test_utm_never_clobbers_existing_parameters(): void {
		$this->enable_utm();

		$url = 'https://hs.example/e/1?utm_source=partner';

		$this->assertSame( $url, Utm::tag( $url ) );
	}

	public function test_utm_campaign_per_page_override_beats_slug(): void {
		$this->enable_utm();
		$page = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Landing',
				'meta_input'  => [ '_eex_utm_campaign' => 'Special Campaign' ],
			]
		);

		$this->assertSame( 'special-campaign', Utm::campaign( $page ) );
	}

	public function test_register_links_carry_utm_in_component_output_and_calendar(): void {
		$this->enable_utm();

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id' => '101',
					'_eex_event_url'    => 'https://hub.heysummit.example/register',
				],
			]
		);
		$talk = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Tagged session',
				'meta_input'  => [
					'_eex_source_event_id' => '101',
					'_eex_starts_at'       => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
				],
			]
		);

		$html = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'utm_source=emailexpert.com', $html );

		// Calendar entries carry the tagged register URL too.
		$vevent = implode( "\n", Ics::vevent( $talk ) );
		$this->assertStringContainsString( 'utm_source=emailexpert.com', str_replace( "\r\n ", '', $vevent ) );
		$this->assertStringContainsString( 'utm_campaign=calendar', str_replace( "\r\n ", '', $vevent ) );
	}

	public function test_relay_dispatch_queues_only_subscribed_targets_and_never_raw_email(): void {
		update_option(
			'eex_relay_urls',
			[
				[
					'url'     => 'https://hooks.example/a',
					'secret'  => 's3cret',
					'actions' => [ 'checkout_complete' ],
				],
				[
					'url'     => 'https://hooks.example/b',
					'secret'  => '',
					'actions' => [ 'registration_started' ],
				],
			]
		);

		$relay = new Relay();
		$relay->dispatch( 'checkout_complete', [ 'hs_id' => '1', 'email' => 'raw@example.org', 'email_hash' => 'abc' ], 0 );

		$jobs = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_relay_deliver' === $e['hook'] ) );
		$this->assertCount( 1, $jobs, 'only the subscribed target is queued' );
		$this->assertSame( 0, $jobs[0]['args'][0] );
		$this->assertArrayNotHasKey( 'email', $jobs[0]['args'][1]['attendee'], 'raw email never relayed' );
		$this->assertSame( 'abc', $jobs[0]['args'][1]['attendee']['email_hash'] );
	}

	public function test_relay_delivery_sends_secret_header_retries_and_logs(): void {
		update_option(
			'eex_relay_urls',
			[
				[
					'url'     => 'https://hooks.example/a',
					'secret'  => 's3cret',
					'actions' => [ 'checkout_complete' ],
				],
			]
		);

		$seen = [];
		$this->mock_http( function ( $url, $args ) use ( &$seen ) {
			$seen[] = $args;

			return count( $seen ) < 2
				? self::json_response( [], 500 )
				: self::json_response( [ 'ok' => true ], 200 );
		} );

		$relay = new Relay();

		// First attempt fails -> a retry is scheduled.
		$this->assertFalse( $relay->deliver( 0, [ 'action' => 'checkout_complete' ], 1 ) );
		$retries = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_relay_deliver' === $e['hook'] ) );
		$this->assertCount( 1, $retries );
		$this->assertSame( 2, $retries[0]['args'][2], 'attempt counter advances' );

		// Second attempt succeeds with the shared-secret header.
		$this->assertTrue( $relay->deliver( 0, [ 'action' => 'checkout_complete' ], 2 ) );
		$this->assertSame( 's3cret', $seen[1]['headers']['X-Eex-Secret'] );

		// Deliveries are logged.
		global $wpdb;
		$logged = json_encode( $wpdb->tables['wp_eex_log'] ); // phpcs:ignore
		$this->assertStringContainsString( 'Relay checkout_complete', $logged );
		$this->assertStringContainsString( 'delivered', $logged );
		$this->assertStringNotContainsString( 's3cret', $logged, 'secret never logged' );
	}

	public function test_filter_bar_renders_category_and_speaker_links_and_grid_carries_data_attrs(): void {
		wp_insert_term( 'Deliverability', 'eex_category' );
		$speaker = wp_insert_post(
			[
				'post_type'   => 'eex_speaker',
				'post_status' => 'publish',
				'post_title'  => 'Jane Sender',
			]
		);
		$talk = wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Filterable session',
				'meta_input'  => [
					'_eex_starts_at'   => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ),
					'_eex_speaker_ids' => [ $speaker ],
				],
			]
		);
		wp_set_object_terms( $talk, [ 'deliverability' ], 'eex_category' );

		$bar = Components::render( 'session-filter', [] );
		$this->assertStringContainsString( 'data-eex-filter', $bar );
		$this->assertStringContainsString( 'data-eex-filter-cat="deliverability"', $bar );
		$this->assertStringContainsString( 'data-eex-filter-speaker="jane sender"', $bar );
		$this->assertStringContainsString( 'name="eex_q"', $bar, 'no-JS search form' );

		$grid = Components::render( 'past-sessions', [] );
		$this->assertStringContainsString( 'data-eex-title="filterable session"', $grid );
		$this->assertStringContainsString( 'data-eex-cats="deliverability"', $grid );
		$this->assertStringContainsString( 'data-eex-speakers="jane sender"', $grid );
	}

	public function test_past_sessions_honours_no_js_search_query(): void {
		foreach ( [ 'Deliverability deep dive', 'BIMI basics' ] as $title ) {
			wp_insert_post(
				[
					'post_type'   => 'eex_talk',
					'post_status' => 'publish',
					'post_title'  => $title,
					'meta_input'  => [ '_eex_starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - DAY_IN_SECONDS ) ],
				]
			);
		}

		$html = Components::render( 'past-sessions', [ 'q' => 'bimi' ] );

		$this->assertStringContainsString( 'BIMI basics', $html );
		$this->assertStringNotContainsString( 'Deliverability deep dive', $html );
	}

	public function test_export_contains_no_key_or_secret_material(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'SUPERSECRETKEY',
				],
			]
		);
		update_option( Options::SECRET, 'WEBHOOKSECRETX' );
		update_option(
			'eex_relay_urls',
			[
				[
					'url'     => 'https://hooks.example/a',
					'secret'  => 'RELAYSECRET',
					'actions' => [ 'checkout_complete' ],
				],
			]
		);
		Options::update_settings( [ 'utm_source' => 'emailexpert.com' ] );

		$json = json_encode( ExportImport::snapshot() ); // phpcs:ignore

		$this->assertStringNotContainsString( 'SUPERSECRETKEY', $json );
		$this->assertStringNotContainsString( 'WEBHOOKSECRETX', $json );
		$this->assertStringNotContainsString( 'RELAYSECRET', $json );
		$this->assertStringContainsString( 'emailexpert.com', $json, 'real settings do travel' );
		$this->assertStringContainsString( 'hooks.example', $json, 'relay URLs travel without secrets' );
	}

	public function test_import_applies_cleanly_and_preserves_local_keys(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Old label',
					'api_key' => 'LOCALKEY',
				],
			]
		);
		update_option(
			'eex_relay_urls',
			[
				[
					'url'     => 'https://hooks.example/a',
					'secret'  => 'LOCALRELAYSECRET',
					'actions' => [],
				],
			]
		);

		ExportImport::apply_snapshot(
			[
				'plugin'  => 'emailexpert-events',
				'options' => [
					'eex_settings'      => [
						'utm_source' => 'staging.example',
						'nonsense'   => 'dropped',
					],
					'eex_synced_events' => [ 'c1|101' => [ 'enabled' => 1 ] ],
					'eex_connections'   => [
						[
							'id'    => 'c1',
							'label' => 'New label',
						],
					],
					'eex_relay_urls'    => [
						[
							'url'     => 'https://hooks.example/a',
							'actions' => [ 'checkout_complete' ],
						],
					],
				],
			]
		);

		$this->assertSame( 'staging.example', Options::setting( 'utm_source' ) );
		$this->assertNull( Options::setting( 'nonsense' ), 'unknown keys dropped' );
		$this->assertSame( 1, Options::synced_events()['c1|101']['enabled'] );

		$connections = (array) get_option( Options::CONNECTIONS );
		$this->assertSame( 'New label', $connections[0]['label'] );
		$this->assertSame( 'LOCALKEY', $connections[0]['api_key'], 'local key preserved' );

		$relays = (array) get_option( 'eex_relay_urls' );
		$this->assertSame( 'LOCALRELAYSECRET', $relays[0]['secret'], 'local relay secret preserved' );
		$this->assertSame( [ 'checkout_complete' ], $relays[0]['actions'] );

		// Importing enabled events schedules the sync cron.
		$this->assertNotFalse( wp_next_scheduled( 'eex_sync_cron' ) );
	}

	public function test_purge_disabled_by_default_and_fires_hooks_when_enabled(): void {
		$purge = new PurgeIntegration();

		$purge->purge_after_sync();
		$this->assertSame( 0, did_action( 'eex_cache_purged' ), 'off by default' );

		Options::update_settings( [ 'purge_enabled' => 1 ] );
		$purge->purge_after_sync();
		$this->assertSame( 1, did_action( 'eex_cache_purged' ) );
	}

	public function test_digest_schedule_follows_toggle_and_composes_summary(): void {
		Digest::sync_schedule_state();
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_weekly_digest' ), 'off by default' );

		Options::update_settings( [ 'digest_enabled' => 1 ] );
		Digest::sync_schedule_state();
		$this->assertNotFalse( wp_next_scheduled( 'eex_weekly_digest' ) );

		\Emailexpert\Events\Webhooks\Attribution::insert(
			[
				'hs_id'      => '1',
				'email_hash' => hash( 'sha256', 'a@b.c' ),
				'utm_source' => 'newsletter',
			],
			'101',
			'completed'
		);

		$body = Digest::compose();
		$this->assertStringContainsString( 'newsletter: 1', $body );
		$this->assertStringContainsString( 'Healthy', $body );

		( new Digest() )->send();
		$this->assertNotEmpty( \EEX_Test_State::$mail );

		Options::update_settings( [ 'digest_enabled' => 0 ] );
		Digest::sync_schedule_state();
		$this->assertFalse( (bool) wp_next_scheduled( 'eex_weekly_digest' ), 'unsubscribable via the toggle' );
	}

	public function test_component_cache_varies_by_utm_campaign_context(): void {
		$this->enable_utm();

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id' => '101',
					'_eex_event_url'    => 'https://hub.heysummit.example/register',
				],
			]
		);
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Cache context probe',
				'meta_input'  => [
					'_eex_source_event_id' => '101',
					'_eex_starts_at'       => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
				],
			]
		);

		// Two different rendering pages must not share a cached fragment.
		$GLOBALS['eex_test_queried_object_id'] = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Page A',
			]
		);
		$html_a = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'utm_campaign=page-a', $html_a );

		$GLOBALS['eex_test_queried_object_id'] = wp_insert_post(
			[
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Page B',
			]
		);
		$html_b = Components::render( 'upcoming-sessions', [] );
		$this->assertStringContainsString( 'utm_campaign=page-b', $html_b );

		unset( $GLOBALS['eex_test_queried_object_id'] );
	}
}
