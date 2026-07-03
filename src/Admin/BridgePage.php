<?php
/**
 * Bridge settings screen (MyListing mapping, WooCommerce summary).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\MyListing\Detection;
use Emailexpert\Events\MyListing\Module as MyListingModule;
use Emailexpert\Events\MyListing\Projector;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → EEX Bridges. The MyListing section renders only when MyListing
 * is detected; other modules (WooCommerce) append their sections via the
 * eex_bridge_sections action. The page itself is plain plugin admin code —
 * module code still only loads when its host is present.
 */
final class BridgePage {

	private const SLUG = 'emailexpert-events-bridge';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_eex_save_bridge', [ $this, 'save' ] );
		add_action( 'admin_post_eex_project_now', [ $this, 'project_now' ] );
		add_action( 'admin_post_eex_toggle_accounts', [ $this, 'toggle_accounts' ] );
	}

	/**
	 * The accounts master switch. Until enabled, this is the only Accounts
	 * UI and none of the module's code loads.
	 */
	private function render_accounts_toggle(): void {
		$enabled = (bool) \Emailexpert\Events\Options::setting( 'accounts_enabled' );
		?>
		<h2><?php esc_html_e( 'Account registration', 'emailexpert-events' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_toggle_accounts" />
			<?php wp_nonce_field( 'eex_toggle_accounts' ); ?>
			<p>
				<label>
					<input type="checkbox" name="accounts_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enable account registration rules', 'emailexpert-events' ); ?>
				</label>
			</p>
			<?php if ( ! $enabled ) : ?>
				<p class="description"><?php esc_html_e( 'When enabled, granular rules can register account holders as HeySummit attendees (on confirmation, role changes or listing publication), always subject to consent and the suppression list.', 'emailexpert-events' ); ?></p>
			<?php endif; ?>
			<?php submit_button( __( 'Save', 'emailexpert-events' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}

	/**
	 * Persist the accounts master switch.
	 */
	public function toggle_accounts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_toggle_accounts' );

		\Emailexpert\Events\Options::update_settings( [ 'accounts_enabled' => empty( $_POST['accounts_enabled'] ) ? 0 : 1 ] );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Register the page.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'options-general.php',
			__( 'emailexpert Events bridges', 'emailexpert-events' ),
			__( 'EEX Bridges', 'emailexpert-events' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}
		?>
		<div class="wrap eex-settings eex-bridge">
			<h1><?php esc_html_e( 'emailexpert Events bridges', 'emailexpert-events' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Bridge settings saved.', 'emailexpert-events' ); ?></p></div>
			<?php endif; ?>

			<?php $this->render_mylisting_section(); ?>

			<?php $this->render_accounts_toggle(); ?>

			<?php
			/**
			 * Optional modules append their bridge sections here.
			 */
			do_action( 'eex_bridge_sections' );
			?>
		</div>
		<?php
	}

	/**
	 * The MyListing mapping UI (visible only when MyListing is active).
	 */
	private function render_mylisting_section(): void {
		if ( ! MyListingModule::detected() ) {
			echo '<p class="description">' . esc_html__( 'MyListing is not active on this site; the listings bridge is unavailable.', 'emailexpert-events' ) . '</p>';

			return;
		}

		$detection = Detection::get();

		if ( empty( $detection['confident'] ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'MyListing is active but its listing types could not be read confidently; the bridge is disabled.', 'emailexpert-events' ) . '</p></div>';

			return;
		}

		$config = MyListingModule::config();
		?>
		<h2><?php esc_html_e( 'MyListing projection', 'emailexpert-events' ); ?></h2>
		<p class="description"><?php esc_html_e( 'One-way projection: the emailexpert Events posts stay canonical as data; the bridge creates and updates listings after each sync. Unmapped fields are never written.', 'emailexpert-events' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_save_bridge" />
			<?php wp_nonce_field( 'eex_save_bridge' ); ?>

			<?php foreach ( [ 'events', 'sessions', 'speakers' ] as $source ) : ?>
				<?php
				$row   = $config[ $source ];
				$field = 'mylisting[' . $source . ']';
				?>
				<div class="eex-event-row card">
					<label class="eex-event-enable">
						<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $row['enabled'] ) ); ?> />
						<strong><?php echo esc_html( ucfirst( $source ) ); ?></strong>
					</label>
					<div class="eex-event-options">
						<label>
							<?php esc_html_e( 'Target listing type:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[listing_type]">
								<option value=""><?php esc_html_e( 'Choose…', 'emailexpert-events' ); ?></option>
								<?php foreach ( (array) $detection['types'] as $type ) : ?>
									<option value="<?php echo esc_attr( (string) $type['slug'] ); ?>" <?php selected( $row['listing_type'], $type['slug'] ); ?>><?php echo esc_html( (string) $type['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Canonical side:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[canonical]">
								<option value="eex" <?php selected( $row['canonical'], 'eex' ); ?>><?php esc_html_e( 'emailexpert Events pages (default)', 'emailexpert-events' ); ?></option>
								<option value="listing" <?php selected( $row['canonical'], 'listing' ); ?>><?php esc_html_e( 'Listings', 'emailexpert-events' ); ?></option>
							</select>
						</label>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[listings_only]" value="1" <?php checked( ! empty( $row['listings_only'] ) ); ?> />
							<?php esc_html_e( 'Listings only (noindex the plugin pages of this type)', 'emailexpert-events' ); ?>
						</label>

						<table class="widefat striped" style="max-width:640px">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Source field', 'emailexpert-events' ); ?></th>
									<th><?php esc_html_e( 'Listing field', 'emailexpert-events' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( MyListingModule::source_fields( $source ) as $source_field => $label ) : ?>
									<tr>
										<td><?php echo esc_html( $label ); ?></td>
										<td>
											<select name="<?php echo esc_attr( $field ); ?>[map][<?php echo esc_attr( $source_field ); ?>]">
												<option value=""><?php esc_html_e( 'Not mapped', 'emailexpert-events' ); ?></option>
												<?php if ( in_array( $source_field, [ 'title', 'description' ], true ) ) : ?>
													<option value="post" <?php selected( (string) ( $row['map'][ $source_field ] ?? '' ), 'post' ); ?>>
														<?php echo 'title' === $source_field ? esc_html__( 'Listing title', 'emailexpert-events' ) : esc_html__( 'Listing description', 'emailexpert-events' ); ?>
													</option>
												<?php elseif ( 'photo' === $source_field ) : ?>
													<option value="_thumbnail" <?php selected( (string) ( $row['map']['photo'] ?? '' ), '_thumbnail' ); ?>><?php esc_html_e( 'Listing image (featured)', 'emailexpert-events' ); ?></option>
												<?php elseif ( 'categories' === $source_field ) : ?>
													<?php
													$type_taxonomies = [];
													foreach ( (array) $detection['types'] as $type ) {
														if ( (string) $type['slug'] === (string) $row['listing_type'] || '' === (string) $row['listing_type'] ) {
															$type_taxonomies = array_merge( $type_taxonomies, (array) ( $type['taxonomies'] ?? [] ) );
														}
													}
													foreach ( array_unique( $type_taxonomies ) as $taxonomy ) :
														?>
														<option value="<?php echo esc_attr( (string) $taxonomy ); ?>" <?php selected( (string) ( $row['map']['categories'] ?? '' ), (string) $taxonomy ); ?>><?php echo esc_html( (string) $taxonomy ); ?></option>
													<?php endforeach; ?>
												<?php endif; ?>
												<?php if ( ! in_array( $source_field, [ 'title', 'description', 'categories' ], true ) ) : ?>
													<?php foreach ( (array) $detection['types'] as $type ) : ?>
														<?php
														if ( (string) $type['slug'] !== (string) $row['listing_type'] && '' !== (string) $row['listing_type'] ) {
															continue;
														}
														foreach ( (array) $type['fields'] as $listing_field ) :
															?>
															<option value="<?php echo esc_attr( (string) $listing_field['key'] ); ?>" <?php selected( (string) ( $row['map'][ $source_field ] ?? '' ), (string) $listing_field['key'] ); ?>>
																<?php echo esc_html( (string) $listing_field['label'] . ' (' . (string) $listing_field['key'] . ')' ); ?>
															</option>
														<?php endforeach; ?>
													<?php endforeach; ?>
												<?php endif; ?>
											</select>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save bridge settings', 'emailexpert-events' ) ); ?>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_project_now" />
			<?php wp_nonce_field( 'eex_project_now' ); ?>
			<?php submit_button( __( 'Project now', 'emailexpert-events' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Persist the MyListing bridge settings.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_save_bridge' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; sanitised field-by-field below.
		$posted = isset( $_POST['mylisting'] ) && is_array( $_POST['mylisting'] ) ? wp_unslash( $_POST['mylisting'] ) : [];
		$saved  = [];

		foreach ( [ 'events', 'sessions', 'speakers' ] as $source ) {
			$row = is_array( $posted[ $source ] ?? null ) ? $posted[ $source ] : [];

			$map = [];
			foreach ( (array) ( $row['map'] ?? [] ) as $source_field => $target ) {
				$target = sanitize_text_field( (string) $target );
				if ( '' !== $target ) {
					$map[ sanitize_key( (string) $source_field ) ] = $target;
				}
			}

			$saved[ $source ] = [
				'enabled'       => empty( $row['enabled'] ) ? 0 : 1,
				'listing_type'  => sanitize_title( (string) ( $row['listing_type'] ?? '' ) ),
				'canonical'     => in_array( $row['canonical'] ?? 'eex', [ 'eex', 'listing' ], true ) ? $row['canonical'] : 'eex',
				'listings_only' => empty( $row['listings_only'] ) ? 0 : 1,
				'map'           => $map,
			];
		}

		update_option( 'eex_mylisting', $saved, false );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Run a projection on demand.
	 */
	public function project_now(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_project_now' );

		( new Projector() )->project_all();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}
}
