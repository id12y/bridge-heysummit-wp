<?php
/**
 * WooCommerce bridge: write allowlist, push flow, dedupe, consent, retries.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Api\WriteEndpoints;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;
use Emailexpert\Events\WooCommerce\Module;
use Emailexpert\Events\WooCommerce\Pusher;

/**
 * @covers \Emailexpert\Events\Api\WriteEndpoints
 * @covers \Emailexpert\Events\WooCommerce\Module
 * @covers \Emailexpert\Events\WooCommerce\Pusher
 */
final class WooBridgeTest extends TestCase {

	/**
	 * POST calls captured by the HTTP mock.
	 *
	 * @var array<int,array{url:string,body:array}>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		\EEX_Test_WC::reset();
		$this->posts = [];

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

		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => (array) json_decode( (string) ( $args['body'] ?? '' ), true ),
				];

				if ( str_contains( $url, 'attendees/' ) ) {
					return self::json_response( [ 'id' => 55001, 'email' => 'buyer@example.org' ], 201 );
				}

				return self::json_response( [ 'id' => 77001 ], 201 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );
	}

	/**
	 * Create a mapped product + consented order with one line item.
	 *
	 * @return array{order:\WC_Order,item_id:int,product_id:int}
	 */
	private function make_order( int $quantity = 1, bool $consent = true, bool $mapped = true ): array {
		$product_id = wp_insert_post(
			[
				'post_type'   => 'product',
				'post_status' => 'publish',
				'post_title'  => 'Forum ticket',
			]
		);

		if ( $mapped ) {
			Module::store_mapping( $product_id, 'c1', '101', 'T-1' );
		}

		$item_id = \EEX_Test_WC::$next_item_id++;
		$order   = new \WC_Order( 501 );
		$order->add_item( new \WC_Order_Item_Product( $item_id, $product_id, 0, $quantity, 100.00, 20.00 ) );

		if ( $consent ) {
			$order->update_meta_data( '_eex_consent', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}

		\EEX_Test_WC::$orders[ 501 ] = $order;

		return [
			'order'      => $order,
			'item_id'    => $item_id,
			'product_id' => $product_id,
		];
	}

	/**
	 * Run all queued eex_woo_push jobs (repeatedly, to follow retries).
	 */
	private function run_queue( Pusher $pusher ): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$jobs                        = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_woo_push' === $e['hook'] );
			\EEX_Test_State::$scheduled = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_woo_push' !== $e['hook'] ) );

			if ( empty( $jobs ) ) {
				return;
			}

			foreach ( $jobs as $job ) {
				$pusher->run_job( ...$job['args'] );
			}
		}
	}

	public function test_write_allowlist_rejects_everything_else(): void {
		$client = new HeySummitClient( 'k', 'c1' );

		$this->assertTrue( WriteEndpoints::allowed( 'attendees/' ) );
		$this->assertTrue( WriteEndpoints::allowed( 'external-ticket-sales/' ) );
		$this->assertFalse( WriteEndpoints::allowed( 'events/' ) );
		$this->assertFalse( WriteEndpoints::allowed( 'events/101/archive/' ) );
		$this->assertFalse( WriteEndpoints::allowed( 'talks/' ) );
		$this->assertFalse( WriteEndpoints::allowed( 'attendees/../events/' ) );

		$this->expectException( \InvalidArgumentException::class );
		$client->post( 'events/101/archive/', [] );
	}

	public function test_completed_order_produces_exactly_one_attendee_and_one_ticket_call_despite_repeated_hooks(): void {
		$fixture = $this->make_order();
		$pusher  = new Pusher();

		// Order hooks notoriously fire multiple times.
		$pusher->queue_order( 501 );
		$pusher->queue_order( 501 );
		$pusher->queue_order( 501 );

		$this->run_queue( $pusher );

		$attendee_calls = array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'attendees/' ) );
		$ticket_calls   = array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'external-ticket-sales/' ) );

		$this->assertCount( 1, $attendee_calls, 'exactly one attendee-create' );
		$this->assertCount( 1, $ticket_calls, 'exactly one ticket import' );

		// Correct data in both calls.
		$attendee_body = array_values( $attendee_calls )[0]['body'];
		$this->assertSame( 'Test Buyer', $attendee_body['name'] );
		$this->assertSame( 'buyer@example.org', $attendee_body['email'] );
		$this->assertSame( '101', $attendee_body['event'] );

		$ticket_body = array_values( $ticket_calls )[0]['body'];
		$this->assertSame( '55001', $ticket_body['attendee'] );
		$this->assertSame( 'T-1', $ticket_body['ticket'] );
		$this->assertSame( '120.00', $ticket_body['amount_gross'] );
		$this->assertSame( '100.00', $ticket_body['amount_net'] );
		$this->assertSame( 'GBP', $ticket_body['currency'] );
		$this->assertSame( '501', $ticket_body['order_reference'] );

		// Attendee ID stored on the item; note added; hook fired.
		$this->assertSame( '55001', (string) wc_get_order_item_meta( $fixture['item_id'], '_eex_hs_attendee_id', true ) );
		$this->assertNotEmpty( $fixture['order']->notes );
		$this->assertSame( 1, did_action( 'eex_woo_pushed' ) );

		// Re-running the job never double-pushes: the record is the lock.
		$result = $pusher->push( 501, $fixture['item_id'] );
		$this->assertSame( 'done', $result['status'] );
		$this->assertCount( 1, array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'attendees/' ) ) );
	}

	public function test_block_checkout_consent_registers_field_and_gates_push_identically(): void {
		// The consent checkbox reaches the block checkout via the
		// Additional Checkout Fields API…
		( new \Emailexpert\Events\WooCommerce\Consent() )->register();
		do_action( 'woocommerce_init' );

		$this->assertCount( 1, \EEX_Test_WC::$checkout_fields );
		$this->assertSame( \Emailexpert\Events\WooCommerce\Consent::BLOCK_FIELD_ID, \EEX_Test_WC::$checkout_fields[0]['id'] );
		$this->assertSame( 'checkbox', \EEX_Test_WC::$checkout_fields[0]['type'] );

		// …and a ticked field lands in the same order meta the classic
		// checkout writes, so the pusher needs no second path.
		$fixture = $this->make_order( 1, false ); // No classic consent.
		do_action( 'woocommerce_set_additional_field_value', \Emailexpert\Events\WooCommerce\Consent::BLOCK_FIELD_ID, 1, 'other', $fixture['order'] );

		$this->assertNotEmpty( $fixture['order']->get_meta( '_eex_consent' ) );

		$pusher = new Pusher();
		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$this->assertCount( 1, array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'attendees/' ) ), 'block-checkout consent gates the push exactly like classic consent' );

		// An unticked field records nothing.
		$untouched = $this->make_order( 1, false );
		do_action( 'woocommerce_set_additional_field_value', \Emailexpert\Events\WooCommerce\Consent::BLOCK_FIELD_ID, 0, 'other', $untouched['order'] );
		$this->assertEmpty( $untouched['order']->get_meta( '_eex_consent' ) );
	}

	public function test_unmapped_product_produces_zero_api_calls(): void {
		$this->make_order( 1, true, false );
		$pusher = new Pusher();

		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$this->assertSame( [], $this->posts );
		$this->assertSame( [], array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_woo_push' === $e['hook'] ) );
	}

	public function test_no_consent_means_no_push_and_flagged_order(): void {
		$fixture = $this->make_order( 1, false );
		$pusher  = new Pusher();

		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$this->assertSame( [], $this->posts, 'no API calls without consent' );
		$this->assertSame( 1, $fixture['order']->get_meta( '_eex_hs_no_consent' ) );
		$this->assertStringContainsString( 'no registration consent', implode( ' ', $fixture['order']->notes ) );
	}

	public function test_failed_push_retries_then_flags_and_is_manually_repushable(): void {
		$fixture = $this->make_order();

		// Every POST fails.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => [],
				];

				return self::json_response( [ 'detail' => 'boom' ], 500 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$pusher = new Pusher();
		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$record = (array) wc_get_order_item_meta( $fixture['item_id'], '_eex_hs_push', true );
		$this->assertSame( 'failed', $record['status'] );
		$this->assertSame( 3, $record['attempts'], 'three attempts with backoff' );
		$this->assertSame( 1, $fixture['order']->get_meta( '_eex_hs_push_failed' ) );

		// The API recovers; the manual button/CLI path re-pushes.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				return str_contains( $url, 'attendees/' )
					? self::json_response( [ 'id' => 55002 ], 201 )
					: self::json_response( [ 'id' => 77002 ], 201 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$results = $pusher->push_order_now( 501 );
		$this->assertSame( 'done', $results[ $fixture['item_id'] ]['status'] );
		$this->assertSame( '', (string) $fixture['order']->get_meta( '_eex_hs_push_failed' ), 'flag cleared on success' );
	}

	public function test_multi_quantity_registers_purchaser_once_and_warns(): void {
		$fixture = $this->make_order( 3 );
		$pusher  = new Pusher();

		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$this->assertCount( 1, array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'attendees/' ) ), 'purchaser registered once' );
		$this->assertSame( 1, $fixture['order']->get_meta( '_eex_hs_multi_quantity' ) );
		$this->assertStringContainsString( 'Register the additional attendees manually', implode( ' ', $fixture['order']->notes ) );
		$this->assertSame( 1, did_action( 'eex_woo_multi_quantity' ) );

		// The push record schema is ready for multiple attendees per item.
		$record = (array) wc_get_order_item_meta( $fixture['item_id'], '_eex_hs_push', true );
		$this->assertIsArray( $record['attendees'] );
		$this->assertCount( 1, $record['attendees'] );
	}

	public function test_woo_attribution_row_is_tagged_and_webhook_dedupes_on_attendee_id(): void {
		$this->make_order();
		$pusher = new Pusher();
		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		global $wpdb;
		$rows = $wpdb->tables['wp_eex_attribution'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 501, $rows[0]['order_id'] );
		$this->assertSame( '55001', $rows[0]['attendee_hs_id'] );

		// HeySummit's own checkout-complete webhook for the pushed sale
		// arrives later: same attendee ID, no second completed row.
		\Emailexpert\Events\Webhooks\Attribution::insert(
			[
				'hs_id'      => '55001',
				'email_hash' => hash( 'sha256', 'buyer@example.org' ),
			],
			'101',
			'completed'
		);

		$this->assertCount( 1, $wpdb->tables['wp_eex_attribution'], 'webhook deduped against the Woo push' );
	}

	public function test_refund_of_pushed_order_notes_and_fires_hook(): void {
		$fixture = $this->make_order();
		$pusher  = new Pusher();
		$pusher->queue_order( 501 );
		$this->run_queue( $pusher );

		$pusher->on_refund( 501 );

		$this->assertStringContainsString( 'remove the registration manually', strtolower( implode( ' ', $fixture['order']->notes ) ) );
		$this->assertSame( 1, did_action( 'eex_woo_refunded' ) );
	}

	public function test_refund_of_unpushed_order_is_silent(): void {
		$this->make_order( 1, true, false );

		( new Pusher() )->on_refund( 501 );

		$this->assertSame( 0, did_action( 'eex_woo_refunded' ) );
	}
}
