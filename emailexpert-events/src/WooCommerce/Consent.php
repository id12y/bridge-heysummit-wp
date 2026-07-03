<?php
/**
 * Checkout registration consent.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\WooCommerce;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Carts containing mapped products require a checkout checkbox consenting
 * to event registration and event-related emails — the HeySummit API
 * assumes the attendee has agreed to be added and emailed. The consent
 * timestamp lands in order meta; no consent recorded means no push.
 *
 * Two checkout surfaces are covered: the classic (shortcode) checkout via
 * the form hooks below, and the block checkout via the Additional Checkout
 * Fields API (WooCommerce 8.9+). The block field is shown on every
 * checkout and is optional — the Blocks API cannot condition a field on
 * cart contents or require it selectively — so the enforcement point is
 * unchanged: no recorded consent, no push. On older WooCommerce with a
 * block checkout, an admin notice explains that consent cannot be
 * captured (see Module::maybe_block_checkout_notice()).
 */
final class Consent {

	/**
	 * The block-checkout field ID (namespace/name per the Blocks API).
	 */
	public const BLOCK_FIELD_ID = 'emailexpert-events/hs-consent';

	/**
	 * Hook up.
	 */
	public function register(): void {
		// Classic (shortcode) checkout.
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_checkbox' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'record' ] );

		// Block checkout (WooCommerce 8.9+ Additional Checkout Fields API).
		add_action( 'woocommerce_init', [ $this, 'register_block_field' ] );
		add_action( 'woocommerce_set_additional_field_value', [ $this, 'record_block_value' ], 10, 4 );
	}

	/**
	 * Register the consent checkbox with the block checkout.
	 */
	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return; // Older WooCommerce: classic hooks only.
		}

		woocommerce_register_additional_checkout_field(
			[
				'id'       => self::BLOCK_FIELD_ID,
				'label'    => (string) Options::setting( 'woo_consent_text' ),
				'location' => 'order',
				'type'     => 'checkbox',
			]
		);
	}

	/**
	 * Copy a ticked block-checkout consent field into the same order meta
	 * the classic checkout writes, so the pusher needs no second path.
	 *
	 * @param string $key       Field ID.
	 * @param mixed  $value     Submitted value.
	 * @param string $group     Field group.
	 * @param object $wc_object The order (or customer) being updated.
	 */
	public function record_block_value( $key, $value, $group, $wc_object ): void {
		if ( self::BLOCK_FIELD_ID !== (string) $key || empty( $value ) ) {
			return;
		}

		if ( is_object( $wc_object ) && method_exists( $wc_object, 'update_meta_data' ) ) {
			$wc_object->update_meta_data( '_eex_consent', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}
	}

	/**
	 * Whether the current cart contains any mapped product.
	 */
	public static function cart_has_mapped_products(): bool {
		if ( ! function_exists( 'WC' ) || null === WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$mapping = Module::mapping_for( (int) ( $cart_item['product_id'] ?? 0 ), (int) ( $cart_item['variation_id'] ?? 0 ) );

			if ( null !== $mapping ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The checkbox.
	 */
	public function render_checkbox(): void {
		if ( ! self::cart_has_mapped_products() ) {
			return;
		}

		woocommerce_form_field(
			'eex_hs_consent',
			[
				'type'     => 'checkbox',
				'class'    => [ 'form-row', 'eex-consent' ],
				'label'    => esc_html( (string) Options::setting( 'woo_consent_text' ) ),
				'required' => true,
			],
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the checkout nonce.
			! empty( $_POST['eex_hs_consent'] )
		);
	}

	/**
	 * Require the checkbox when relevant.
	 */
	public function validate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the checkout nonce.
		if ( self::cart_has_mapped_products() && empty( $_POST['eex_hs_consent'] ) ) {
			wc_add_notice( __( 'Please confirm the event registration consent to complete your purchase.', 'emailexpert-events' ), 'error' );
		}
	}

	/**
	 * Store the consent timestamp on the order.
	 *
	 * @param object $order The order being created.
	 */
	public function record( $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce validates the checkout nonce.
		if ( ! empty( $_POST['eex_hs_consent'] ) ) {
			$order->update_meta_data( '_eex_consent', gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}
	}
}
