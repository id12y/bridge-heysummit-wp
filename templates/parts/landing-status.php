<?php
/**
 * Event status notice: the public postponed/cancelled/scheduled message.
 * Rendered only when there is genuinely something to say. Override by
 * copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $lifecycle Lifecycle reading (EventLifecycle).
 *     @type array $event     Enriched event.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_lifecycle = (array) ( $args['lifecycle'] ?? [] );
$eex_status    = (string) ( $eex_lifecycle['status'] ?? '' );
$eex_notice    = trim( (string) ( $eex_lifecycle['notice'] ?? '' ) );

$eex_leads = [
	'cancelled' => __( 'This event has been cancelled.', 'emailexpert-events' ),
	'postponed' => __( 'This event has been postponed.', 'emailexpert-events' ),
];

$eex_lead = (string) ( $eex_leads[ $eex_status ] ?? '' );

if ( '' === $eex_lead && '' === $eex_notice ) {
	return;
}
?>
<div class="eex-el__status eex-el__status--<?php echo esc_attr( '' !== $eex_status ? $eex_status : 'note' ); ?>" role="note">
	<?php if ( '' !== $eex_lead ) : ?>
		<p class="eex-el__status-lead"><?php echo esc_html( $eex_lead ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $eex_notice ) : ?>
		<p class="eex-el__status-message"><?php echo esc_html( $eex_notice ); ?></p>
	<?php endif; ?>
</div>
