<?php
/**
 * Order → HeySummit push jobs.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\WooCommerce;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Mappers\TicketSaleRequestBuilder;
use Emailexpert\Events\Options;
use Emailexpert\Events\Webhooks\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * One async job per mapped order line item: dedupe check (the local push
 * record is the lock, so re-fired order hooks never double-push) → create
 * attendee → import external ticket sale → store the attendee ID in order
 * item meta → order note → eex_woo_pushed. Three retries with backoff, then
 * the order is flagged with an orders-list notice, a manual push button and
 * `wp eex woo:push`.
 */
class Pusher {

	private const RECORD_KEY = '_eex_hs_push';
	private const BACKOFF    = [ 300, 900, 2700 ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'woocommerce_order_status_completed', [ $this, 'queue_order' ] );

		if ( (bool) Options::setting( 'woo_push_processing' ) ) {
			add_action( 'woocommerce_order_status_processing', [ $this, 'queue_order' ] );
		}

		add_action( 'eex_woo_push', [ $this, 'run_job' ], 10, 2 );
		add_action( 'woocommerce_order_fully_refunded', [ $this, 'on_refund' ] );
		add_action( 'admin_notices', [ $this, 'failed_orders_notice' ] );
		add_action( 'add_meta_boxes', [ $this, 'add_order_metabox' ] );
		add_action( 'admin_post_eex_woo_push_now', [ $this, 'manual_push' ] );
	}

	/**
	 * Queue one job per mapped line item. Idempotent: an existing push
	 * record (any status) means the item is already owned by a job chain.
	 *
	 * @param int $order_id Order ID.
	 */
	public function queue_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$mapping = Module::mapping_for( (int) $item->get_product_id(), (int) $item->get_variation_id() );

			if ( null === $mapping ) {
				continue; // Unmapped products are ignored entirely.
			}

			if ( ! empty( wc_get_order_item_meta( (int) $item_id, self::RECORD_KEY, true ) ) ) {
				continue; // Already pushed or in flight: the record is the lock.
			}

			wc_update_order_item_meta(
				(int) $item_id,
				self::RECORD_KEY,
				[
					'status'    => 'pending',
					'attempts'  => 0,
					'attendees' => [], // Ready for per-attendee capture (multi-quantity, phase 2).
				]
			);

			wp_schedule_single_event( time() - 1, 'eex_woo_push', [ (int) $order_id, (int) $item_id ] );
			spawn_cron();
		}
	}

	/**
	 * The async job entry point.
	 *
	 * @param int $order_id Order ID.
	 * @param int $item_id  Order item ID.
	 */
	public function run_job( $order_id = 0, $item_id = 0 ): void {
		$this->push( (int) $order_id, (int) $item_id );
	}

	/**
	 * Push one order item to HeySummit.
	 *
	 * @param int  $order_id Order ID.
	 * @param int  $item_id  Order item ID.
	 * @param bool $manual   True for the manual button/CLI (resets the retry budget).
	 * @return array{status:string,message:string} Outcome.
	 */
	public function push( int $order_id, int $item_id, bool $manual = false ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return [
				'status'  => 'error',
				'message' => __( 'Order not found.', 'emailexpert-events' ),
			];
		}

		$item = null;
		foreach ( $order->get_items() as $iid => $candidate ) {
			if ( (int) $iid === $item_id ) {
				$item = $candidate;
				break;
			}
		}

		if ( null === $item ) {
			return [
				'status'  => 'error',
				'message' => __( 'Order item not found.', 'emailexpert-events' ),
			];
		}

		$record = (array) wc_get_order_item_meta( $item_id, self::RECORD_KEY, true );
		$record = wp_parse_args(
			$record,
			[
				'status'    => 'pending',
				'attempts'  => 0,
				'attendees' => [],
			]
		);

		// Never push the same order item twice.
		if ( 'done' === $record['status'] ) {
			return [
				'status'  => 'done',
				'message' => __( 'Already pushed.', 'emailexpert-events' ),
			];
		}

		if ( $manual ) {
			$record['attempts'] = 0;
		}

		$mapping = Module::mapping_for( (int) $item->get_product_id(), (int) $item->get_variation_id() );
		if ( null === $mapping ) {
			return [
				'status'  => 'skipped',
				'message' => __( 'Product is not mapped.', 'emailexpert-events' ),
			];
		}

		// Consent: the HeySummit API assumes the attendee agreed to be added
		// and emailed. No consent recorded, no push, flagged on the order.
		if ( '' === (string) $order->get_meta( '_eex_consent' ) ) {
			$order->update_meta_data( '_eex_hs_no_consent', 1 );
			$order->add_order_note( __( 'HeySummit push skipped: no registration consent was recorded at checkout.', 'emailexpert-events' ) );
			$order->save();

			wc_update_order_item_meta( $item_id, self::RECORD_KEY, array_merge( $record, [ 'status' => 'no_consent' ] ) );

			return [
				'status'  => 'no_consent',
				'message' => __( 'No consent recorded; not pushed.', 'emailexpert-events' ),
			];
		}

		$connection = Options::connection( (string) $mapping['connection'] );
		if ( null === $connection || '' === (string) ( $connection['api_key'] ?? '' ) ) {
			return $this->fail( $order, $item_id, $record, __( 'Connection has no API key.', 'emailexpert-events' ) );
		}

		$client = HeySummitClient::for_connection( $connection );

		// 1. Create the attendee.
		$attendee_request = AttendeeRequestBuilder::build(
			[
				'name'            => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'email'           => $order->get_billing_email(),
				'event_hs_id'     => (string) $mapping['event'],
				'order_reference' => (string) $order_id,
			]
		);

		$attendee_response = $client->post( (string) $attendee_request['path'], (array) $attendee_request['body'] );

		if ( is_wp_error( $attendee_response ) ) {
			return $this->fail( $order, $item_id, $record, $attendee_response->get_error_message() );
		}

		$attendee_hs_id = (string) ( $attendee_response['id'] ?? '' );

		// 2. Import the external ticket sale.
		$gross = (float) $item->get_total() + (float) $item->get_total_tax();
		$net   = (float) $item->get_total();

		$sale_request = TicketSaleRequestBuilder::build(
			[
				'attendee_hs_id'  => $attendee_hs_id,
				'ticket_hs_id'    => (string) $mapping['ticket'],
				'amount_gross'    => number_format( $gross, 2, '.', '' ),
				'amount_net'      => number_format( $net, 2, '.', '' ),
				'currency'        => $order->get_currency(),
				'order_reference' => (string) $order_id,
			]
		);

		$sale_response = $client->post( (string) $sale_request['path'], (array) $sale_request['body'] );

		if ( is_wp_error( $sale_response ) ) {
			return $this->fail( $order, $item_id, $record, $sale_response->get_error_message() );
		}

		// 3. Record the outcome.
		$record['status']      = 'done';
		$record['attendees'][] = [ 'hs_id' => $attendee_hs_id ];
		$record['pushed_at']   = gmdate( 'Y-m-d\TH:i:s\Z' );
		wc_update_order_item_meta( $item_id, self::RECORD_KEY, $record );
		wc_update_order_item_meta( $item_id, '_eex_hs_attendee_id', $attendee_hs_id );

		$order->delete_meta_data( '_eex_hs_push_failed' );
		$order->add_order_note(
			sprintf(
				/* translators: 1: attendee ID, 2: ticket ID. */
				__( 'Pushed to HeySummit: attendee %1$s registered with ticket %2$s.', 'emailexpert-events' ),
				$attendee_hs_id ?: '?',
				(string) $mapping['ticket']
			)
		);

		// Shared registration ledger: the accounts rules dedupe against
		// purchases through this record (docs/decisions.md D29).
		\Emailexpert\Events\Registrations::record_woo_purchase(
			(string) $order->get_billing_email(),
			(string) $mapping['event'],
			$attendee_hs_id,
			$order_id
		);

		// Attribution: tag the Woo origin; HeySummit's own checkout-complete
		// webhook for this sale then deduplicates on the attendee ID.
		Attribution::insert(
			[
				'hs_id'        => $attendee_hs_id,
				'email_hash'   => hash( 'sha256', strtolower( (string) $order->get_billing_email() ) ),
				'utm_source'   => 'woocommerce',
				'ticket_name'  => (string) $mapping['ticket'],
				'amount_gross' => number_format( $gross, 2, '.', '' ),
			],
			(string) $mapping['event'],
			'completed',
			$order_id
		);

		// Quantity above 1: the purchaser is registered once; additional
		// attendees need manual registration until per-attendee capture ships.
		if ( (int) $item->get_quantity() > 1 ) {
			$order->update_meta_data( '_eex_hs_multi_quantity', 1 );
			$order->add_order_note(
				sprintf(
					/* translators: %d: quantity. */
					__( 'IMPORTANT: quantity %d — only the purchaser was registered with HeySummit. Register the additional attendees manually in HeySummit.', 'emailexpert-events' ),
					(int) $item->get_quantity()
				)
			);

			/**
			 * A multi-quantity item was pushed; only the purchaser is
			 * registered. Hook point for future per-attendee assignment.
			 *
			 * @param int $order_id Order ID.
			 * @param int $item_id  Item ID.
			 * @param int $quantity Quantity purchased.
			 */
			do_action( 'eex_woo_multi_quantity', $order_id, $item_id, (int) $item->get_quantity() );
		}

		$order->save();

		Logger::info(
			Logger::CONTEXT_API,
			sprintf( 'Woo order %d item %d pushed to HeySummit (attendee %s).', $order_id, $item_id, $attendee_hs_id ?: '?' ),
			[ 'event' => (string) $mapping['event'] ]
		);

		/**
		 * A Woo purchase was pushed to HeySummit.
		 *
		 * @param int                 $order_id Order ID.
		 * @param int                 $item_id  Order item ID.
		 * @param array<string,mixed> $attendee The attendee-create response.
		 */
		do_action( 'eex_woo_pushed', $order_id, $item_id, is_array( $attendee_response ) ? $attendee_response : [] );

		return [
			'status'  => 'done',
			'message' => sprintf( /* translators: %s: attendee ID. */ __( 'Pushed; attendee %s.', 'emailexpert-events' ), $attendee_hs_id ?: '?' ),
		];
	}

	/**
	 * Record a failed attempt: retry with backoff up to 3 attempts, then
	 * flag the order.
	 *
	 * @param object              $order   Order.
	 * @param int                 $item_id Item ID.
	 * @param array<string,mixed> $record  Push record.
	 * @param string              $message Failure message.
	 * @return array{status:string,message:string}
	 */
	private function fail( $order, int $item_id, array $record, string $message ): array {
		$record['attempts']   = (int) $record['attempts'] + 1;
		$record['status']     = 'failed';
		$record['last_error'] = $message;
		wc_update_order_item_meta( $item_id, self::RECORD_KEY, $record );

		Logger::error(
			Logger::CONTEXT_API,
			sprintf( 'Woo push attempt %d failed for order %d item %d: %s', (int) $record['attempts'], (int) $order->get_id(), $item_id, $message )
		);

		if ( (int) $record['attempts'] < 3 ) {
			$delay = self::BACKOFF[ (int) $record['attempts'] - 1 ] ?? 900;
			wp_schedule_single_event( time() + $delay, 'eex_woo_push', [ (int) $order->get_id(), $item_id ] );

			return [
				'status'  => 'retrying',
				'message' => $message,
			];
		}

		$order->update_meta_data( '_eex_hs_push_failed', 1 );
		$order->add_order_note(
			sprintf(
				/* translators: %s: error message. */
				__( 'HeySummit push failed after 3 attempts: %s. Use "Push to HeySummit" on this screen or wp eex woo:push.', 'emailexpert-events' ),
				$message
			)
		);
		$order->save();

		return [
			'status'  => 'failed',
			'message' => $message,
		];
	}

	/**
	 * Full refund: the write allowlist does not include attendee removal,
	 * so instruct manual removal and fire the hook (docs/decisions.md D24).
	 *
	 * @param int $order_id Order ID.
	 */
	public function on_refund( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order ) {
			return;
		}

		// Only relevant when something was actually pushed.
		$pushed = false;
		foreach ( array_keys( $order->get_items() ) as $item_id ) {
			if ( '' !== (string) wc_get_order_item_meta( (int) $item_id, '_eex_hs_attendee_id', true ) ) {
				$pushed = true;
				break;
			}
		}
		if ( ! $pushed ) {
			return;
		}

		$order->add_order_note( __( 'Order fully refunded. The HeySummit API does not allow this plugin to remove attendees: remove the registration manually in HeySummit.', 'emailexpert-events' ) );
		$order->update_meta_data( '_eex_hs_refund_manual', 1 );
		$order->save();

		\Emailexpert\Events\Admin\Notices::add(
			'woo_refund_' . (int) $order_id,
			sprintf(
				/* translators: %d: order ID. */
				__( 'Order #%d was refunded after being pushed to HeySummit. Remove the attendee manually in HeySummit.', 'emailexpert-events' ),
				(int) $order_id
			),
			'warning'
		);

		/**
		 * A pushed order was fully refunded; manual removal is required.
		 *
		 * @param int $order_id Order ID.
		 */
		do_action( 'eex_woo_refunded', (int) $order_id );
	}

	/**
	 * Orders-list notice for flagged orders.
	 */
	public function failed_orders_notice(): void {
		if ( ! function_exists( 'get_current_screen' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}

		$flagged = get_posts(
			[
				'post_type'      => 'shop_order',
				'post_status'    => 'any',
				'posts_per_page' => 5,
				'no_found_rows'  => true,
				'meta_key'       => '_eex_hs_push_failed', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin notice.
				'fields'         => 'ids',
			]
		);

		if ( empty( $flagged ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s %s</p></div>',
			esc_html__( 'Some orders failed to push to HeySummit:', 'emailexpert-events' ),
			wp_kses_post(
				implode(
					', ',
					array_map(
						static fn( $id ): string => sprintf( '<a href="%s">#%d</a>', esc_url( admin_url( 'post.php?post=' . (int) $id . '&action=edit' ) ), (int) $id ),
						$flagged
					)
				)
			)
		);
	}

	/**
	 * Order screen metabox with the manual push button.
	 */
	public function add_order_metabox(): void {
		foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
			add_meta_box(
				'eex-hs-push',
				__( 'HeySummit', 'emailexpert-events' ),
				[ $this, 'render_order_metabox' ],
				$screen,
				'side'
			);
		}
	}

	/**
	 * The metabox.
	 *
	 * @param mixed $post_or_order Post or order object.
	 */
	public function render_order_metabox( $post_or_order ): void {
		$order_id = is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' )
			? (int) $post_or_order->get_id()
			: (int) ( $post_or_order->ID ?? 0 );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$record = (array) wc_get_order_item_meta( (int) $item_id, self::RECORD_KEY, true );
			if ( empty( $record ) ) {
				continue;
			}
			printf(
				'<p><strong>%s</strong>: %s</p>',
				esc_html( $item->get_name() ),
				esc_html( (string) ( $record['status'] ?? '' ) . ( ! empty( $record['last_error'] ) ? ' — ' . (string) $record['last_error'] : '' ) )
			);
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=eex_woo_push_now&order_id=' . $order_id ),
			'eex_woo_push_now'
		);
		printf( '<a class="button" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Push to HeySummit', 'emailexpert-events' ) );
	}

	/**
	 * Manual push (button).
	 */
	public function manual_push(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_woo_push_now' );

		$order_id = isset( $_GET['order_id'] ) ? (int) $_GET['order_id'] : 0;
		$this->push_order_now( $order_id );

		wp_safe_redirect( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) );
		exit;
	}

	/**
	 * Push every mapped item of an order synchronously (manual/CLI path).
	 *
	 * @param int $order_id Order ID.
	 * @return array<int,array{status:string,message:string}> Results per item.
	 */
	public function push_order_now( int $order_id ): array {
		$order   = wc_get_order( $order_id );
		$results = [];

		if ( ! $order ) {
			return $results;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$mapping = Module::mapping_for( (int) $item->get_product_id(), (int) $item->get_variation_id() );
			if ( null === $mapping ) {
				continue;
			}

			$results[ (int) $item_id ] = $this->push( $order_id, (int) $item_id, true );
		}

		return $results;
	}
}
