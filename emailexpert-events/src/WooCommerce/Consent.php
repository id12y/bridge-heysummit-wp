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
 */
final class Consent {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'render_checkbox' ] );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'record' ] );
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
