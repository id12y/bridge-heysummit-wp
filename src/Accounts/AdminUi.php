<?php
/**
 * Accounts module admin surfaces.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Options;
use Emailexpert\Events\Registrations;

defined( 'ABSPATH' ) || exit;

/**
 * The rules table, consent assertion, suppression list and backfill actions
 * on the Bridge settings Accounts tab, plus the users-screen column and
 * "Push to HeySummit" row action. Loaded only when the module is enabled.
 */
final class AdminUi {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_bridge_sections', [ $this, 'render_section' ] );
		add_action( 'admin_post_eex_save_account_rules', [ $this, 'save' ] );
		add_action( 'admin_post_eex_accounts_backfill_action', [ $this, 'backfill_action' ] );
		add_action( 'admin_post_eex_accounts_push_now', [ $this, 'manual_push' ] );

		add_filter( 'manage_users_columns', [ $this, 'users_column' ] );
		add_filter( 'manage_users_custom_column', [ $this, 'users_column_value' ], 10, 3 );
		add_filter( 'user_row_actions', [ $this, 'row_action' ], 10, 2 );
	}

	/**
	 * The Accounts section on the bridge page (module enabled).
	 */
	public function render_section(): void {
		$rules       = Rules::all();
		$assertion   = Consent::assertion();
		$connections = Options::connections();
		$events      = (array) get_option( 'eex_available_events', [] );

		// A notice from a just-run dry run.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a stashed result only.
		$dry_token = isset( $_GET['eex_dry'] ) ? sanitize_key( $_GET['eex_dry'] ) : '';
		$dry       = '' !== $dry_token ? get_transient( 'eex_backfill_dry_' . $dry_token ) : false;
		?>
		<h2><?php esc_html_e( 'Account registration rules', 'emailexpert-events' ); ?></h2>

		<?php if ( is_array( $dry ) ) : ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: rule ID, 2: matched count, 3: sample logins. */
							__( 'Backfill dry run for rule %1$s: %2$d user(s) would be pushed. Sample: %3$s', 'emailexpert-events' ),
							(string) $dry['rule'],
							(int) $dry['count'],
							implode( ', ', (array) $dry['sample'] ) ?: '—'
						)
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_accounts_backfill_action&mode=confirm&rule=' . rawurlencode( (string) $dry['rule'] ) ), 'eex_accounts_backfill' ) ); ?>">
						<?php echo esc_html( sprintf( /* translators: %d: user count. */ __( 'Confirm: push these %d user(s) in batches', 'emailexpert-events' ), (int) $dry['count'] ) ); ?>
					</a>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_save_account_rules" />
			<?php wp_nonce_field( 'eex_save_account_rules' ); ?>

			<h3><?php esc_html_e( 'Consent assertion', 'emailexpert-events' ); ?></h3>
			<p>
				<label>
					<input type="checkbox" name="consent_assertion" value="1" <?php checked( ! empty( $assertion['enabled'] ) ); ?> />
					<strong><?php esc_html_e( 'I confirm this site\'s registration terms already cover event registration and event-related email for every account holder.', 'emailexpert-events' ); ?></strong>
				</label>
			</p>
			<p class="description">
				<?php esc_html_e( 'Plain warning: enabling this asserts, on your responsibility, that users consented at registration. Rules using the "site terms assertion" consent source will register users and cause HeySummit to email them without any further checkbox. If your terms do not say this, do not enable it — use the registration checkbox source instead.', 'emailexpert-events' ); ?>
				<?php if ( ! empty( $assertion['enabled'] ) ) : ?>
					<em>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: user login, 2: timestamp. */
								__( 'Enabled by %1$s at %2$s.', 'emailexpert-events' ),
								(string) ( $assertion['by'] ?? '?' ),
								(string) ( $assertion['at'] ?? '?' )
							)
						);
						?>
					</em>
				<?php endif; ?>
			</p>

			<h3><?php esc_html_e( 'Rules', 'emailexpert-events' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Lists (roles, listing types, excluded users) are comma separated. A user is registered at most once per event, whichever and however many rules match.', 'emailexpert-events' ); ?></p>

			<?php
			$rows   = array_values( $rules );
			$rows[] = Rules::normalise( '', [] ); // One empty row for adding.

			foreach ( $rows as $index => $rule ) :
				$field = 'rules[' . $index . ']';
				?>
				<div class="eex-event-row card">
					<label class="eex-event-enable">
						<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?> />
						<strong><?php echo '' !== (string) $rule['id'] ? esc_html( sprintf( /* translators: %s: rule ID. */ __( 'Rule %s', 'emailexpert-events' ), (string) $rule['id'] ) ) : esc_html__( 'New rule', 'emailexpert-events' ); ?></strong>
						<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( (string) $rule['id'] ); ?>" />
						<?php if ( '' !== (string) $rule['id'] ) : ?>
							<label style="margin-left:12px"><input type="checkbox" name="<?php echo esc_attr( $field ); ?>[delete]" value="1" /> <?php esc_html_e( 'Delete', 'emailexpert-events' ); ?></label>
						<?php endif; ?>
					</label>
					<div class="eex-event-options">
						<label>
							<?php esc_html_e( 'Trigger:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[trigger]">
								<option value="confirmed" <?php selected( $rule['trigger'], 'confirmed' ); ?>><?php esc_html_e( 'Account confirmed', 'emailexpert-events' ); ?></option>
								<option value="role_gained" <?php selected( $rule['trigger'], 'role_gained' ); ?>><?php esc_html_e( 'Role gained', 'emailexpert-events' ); ?></option>
								<?php if ( \Emailexpert\Events\MyListing\Module::detected() ) : ?>
									<option value="listing_published" <?php selected( $rule['trigger'], 'listing_published' ); ?>><?php esc_html_e( 'Listing published', 'emailexpert-events' ); ?></option>
								<?php endif; ?>
							</select>
						</label>
						<label>
							<?php esc_html_e( '"Confirmed" means:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[confirmed_point]">
								<option value="register" <?php selected( $rule['confirmed_point'], 'register' ); ?>><?php esc_html_e( 'On registration (no verification)', 'emailexpert-events' ); ?></option>
								<option value="first_login" <?php selected( $rule['confirmed_point'], 'first_login' ); ?>><?php esc_html_e( 'On first login', 'emailexpert-events' ); ?></option>
								<option value="confirmed_action" <?php selected( $rule['confirmed_point'], 'confirmed_action' ); ?>><?php esc_html_e( 'On eex_user_confirmed (adapters / verification plugins)', 'emailexpert-events' ); ?></option>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Roles (trigger and/or condition):', 'emailexpert-events' ); ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[roles]" value="<?php echo esc_attr( implode( ',', (array) $rule['roles'] ) ); ?>" placeholder="member" />
						</label>
						<label>
							<?php esc_html_e( 'Listing types:', 'emailexpert-events' ); ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[listing_types]" value="<?php echo esc_attr( implode( ',', (array) $rule['listing_types'] ) ); ?>" />
						</label>
						<label>
							<?php esc_html_e( 'Exclude roles:', 'emailexpert-events' ); ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[exclude_roles]" value="<?php echo esc_attr( implode( ',', (array) $rule['exclude_roles'] ) ); ?>" />
						</label>
						<label>
							<?php esc_html_e( 'Exclude user IDs:', 'emailexpert-events' ); ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[exclude_users]" value="<?php echo esc_attr( implode( ',', (array) $rule['exclude_users'] ) ); ?>" />
						</label>
						<label>
							<?php esc_html_e( 'Connection:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[connection]">
								<option value=""><?php esc_html_e( 'Choose…', 'emailexpert-events' ); ?></option>
								<?php foreach ( $connections as $connection ) : ?>
									<option value="<?php echo esc_attr( (string) $connection['id'] ); ?>" <?php selected( $rule['connection'], $connection['id'] ); ?>><?php echo esc_html( (string) $connection['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Event:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[event]">
								<option value=""><?php esc_html_e( 'Choose…', 'emailexpert-events' ); ?></option>
								<?php foreach ( $events as $conn_events ) : ?>
									<?php foreach ( (array) $conn_events as $event ) : ?>
										<option value="<?php echo esc_attr( (string) $event['id'] ); ?>" <?php selected( $rule['event'], (string) $event['id'] ); ?>><?php echo esc_html( (string) ( $event['title'] ?? $event['id'] ) ); ?></option>
									<?php endforeach; ?>
								<?php endforeach; ?>
							</select>
						</label>
						<label>
							<?php esc_html_e( 'Ticket price ID:', 'emailexpert-events' ); ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[ticket]" value="<?php echo esc_attr( (string) $rule['ticket'] ); ?>" size="10" />
						</label>
						<label>
							<?php esc_html_e( 'Consent source:', 'emailexpert-events' ); ?>
							<select name="<?php echo esc_attr( $field ); ?>[consent_source]">
								<option value="checkbox" <?php selected( $rule['consent_source'], 'checkbox' ); ?>><?php esc_html_e( 'Registration checkbox (eex_event_consent meta)', 'emailexpert-events' ); ?></option>
								<option value="assertion" <?php selected( $rule['consent_source'], 'assertion' ); ?>><?php esc_html_e( 'Site terms assertion (above)', 'emailexpert-events' ); ?></option>
							</select>
						</label>
						<label style="flex-basis:100%">
							<?php esc_html_e( 'Notes:', 'emailexpert-events' ); ?>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $field ); ?>[notes]" value="<?php echo esc_attr( (string) $rule['notes'] ); ?>" />
						</label>

						<?php if ( '' !== (string) $rule['id'] ) : ?>
							<?php $progress = Backfill::progress( (string) $rule['id'] ); ?>
							<span>
								<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_accounts_backfill_action&mode=dry&rule=' . rawurlencode( (string) $rule['id'] ) ), 'eex_accounts_backfill' ) ); ?>">
									<?php esc_html_e( 'Backfill: dry run', 'emailexpert-events' ); ?>
								</a>
								<?php if ( null !== $progress ) : ?>
									<em>
										<?php
										echo esc_html(
											sprintf(
												/* translators: 1: processed, 2: total. */
												__( 'Backfill in progress: %1$d of %2$d.', 'emailexpert-events' ),
												(int) $progress['position'],
												(int) $progress['total']
											)
										);
										?>
									</em>
									<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_accounts_backfill_action&mode=resume&rule=' . rawurlencode( (string) $rule['id'] ) ), 'eex_accounts_backfill' ) ); ?>"><?php esc_html_e( 'Resume', 'emailexpert-events' ); ?></a>
								<?php endif; ?>
							</span>
						<?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>

			<h3><?php esc_html_e( 'Suppression list', 'emailexpert-events' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Addresses here are never pushed by any rule, backfill, retry or manual action. Populated by profile opt-outs, erasure requests and manual entries; stored as hashes.', 'emailexpert-events' ); ?></p>
			<table class="widefat striped" style="max-width:720px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Hash (prefix)', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Domain', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Event', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Reason', 'emailexpert-events' ); ?></th>
						<th><?php esc_html_e( 'Remove', 'emailexpert-events' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( Suppression::entries() as $entry ) : ?>
						<tr>
							<td><code><?php echo esc_html( substr( (string) $entry['email_hash'], 0, 12 ) ); ?>…</code></td>
							<td><?php echo esc_html( (string) ( $entry['domain'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['event'] ?? '*' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $entry['reason'] ?? '' ) ); ?></td>
							<td><input type="checkbox" name="suppression_remove[]" value="<?php echo esc_attr( (string) $entry['email_hash'] . '|' . (string) ( $entry['event'] ?? '*' ) ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<td colspan="5">
							<label>
								<?php esc_html_e( 'Add manually:', 'emailexpert-events' ); ?>
								<input type="email" name="suppression_add_email" value="" placeholder="person@example.org" />
							</label>
							<label>
								<?php esc_html_e( 'Event ID (* = all):', 'emailexpert-events' ); ?>
								<input type="text" name="suppression_add_event" value="*" size="6" />
							</label>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( __( 'Save account rules', 'emailexpert-events' ) ); ?>
		</form>
		<?php
	}

	/**
	 * Persist rules, assertion and suppression edits.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_save_account_rules' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; sanitised field-by-field below.
		$posted = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : [];

		$rules = [];
		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
				continue;
			}

			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );

			// A new row counts only when it has a target event.
			if ( '' === $id ) {
				if ( '' === (string) ( $row['event'] ?? '' ) ) {
					continue;
				}
				$id = 'r' . substr( md5( uniqid( 'eex', true ) ), 0, 8 );
			}

			$csv = static fn( $value ): array => array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) );

			$rules[ $id ] = [
				'id'              => $id,
				'enabled'         => empty( $row['enabled'] ) ? 0 : 1,
				'trigger'         => in_array( $row['trigger'] ?? '', [ 'confirmed', 'role_gained', 'listing_published' ], true ) ? $row['trigger'] : 'confirmed',
				'confirmed_point' => in_array( $row['confirmed_point'] ?? '', [ 'register', 'first_login', 'confirmed_action' ], true ) ? $row['confirmed_point'] : 'confirmed_action',
				'roles'           => array_map( 'sanitize_key', $csv( $row['roles'] ?? '' ) ),
				'listing_types'   => array_map( 'sanitize_title', $csv( $row['listing_types'] ?? '' ) ),
				'exclude_roles'   => array_map( 'sanitize_key', $csv( $row['exclude_roles'] ?? '' ) ),
				'exclude_users'   => array_map( 'intval', $csv( $row['exclude_users'] ?? '' ) ),
				'connection'      => sanitize_key( (string) ( $row['connection'] ?? '' ) ),
				'event'           => sanitize_text_field( (string) ( $row['event'] ?? '' ) ),
				'ticket'          => sanitize_text_field( (string) ( $row['ticket'] ?? '' ) ),
				'consent_source'  => in_array( $row['consent_source'] ?? '', [ 'checkbox', 'assertion' ], true ) ? $row['consent_source'] : 'checkbox',
				'notes'           => sanitize_text_field( (string) ( $row['notes'] ?? '' ) ),
			];
		}
		Rules::save( $rules );

		// Assertion (records who and when on transitions to enabled).
		$was = ! empty( Consent::assertion()['enabled'] );
		$now = ! empty( $_POST['consent_assertion'] );
		if ( $was !== $now ) {
			$current_user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			Consent::set_assertion( $now, $current_user ? (string) $current_user->user_login : 'unknown' );
		}

		// Suppression edits.
		foreach ( (array) ( $_POST['suppression_remove'] ?? [] ) as $key ) {
			[ $hash, $event ] = array_pad( explode( '|', sanitize_text_field( (string) $key ), 2 ), 2, '*' );
			Suppression::remove( $hash, $event );
		}
		$add_email = sanitize_email( (string) ( $_POST['suppression_add_email'] ?? '' ) );
		if ( '' !== $add_email ) {
			Suppression::add( $add_email, sanitize_text_field( (string) ( $_POST['suppression_add_event'] ?? '*' ) ), 'manual' );
		}
		// phpcs:enable

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ) );
		exit;
	}

	/**
	 * Backfill actions: dry run (stash + show), confirm, resume.
	 */
	public function backfill_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_accounts_backfill' );

		$rule_id = isset( $_GET['rule'] ) ? sanitize_key( $_GET['rule'] ) : '';
		$mode    = isset( $_GET['mode'] ) ? sanitize_key( $_GET['mode'] ) : 'dry';

		$backfill = new Backfill();
		$redirect = admin_url( 'options-general.php?page=emailexpert-events-bridge' );

		if ( 'confirm' === $mode ) {
			$backfill->confirm( $rule_id );
		} elseif ( 'resume' === $mode ) {
			$backfill->resume( $rule_id );
		} else {
			$dry   = $backfill->dry_run( $rule_id );
			$token = wp_generate_password( 12, false, false );
			set_transient(
				'eex_backfill_dry_' . $token,
				[
					'rule'   => $rule_id,
					'count'  => $dry['count'],
					'sample' => $dry['sample'],
				],
				HOUR_IN_SECONDS
			);
			$redirect = add_query_arg( 'eex_dry', $token, $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Manual per-user push: evaluate every enabled rule (trigger waived) and
	 * run the queued pushes synchronously for immediate feedback; also retry
	 * failed records.
	 *
	 * @param int $user_id User ID (0 = from the request).
	 * @return array<string,array{status:string,message:string}> Results per event.
	 */
	public function push_user_now( int $user_id ): array {
		$engine  = new Engine();
		$pusher  = new Pusher();
		$results = [];

		foreach ( $engine->evaluate( $user_id, '' ) as $event_hs_id => $rule_id ) {
			$results[ $event_hs_id ] = $pusher->push( $user_id, $event_hs_id, $rule_id );
		}

		// Retry previously failed records too.
		foreach ( Registrations::all( $user_id ) as $event_hs_id => $record ) {
			if ( Registrations::STATUS_FAILED === (string) ( $record['status'] ?? '' ) && ! isset( $results[ $event_hs_id ] ) ) {
				$results[ $event_hs_id ] = $pusher->push( $user_id, (string) $event_hs_id, (string) ( $record['rule'] ?? '' ) );
			}
		}

		return $results;
	}

	/**
	 * The row-action handler.
	 */
	public function manual_push(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_accounts_push_now' );

		$user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
		$this->push_user_now( $user_id );

		wp_safe_redirect( admin_url( 'users.php' ) );
		exit;
	}

	/**
	 * Users screen column.
	 *
	 * @param array<string,string> $columns Columns.
	 * @return array<string,string>
	 */
	public function users_column( array $columns ): array {
		$columns['eex_heysummit'] = __( 'HeySummit', 'emailexpert-events' );

		return $columns;
	}

	/**
	 * Column value: per-event push status.
	 *
	 * @param string $output      Current output.
	 * @param string $column_name Column.
	 * @param int    $user_id     User.
	 * @return string
	 */
	public function users_column_value( $output, $column_name, $user_id ) {
		if ( 'eex_heysummit' !== $column_name ) {
			return $output;
		}

		$records = Registrations::all( (int) $user_id );

		if ( empty( $records ) ) {
			return '—';
		}

		$parts = [];
		foreach ( $records as $event_hs_id => $record ) {
			$parts[] = esc_html( $event_hs_id . ': ' . (string) ( $record['status'] ?? '?' ) );
		}

		if ( '' !== (string) get_user_meta( (int) $user_id, '_eex_hs_push_failed', true ) ) {
			$parts[] = '<strong>' . esc_html__( 'push failed', 'emailexpert-events' ) . '</strong>';
		}

		return implode( '<br />', $parts );
	}

	/**
	 * "Push to HeySummit" row action.
	 *
	 * @param array<string,string> $actions Actions.
	 * @param \WP_User             $user    Row user.
	 * @return array<string,string>
	 */
	public function row_action( array $actions, $user ): array {
		if ( current_user_can( 'manage_options' ) ) {
			$actions['eex_push'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_accounts_push_now&user_id=' . (int) $user->ID ), 'eex_accounts_push_now' ) ),
				esc_html__( 'Push to HeySummit', 'emailexpert-events' )
			);
		}

		return $actions;
	}
}
