<?php
/**
 * Final event CTA: the landing page's closing action. Follows the same
 * lifecycle-aware CTA model as the hero, so an ended event closes with
 * replays, never a dead register button. Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $target      Feature target.
 *     @type array  $event       Enriched event.
 *     @type array  $cta         CTA view model (CtaResolver).
 *     @type string $drawer      Ticket panel element ID ('' = plain link).
 *     @type array  $rsvp        RSVP form context ([] = none).
 *     @type string $title       Heading ('' = automatic).
 *     @type string $heading_tag Heading tag.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_event = (array) ( $args['event'] ?? [] );
$eex_cta   = (array) ( $args['cta'] ?? [] );

$eex_primary   = isset( $eex_cta['primary'] ) && is_array( $eex_cta['primary'] ) ? $eex_cta['primary'] : null;
$eex_secondary = isset( $eex_cta['secondary'] ) && is_array( $eex_cta['secondary'] ) ? $eex_cta['secondary'] : null;

if ( null === $eex_primary && null === $eex_secondary ) {
	return;
}

$eex_target    = (array) ( $args['target'] ?? [] );
$eex_session   = isset( $eex_target['session'] ) && is_array( $eex_target['session'] ) ? $eex_target['session'] : null;
$eex_status    = (string) ( $eex_target['lifecycle']['status'] ?? '' );
$eex_drawer_id = (string) ( $args['drawer'] ?? '' );
$eex_tag       = in_array( (string) ( $args['heading_tag'] ?? 'h2' ), [ 'h2', 'h3' ], true ) ? (string) $args['heading_tag'] : 'h2';

$eex_title = trim( (string) ( $args['title'] ?? '' ) );
if ( '' === $eex_title ) {
	if ( in_array( $eex_status, [ 'ended', 'replay' ], true ) ) {
		$eex_title = __( 'Catch up on the sessions', 'emailexpert-events' );
	} else {
		$eex_title = sprintf(
			/* translators: %s: event title. */
			__( 'Join us at %s', 'emailexpert-events' ),
			(string) ( $eex_event['title'] ?? '' )
		);
	}
}
?>
<section class="eex-el__section eex-el__section--final-cta">
	<div class="eex-el__final">
		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-el__final-title"><?php echo esc_html( $eex_title ); ?></<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>
		<p class="eex-card-actions">
			<?php if ( null !== $eex_primary ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo ! empty( $eex_primary['drawer'] ) && '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['hs_id'] ?? '' ) : '' ) . '" data-eex-talk-title="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['title'] ?? '' ) : '' ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" data-eex-position="final-cta" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
			<?php endif; ?>
			<?php if ( null !== $eex_secondary ) : ?>
				<a class="eex-cta eex-cta-session" data-eex-action="<?php echo esc_attr( (string) $eex_secondary['action'] ); ?>" data-eex-position="final-cta" href="<?php echo esc_url( (string) $eex_secondary['url'] ); ?>"><?php echo esc_html( (string) $eex_secondary['label'] ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</section>
