<?php
/**
 * Event card.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $event   Event data array (see Data\Repository).
 *     @type string $context 'upcoming' or 'past'.
 * }
 */

use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_event   = (array) ( $args['event'] ?? [] );
$eex_context = (string) ( $args['context'] ?? 'upcoming' );

// Back-compat for overrides passing a post ID.
if ( empty( $eex_event ) && ! empty( $args['event_id'] ) ) {
	$eex_event = \Emailexpert\Events\Data\SyncedRepository::event_data( (int) $args['event_id'] );
}

if ( empty( $eex_event['title'] ) && empty( $eex_event['id'] ) ) {
	return;
}

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = __( 'Register', 'emailexpert-events' );
}

$eex_first = (string) ( $eex_event['first_talk_at'] ?? '' );
$eex_tz    = (string) ( $eex_event['timezone'] ?? '' );
$eex_open  = ! empty( $eex_event['open'] );
$eex_url   = (string) ( $eex_event['event_url'] ?? '' );
$eex_venue = (string) ( $eex_event['venue'] ?? '' );
?>
<article class="eex-card eex-card-event eex-context-<?php echo esc_attr( $eex_context ); ?>">
	<h3 class="eex-card-title">
		<a href="<?php echo esc_url( (string) ( $eex_event['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $eex_event['title'] ?? '' ) ); ?></a>
	</h3>

	<?php if ( ! empty( $eex_event['series'] ) ) : ?>
		<p class="eex-badges">
			<?php foreach ( (array) $eex_event['series'] as $eex_term ) : ?>
				<span class="eex-badge eex-badge-series-<?php echo esc_attr( (string) $eex_term['slug'] ); ?>"><?php echo esc_html( (string) $eex_term['name'] ); ?></span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $eex_first ) : ?>
		<p class="eex-card-time"><?php echo TimeFormat::render( $eex_first, $eex_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></p>
	<?php endif; ?>

	<?php if ( '' !== $eex_venue ) : ?>
		<p class="eex-card-venue"><?php echo esc_html( $eex_venue ); ?></p>
	<?php endif; ?>

	<p class="eex-card-actions">
		<?php if ( 'upcoming' === $eex_context && $eex_open && '' !== $eex_url ) : ?>
			<a class="eex-cta eex-cta-register" href="<?php echo esc_url( $eex_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
		<?php endif; ?>
		<a class="eex-cta-secondary" href="<?php echo esc_url( (string) ( $eex_event['url'] ?? '' ) ); ?>"><?php esc_html_e( 'View details', 'emailexpert-events' ); ?></a>
	</p>
</article>
