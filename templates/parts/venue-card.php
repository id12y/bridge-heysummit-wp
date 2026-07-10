<?php
/**
 * Venue card: the event's physical address with an optional map link.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type string $heading Card heading.
 *     @type array  $fields  name/street/locality/postcode/country strings.
 *     @type string $map_url Directions URL ('' = no link).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_fields = (array) ( $args['fields'] ?? [] );
$eex_name   = (string) ( $eex_fields['name'] ?? '' );

$eex_address = array_values(
	array_filter(
		[
			(string) ( $eex_fields['street'] ?? '' ),
			trim( implode( ' ', array_filter( [ (string) ( $eex_fields['locality'] ?? '' ), (string) ( $eex_fields['postcode'] ?? '' ) ] ) ) ),
			(string) ( $eex_fields['country'] ?? '' ),
		]
	)
);

if ( '' === $eex_name && empty( $eex_address ) ) {
	return;
}
?>
<article class="eex-card eex-venue-card">
	<h3 class="eex-card-title"><?php echo esc_html( (string) ( $args['heading'] ?? '' ) ); ?></h3>
	<?php if ( '' !== $eex_name ) : ?>
		<p class="eex-venue-name"><?php echo esc_html( $eex_name ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $eex_address ) ) : ?>
		<p class="eex-venue-address">
			<?php echo implode( '<br />', array_map( 'esc_html', $eex_address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line above. ?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== (string) ( $args['map_url'] ?? '' ) ) : ?>
		<p class="eex-card-actions">
			<a class="eex-cta-secondary" href="<?php echo esc_url( (string) $args['map_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Directions', 'emailexpert-events' ); ?></a>
		</p>
	<?php endif; ?>
</article>
