<?php
/**
 * Minimal WooCommerce stubs for unit testing the Woo bridge. Same posture
 * as wp-stubs.php: everything guarded so a real WooCommerce wins.
 *
 * @package Emailexpert\Events\Tests
 */

// phpcs:ignoreFile -- test infrastructure mirroring WooCommerce signatures.

if ( ! class_exists( 'EEX_Test_WC' ) ) {
	class EEX_Test_WC {
		/** @var array<int,WC_Order> */
		public static array $orders = [];
		/** @var array<int,array<string,mixed>> item_id => meta */
		public static array $item_meta = [];
		public static int $next_item_id = 1000;

		/** @var array<int,array<string,mixed>> registered block-checkout fields. */
		public static array $checkout_fields = [];

		public static function reset(): void {
			self::$orders          = [];
			self::$item_meta       = [];
			self::$next_item_id    = 1000;
			self::$checkout_fields = [];
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	class WC_Order_Item_Product {
		public function __construct(
			public int $item_id,
			public int $product_id,
			public int $variation_id = 0,
			public int $quantity = 1,
			public float $total = 0.0,
			public float $total_tax = 0.0,
			public string $name = 'Item'
		) {}

		public function get_id(): int {
			return $this->item_id;
		}

		public function get_product_id(): int {
			return $this->product_id;
		}

		public function get_variation_id(): int {
			return $this->variation_id;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_total() {
			return $this->total;
		}

		public function get_total_tax() {
			return $this->total_tax;
		}

		public function get_name(): string {
			return $this->name;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {
		public array $meta  = [];
		public array $notes = [];
		/** @var array<int,WC_Order_Item_Product> */
		public array $items = [];

		public function __construct(
			public int $id,
			public string $billing_first_name = 'Test',
			public string $billing_last_name = 'Buyer',
			public string $billing_email = 'buyer@example.org',
			public string $currency = 'GBP'
		) {}

		public function get_id(): int {
			return $this->id;
		}

		public function add_item( WC_Order_Item_Product $item ): void {
			$this->items[ $item->get_id() ] = $item;
		}

		public function get_items(): array {
			return $this->items;
		}

		public function get_billing_first_name(): string {
			return $this->billing_first_name;
		}

		public function get_billing_last_name(): string {
			return $this->billing_last_name;
		}

		public function get_billing_email(): string {
			return $this->billing_email;
		}

		public function get_currency(): string {
			return $this->currency;
		}

		public function get_meta( $key = '', $single = true ) {
			return $this->meta[ $key ] ?? '';
		}

		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function delete_meta_data( $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function add_order_note( $note ) {
			$this->notes[] = (string) $note;
			return count( $this->notes );
		}

		public function save(): int {
			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( $order_id ) {
		return EEX_Test_WC::$orders[ (int) $order_id ] ?? false;
	}
}
if ( ! function_exists( 'wc_get_order_item_meta' ) ) {
	function wc_get_order_item_meta( $item_id, $key, $single = true ) {
		return EEX_Test_WC::$item_meta[ (int) $item_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'wc_update_order_item_meta' ) ) {
	function wc_update_order_item_meta( $item_id, $key, $value ) {
		EEX_Test_WC::$item_meta[ (int) $item_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $notice_type = 'success' ) {
		$GLOBALS['eex_test_wc_notices'][] = [ $message, $notice_type ];
	}
}

if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
	function woocommerce_register_additional_checkout_field( $args ) {
		EEX_Test_WC::$checkout_fields[] = (array) $args;
		return true;
	}
}
