<?php
/**
 * Sticky register bar. Rendered in normal flow (the no-JS presentation);
 * eex-time.js pins it to its position and reveals it after the scroll
 * offset. Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type string $id          Unique bar id (dismissal storage key).
 *     @type string $text        Bar text.
 *     @type string $label       CTA label.
 *     @type string $url         CTA destination (no-JS / link mode).
 *     @type string $position    'top' or 'bottom'.
 *     @type int    $offset      Reveal after this many scrolled pixels.
 *     @type bool   $dismissible Show a dismiss button.
 *     @type string $countdown   Rendered countdown fragment ('' = none).
 *     @type array  $session     Talk data for the live flip ([] = none).
 *     @type string $drawer_id   Ticket drawer id ('' = plain link).
 * }
 */

defined( 'ABSPATH' ) || exit;

use Emailexpert\Events\Frontend\Components;

$eex_id      = (string) ( $args['id'] ?? '' );
$eex_url     = (string) ( $args['url'] ?? '' );
$eex_session = (array) ( $args['session'] ?? [] );

if ( '' === $eex_id || '' === $eex_url ) {
	return;
}
?>
<div
	class="eex-register-bar eex-bar-<?php echo esc_attr( (string) ( $args['position'] ?? 'bottom' ) ); ?>"
	id="<?php echo esc_attr( $eex_id ); ?>"
	role="region"
	aria-label="<?php esc_attr_e( 'Event registration', 'emailexpert-events' ); ?>"
	data-eex-register-bar="1"
	data-eex-bar-offset="<?php echo (int) ( $args['offset'] ?? 400 ); ?>"
	<?php echo ! empty( $eex_session ) ? Components::session_attrs( $eex_session ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
>
	<div class="eex-bar-body">
		<p class="eex-bar-text">
			<strong><?php echo esc_html( (string) ( $args['text'] ?? '' ) ); ?></strong>
			<span data-eex-live-slot hidden></span>
			<?php echo (string) ( $args['countdown'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered, escaped fragment. ?>
		</p>
		<p class="eex-bar-actions">
			<?php $eex_rsvp = (array) ( $args['rsvp'] ?? [] ); ?>
			<?php if ( ! empty( $eex_rsvp ) ) : ?>
				<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" href="<?php echo esc_url( $eex_url ); ?>"><?php echo esc_html( (string) ( $args['label'] ?? '' ) ); ?></a>
			<?php else : ?>
				<a
					class="eex-cta eex-cta-register"
					data-eex-cta="1"
					<?php echo '' !== (string) ( $args['drawer_id'] ?? '' ) ? 'data-eex-drawer="' . esc_attr( (string) $args['drawer_id'] ) . '" ' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
					href="<?php echo esc_url( $eex_url ); ?>"
				><?php echo esc_html( (string) ( $args['label'] ?? '' ) ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $args['dismissible'] ) ) : ?>
				<button type="button" class="eex-bar-dismiss" data-eex-bar-dismiss="1" aria-label="<?php esc_attr_e( 'Dismiss this bar', 'emailexpert-events' ); ?>">&#215;</button>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $eex_rsvp ) ) : ?>
			<?php
			// The bar registers for the event; when it is showing a live/next
			// session, that session lands on the schedule too.
			\Emailexpert\Events\Frontend\TemplateLoader::part(
				'register-form',
				[
					'event_id'    => (string) ( $eex_rsvp['event_id'] ?? '' ),
					'ticket_id'   => (string) ( $eex_rsvp['ticket_id'] ?? '' ),
					'price_id'    => (string) ( $eex_rsvp['price_id'] ?? '' ),
					'talk_id'     => (string) ( $eex_session['hs_id'] ?? '' ),
					'submit_text' => __( 'RSVP', 'emailexpert-events' ),
					'hidden'      => true,
				]
			);
			?>
		<?php endif; ?>
	</div>
</div>
