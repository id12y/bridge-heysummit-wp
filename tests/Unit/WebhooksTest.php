<?php
/**
 * Webhook receiver, parser, processor, attribution and privacy.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;
use Emailexpert\Events\Webhooks\Attribution;
use Emailexpert\Events\Webhooks\Parser;
use Emailexpert\Events\Webhooks\Privacy;
use Emailexpert\Events\Webhooks\Processor;
use Emailexpert\Events\Webhooks\RestController;
use WP_REST_Request;

/**
 * @covers \Emailexpert\Events\Webhooks\RestController
 * @covers \Emailexpert\Events\Webhooks\Parser
 * @covers \Emailexpert\Events\Webhooks\Processor
 * @covers \Emailexpert\Events\Webhooks\Attribution
 * @covers \Emailexpert\Events\Webhooks\Privacy
 */
final class WebhooksTest extends TestCase {

	private const SECRET = 'correcthorsebatterystaplecorrecthorsebat';

	/**
	 * The event post mapped to HeySummit event 101.
	 */
	private int $event_post = 0;

	protected function setUp(): void {
		parent::setUp();

		update_option( Options::SECRET, self::SECRET );
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

		$this->event_post = wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Member Hub',
				'meta_input'  => [
					'_eex_heysummit_id'       => '101',
					'_eex_registration_count' => 0,
				],
			]
		);

		// The API verifies attendee 7001 for event 101.
		$this->mock_http( function ( $url ) {
			if ( str_contains( $url, 'attendees/7001/' ) ) {
				return self::json_response(
					[
						'id'          => 7001,
						'email'       => 'attendee@example.org',
						'event_id'    => 101,
						'utm_source'  => 'newsletter',
						'tickets'     => [ [ 'title' => 'Member', 'amount_gross' => '0.00' ] ],
					]
				);
			}

			return self::json_response( [], 404 );
		} );
	}

	/**
	 * Deliver a payload to the receiver.
	 *
	 * @param array<string,mixed> $payload Payload.
	 * @param string              $secret  Secret path segment.
	 */
	private function deliver( array $payload, string $secret = self::SECRET ): \WP_REST_Response {
		$controller = new RestController();

		return $controller->handle( new WP_REST_Request( [ 'secret' => $secret ], $payload ) );
	}

	/**
	 * Run every queued eex_process_webhook event through the processor.
	 */
	private function run_queue(): void {
		$processor = new Processor();

		foreach ( \EEX_Test_State::$scheduled as $event ) {
			if ( 'eex_process_webhook' === $event['hook'] ) {
				$processor->process( $event['args'][0] );
			}
		}
	}

	private function checkout_payload(): array {
		return [
			'action'   => 'attendee.checkout_complete',
			'attendee' => [
				'id'       => 7001,
				'email'    => 'attendee@example.org',
				'event_id' => 101,
			],
		];
	}

	public function test_wrong_secret_gets_404_and_logs_nothing_to_attribution(): void {
		$response = $this->deliver( $this->checkout_payload(), 'wrongsecret000000000000000000000000000' );
		$this->run_queue();

		global $wpdb;
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'], 'must not confirm the route exists' );
		$this->assertEmpty( $wpdb->tables['wp_eex_attribution'] ?? [] );
		$this->assertEmpty( \EEX_Test_State::$scheduled, 'nothing queued' );
	}

	public function test_checkout_increments_counter_once_across_three_deliveries(): void {
		$payload = $this->checkout_payload();

		$this->assertSame( 200, $this->deliver( $payload )->get_status() );
		$this->assertSame( 200, $this->deliver( $payload )->get_status(), 'retries still get 200' );
		$this->assertSame( 200, $this->deliver( $payload )->get_status() );

		$this->run_queue();

		$this->assertSame( 1, (int) get_post_meta( $this->event_post, '_eex_registration_count', true ), 'idempotent counter' );

		global $wpdb;
		$this->assertCount( 1, $wpdb->tables['wp_eex_attribution'], 'one attribution row' );
		$row = $wpdb->tables['wp_eex_attribution'][0];
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( '101', $row['event_hs_id'] );
		$this->assertSame( hash( 'sha256', 'attendee@example.org' ), $row['email_hash'] );
		$this->assertSame( 'newsletter', $row['utm_source'], 'attribution uses the verified API record, not the payload' );

		$this->assertSame( 1, did_action( 'eex_checkout_complete' ) );
	}

	public function test_unverifiable_checkout_does_not_increment_counter(): void {
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( fn() => self::json_response( [], 500 ) );

		$this->deliver( $this->checkout_payload() );
		$this->run_queue();

		$this->assertSame( 0, (int) get_post_meta( $this->event_post, '_eex_registration_count', true ) );

		// Attribution still records the (hashed) signal for reporting.
		global $wpdb;
		$this->assertCount( 1, $wpdb->tables['wp_eex_attribution'] );
	}

	public function test_registration_started_inserts_row_and_schedules_abandonment_check(): void {
		$this->deliver(
			[
				'action' => 'attendee.registration_started',
				'data'   => [
					'attendee' => [
						'id'       => '7001',
						'email'    => 'attendee@example.org',
						'event_id' => '101',
					],
				],
			]
		);
		$this->run_queue();

		global $wpdb;
		$this->assertSame( 'started', $wpdb->tables['wp_eex_attribution'][0]['status'] );
		$this->assertSame( 1, did_action( 'eex_registration_started' ) );

		$checks = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_abandonment_check' === $e['hook'] );
		$this->assertCount( 1, $checks );
	}

	public function test_abandonment_fires_only_without_matching_checkout(): void {
		$hash = hash( 'sha256', 'attendee@example.org' );

		$processor = new Processor();
		$processor->abandonment_check( $hash, '101' );
		$this->assertSame( 1, did_action( 'eex_registration_abandoned' ) );

		Attribution::insert(
			[
				'hs_id'      => '7001',
				'email_hash' => $hash,
			],
			'101',
			'completed'
		);

		$processor->abandonment_check( $hash, '101' );
		$this->assertSame( 1, did_action( 'eex_registration_abandoned' ), 'completed checkout suppresses the hook' );
	}

	public function test_talk_added_fires_hook_only(): void {
		$this->deliver(
			[
				'action'   => 'talk_added_to_schedule',
				'attendee' => [
					'id'       => 7001,
					'event_id' => 101,
				],
				'talk_id'  => 9001,
			]
		);
		$this->run_queue();

		$this->assertSame( 1, did_action( 'eex_talk_signup' ) );
		$this->assertSame( 0, (int) get_post_meta( $this->event_post, '_eex_registration_count', true ) );

		global $wpdb;
		$this->assertEmpty( $wpdb->tables['wp_eex_attribution'] ?? [] );
	}

	public function test_parser_accepts_flat_payload_and_string_ids(): void {
		$parsed = Parser::parse(
			[
				'type'     => 'Registration Started',
				'id'       => '7001',
				'email'    => 'Someone@Example.org',
				'event_id' => '101',
			]
		);

		$this->assertSame( Parser::ACTION_STARTED, $parsed['action'] );
		$this->assertSame( '7001', $parsed['attendee']['hs_id'] );
		$this->assertSame( 'someone@example.org', $parsed['attendee']['email'] );
		$this->assertSame( '101', $parsed['event_hs_id'] );
	}

	public function test_parser_returns_empty_action_for_unknown_payloads(): void {
		$parsed = Parser::parse( [ 'hello' => 'world' ] );

		$this->assertSame( '', $parsed['action'] );
		$this->assertNull( $parsed['attendee'] );
	}

	public function test_disabled_action_toggle_skips_behaviour(): void {
		Options::update_settings( [ 'wh_checkout' => 0 ] );

		$this->deliver( $this->checkout_payload() );
		$this->run_queue();

		$this->assertSame( 0, (int) get_post_meta( $this->event_post, '_eex_registration_count', true ) );
		$this->assertSame( 0, did_action( 'eex_checkout_complete' ) );
	}

	public function test_capture_mode_stores_payload_for_replay(): void {
		Options::update_settings( [ 'wh_capture' => 1 ] );

		$this->deliver( $this->checkout_payload() );

		global $wpdb;
		$captured = array_values(
			array_filter(
				$wpdb->tables['wp_eex_log'] ?? [],
				static fn( $row ) => str_contains( (string) $row['data'], '"capture"' )
			)
		);
		$this->assertNotEmpty( $captured );

		// Replay the captured payload through the processor (dry run).
		$data   = json_decode( (string) $captured[0]['data'], true );
		$result = ( new Processor() )->process_payload( (array) $data['payload'], true );

		$this->assertSame( Parser::ACTION_CHECKOUT, $result['action'] );
		$this->assertSame( '7001', $result['attendee_hs_id'] );
		$this->assertTrue( $result['handled'] );
	}

	public function test_rate_limit_returns_429_beyond_60_per_minute(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';

		$last = null;
		for ( $i = 0; $i < 61; $i++ ) {
			$last = $this->deliver(
				[
					'action'   => 'attendee.registration_started',
					'attendee' => [ 'id' => 9000 + $i ],
				]
			);
		}

		$this->assertSame( 429, $last->get_status() );
	}

	public function test_privacy_eraser_removes_attribution_rows_by_hash(): void {
		$email = 'attendee@example.org';
		$hash  = hash( 'sha256', $email );

		Attribution::insert( [ 'hs_id' => '7001', 'email_hash' => $hash, 'utm_source' => 'newsletter' ], '101', 'completed' );
		Attribution::insert( [ 'hs_id' => '7002', 'email_hash' => hash( 'sha256', 'other@example.org' ) ], '101', 'started' );

		$privacy = new Privacy();

		// Export finds the requester's rows.
		$export = $privacy->export( $email );
		$this->assertCount( 1, $export['data'] );

		// Erase removes them and only them; verify by hash lookup before and after.
		$this->assertNotEmpty( Attribution::rows_for_hash( $hash ) );
		$result = $privacy->erase( $email );
		$this->assertTrue( $result['items_removed'] );
		$this->assertEmpty( Attribution::rows_for_hash( $hash ) );
		$this->assertNotEmpty( Attribution::rows_for_hash( hash( 'sha256', 'other@example.org' ) ), 'other subjects retained' );
	}

	public function test_counter_rest_read_returns_current_figure(): void {
		update_post_meta( $this->event_post, '_eex_registration_count', 77 );

		$controller = new \Emailexpert\Events\Rest\CounterController();
		$response   = $controller->read( new WP_REST_Request( [ 'event' => '101' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 77, $response->get_data()['count'] );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );

		$missing = $controller->read( new WP_REST_Request( [ 'event' => '999' ] ) );
		$this->assertSame( 404, $missing->get_status() );
	}

	public function test_documented_payloads_classify_without_an_action_key(): void {
		// The OpenAPI spec's outbound webhook examples carry no action key
		// in the body; classification falls back to shape inference.
		$checkout = Parser::parse(
			[
				'event_id'         => 67890,
				'attendee_id'      => 12345,
				'email'            => 'attendee@example.com',
				'name'             => 'John Doe',
				'paid_at'          => '2023-10-26T10:05:00+00:00',
				'amount_gross'     => '99.00',
				'ticket_purchases' => [
					[
						'id'              => 1001,
						'ticket_price_id' => 101,
						'ticket_name'     => 'VIP Pass',
					],
				],
				'tickets'          => [
					[
						'ticket_id'   => 1,
						'ticket_name' => 'VIP Pass',
					],
				],
			]
		);

		$this->assertSame( Parser::ACTION_CHECKOUT, $checkout['action'] );
		$this->assertSame( '67890', $checkout['event_hs_id'] );
		$this->assertSame( 'VIP Pass', $checkout['attendee']['ticket_name'] );

		$talk_added = Parser::parse(
			[
				'attendee_id'         => 12345,
				'attendee_email'      => 'attendee@example.com',
				'attendee_name'       => 'John Doe',
				'attendee_created_at' => '2023-10-26T10:00:00+00:00',
				'event_id'            => 67890,
				'talk_id'             => 444,
				'talk_name'           => 'Scaling Community-Led Events',
				'utm_source'          => 'linkedin',
			]
		);

		$this->assertSame( Parser::ACTION_TALK_ADDED, $talk_added['action'] );
		$this->assertSame( '444', $talk_added['talk_hs_id'] );
		$this->assertNotNull( $talk_added['attendee'], 'the attendee_-prefixed fields become the attendee record' );
		$this->assertSame( 'attendee@example.com', $talk_added['attendee']['email'] );

		$started = Parser::parse(
			[
				'id'                   => 12345,
				'email'                => 'attendee@example.com',
				'registration_status'  => 'Completed Order',
				'event_id'             => 67890,
				'registration_answers' => [ 'What is your company name?' => 'Example Corp' ],
			]
		);

		$this->assertSame( Parser::ACTION_STARTED, $started['action'] );
	}
}
