<?php
/**
 * The free-ticket registration form, shared by the ticket drawer and the
 * inline registration component. Posts to the plugin's own allowlisted
 * REST endpoint (eex/v1/register); eex-time.js handles submission.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type string $event_id    HeySummit event ID.
 *     @type string $ticket_id   HeySummit ticket ID.
 *     @type string $price_id    Ticket price ID ('' when unknown).
 *     @type string $talk_id     Talk to add to the schedule ('' = none; the
 *                               drawer stamps the clicked session in via JS).
 *     @type string $submit_text Submit label ('' = "Complete registration").
 *     @type bool   $hidden      Start hidden (the drawer's toggle reveals it).
 * }
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

$eex_event_id  = (string) ( $args['event_id'] ?? '' );
$eex_ticket_id = (string) ( $args['ticket_id'] ?? '' );

if ( '' === $eex_event_id || '' === $eex_ticket_id ) {
	return;
}

$eex_submit = (string) ( $args['submit_text'] ?? '' );
if ( '' === $eex_submit ) {
	$eex_submit = __( 'Complete registration', 'emailexpert-events' );
}

$eex_marketing_text = Components::consent_marketing_text();
$eex_privacy_url    = function_exists( 'get_privacy_policy_url' ) ? (string) get_privacy_policy_url() : '';

// The page the confirmation link returns to (validated server-side on
// redirect; same for every visitor on this URL, so safe inside cached
// fragments — the fragment cache is already per-page). The full URI is
// kept: on plain permalinks the page identity lives in the query
// string. Our own status flags are stripped so they never accumulate.
$eex_return_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only URL echo.
$eex_return_uri = (string) preg_replace( '/([?&])(eex_reg|eex_confirm)=[^&]*&?/', '$1', $eex_return_uri );
$eex_return     = home_url( rtrim( $eex_return_uri, '?&' ) );
?>
<form class="eex-reg-form" data-eex-reg="1"<?php echo ! empty( $args['hidden'] ) ? ' hidden' : ''; ?>>
	<input type="hidden" name="event" value="<?php echo esc_attr( $eex_event_id ); ?>" />
	<input type="hidden" name="ticket" value="<?php echo esc_attr( $eex_ticket_id ); ?>" />
	<input type="hidden" name="price" value="<?php echo esc_attr( (string) ( $args['price_id'] ?? '' ) ); ?>" />
	<input type="hidden" name="talk" value="<?php echo esc_attr( (string) ( $args['talk_id'] ?? '' ) ); ?>" />
	<input type="hidden" name="return" value="<?php echo esc_url( $eex_return ); ?>" />
	<p class="eex-reg-hp" aria-hidden="true">
		<label><?php esc_html_e( 'Leave this field empty', 'emailexpert-events' ); ?><input type="text" name="website" tabindex="-1" autocomplete="off" /></label>
	</p>
	<p class="eex-reg-field">
		<label><?php esc_html_e( 'Name', 'emailexpert-events' ); ?><input type="text" name="name" required autocomplete="name" /></label>
	</p>
	<p class="eex-reg-field">
		<label><?php esc_html_e( 'Email', 'emailexpert-events' ); ?><input type="email" name="email" required autocomplete="email" /></label>
	</p>
	<p class="eex-reg-consent">
		<label>
			<input type="checkbox" name="consent" value="1" required />
			<?php
			/**
			 * Filter the registration disclosure wording (also stored in the
			 * consent receipt — Settings → Registration edits it without code).
			 *
			 * @param string $text Disclosure text.
			 */
			echo esc_html( Components::consent_disclosure_text() );
			?>
		</label>
	</p>
	<?php if ( '' !== $eex_marketing_text ) : ?>
		<p class="eex-reg-consent eex-reg-marketing">
			<label>
				<?php // Unbundled and never pre-ticked: marketing is a separate, optional choice. ?>
				<input type="checkbox" name="marketing" value="1" />
				<?php echo esc_html( $eex_marketing_text ); ?>
			</label>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $eex_privacy_url ) : ?>
		<p class="eex-reg-privacy"><a href="<?php echo esc_url( $eex_privacy_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Privacy policy', 'emailexpert-events' ); ?></a></p>
	<?php endif; ?>
	<button type="submit" class="eex-cta"><?php echo esc_html( $eex_submit ); ?></button>
	<p class="eex-reg-msg" aria-live="polite"></p>
</form>
