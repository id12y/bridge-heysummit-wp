<?php
/**
 * Settings screen.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Api\Discovery;
use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Scheduler;
use Emailexpert\Events\Admin\Digest;

defined( 'ABSPATH' ) || exit;

/**
 * Settings → emailexpert Events. Four sections: API, Sync, Webhooks, Display.
 * Also registers the sync log viewer as a hidden submenu.
 */
final class SettingsPage {

	private const SLUG = 'emailexpert-events';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_post_eex_save_settings', [ $this, 'save' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	/**
	 * Add menu entries.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'emailexpert Events', 'emailexpert-events' ),
			__( 'emailexpert Events', 'emailexpert-events' ),
			'manage_options',
			self::SLUG,
			[ $this, 'render' ]
		);

		add_submenu_page(
			'options-general.php',
			__( 'emailexpert Events sync log', 'emailexpert-events' ),
			__( 'EEX Sync Log', 'emailexpert-events' ),
			'manage_options',
			'emailexpert-events-log',
			[ new LogPage(), 'render' ]
		);
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'settings_page_' . self::SLUG, 'settings_page_emailexpert-events-log' ], true ) ) {
			return;
		}

		wp_enqueue_style( 'eex-admin', EEX_PLUGIN_URL . 'assets/css/eex-admin.css', [], EEX_VERSION );
		wp_enqueue_script( 'eex-admin', EEX_PLUGIN_URL . 'assets/js/eex-admin.js', [], EEX_VERSION, true );
		wp_localize_script(
			'eex-admin',
			'eexAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eex_admin' ),
				'i18n'    => [
					'working' => __( 'Working…', 'emailexpert-events' ),
					'failed'  => __( 'Request failed.', 'emailexpert-events' ),
				],
			]
		);
	}

	/**
	 * Render the settings screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		$connections = Options::connections();
		?>
		<div class="wrap eex-settings">
			<h1><?php esc_html_e( 'emailexpert Events', 'emailexpert-events' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'emailexpert-events' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eex_save_settings" />
				<?php wp_nonce_field( 'eex_save_settings' ); ?>

				<?php
				$this->render_api_section( $connections );
				$this->render_sync_section( $connections );
				$this->render_webhooks_section();
				$this->render_display_section();
				?>

				<?php submit_button( __( 'Save settings', 'emailexpert-events' ) ); ?>
			</form>

			<?php self::render_export_import(); ?>
		</div>
		<?php
	}

	/**
	 * API section: connections list and diagnostics.
	 *
	 * @param array<int,array<string,string>> $connections Connections.
	 */
	private function render_api_section( array $connections ): void {
		$single = count( $connections ) <= 1;
		?>
		<h2><?php esc_html_e( 'API', 'emailexpert-events' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Each HeySummit account you sync from is a connection with its own API key (HeySummit Business plan required). With one connection this stays as simple as a single key.', 'emailexpert-events' ); ?>
		</p>

		<table class="widefat striped eex-connections" id="eex-connections">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'API key', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'emailexpert-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			if ( empty( $connections ) ) {
				$connections = [
					[
						'id'      => '',
						'label'   => __( 'Primary', 'emailexpert-events' ),
						'api_key' => '',
					],
				];
			}

			foreach ( $connections as $i => $connection ) :
				$from_constant = ! empty( $connection['from_constant'] );
				$has_key       = '' !== (string) ( $connection['api_key'] ?? '' );
				$last4         = $has_key ? substr( (string) $connection['api_key'], -4 ) : '';
				?>
				<tr>
					<td>
						<input type="hidden" name="connections[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) ( $connection['id'] ?? '' ) ); ?>" />
						<input type="text" name="connections[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( (string) ( $connection['label'] ?? '' ) ); ?>" class="regular-text" />
					</td>
					<td>
						<?php if ( $from_constant ) : ?>
							<input type="password" disabled value="****" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Defined by the EEX_HEYSUMMIT_API_KEY constant in wp-config.php; edit it there.', 'emailexpert-events' ); ?></p>
						<?php else : ?>
							<input type="password" name="connections[<?php echo (int) $i; ?>][api_key]" value="" class="regular-text" autocomplete="new-password"
								placeholder="<?php echo $has_key ? esc_attr( sprintf( /* translators: %s: last four characters of the saved key. */ __( 'Saved key ending in %s (leave blank to keep)', 'emailexpert-events' ), $last4 ) ) : esc_attr__( 'Paste your HeySummit API key', 'emailexpert-events' ); ?>" />
						<?php endif; ?>
					</td>
					<td>
						<button type="button" class="button eex-test-connection" data-connection="<?php echo esc_attr( (string) ( $connection['id'] ?? '' ) ); ?>">
							<?php esc_html_e( 'Test connection', 'emailexpert-events' ); ?>
						</button>
						<?php if ( ! $from_constant && ! $single ) : ?>
							<label><input type="checkbox" name="connections[<?php echo (int) $i; ?>][delete]" value="1" /> <?php esc_html_e( 'Remove', 'emailexpert-events' ); ?></label>
						<?php endif; ?>
						<span class="eex-inline-result" aria-live="polite"></span>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="eex-add-connection"><?php esc_html_e( 'Add connection', 'emailexpert-events' ); ?></button>
		</p>

		<?php $this->render_diagnostics( $connections ); ?>
		<?php
	}

	/**
	 * Discovery diagnostics panel.
	 *
	 * @param array<int,array<string,string>> $connections Connections.
	 */
	private function render_diagnostics( array $connections ): void {
		?>
		<details class="eex-diagnostics">
			<summary><?php esc_html_e( 'API discovery diagnostics', 'emailexpert-events' ); ?></summary>
			<p class="description"><?php esc_html_e( 'After a successful connection test, the plugin records the actual shape of each API resource and compares it with what the sync mappers expect. Missing fields are warnings, not fatals; sync proceeds with whatever maps.', 'emailexpert-events' ); ?></p>
			<?php
			foreach ( $connections as $connection ) {
				$id     = (string) ( $connection['id'] ?? '' );
				$report = '' !== $id ? Discovery::stored_report( $id ) : [];

				if ( empty( $report ) ) {
					printf( '<p><em>%s</em></p>', esc_html( sprintf( /* translators: %s: connection label. */ __( '%s: no discovery data yet. Run Test connection.', 'emailexpert-events' ), (string) ( $connection['label'] ?? $id ) ) ) );
					continue;
				}
				?>
				<h4><?php echo esc_html( (string) ( $connection['label'] ?? $id ) ); ?></h4>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Resource', 'emailexpert-events' ); ?></th>
							<th><?php esc_html_e( 'Fields found', 'emailexpert-events' ); ?></th>
							<th><?php esc_html_e( 'Expected but missing', 'emailexpert-events' ); ?></th>
							<th><?php esc_html_e( 'Present but unmapped', 'emailexpert-events' ); ?></th>
							<th><?php esc_html_e( 'Type mismatches', 'emailexpert-events' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $report as $resource => $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) $resource ); ?></code></td>
								<td>
									<?php
									if ( ! empty( $row['error'] ) ) {
										echo esc_html( (string) $row['error'] );
									} elseif ( ! empty( $row['empty'] ) ) {
										esc_html_e( 'No records to sample.', 'emailexpert-events' );
									} else {
										echo esc_html( implode( ', ', array_keys( (array) ( $row['found'] ?? [] ) ) ) );
									}
									?>
								</td>
								<td><?php echo esc_html( implode( ', ', (array) ( $row['missing'] ?? [] ) ) ); ?></td>
								<td><?php echo esc_html( implode( ', ', (array) ( $row['unmapped'] ?? [] ) ) ); ?></td>
								<td>
									<?php
									foreach ( (array) ( $row['type_mismatch'] ?? [] ) as $field => $mismatch ) {
										echo esc_html( sprintf( '%s (expected %s, got %s) ', $field, (string) ( $mismatch['expected'] ?? '' ), (string) ( $mismatch['found'] ?? '' ) ) );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php
			}
			?>
		</details>
		<?php
	}

	/**
	 * Sync section.
	 *
	 * @param array<int,array<string,string>> $connections Connections.
	 */
	private function render_sync_section( array $connections ): void {
		$available  = (array) get_option( 'eex_available_events', [] );
		$configured = Options::synced_events();
		$frequency  = (string) Options::setting( 'frequency' );
		?>
		<h2><?php esc_html_e( 'Sync', 'emailexpert-events' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events-setup' ) ); ?>"><?php esc_html_e( 'Run the setup wizard', 'emailexpert-events' ); ?></a>
		</p>

		<?php foreach ( $connections as $connection ) : ?>
			<?php
			$conn_id = (string) ( $connection['id'] ?? '' );
			if ( '' === $conn_id ) {
				continue;
			}
			$events = (array) ( $available[ $conn_id ] ?? [] );
			?>
			<h3><?php echo esc_html( (string) ( $connection['label'] ?? $conn_id ) ); ?></h3>
			<p>
				<button type="button" class="button eex-load-events" data-connection="<?php echo esc_attr( $conn_id ); ?>">
					<?php esc_html_e( 'Load events from HeySummit', 'emailexpert-events' ); ?>
				</button>
				<span class="eex-inline-result" aria-live="polite"></span>
			</p>

			<?php if ( empty( $events ) ) : ?>
				<p class="description"><?php esc_html_e( 'No events loaded yet for this connection.', 'emailexpert-events' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $events as $event ) {
				$this->render_event_row( $conn_id, (array) $event, $configured );
			}
			?>
		<?php endforeach; ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="eex-frequency"><?php esc_html_e( 'Sync frequency', 'emailexpert-events' ); ?></label></th>
				<td>
					<select name="settings[frequency]" id="eex-frequency">
						<?php
						foreach ( Scheduler::FREQUENCIES as $key => $label_info ) {
							printf(
								'<option value="%s" %s>%s</option>',
								esc_attr( $key ),
								selected( $frequency, $key, false ),
								esc_html( $label_info['label'] )
							);
						}
						?>
					</select>
					<button type="button" class="button" id="eex-sync-now"><?php esc_html_e( 'Sync now', 'emailexpert-events' ); ?></button>
					<span class="eex-inline-result" aria-live="polite"></span>
					<p class="description">
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=emailexpert-events-log' ) ); ?>"><?php esc_html_e( 'View the sync log', 'emailexpert-events' ); ?></a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * One enabled/available event's sync configuration row.
	 *
	 * @param string                            $conn_id    Connection ID.
	 * @param array<string,string>              $event      Available event (id, title).
	 * @param array<string,array<string,mixed>> $configured Configured rows.
	 */
	private function render_event_row( string $conn_id, array $event, array $configured ): void {
		$event_id = (string) ( $event['id'] ?? '' );
		$key      = $conn_id . '|' . $event_id;
		$config   = Options::normalise_event_config( (array) ( $configured[ $key ] ?? [] ) );
		$field    = 'events[' . $key . ']';

		$categories = (array) get_option( 'eex_available_categories', [] );
		$cats       = (array) ( $categories[ $key ] ?? [] );
		?>
		<div class="eex-event-row card">
			<label class="eex-event-enable">
				<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $config['enabled'] ) ); ?> />
				<strong><?php echo esc_html( (string) ( $event['title'] ?? $event_id ) ); ?></strong>
				<code><?php echo esc_html( $event_id ); ?></code>
			</label>

			<div class="eex-event-options">
				<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[talks]" value="1" <?php checked( ! empty( $config['talks'] ) ); ?> /> <?php esc_html_e( 'Sessions', 'emailexpert-events' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[speakers]" value="1" <?php checked( ! empty( $config['speakers'] ) ); ?> /> <?php esc_html_e( 'Speakers', 'emailexpert-events' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[categories]" value="1" <?php checked( ! empty( $config['categories'] ) ); ?> /> <?php esc_html_e( 'Categories', 'emailexpert-events' ); ?></label>
				<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[photos]" value="1" <?php checked( ! empty( $config['photos'] ) ); ?> /> <?php esc_html_e( 'Sideload photos', 'emailexpert-events' ); ?></label>

				<label>
					<?php esc_html_e( 'New posts:', 'emailexpert-events' ); ?>
					<select name="<?php echo esc_attr( $field ); ?>[import_status]">
						<option value="publish" <?php selected( $config['import_status'], 'publish' ); ?>><?php esc_html_e( 'Publish immediately', 'emailexpert-events' ); ?></option>
						<option value="pending" <?php selected( $config['import_status'], 'pending' ); ?>><?php esc_html_e( 'Pending review', 'emailexpert-events' ); ?></option>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Future sessions:', 'emailexpert-events' ); ?>
					<select name="<?php echo esc_attr( $field ); ?>[future_mode]">
						<option value="all" <?php selected( $config['future_mode'], 'all' ); ?>><?php esc_html_e( 'All', 'emailexpert-events' ); ?></option>
						<option value="none" <?php selected( $config['future_mode'], 'none' ); ?>><?php esc_html_e( 'None', 'emailexpert-events' ); ?></option>
					</select>
				</label>

				<label>
					<?php esc_html_e( 'Past sessions:', 'emailexpert-events' ); ?>
					<select name="<?php echo esc_attr( $field ); ?>[past_mode]" class="eex-past-mode">
						<option value="all" <?php selected( $config['past_mode'], 'all' ); ?>><?php esc_html_e( 'All', 'emailexpert-events' ); ?></option>
						<option value="none" <?php selected( $config['past_mode'], 'none' ); ?>><?php esc_html_e( 'None', 'emailexpert-events' ); ?></option>
						<option value="recent" <?php selected( $config['past_mode'], 'recent' ); ?>><?php esc_html_e( 'Most recent N', 'emailexpert-events' ); ?></option>
						<option value="since" <?php selected( $config['past_mode'], 'since' ); ?>><?php esc_html_e( 'Since a date', 'emailexpert-events' ); ?></option>
					</select>
					<input type="number" min="0" name="<?php echo esc_attr( $field ); ?>[past_n]" value="<?php echo esc_attr( (string) (int) $config['past_n'] ); ?>" size="4" aria-label="<?php esc_attr_e( 'Most recent N past sessions', 'emailexpert-events' ); ?>" />
					<input type="date" name="<?php echo esc_attr( $field ); ?>[past_since]" value="<?php echo esc_attr( (string) $config['past_since'] ); ?>" aria-label="<?php esc_attr_e( 'Import past sessions since date', 'emailexpert-events' ); ?>" />
				</label>

				<label>
					<?php esc_html_e( 'Category filter:', 'emailexpert-events' ); ?>
					<select name="<?php echo esc_attr( $field ); ?>[cat_filter_mode]">
						<option value="" <?php selected( $config['cat_filter_mode'], '' ); ?>><?php esc_html_e( 'Import all sessions', 'emailexpert-events' ); ?></option>
						<option value="include" <?php selected( $config['cat_filter_mode'], 'include' ); ?>><?php esc_html_e( 'Only these categories', 'emailexpert-events' ); ?></option>
						<option value="exclude" <?php selected( $config['cat_filter_mode'], 'exclude' ); ?>><?php esc_html_e( 'Everything except these', 'emailexpert-events' ); ?></option>
					</select>
				</label>

				<button type="button" class="button-link eex-load-categories" data-connection="<?php echo esc_attr( $conn_id ); ?>" data-event="<?php echo esc_attr( $event_id ); ?>">
					<?php esc_html_e( 'Refresh categories', 'emailexpert-events' ); ?>
				</button>

				<?php if ( ! empty( $cats ) ) : ?>
					<fieldset class="eex-cat-filter">
						<legend class="screen-reader-text"><?php esc_html_e( 'Categories for the filter', 'emailexpert-events' ); ?></legend>
						<?php foreach ( $cats as $cat ) : ?>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[cat_filter][]" value="<?php echo esc_attr( (string) ( $cat['id'] ?? '' ) ); ?>"
									<?php checked( in_array( (string) ( $cat['id'] ?? '' ), array_map( 'strval', (array) $config['cat_filter'] ), true ) ); ?> />
								<?php echo esc_html( (string) ( $cat['title'] ?? '' ) ); ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>

				<input type="hidden" name="<?php echo esc_attr( $field ); ?>[title]" value="<?php echo esc_attr( (string) ( $event['title'] ?? $config['title'] ) ); ?>" />
			</div>
		</div>
		<?php
	}

	/**
	 * Webhooks section.
	 */
	private function render_webhooks_section(): void {
		$secret = Options::ensure_webhook_secret();
		$url    = '' !== $secret ? rest_url( 'eex/v1/heysummit/' . $secret ) : '';
		?>
		<h2><?php esc_html_e( 'Webhooks', 'emailexpert-events' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Receiver URL', 'emailexpert-events' ); ?></th>
				<td>
					<code id="eex-webhook-url"><?php echo esc_html( $url ); ?></code>
					<p>
						<button type="button" class="button" id="eex-regenerate-secret"><?php esc_html_e( 'Regenerate secret', 'emailexpert-events' ); ?></button>
						<span class="eex-inline-result" aria-live="polite"></span>
					</p>
					<p class="description"><?php esc_html_e( 'Paste this URL into HeySummit outgoing webhooks for: attendee registration started, checkout complete, and talk added to schedule.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actions', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[wh_checkout]" value="1" <?php checked( (bool) Options::setting( 'wh_checkout' ) ); ?> /> <?php esc_html_e( 'Process checkout complete (counter and attribution)', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[wh_started]" value="1" <?php checked( (bool) Options::setting( 'wh_started' ) ); ?> /> <?php esc_html_e( 'Process registration started (attribution and abandonment hook)', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[wh_talk]" value="1" <?php checked( (bool) Options::setting( 'wh_talk' ) ); ?> /> <?php esc_html_e( 'Process talk added to schedule (hook only)', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[notify_checkout_email]" value="1" <?php checked( (bool) Options::setting( 'notify_checkout_email' ) ); ?> /> <?php esc_html_e( 'Email the site admin on each completed checkout', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Outbound relay', 'emailexpert-events' ); ?></th>
				<td>
					<p class="description"><?php esc_html_e( 'Forward each processed, verified action as JSON to these URLs (n8n, Make, an ESP). Attendee emails are relayed as hashes only.', 'emailexpert-events' ); ?></p>
					<table class="widefat striped" id="eex-relay-urls" style="max-width:780px">
						<thead>
							<tr>
								<th><?php esc_html_e( 'URL', 'emailexpert-events' ); ?></th>
								<th><?php esc_html_e( 'Shared secret header', 'emailexpert-events' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'emailexpert-events' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$eex_relays   = array_values( array_filter( (array) get_option( 'eex_relay_urls', [] ), 'is_array' ) );
							$eex_relays[] = [
								'url'     => '',
								'secret'  => '',
								'actions' => [ 'checkout_complete', 'registration_started', 'talk_added' ],
							];
							foreach ( $eex_relays as $eex_i => $eex_relay ) :
								?>
								<tr>
									<td><input type="url" class="regular-text" name="relays[<?php echo (int) $eex_i; ?>][url]" value="<?php echo esc_attr( (string) ( $eex_relay['url'] ?? '' ) ); ?>" placeholder="https://" /></td>
									<td><input type="text" name="relays[<?php echo (int) $eex_i; ?>][secret]" value="<?php echo esc_attr( (string) ( $eex_relay['secret'] ?? '' ) ); ?>" autocomplete="off" /></td>
									<td>
										<?php
										foreach ( [
											'checkout_complete' => __( 'Checkout', 'emailexpert-events' ),
											'registration_started' => __( 'Started', 'emailexpert-events' ),
											'talk_added' => __( 'Talk added', 'emailexpert-events' ),
										] as $eex_action => $eex_label ) :
											?>
											<label><input type="checkbox" name="relays[<?php echo (int) $eex_i; ?>][actions][]" value="<?php echo esc_attr( $eex_action ); ?>" <?php checked( in_array( $eex_action, (array) ( $eex_relay['actions'] ?? [] ), true ) ); ?> /> <?php echo esc_html( $eex_label ); ?></label>
										<?php endforeach; ?>
									</td>
									<td>
										<?php if ( '' !== (string) ( $eex_relay['url'] ?? '' ) ) : ?>
											<button type="button" class="button eex-relay-test" data-index="<?php echo (int) $eex_i; ?>"><?php esc_html_e( 'Send test payload', 'emailexpert-events' ); ?></button>
											<span class="eex-inline-result" aria-live="polite"></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Leave a URL blank to remove its row on save.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Capture mode', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[wh_capture]" value="1" <?php checked( (bool) Options::setting( 'wh_capture' ) ); ?> /> <?php esc_html_e( 'Store complete raw payloads in the log (flagged capture) for parser verification', 'emailexpert-events' ); ?></label>
					<p class="description"><?php esc_html_e( 'Turn on, make a single self-registration on HeySummit, then replay the captured payload with: wp eex webhooks:replay <log_id>. Turn off afterwards.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Display section.
	 */
	private function render_display_section(): void {
		$colours = (array) Options::setting( 'series_colours' );
		$series  = get_terms(
			[
				'taxonomy'   => 'eex_event_series',
				'hide_empty' => false,
			]
		);
		?>
		<h2><?php esc_html_e( 'Display', 'emailexpert-events' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Event series colours', 'emailexpert-events' ); ?></th>
				<td>
					<?php if ( is_array( $series ) && ! empty( $series ) ) : ?>
						<?php foreach ( $series as $term ) : ?>
							<label>
								<?php echo esc_html( $term->name ); ?>
								<input type="text" class="eex-colour" name="settings[series_colours][<?php echo esc_attr( $term->slug ); ?>]"
									value="<?php echo esc_attr( (string) ( $colours[ $term->slug ] ?? '' ) ); ?>" placeholder="#0a66c2" size="8" />
							</label><br />
						<?php endforeach; ?>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Create event series terms first (Events → Event series).', 'emailexpert-events' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="eex-date-format"><?php esc_html_e( 'Date format override', 'emailexpert-events' ); ?></label></th>
				<td>
					<input type="text" id="eex-date-format" name="settings[date_format]" value="<?php echo esc_attr( (string) Options::setting( 'date_format' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'date_format' ) ); ?>" />
					<p class="description"><?php esc_html_e( 'PHP date format for component output. Leave blank to use the site default.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Structured data', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[schema_enabled]" value="1" <?php checked( (bool) Options::setting( 'schema_enabled' ) ); ?> /> <?php esc_html_e( 'Output Schema.org JSON-LD', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[schema_event]" value="1" <?php checked( (bool) Options::setting( 'schema_event' ) ); ?> /> <?php esc_html_e( 'Event schema', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[schema_person]" value="1" <?php checked( (bool) Options::setting( 'schema_person' ) ); ?> /> <?php esc_html_e( 'Person schema', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[schema_video]" value="1" <?php checked( (bool) Options::setting( 'schema_video' ) ); ?> /> <?php esc_html_e( 'VideoObject schema for replays', 'emailexpert-events' ); ?></label><br />
					<label><input type="checkbox" name="settings[og_fallback]" value="1" <?php checked( (bool) Options::setting( 'og_fallback' ) ); ?> /> <?php esc_html_e( 'Open Graph and Twitter card fallback when no SEO plugin covers these pages', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'UTM auto-tagging', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[utm_enabled]" value="1" <?php checked( (bool) Options::setting( 'utm_enabled' ) ); ?> /> <?php esc_html_e( 'Append UTM parameters to HeySummit register and event links (active once a source is set)', 'emailexpert-events' ); ?></label><br />
					<label><?php esc_html_e( 'Source', 'emailexpert-events' ); ?> <input type="text" name="settings[utm_source]" value="<?php echo esc_attr( (string) Options::setting( 'utm_source' ) ); ?>" placeholder="emailexpert.com" /></label>
					<label><?php esc_html_e( 'Medium', 'emailexpert-events' ); ?> <input type="text" name="settings[utm_medium]" value="<?php echo esc_attr( (string) Options::setting( 'utm_medium' ) ); ?>" placeholder="web" /></label>
					<p class="description"><?php esc_html_e( 'Campaign is set automatically from the rendering page slug; override per page with the _eex_utm_campaign custom field.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Page cache purging', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[purge_enabled]" value="1" <?php checked( (bool) Options::setting( 'purge_enabled' ) ); ?> /> <?php esc_html_e( 'Purge common page caches (WP Rocket, LiteSpeed, W3TC, Cloudflare) after syncs and counter changes', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Weekly digest', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[digest_enabled]" value="1" <?php checked( (bool) Options::setting( 'digest_enabled' ) ); ?> /> <?php esc_html_e( 'Email the admin a Monday summary (registrations by source, sessions, sync health)', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync health', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[health_email]" value="1" <?php checked( (bool) Options::setting( 'health_email' ) ); ?> /> <?php esc_html_e( 'Email the admin after six consecutive failed sync runs', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Data retention', 'emailexpert-events' ); ?></th>
				<td>
					<label>
						<?php esc_html_e( 'Keep attribution rows for', 'emailexpert-events' ); ?>
						<input type="number" min="1" max="120" name="settings[retention_months]" value="<?php echo esc_attr( (string) Options::setting( 'retention_months' ) ); ?>" size="4" />
						<?php esc_html_e( 'months', 'emailexpert-events' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Uninstall', 'emailexpert-events' ); ?></th>
				<td>
					<label><input type="checkbox" name="settings[uninstall_delete]" value="1" <?php checked( (bool) Options::setting( 'uninstall_delete' ) ); ?> /> <?php esc_html_e( 'On uninstall, delete all synced content and data', 'emailexpert-events' ); ?></label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the settings form.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_save_settings' );

		$this->save_connections();
		$this->save_events();
		$this->save_settings();
		$this->save_relays();

		// Cron on demand: reflects whether any event is enabled.
		Scheduler::sync_schedule_state();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=' . self::SLUG ) ) );
		exit;
	}

	/**
	 * Persist connections. Keys are write-only: an empty key field keeps the
	 * stored key.
	 */
	private function save_connections(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); sanitised field-by-field below.
		$posted   = isset( $_POST['connections'] ) && is_array( $_POST['connections'] ) ? wp_unslash( $_POST['connections'] ) : [];
		$existing = (array) get_option( Options::CONNECTIONS, [] );
		$by_id    = [];

		foreach ( $existing as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) ) {
				$by_id[ (string) $row['id'] ] = $row;
			}
		}

		$saved = [];

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}

			$id    = sanitize_key( (string) ( $row['id'] ?? '' ) );
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			$key   = trim( (string) ( $row['api_key'] ?? '' ) );

			if ( '' === $id ) {
				if ( '' === $key && '' === $label ) {
					continue; // Empty template row.
				}
				$id = 'c' . substr( md5( uniqid( 'eex', true ) ), 0, 8 );
			}

			$saved[] = [
				'id'      => $id,
				'label'   => '' !== $label ? $label : __( 'Connection', 'emailexpert-events' ),
				'api_key' => '' !== $key ? sanitize_text_field( $key ) : (string) ( $by_id[ $id ]['api_key'] ?? '' ),
			];
		}

		update_option( Options::CONNECTIONS, $saved, false );
	}

	/**
	 * Persist per-event sync configuration.
	 */
	private function save_events(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); sanitised field-by-field below.
		$posted     = isset( $_POST['events'] ) && is_array( $_POST['events'] ) ? wp_unslash( $_POST['events'] ) : [];
		$configured = Options::synced_events();

		foreach ( $posted as $key => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$key = sanitize_text_field( (string) $key );

			$configured[ $key ] = [
				'enabled'         => empty( $row['enabled'] ) ? 0 : 1,
				'talks'           => empty( $row['talks'] ) ? 0 : 1,
				'speakers'        => empty( $row['speakers'] ) ? 0 : 1,
				'categories'      => empty( $row['categories'] ) ? 0 : 1,
				'photos'          => empty( $row['photos'] ) ? 0 : 1,
				'import_status'   => in_array( $row['import_status'] ?? '', [ 'publish', 'pending' ], true ) ? $row['import_status'] : 'publish',
				'cat_filter_mode' => in_array( $row['cat_filter_mode'] ?? '', [ '', 'include', 'exclude' ], true ) ? $row['cat_filter_mode'] : '',
				'cat_filter'      => array_map( 'sanitize_text_field', (array) ( $row['cat_filter'] ?? [] ) ),
				'future_mode'     => in_array( $row['future_mode'] ?? 'all', [ 'all', 'none' ], true ) ? $row['future_mode'] : 'all',
				'past_mode'       => in_array( $row['past_mode'] ?? 'all', [ 'all', 'none', 'recent', 'since' ], true ) ? $row['past_mode'] : 'all',
				'past_n'          => max( 0, (int) ( $row['past_n'] ?? 20 ) ),
				'past_since'      => preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', (string) ( $row['past_since'] ?? '' ) ) ? (string) $row['past_since'] : '',
				'title'           => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			];
		}

		update_option( Options::SYNCED_EVENTS, $configured, false );
	}

	/**
	 * Persist general settings and reschedule cron when frequency changes.
	 */
	private function save_settings(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); sanitised field-by-field below.
		$posted = isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ? wp_unslash( $_POST['settings'] ) : [];

		$colours = [];
		foreach ( (array) ( $posted['series_colours'] ?? [] ) as $slug => $colour ) {
			$colour = sanitize_hex_color( (string) $colour );
			if ( $colour ) {
				$colours[ sanitize_key( (string) $slug ) ] = $colour;
			}
		}

		$frequency = in_array( $posted['frequency'] ?? '', array_keys( Scheduler::FREQUENCIES ), true ) ? $posted['frequency'] : 'hourly';

		Options::update_settings(
			[
				'frequency'             => $frequency,
				'date_format'           => sanitize_text_field( (string) ( $posted['date_format'] ?? '' ) ),
				'series_colours'        => $colours,
				'schema_enabled'        => empty( $posted['schema_enabled'] ) ? 0 : 1,
				'schema_event'          => empty( $posted['schema_event'] ) ? 0 : 1,
				'schema_person'         => empty( $posted['schema_person'] ) ? 0 : 1,
				'schema_video'          => empty( $posted['schema_video'] ) ? 0 : 1,
				'og_fallback'           => empty( $posted['og_fallback'] ) ? 0 : 1,
				'wh_checkout'           => empty( $posted['wh_checkout'] ) ? 0 : 1,
				'wh_started'            => empty( $posted['wh_started'] ) ? 0 : 1,
				'wh_talk'               => empty( $posted['wh_talk'] ) ? 0 : 1,
				'wh_capture'            => empty( $posted['wh_capture'] ) ? 0 : 1,
				'notify_checkout_email' => empty( $posted['notify_checkout_email'] ) ? 0 : 1,
				'health_email'          => empty( $posted['health_email'] ) ? 0 : 1,
				'retention_months'      => max( 1, min( 120, (int) ( $posted['retention_months'] ?? 24 ) ) ),
				'uninstall_delete'      => empty( $posted['uninstall_delete'] ) ? 0 : 1,
				'utm_enabled'           => empty( $posted['utm_enabled'] ) ? 0 : 1,
				'utm_source'            => sanitize_text_field( (string) ( $posted['utm_source'] ?? '' ) ),
				'utm_medium'            => sanitize_text_field( (string) ( $posted['utm_medium'] ?? 'web' ) ),
				'purge_enabled'         => empty( $posted['purge_enabled'] ) ? 0 : 1,
				'digest_enabled'        => empty( $posted['digest_enabled'] ) ? 0 : 1,
			]
		);

		Digest::sync_schedule_state();

		// The attribution table exists from the moment webhooks are enabled.
		if ( ! empty( $posted['wh_checkout'] ) || ! empty( $posted['wh_started'] ) || ! empty( $posted['wh_talk'] ) ) {
			\Emailexpert\Events\Install\Tables::ensure_attribution();
		}
	}

	/**
	 * Persist the outbound relay targets.
	 */
	private function save_relays(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in save(); sanitised field-by-field below.
		$posted = isset( $_POST['relays'] ) && is_array( $_POST['relays'] ) ? wp_unslash( $_POST['relays'] ) : null;

		if ( null === $posted ) {
			return; // Section not present in this submission.
		}

		$saved = [];
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$url = esc_url_raw( (string) ( $row['url'] ?? '' ) );
			if ( '' === $url ) {
				continue; // Blank URL removes the row.
			}
			$saved[] = [
				'url'     => $url,
				'secret'  => sanitize_text_field( (string) ( $row['secret'] ?? '' ) ),
				'actions' => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $row['actions'] ?? [] ) ), [ 'checkout_complete', 'registration_started', 'talk_added' ] ) ),
			];
		}

		update_option( 'eex_relay_urls', $saved, false );
	}

	/**
	 * Export / import section rendered after the main form.
	 */
	public static function render_export_import(): void {
		$export_url = wp_nonce_url( admin_url( 'admin-post.php?action=eex_export_settings' ), 'eex_export_settings' );
		?>
		<h2><?php esc_html_e( 'Settings export and import', 'emailexpert-events' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export settings (JSON, no keys or secrets)', 'emailexpert-events' ); ?></a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="eex_import_preview" />
			<?php wp_nonce_field( 'eex_import_settings' ); ?>
			<input type="file" name="eex_import_file" accept="application/json" />
			<?php submit_button( __( 'Preview import', 'emailexpert-events' ), 'secondary', '', false ); ?>
		</form>
		<?php
	}
}
