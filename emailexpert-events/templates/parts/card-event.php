<?php
/**
 * Event card.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type int    $event_id Event post ID.
 *     @type string $context  'upcoming' or 'past'.
 * }
 */

use Emailexpert\Events\Frontend\TimeFormat;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

$eex_event_id = (int) ( $args['event_id'] ?? 0 );
$eex_context  = (string) ( $args['context'] ?? 'upcoming' );

if ( 0 === $eex_event_id ) {
	return;
}

$eex_first = (string) get_post_meta( $eex_event_id, '_eex_first_talk_at', true );
$eex_tz    = (string) get_post_meta( $eex_event_id, '_eex_timezone', true );
$eex_open  = (bool) get_post_meta( $eex_event_id, '_eex_is_open_for_registrations', true );
$eex_url   = (string) get_post_meta( $eex_event_id, '_eex_event_url', true );
$eex_venue = (string) get_post_meta( $eex_event_id, '_eex_venue_name', true );
$eex_terms = get_the_terms( $eex_event_id, Taxonomies::SERIES );
?>
<article class="eex-card eex-card-event eex-context-<?php echo esc_attr( $eex_context ); ?>">
	<h3 class="eex-card-title">
		<a href="<?php echo esc_url( (string) get_permalink( $eex_event_id ) ); ?>"><?php echo esc_html( get_the_title( $eex_event_id ) ); ?></a>
	</h3>

	<?php if ( is_array( $eex_terms ) ) : ?>
		<p class="eex-badges">
			<?php foreach ( $eex_terms as $eex_term ) : ?>
				<span class="eex-badge eex-badge-series-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
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
			<a class="eex-cta eex-cta-register" href="<?php echo esc_url( $eex_url ); ?>"><?php esc_html_e( 'Register', 'emailexpert-events' ); ?></a>
		<?php endif; ?>
		<a class="eex-cta-secondary" href="<?php echo esc_url( (string) get_permalink( $eex_event_id ) ); ?>"><?php esc_html_e( 'View details', 'emailexpert-events' ); ?></a>
	</p>
</article>
