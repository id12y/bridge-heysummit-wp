<?php
/**
 * Account-registration consent sources.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Consent is a hard rule: no user is ever pushed without a satisfied
 * consent source. Two sources exist, chosen per rule:
 *
 * (a) `checkbox` — the `eex_event_consent` user meta (a consent timestamp),
 *     set by the registration-form checkboxes this class renders for the
 *     WordPress core and WooCommerce registration forms, or by any form
 *     builder writing that documented meta key.
 * (b) `assertion` — a deliberately worded operator setting confirming the
 *     site's registration terms cover event registration and event-related
 *     email, stored with who enabled it and when.
 *
 * The per-user profile opt-out overrides both by writing a suppression
 * entry, checked separately before every push.
 */
final class Consent {

	public const META_KEY         = 'eex_event_consent';
	public const OPT_OUT_META_KEY = 'eex_events_opt_out';
	private const ASSERTION       = 'eex_consent_assertion';

	/**
	 * Hook up form integrations and the profile opt-out.
	 */
	public function register(): void {
		// WordPress core registration form.
		add_action( 'register_form', [ $this, 'render_core_checkbox' ] );
		add_action( 'user_register', [ $this, 'save_from_registration' ], 5 );

		// WooCommerce registration form (hooks are no-ops without Woo).
		add_action( 'woocommerce_register_form', [ $this, 'render_core_checkbox' ] );
		add_action( 'woocommerce_created_customer', [ $this, 'save_from_registration' ], 5 );

		// Profile opt-out field.
		add_action( 'show_user_profile', [ $this, 'render_opt_out' ] );
		add_action( 'edit_user_profile', [ $this, 'render_opt_out' ] );
		add_action( 'personal_options_update', [ $this, 'save_opt_out' ] );
		add_action( 'edit_user_profile_update', [ $this, 'save_opt_out' ] );
	}

	/**
	 * Whether a rule's consent source is satisfied for a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $source  'checkbox' or 'assertion'.
	 * @return array{ok:bool,source:string} ok + the recorded justification.
	 */
	public static function satisfied( int $user_id, string $source ): array {
		if ( 'assertion' === $source ) {
			$assertion = self::assertion();

			return [
				'ok'     => ! empty( $assertion['enabled'] ),
				'source' => 'assertion:' . (string) ( $assertion['by'] ?? '' ) . '@' . (string) ( $assertion['at'] ?? '' ),
			];
		}

		$stamp = (string) get_user_meta( $user_id, self::META_KEY, true );

		return [
			'ok'     => '' !== $stamp,
			'source' => 'checkbox:' . $stamp,
		];
	}

	/**
	 * The operator assertion record.
	 *
	 * @return array<string,mixed> enabled, by, at.
	 */
	public static function assertion(): array {
		return (array) get_option( self::ASSERTION, [] );
	}

	/**
	 * Store the operator assertion (who + when), or clear it.
	 *
	 * @param bool   $enabled    Enabled.
	 * @param string $user_login Who enabled it.
	 */
	public static function set_assertion( bool $enabled, string $user_login ): void {
		update_option(
			self::ASSERTION,
			$enabled
				? [
					'enabled' => 1,
					'by'      => $user_login,
					'at'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
				]
				: [],
			false
		);
	}

	/**
	 * The registration-form checkbox (core and WooCommerce forms share it).
	 */
	public function render_core_checkbox(): void {
		?>
		<p class="eex-consent-field">
			<label>
				<input type="checkbox" name="eex_event_consent" value="1" <?php checked( ! empty( $_POST['eex_event_consent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- re-population only; core validates its own registration nonce. ?> />
				<?php echo esc_html( (string) Options::setting( 'woo_consent_text' ) ); ?>
			</label>
		</p>
		<?php
	}

	/**
	 * Persist the checkbox as the documented consent meta.
	 *
	 * @param int $user_id New user ID.
	 */
	public function save_from_registration( $user_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the registration form's own nonce/flow has already run.
		if ( ! empty( $_POST['eex_event_consent'] ) ) {
			update_user_meta( (int) $user_id, self::META_KEY, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		}
	}

	/**
	 * The profile opt-out field.
	 *
	 * @param \WP_User $user Profile being edited.
	 */
	public function render_opt_out( $user ): void {
		wp_nonce_field( 'eex_opt_out', 'eex_opt_out_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Event registration', 'emailexpert-events' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="eex_events_opt_out" value="1" <?php checked( '' !== (string) get_user_meta( (int) $user->ID, self::OPT_OUT_META_KEY, true ) ); ?> />
						<?php esc_html_e( 'Do not register me for events', 'emailexpert-events' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Stops every automatic HeySummit registration for this account, permanently, until unticked.', 'emailexpert-events' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save the opt-out; opting out writes an all-events suppression entry
	 * immediately.
	 *
	 * @param int $user_id Profile user ID.
	 */
	public function save_opt_out( $user_id ): void {
		$user_id = (int) $user_id;

		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['eex_opt_out_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['eex_opt_out_nonce'] ), 'eex_opt_out' ) ) {
			return;
		}

		$user = get_userdata( $user_id );

		if ( ! empty( $_POST['eex_events_opt_out'] ) ) {
			update_user_meta( $user_id, self::OPT_OUT_META_KEY, gmdate( 'Y-m-d\TH:i:s\Z' ) );
			if ( $user ) {
				Suppression::add( (string) $user->user_email, Suppression::ALL_EVENTS, 'opt_out' );
			}
		} else {
			delete_user_meta( $user_id, self::OPT_OUT_META_KEY );
			// Deliberately does NOT remove the suppression entry: unticking
			// re-allows future consent, but re-adding to events needs the
			// operator to clear the suppression row consciously.
		}
	}
}
