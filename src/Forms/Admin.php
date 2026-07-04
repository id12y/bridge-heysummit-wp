<?php
/**
 * Forms bridge admin section.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Tickets;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The Forms section of Settings → EEX Bridges: a row editor for mappings
 * (event and ticket offered as name pickers wherever the API answers) plus
 * the push queue's status with retry/clear actions. Queue rows show the
 * address masked — enough for the operator to recognise a submission, no
 * full addresses on screen.
 */
final class Admin {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_bridge_sections', [ $this, 'bridge_section' ] );
		add_action( 'admin_post_eex_save_forms', [ $this, 'save' ] );
		add_action( 'admin_post_eex_forms_retry', [ $this, 'retry_failed' ] );
		add_action( 'admin_post_eex_forms_clear', [ $this, 'clear_failed' ] );
	}

	/**
	 * Render the section.
	 */
	public function bridge_section(): void {
		$mappings = Mappings::all();
		$events   = $this->known_events();
		?>
		<h2><?php esc_html_e( 'Forms → HeySummit', 'emailexpert-events' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'Register form submissions as HeySummit attendees. Works with Elementor Pro Forms (pick the mapping in the form widget\'s Actions After Submit), Gravity Forms, WPForms and Fluent Forms (matched by form ID). Nothing is pushed without consent, and suppressed addresses are never pushed.', 'emailexpert-events' ); ?>
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eex_save_forms" />
			<?php wp_nonce_field( 'eex_save_forms' ); ?>

			<?php foreach ( array_merge( $mappings, [ [] ] ) as $index => $mapping ) : ?>
				<?php $this->mapping_row( (int) $index, (array) $mapping, $events ); ?>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save form mappings', 'emailexpert-events' ) ); ?>
		</form>

		<?php $this->queue_status(); ?>
		<?php
	}

	/**
	 * One mapping row (the last, empty one adds a new mapping).
	 *
	 * @param int                              $index   Row index.
	 * @param array<string,mixed>              $mapping Mapping (empty for the add row).
	 * @param array<int,array<string,string>>  $events  Known events for the picker.
	 */
	private function mapping_row( int $index, array $mapping, array $events ): void {
		$field  = 'forms[' . $index . ']';
		$is_new = empty( $mapping['id'] );

		$questions_text = '';
		foreach ( (array) ( $mapping['questions'] ?? [] ) as $key => $question_id ) {
			$questions_text .= $key . ' | ' . (int) $question_id . "\n";
		}
		?>
		<div class="eex-event-row card">
			<p>
				<strong><?php $is_new ? esc_html_e( 'Add a mapping', 'emailexpert-events' ) : print( esc_html( (string) ( $mapping['label'] ?: $mapping['id'] ) ) ); ?></strong>
				<?php if ( ! $is_new ) : ?>
					<label style="margin-left:1em">
						<input type="checkbox" name="<?php echo esc_attr( $field ); ?>[remove]" value="1" />
						<?php esc_html_e( 'Remove', 'emailexpert-events' ); ?>
					</label>
					<input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( (string) $mapping['id'] ); ?>" />
				<?php endif; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Label', 'emailexpert-events' ); ?></th>
					<td><input type="text" name="<?php echo esc_attr( $field ); ?>[label]" class="regular-text" value="<?php echo esc_attr( (string) ( $mapping['label'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Newsletter sign-up form', 'emailexpert-events' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Form', 'emailexpert-events' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $field ); ?>[source]">
							<?php
							$labels = [
								'elementor' => __( 'Elementor Pro Forms', 'emailexpert-events' ),
								'gravity'   => __( 'Gravity Forms', 'emailexpert-events' ),
								'wpforms'   => __( 'WPForms', 'emailexpert-events' ),
								'fluent'    => __( 'Fluent Forms', 'emailexpert-events' ),
							];
							foreach ( $labels as $key => $label ) {
								printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( (string) ( $mapping['source'] ?? 'elementor' ), $key, false ), esc_html( $label ) );
							}
							?>
						</select>
						<input type="text" name="<?php echo esc_attr( $field ); ?>[form_id]" value="<?php echo esc_attr( (string) ( $mapping['form_id'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Form ID', 'emailexpert-events' ); ?>" style="width:8em" />
						<p class="description"><?php esc_html_e( 'Form ID: the numeric ID for Gravity Forms, WPForms and Fluent Forms. Elementor forms ignore it — there the mapping is picked inside the form widget instead.', 'emailexpert-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Destination', 'emailexpert-events' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $field ); ?>[connection]">
							<?php foreach ( Options::connections() as $connection ) : ?>
								<option value="<?php echo esc_attr( (string) $connection['id'] ); ?>" <?php selected( (string) ( $mapping['connection'] ?? '' ), (string) $connection['id'] ); ?>><?php echo esc_html( (string) ( $connection['label'] ?: $connection['id'] ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! empty( $events ) ) : ?>
							<select name="<?php echo esc_attr( $field ); ?>[event]">
								<option value=""><?php esc_html_e( 'Choose an event…', 'emailexpert-events' ); ?></option>
								<?php foreach ( $events as $event ) : ?>
									<option value="<?php echo esc_attr( $event['id'] ); ?>" <?php selected( (string) ( $mapping['event'] ?? '' ), $event['id'] ); ?>><?php echo esc_html( $event['title'] ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<input type="text" name="<?php echo esc_attr( $field ); ?>[event]" value="<?php echo esc_attr( (string) ( $mapping['event'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'HeySummit event ID', 'emailexpert-events' ); ?>" style="width:12em" />
						<?php endif; ?>
						<?php $this->ticket_control( $field, $mapping ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fields', 'emailexpert-events' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Email field ID', 'emailexpert-events' ); ?> <input type="text" name="<?php echo esc_attr( $field ); ?>[email_field]" value="<?php echo esc_attr( (string) ( $mapping['email_field'] ?? '' ) ); ?>" style="width:10em" /></label>
						<label style="margin-left:1em"><?php esc_html_e( 'Name field ID', 'emailexpert-events' ); ?> <input type="text" name="<?php echo esc_attr( $field ); ?>[name_field]" value="<?php echo esc_attr( (string) ( $mapping['name_field'] ?? '' ) ); ?>" style="width:10em" /></label>
						<p class="description"><?php esc_html_e( 'Field IDs as the form plugin knows them: Elementor custom field IDs, Gravity Forms field numbers (dotted for name parts, e.g. 1.3), WPForms field numbers, Fluent Forms input names.', 'emailexpert-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Consent', 'emailexpert-events' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $field ); ?>[consent_mode]">
							<option value="field" <?php selected( (string) ( $mapping['consent_mode'] ?? 'field' ), 'field' ); ?>><?php esc_html_e( 'A consent checkbox must be ticked', 'emailexpert-events' ); ?></option>
							<option value="implied" <?php selected( (string) ( $mapping['consent_mode'] ?? 'field' ), 'implied' ); ?>><?php esc_html_e( 'Submitting this form is itself the registration consent', 'emailexpert-events' ); ?></option>
						</select>
						<input type="text" name="<?php echo esc_attr( $field ); ?>[consent_field]" value="<?php echo esc_attr( (string) ( $mapping['consent_field'] ?? '' ) ); ?>" placeholder="<?php esc_attr_e( 'Consent field ID', 'emailexpert-events' ); ?>" style="width:10em" />
						<p class="description"><?php esc_html_e( 'Choose "implied" only for a form whose stated purpose is registering for the event. Otherwise map the checkbox; unticked means no push, ever.', 'emailexpert-events' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Question answers (optional)', 'emailexpert-events' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $field ); ?>[questions]" rows="2" class="large-text code" placeholder="company_field | 4711"><?php echo esc_textarea( $questions_text ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line: field ID | HeySummit question ID. Answers travel inside the same attendee-create call.', 'emailexpert-events' ); ?></p>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Ticket picker: a dropdown of ticket price names when the API answers
	 * for the mapping's connection + event, otherwise a guided text input.
	 *
	 * @param string              $field   Input name prefix.
	 * @param array<string,mixed> $mapping Mapping row.
	 */
	private function ticket_control( string $field, array $mapping ): void {
		$connection = (string) ( $mapping['connection'] ?? '' );
		$event      = (string) ( $mapping['event'] ?? '' );
		$current    = (string) ( $mapping['ticket'] ?? '' );
		$options    = [];

		if ( '' !== $connection && '' !== $event ) {
			$list = Tickets::price_options( $connection, $event );

			if ( ! is_wp_error( $list ) ) {
				$options = $list;
			}
		}

		if ( ! empty( $options ) ) :
			?>
			<select name="<?php echo esc_attr( $field ); ?>[ticket]">
				<option value=""><?php esc_html_e( 'No ticket (attendee only)', 'emailexpert-events' ); ?></option>
				<?php foreach ( $options as $option ) : ?>
					<option value="<?php echo esc_attr( (string) $option['id'] ); ?>" <?php selected( $current, (string) $option['id'] ); ?>><?php echo esc_html( (string) $option['title'] ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="text" name="<?php echo esc_attr( $field ); ?>[ticket]" value="<?php echo esc_attr( $current ); ?>" placeholder="<?php esc_attr_e( 'Ticket price ID (optional)', 'emailexpert-events' ); ?>" style="width:14em" />
		<?php endif; ?>
		<?php
	}

	/**
	 * The queue's current state with retry/clear actions.
	 */
	private function queue_status(): void {
		$entries = Queue::all();

		if ( empty( $entries ) ) {
			return;
		}

		$failed = Queue::with_status( 'failed' );
		?>
		<h3><?php esc_html_e( 'Form push queue', 'emailexpert-events' ); ?></h3>
		<table class="widefat striped" style="max-width:720px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Submission', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Mapping', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Status', 'emailexpert-events' ); ?></th>
					<th><?php esc_html_e( 'Queued', 'emailexpert-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php $mapping = Mappings::get( (string) ( $entry['mapping'] ?? '' ) ); ?>
					<tr>
						<td><?php echo esc_html( self::mask_email( (string) ( $entry['email'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( null !== $mapping ? (string) ( $mapping['label'] ?: $mapping['id'] ) : (string) ( $entry['mapping'] ?? '?' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['status'] ?? '' ) . ( ! empty( $entry['last_error'] ) ? ' — ' . (string) $entry['last_error'] : '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $entry['queued_at'] ?? '' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $failed ) ) : ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_forms_retry' ), 'eex_forms_retry' ) ); ?>"><?php esc_html_e( 'Retry failed pushes', 'emailexpert-events' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=eex_forms_clear' ), 'eex_forms_clear' ) ); ?>"><?php esc_html_e( 'Clear failed pushes', 'emailexpert-events' ); ?></a>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Persist the mapping rows.
	 */
	public function save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_save_forms' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above; sanitised row-by-row below.
		$posted = isset( $_POST['forms'] ) && is_array( $_POST['forms'] ) ? wp_unslash( $_POST['forms'] ) : [];
		$rows   = [];

		foreach ( $posted as $row ) {
			if ( ! is_array( $row ) || ! empty( $row['remove'] ) ) {
				continue;
			}

			$clean = Mappings::sanitise_row( $row );

			if ( null !== $clean ) {
				$rows[] = $clean;
			}
		}

		Mappings::save( $rows );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ) );
		exit;
	}

	/**
	 * Re-queue every failed push.
	 */
	public function retry_failed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_forms_retry' );

		( new Pusher() )->retry_failed();

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ) );
		exit;
	}

	/**
	 * Delete every failed entry.
	 */
	public function clear_failed(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'emailexpert-events' ) );
		}

		check_admin_referer( 'eex_forms_clear' );

		foreach ( array_keys( Queue::with_status( 'failed' ) ) as $id ) {
			Queue::delete( (string) $id );
		}

		\Emailexpert\Events\Admin\Notices::remove( 'forms_push_failed' );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'options-general.php?page=emailexpert-events-bridge' ) ) );
		exit;
	}

	/**
	 * Known events for the picker, newest first; empty when the API cannot
	 * answer (the row falls back to a text input).
	 *
	 * @return array<int,array<string,string>>
	 */
	private function known_events(): array {
		$out = [];

		foreach ( (array) Repositories::current()->all_events( [] ) as $event ) {
			$hs_id = (string) ( $event['hs_id'] ?? '' );

			if ( '' !== $hs_id ) {
				$out[] = [
					'id'    => $hs_id,
					'title' => (string) ( $event['title'] ?? $hs_id ),
				];
			}
		}

		return $out;
	}

	/**
	 * A recognisable but non-disclosing form of a queued address.
	 *
	 * @param string $email Email address.
	 */
	public static function mask_email( string $email ): string {
		$at = strpos( $email, '@' );

		if ( false === $at || 0 === $at ) {
			return '…';
		}

		return substr( $email, 0, 1 ) . '…' . substr( $email, $at );
	}
}
