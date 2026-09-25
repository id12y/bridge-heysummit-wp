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
use Emailexpert\Events\MyListing\Suggestions;

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
		add_action( 'admin_post_eex_mylisting_manual', [ $this, 'save_manual_mapping' ] );
		add_action( 'admin_post_eex_mylisting_redetect', [ $this, 'redetect' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Admin styles for this screen.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'settings_page_' . self::SLUG !== $hook ) {
			return;
		}

		AdminAssets::enqueue();
	}

	/**
	 * Store (or clear) the operator's manual MyListing mapping.
	 */
	public function save_manual_mapping(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_mylisting_manual' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above; sanitised below.
		if ( ! empty( $_POST['eex_manual_clear'] ) ) {
			Detection::save_manual( null );
			\Emailexpert\Events\Admin\Notices::remove( 'mylisting_detection' );
			wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
			exit;
		}

		$post_type = isset( $_POST['manual']['post_type'] ) ? sanitize_key( wp_unslash( $_POST['manual']['post_type'] ) ) : '';
		$meta_key  = isset( $_POST['manual']['type_meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['manual']['type_meta_key'] ) ) : '';
		$types_raw = isset( $_POST['manual']['types'] ) ? sanitize_textarea_field( wp_unslash( $_POST['manual']['types'] ) ) : '';
		$field_raw = isset( $_POST['manual']['fields'] ) ? sanitize_textarea_field( wp_unslash( $_POST['manual']['fields'] ) ) : '';
		// phpcs:enable

		$mapping = self::parse_manual_mapping( $post_type, $meta_key, $types_raw, $field_raw );

		if ( null === $mapping ) {
			wp_die( esc_html__( 'The manual mapping needs at least a listing post type and one listing type line (slug | Label).', 'emailexpert-events' ) );
		}

		Detection::save_manual( $mapping );
		\Emailexpert\Events\Admin\Notices::remove( 'mylisting_detection' );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Parse the manual-mapping form lines into a Detection manual option.
	 * Lines are "slug | Label" for types and "meta_key | Label" for fields;
	 * the label falls back to the key.
	 *
	 * @param string $post_type Listing post type.
	 * @param string $meta_key  Listing-type meta key.
	 * @param string $types_raw One type per line.
	 * @param string $field_raw One field per line.
	 * @return array<string,mixed>|null Null when unusable.
	 */
	public static function parse_manual_mapping( string $post_type, string $meta_key, string $types_raw, string $field_raw ): ?array {
		if ( '' === $post_type ) {
			return null;
		}

		$parse_lines = static function ( string $raw ): array {
			$out = [];
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: [] as $line ) {
				[ $key, $label ] = array_pad( array_map( 'trim', explode( '|', $line, 2 ) ), 2, '' );
				if ( '' !== $key ) {
					$out[] = [
						'key'   => sanitize_text_field( $key ),
						'label' => sanitize_text_field( '' !== $label ? $label : $key ),
					];
				}
			}

			return $out;
		};

		$types = array_map(
			static fn( array $row ): array => [
				'slug'  => sanitize_title( $row['key'] ),
				'label' => $row['label'],
			],
			$parse_lines( $types_raw )
		);

		if ( empty( $types ) ) {
			return null;
		}

		return [
			'post_type'     => $post_type,
			'type_meta_key' => '' !== $meta_key ? $meta_key : '_case27_listing_type',
			'types'         => $types,
			'fields'        => $parse_lines( $field_raw ),
		];
	}

	/**
	 * Re-run automatic detection on demand.
	 */
	public function redetect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_mylisting_redetect' );

		Detection::get( true );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
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
			$this->render_mylisting_helper();

			return;
		}

		// Detection is confident here, so any earlier "could not be read"
		// warning is stale; leaving it up contradicts the panel below it.
		\Emailexpert\Events\Admin\Notices::remove( 'mylisting_detection' );

		$config = MyListingModule::config();
		$manual = 'manual' === (string) ( $detection['source'] ?? 'auto' );

		// The field mapping is worked out up front, but only within a listing
		// type the operator has actually chosen. The type itself is never
		// proposed — see Suggestions.
		$suggested_maps   = [];
		$suggestion_count = 0;
		$awaiting_type    = false;

		foreach ( [ 'events', 'sessions', 'speakers' ] as $suggest_source ) {
			$row_config = $config[ $suggest_source ];
			$chosen     = (string) $row_config['listing_type'];

			if ( '' === $chosen ) {
				$suggested_maps[ $suggest_source ] = [];
				$awaiting_type                     = true;

				continue;
			}

			$chosen_type = null;
			foreach ( (array) $detection['types'] as $candidate ) {
				if ( (string) $candidate['slug'] === $chosen ) {
					$chosen_type = $candidate;
					break;
				}
			}

			$suggested_maps[ $suggest_source ] = Suggestions::map( $suggest_source, $chosen_type, (array) ( $row_config['map'] ?? [] ) );
			$suggestion_count                 += count( $suggested_maps[ $suggest_source ] );
		}

		// Every type's mapping, so picking one fills the table straight away
		// rather than making the operator save first to find out.
		$per_type = [];
		foreach ( [ 'events', 'sessions', 'speakers' ] as $suggest_source ) {
			foreach ( (array) $detection['types'] as $candidate ) {
				$per_type[ $suggest_source ][ (string) $candidate['slug'] ] = Suggestions::map( $suggest_source, $candidate );
			}
		}
		?>
		<script type="application/json" id="eex-mylisting-suggestions"><?php echo wp_json_encode( $per_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a application/json block, encoded above. ?></script>
		<h2><?php esc_html_e( 'MyListing projection', 'emailexpert-events' ); ?></h2>
		<p>
			<?php if ( $manual ) : ?>
				<span class="eex-pill eex-pill-manual"><?php esc_html_e( 'Manual mapping in use', 'emailexpert-events' ); ?></span>
			<?php else : ?>
				<span class="eex-pill eex-pill-ok">
					<?php
					printf(
						/* translators: %d: number of detected listing types. */
						esc_html( _n( '%d listing type detected automatically', '%d listing types detected automatically', count( (array) $detection['types'] ), 'emailexpert-events' ) ),
						(int) count( (array) $detection['types'] )
					);
					?>
				</span>
			<?php endif; ?>
			<span class="description"><?php esc_html_e( 'The bridge stays off until you enable a projection below — detection alone never creates or changes listings.', 'emailexpert-events' ); ?></span>
		</p>
		<p class="description"><?php esc_html_e( 'One-way projection: the emailexpert Events posts stay canonical as data; the bridge creates and updates listings after each sync. Unmapped fields are never written.', 'emailexpert-events' ); ?></p>

		<?php if ( $suggestion_count > 0 ) : ?>
			<p class="eex-suggestion-note">
				<strong>
					<?php
					printf(
						/* translators: %d: number of suggested field mappings. */
						esc_html( _n( '%d field mapping has been filled in for you below.', '%d field mappings have been filled in for you below.', $suggestion_count, 'emailexpert-events' ) ),
						(int) $suggestion_count
					);
					?>
				</strong>
				<?php esc_html_e( 'They come from the fields on the listing type you chose, are marked “suggested”, and nothing is stored until you save. Change anything that looks wrong first; already-saved choices are never overwritten.', 'emailexpert-events' ); ?>
			</p>
		<?php elseif ( $awaiting_type ) : ?>
			<p class="eex-suggestion-note">
				<?php esc_html_e( 'Choose a target listing type and the field mapping below fills in from that type’s own fields straight away. The listing type is the one thing not chosen for you: it decides which listings this bridge creates and overwrites, and a type’s name does not say what it holds — a directory with its own Events type is not necessarily where these events belong.', 'emailexpert-events' ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $manual ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eex-inline-form">
				<input type="hidden" name="action" value="eex_mylisting_manual" />
				<input type="hidden" name="eex_manual_clear" value="1" />
				<?php wp_nonce_field( 'eex_mylisting_manual' ); ?>
				<button type="submit" class="button-link"><?php esc_html_e( 'Discard the manual mapping and retry automatic detection', 'emailexpert-events' ); ?></button>
			</form>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_save_bridge" />
			<?php wp_nonce_field( 'eex_save_bridge' ); ?>

			<?php foreach ( [ 'events', 'sessions', 'speakers' ] as $source ) : ?>
				<?php
				$row   = $config[ $source ];
				$field = 'mylisting[' . $source . ']';

				// The listing type shows only what is saved; the field mapping
				// shows what is saved, or the suggestion for it.
				$shown_type      = (string) $row['listing_type'];
				$map_suggestions = (array) ( $suggested_maps[ $source ] ?? [] );
				$shown_map       = (array) ( $row['map'] ?? [] ) + $map_suggestions;
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
									<option value="<?php echo esc_attr( (string) $type['slug'] ); ?>" <?php selected( $shown_type, (string) $type['slug'] ); ?>><?php echo esc_html( (string) $type['label'] ); ?></option>
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
											<select name="<?php echo esc_attr( $field ); ?>[map][<?php echo esc_attr( $source_field ); ?>]"<?php echo isset( $map_suggestions[ $source_field ] ) ? ' data-eex-suggested="1"' : ''; ?>>
												<option value=""><?php esc_html_e( 'Not mapped', 'emailexpert-events' ); ?></option>
												<?php if ( in_array( $source_field, [ 'title', 'description' ], true ) ) : ?>
													<option value="post" <?php selected( (string) ( $shown_map[ $source_field ] ?? '' ), 'post' ); ?>>
														<?php
														echo esc_html(
															( 'title' === $source_field ? __( 'Listing title', 'emailexpert-events' ) : __( 'Listing description', 'emailexpert-events' ) )
															. ( isset( $map_suggestions[ $source_field ] ) ? ' — ' . __( 'suggested', 'emailexpert-events' ) : '' )
														);
														?>
													</option>
												<?php elseif ( 'photo' === $source_field ) : ?>
													<option value="_thumbnail" <?php selected( (string) ( $shown_map['photo'] ?? '' ), '_thumbnail' ); ?>>
														<?php
														echo esc_html(
															__( 'Listing image (featured)', 'emailexpert-events' )
															. ( isset( $map_suggestions['photo'] ) ? ' — ' . __( 'suggested', 'emailexpert-events' ) : '' )
														);
														?>
													</option>
												<?php elseif ( 'categories' === $source_field ) : ?>
													<?php
													$type_taxonomies = [];
													foreach ( (array) $detection['types'] as $type ) {
														if ( (string) $type['slug'] === $shown_type || '' === $shown_type ) {
															$type_taxonomies = array_merge( $type_taxonomies, (array) ( $type['taxonomies'] ?? [] ) );
														}
													}
													foreach ( array_unique( $type_taxonomies ) as $taxonomy ) :
														?>
														<option value="<?php echo esc_attr( (string) $taxonomy ); ?>" <?php selected( (string) ( $shown_map['categories'] ?? '' ), (string) $taxonomy ); ?>>
															<?php
															echo esc_html(
																(string) $taxonomy
																. ( ( $map_suggestions['categories'] ?? '' ) === (string) $taxonomy ? ' — ' . __( 'suggested', 'emailexpert-events' ) : '' )
															);
															?>
														</option>
													<?php endforeach; ?>
												<?php endif; ?>
												<?php if ( ! in_array( $source_field, [ 'title', 'description', 'categories' ], true ) ) : ?>
													<?php foreach ( (array) $detection['types'] as $type ) : ?>
														<?php
														if ( (string) $type['slug'] !== $shown_type && '' !== $shown_type ) {
															continue;
														}
														foreach ( (array) $type['fields'] as $listing_field ) :
															?>
															<option value="<?php echo esc_attr( (string) $listing_field['key'] ); ?>" <?php selected( (string) ( $shown_map[ $source_field ] ?? '' ), (string) $listing_field['key'] ); ?>>
																<?php
																echo esc_html(
																	(string) $listing_field['label'] . ' (' . (string) $listing_field['key'] . ')'
																	. ( ( $map_suggestions[ $source_field ] ?? '' ) === (string) $listing_field['key'] ? ' — ' . __( 'suggested', 'emailexpert-events' ) : '' )
																);
																?>
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
	 * Shown when automatic detection could not read the theme: explain what
	 * happened, offer a retry, and let the operator map the structure
	 * manually instead of leaving a dead end.
	 */
	private function render_mylisting_helper(): void {
		$manual    = (array) get_option( Detection::MANUAL_OPTION, [] );
		$detection = Detection::get();
		$evidence  = (array) ( $detection['evidence'] ?? [] );
		?>
		<h2><?php esc_html_e( 'MyListing projection', 'emailexpert-events' ); ?></h2>

		<div class="eex-helper">
			<p>
				<strong><?php esc_html_e( 'MyListing was found, but this site has no listing types the bridge can read yet.', 'emailexpert-events' ); ?></strong>
				<?php esc_html_e( 'The bridge is off and no listings will be created or changed. Detection looks at the theme, its stored configuration and the listings already on the site, and it re-runs itself whenever the theme or a listing type changes and once a day — so if listing types exist or are added later, this clears on its own. Nothing below is needed unless it does not.', 'emailexpert-events' ); ?>
			</p>

			<?php if ( ! empty( $evidence ) ) : ?>
				<p class="description"><?php esc_html_e( 'What the last check found:', 'emailexpert-events' ); ?></p>
				<ul class="eex-evidence">
					<?php foreach ( $evidence as $line ) : ?>
						<li><code><?php echo esc_html( (string) $line ); ?></code></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<ol>
				<li>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eex-inline-form">
						<input type="hidden" name="action" value="eex_mylisting_redetect" />
						<?php wp_nonce_field( 'eex_mylisting_redetect' ); ?>
						<button type="submit" class="button"><?php esc_html_e( 'Check again now', 'emailexpert-events' ); ?></button>
						<span class="description"><?php esc_html_e( 'detection already re-runs on theme changes and daily; this is only to see the result immediately.', 'emailexpert-events' ); ?></span>
					</form>
				</li>
				<li>
					<p><strong><?php esc_html_e( 'Map it manually — last resort.', 'emailexpert-events' ); ?></strong> <?php esc_html_e( 'Only needed if automatic detection keeps coming back empty. A manual mapping overrides detection and stays in force until you discard it, so the bridge will not pick up listing types added afterwards.', 'emailexpert-events' ); ?></p>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="eex_mylisting_manual" />
						<?php wp_nonce_field( 'eex_mylisting_manual' ); ?>

						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="eex-manual-post-type"><?php esc_html_e( 'Listing post type', 'emailexpert-events' ); ?></label></th>
								<td>
									<select id="eex-manual-post-type" name="manual[post_type]">
										<?php
										$current = (string) ( $manual['post_type'] ?? 'job_listing' );
										$types   = function_exists( 'get_post_types' ) ? (array) get_post_types( [ 'public' => true ], 'objects' ) : [];
										if ( empty( $types ) ) {
											printf( '<option value="job_listing">%s</option>', esc_html__( 'job_listing (MyListing default)', 'emailexpert-events' ) );
										}
										foreach ( $types as $slug => $object ) {
											printf(
												'<option value="%s" %s>%s (%s)</option>',
												esc_attr( (string) $slug ),
												selected( $current, (string) $slug, false ),
												esc_html( (string) ( $object->labels->name ?? $slug ) ),
												esc_html( (string) $slug )
											);
										}
										?>
									</select>
									<p class="description"><?php esc_html_e( 'MyListing normally uses job_listing.', 'emailexpert-events' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="eex-manual-meta-key"><?php esc_html_e( 'Listing-type meta key', 'emailexpert-events' ); ?></label></th>
								<td>
									<input type="text" id="eex-manual-meta-key" name="manual[type_meta_key]" class="regular-text" value="<?php echo esc_attr( (string) ( $manual['type_meta_key'] ?? '_case27_listing_type' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'The post meta key holding each listing’s type slug. MyListing normally uses _case27_listing_type — check any listing in a database tool if unsure.', 'emailexpert-events' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="eex-manual-types"><?php esc_html_e( 'Listing types', 'emailexpert-events' ); ?></label></th>
								<td>
									<textarea id="eex-manual-types" name="manual[types]" rows="3" class="large-text code" placeholder="event | Event&#10;venue | Venue">
									<?php
									foreach ( (array) ( $manual['types'] ?? [] ) as $type ) {
										echo esc_textarea( (string) ( $type['slug'] ?? '' ) . ' | ' . (string) ( $type['label'] ?? '' ) . "\n" );
									}
									?>
									</textarea>
									<p class="description"><?php esc_html_e( 'One per line: slug | Label. The slugs are what MyListing shows under Listing Types (the URL slug of each type).', 'emailexpert-events' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="eex-manual-fields"><?php esc_html_e( 'Listing fields (optional)', 'emailexpert-events' ); ?></label></th>
								<td>
									<textarea id="eex-manual-fields" name="manual[fields]" rows="4" class="large-text code" placeholder="job_date | Event date&#10;job_location | Location">
									<?php
									foreach ( (array) ( $manual['fields'] ?? [] ) as $field_row ) {
										echo esc_textarea( (string) ( $field_row['key'] ?? '' ) . ' | ' . (string) ( $field_row['label'] ?? '' ) . "\n" );
									}
									?>
									</textarea>
									<p class="description"><?php esc_html_e( 'One per line: field meta key | Label. These become the targets offered in the field-mapping table; you can start with none and add them later.', 'emailexpert-events' ); ?></p>
								</td>
							</tr>
						</table>

						<?php submit_button( __( 'Save manual mapping', 'emailexpert-events' ), 'primary', '', false ); ?>
					</form>
				</li>
			</ol>
		</div>
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
