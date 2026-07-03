<?php
/**
 * WooCommerce bridge module.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\WooCommerce;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Sell tickets with WooCommerce; each consented, completed purchase is
 * pushed to HeySummit as a registered attendee with an imported external
 * ticket sale (the API's off-platform sales flow). Registered only on
 * woocommerce_loaded, so no code here loads without WooCommerce.
 */
final class Module {

	/**
	 * Entry point (woocommerce_loaded).
	 */
	public static function register(): void {
		( new Pusher() )->register();
		( new Consent() )->register();

		$module = new self();

		add_filter( 'woocommerce_product_data_tabs', [ $module, 'product_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $module, 'product_panel' ] );
		add_action( 'woocommerce_process_product_meta', [ $module, 'save_product_mapping' ] );
		add_action( 'woocommerce_product_after_variable_attributes', [ $module, 'variation_fields' ], 10, 3 );
		add_action( 'woocommerce_save_product_variation', [ $module, 'save_variation_mapping' ], 10, 2 );
		add_action( 'wp_ajax_eex_woo_tickets', [ $module, 'ajax_tickets' ] );
		add_action( 'eex_bridge_sections', [ $module, 'bridge_section' ] );
		add_action( 'admin_post_eex_save_woo', [ $module, 'save_settings' ] );
	}

	/**
	 * The mapping for a product/variation: variation meta wins, product
	 * meta is the fallback. Null when unmapped (unmapped products are
	 * ignored entirely).
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID (0 = none).
	 * @return array{connection:string,event:string,ticket:string}|null
	 */
	public static function mapping_for( int $product_id, int $variation_id = 0 ): ?array {
		foreach ( array_filter( [ $variation_id, $product_id ] ) as $post_id ) {
			$ticket = (string) get_post_meta( $post_id, '_eex_hs_ticket', true );

			if ( '' !== $ticket ) {
				return [
					'connection' => (string) get_post_meta( $post_id, '_eex_hs_connection', true ),
					'event'      => (string) get_post_meta( $post_id, '_eex_hs_event', true ),
					'ticket'     => $ticket,
				];
			}
		}

		return null;
	}

	/**
	 * Add the HeySummit product data tab.
	 *
	 * @param array<string,mixed> $tabs Tabs.
	 * @return array<string,mixed>
	 */
	public function product_tab( array $tabs ): array {
		$tabs['eex_heysummit'] = [
			'label'    => __( 'HeySummit', 'emailexpert-events' ),
			'target'   => 'eex_heysummit_panel',
			'class'    => [],
			'priority' => 75,
		];

		return $tabs;
	}

	/**
	 * The product panel.
	 */
	public function product_panel(): void {
		global $post;

		$product_id = $post ? (int) $post->ID : 0;
		?>
		<div id="eex_heysummit_panel" class="panel woocommerce_options_panel">
			<?php wp_nonce_field( 'eex_woo_mapping', 'eex_woo_mapping_nonce' ); ?>
			<?php $this->mapping_fields( $product_id, '' ); ?>
			<p class="description" style="padding:0 12px">
				<?php esc_html_e( 'Map this product to a HeySummit ticket to register purchasers automatically. Unmapped products are ignored entirely.', 'emailexpert-events' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Variation mapping fields.
	 *
	 * @param int    $loop           Loop index.
	 * @param array  $variation_data Variation data.
	 * @param object $variation      Variation post.
	 */
	public function variation_fields( $loop, $variation_data, $variation ): void {
		echo '<div class="eex-variation-mapping">';
		wp_nonce_field( 'eex_woo_mapping', 'eex_woo_mapping_nonce' );
		$this->mapping_fields( (int) $variation->ID, '_v[' . (int) $loop . ']' );
		echo '</div>';
	}

	/**
	 * Shared mapping selects.
	 *
	 * @param int    $post_id Product or variation ID.
	 * @param string $suffix  Field name suffix for variations.
	 */
	private function mapping_fields( int $post_id, string $suffix ): void {
		$connection = (string) get_post_meta( $post_id, '_eex_hs_connection', true );
		$event      = (string) get_post_meta( $post_id, '_eex_hs_event', true );
		$ticket     = (string) get_post_meta( $post_id, '_eex_hs_ticket', true );

		$events  = (array) get_option( 'eex_available_events', [] );
		$tickets = (array) get_option( 'eex_available_tickets', [] );
		?>
		<p class="form-field">
			<label><?php esc_html_e( 'HeySummit connection', 'emailexpert-events' ); ?></label>
			<select name="eex_hs_connection<?php echo esc_attr( $suffix ); ?>">
				<option value=""><?php esc_html_e( 'Not mapped', 'emailexpert-events' ); ?></option>
				<?php foreach ( Options::connections() as $row ) : ?>
					<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" <?php selected( $connection, $row['id'] ); ?>><?php echo esc_html( (string) $row['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="form-field">
			<label><?php esc_html_e( 'HeySummit event', 'emailexpert-events' ); ?></label>
			<select name="eex_hs_event<?php echo esc_attr( $suffix ); ?>">
				<option value=""><?php esc_html_e( 'Choose…', 'emailexpert-events' ); ?></option>
				<?php foreach ( $events as $conn_id => $conn_events ) : ?>
					<?php foreach ( (array) $conn_events as $row ) : ?>
						<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" <?php selected( $event, (string) $row['id'] ); ?>><?php echo esc_html( (string) ( $row['title'] ?? $row['id'] ) ); ?></option>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</select>
		</p>
		<p class="form-field">
			<label><?php esc_html_e( 'HeySummit ticket', 'emailexpert-events' ); ?></label>
			<select name="eex_hs_ticket<?php echo esc_attr( $suffix ); ?>">
				<option value=""><?php esc_html_e( 'Choose…', 'emailexpert-events' ); ?></option>
				<?php foreach ( (array) ( $tickets[ $connection . '|' . $event ] ?? [] ) as $row ) : ?>
					<option value="<?php echo esc_attr( (string) $row['id'] ); ?>" <?php selected( $ticket, (string) $row['id'] ); ?>><?php echo esc_html( (string) ( $row['title'] ?? $row['id'] ) ); ?></option>
				<?php endforeach; ?>
				<?php if ( '' !== $ticket && empty( $tickets[ $connection . '|' . $event ] ) ) : ?>
					<option value="<?php echo esc_attr( $ticket ); ?>" selected><?php echo esc_html( $ticket ); ?></option>
				<?php endif; ?>
			</select>
			<button type="button" class="button eex-woo-load-tickets" data-connection-field="eex_hs_connection<?php echo esc_attr( $suffix ); ?>" data-event-field="eex_hs_event<?php echo esc_attr( $suffix ); ?>"><?php esc_html_e( 'Load tickets', 'emailexpert-events' ); ?></button>
		</p>
		<?php
	}

	/**
	 * Save the product mapping.
	 *
	 * @param int $product_id Product ID.
	 */
	public function save_product_mapping( $product_id ): void {
		if ( ! isset( $_POST['eex_woo_mapping_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['eex_woo_mapping_nonce'] ), 'eex_woo_mapping' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product_id ) ) {
			return;
		}

		self::store_mapping(
			(int) $product_id,
			isset( $_POST['eex_hs_connection'] ) ? sanitize_key( wp_unslash( $_POST['eex_hs_connection'] ) ) : '',
			isset( $_POST['eex_hs_event'] ) ? sanitize_text_field( wp_unslash( $_POST['eex_hs_event'] ) ) : '',
			isset( $_POST['eex_hs_ticket'] ) ? sanitize_text_field( wp_unslash( $_POST['eex_hs_ticket'] ) ) : ''
		);
	}

	/**
	 * Save a variation mapping.
	 *
	 * @param int $variation_id Variation ID.
	 * @param int $loop         Loop index.
	 */
	public function save_variation_mapping( $variation_id, $loop ): void {
		if ( ! isset( $_POST['eex_woo_mapping_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['eex_woo_mapping_nonce'] ), 'eex_woo_mapping' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $variation_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitised in store_mapping args below.
		$connections = (array) ( $_POST['eex_hs_connection_v'] ?? [] );
		$events      = (array) ( $_POST['eex_hs_event_v'] ?? [] );
		$tickets     = (array) ( $_POST['eex_hs_ticket_v'] ?? [] );
		// phpcs:enable

		self::store_mapping(
			(int) $variation_id,
			sanitize_key( wp_unslash( (string) ( $connections[ $loop ] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $events[ $loop ] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $tickets[ $loop ] ?? '' ) ) )
		);
	}

	/**
	 * Persist one mapping (empty ticket clears it).
	 *
	 * @param int    $post_id    Product or variation ID.
	 * @param string $connection Connection ID.
	 * @param string $event      Event HS ID.
	 * @param string $ticket     Ticket HS ID.
	 */
	public static function store_mapping( int $post_id, string $connection, string $event, string $ticket ): void {
		if ( '' === $ticket || '' === $connection ) {
			delete_post_meta( $post_id, '_eex_hs_connection' );
			delete_post_meta( $post_id, '_eex_hs_event' );
			delete_post_meta( $post_id, '_eex_hs_ticket' );

			return;
		}

		update_post_meta( $post_id, '_eex_hs_connection', $connection );
		update_post_meta( $post_id, '_eex_hs_event', $event );
		update_post_meta( $post_id, '_eex_hs_ticket', $ticket );
	}

	/**
	 * Enumerate an event's tickets via the API (endpoint verified by
	 * discovery) and cache them for the selects.
	 */
	public function ajax_tickets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'emailexpert-events' ) ], 403 );
		}
		check_ajax_referer( 'eex_admin', 'nonce' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$connection_id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		$event_id      = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';
		// phpcs:enable

		$connection = Options::connection( $connection_id );
		if ( null === $connection || '' === $event_id ) {
			wp_send_json_error( [ 'message' => __( 'Choose a connection and event first.', 'emailexpert-events' ) ], 400 );
		}

		$client  = HeySummitClient::for_connection( $connection );
		$tickets = $client->get_all( 'tickets/', [ 'event' => $event_id ] );

		if ( is_wp_error( $tickets ) ) {
			wp_send_json_error( [ 'message' => $tickets->get_error_message() ] );
		}

		$list = [];
		foreach ( $tickets as $ticket ) {
			if ( is_array( $ticket ) && isset( $ticket['id'] ) ) {
				$list[] = [
					'id'    => (string) $ticket['id'],
					'title' => sanitize_text_field( (string) ( $ticket['title'] ?? $ticket['name'] ?? $ticket['id'] ) ),
				];
			}
		}

		$cache = (array) get_option( 'eex_available_tickets', [] );

		$cache[ $connection_id . '|' . $event_id ] = $list;
		update_option( 'eex_available_tickets', $cache, false );

		wp_send_json_success( [ 'tickets' => $list ] );
	}

	/**
	 * The Woo section of the Bridge settings page: options plus a summary
	 * of mapped products.
	 */
	public function bridge_section(): void {
		$mapped = get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- deliberate cap on the admin mapping summary.
				'no_found_rows'  => true,
				'meta_key'       => '_eex_hs_ticket', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- bounded admin summary.
			]
		);
		?>
		<h2><?php esc_html_e( 'WooCommerce → HeySummit', 'emailexpert-events' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_save_woo" />
			<?php wp_nonce_field( 'eex_save_woo' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Push trigger', 'emailexpert-events' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="woo_push_processing" value="1" <?php checked( (bool) Options::setting( 'woo_push_processing' ) ); ?> />
							<?php esc_html_e( 'Also push on "processing" (for virtual products); "completed" always pushes', 'emailexpert-events' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="eex-woo-consent"><?php esc_html_e( 'Consent checkbox text', 'emailexpert-events' ); ?></label></th>
					<td>
						<input type="text" id="eex-woo-consent" class="large-text" name="woo_consent_text" value="<?php echo esc_attr( (string) Options::setting( 'woo_consent_text' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Shown at checkout when the cart contains mapped products. No recorded consent means no push.', 'emailexpert-events' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save WooCommerce settings', 'emailexpert-events' ) ); ?>
		</form>

		<h3><?php esc_html_e( 'Mapped products', 'emailexpert-events' ); ?></h3>
		<table class="widefat striped" style="max-width:720px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Connection', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Event', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Ticket', 'emailexpert-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $mapped ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No products are mapped yet. Map one on its edit screen (HeySummit tab).', 'emailexpert-events' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $mapped as $product ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $product->ID . '&action=edit' ) ); ?>"><?php echo esc_html( $product->post_title ); ?></a></td>
						<td><?php echo esc_html( (string) get_post_meta( (int) $product->ID, '_eex_hs_connection', true ) ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( (int) $product->ID, '_eex_hs_event', true ) ); ?></td>
						<td><?php echo esc_html( (string) get_post_meta( (int) $product->ID, '_eex_hs_ticket', true ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Save the Woo bridge settings.
	 */
	public function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_save_woo' );

		Options::update_settings(
			[
				'woo_push_processing' => empty( $_POST['woo_push_processing'] ) ? 0 : 1,
				'woo_consent_text'    => isset( $_POST['woo_consent_text'] )
					? sanitize_text_field( wp_unslash( $_POST['woo_consent_text'] ) )
					: (string) Options::setting( 'woo_consent_text' ),
			]
		);

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ) );
		exit;
	}
}
