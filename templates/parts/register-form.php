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
?>
<form class="eex-reg-form" data-eex-reg="1"<?php echo ! empty( $args['hidden'] ) ? ' hidden' : ''; ?>>
	<input type="hidden" name="event" value="<?php echo esc_attr( $eex_event_id ); ?>" />
	<input type="hidden" name="ticket" value="<?php echo esc_attr( $eex_ticket_id ); ?>" />
	<input type="hidden" name="price" value="<?php echo esc_attr( (string) ( $args['price_id'] ?? '' ) ); ?>" />
	<input type="hidden" name="talk" value="<?php echo esc_attr( (string) ( $args['talk_id'] ?? '' ) ); ?>" />
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
			echo esc_html(
				(string) apply_filters(
					'eex_register_consent_text',
					__( 'Register me for this event; the organiser may email me about it.', 'emailexpert-events' )
				)
			);
			?>
		</label>
	</p>
	<button type="submit" class="eex-cta"><?php echo esc_html( $eex_submit ); ?></button>
	<p class="eex-reg-msg" aria-live="polite"></p>
</form>
