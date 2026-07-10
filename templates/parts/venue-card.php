<?php
/**
 * Venue card: the event's physical venue with granular pieces — image,
 * name, address lines, map link — each individually toggleable.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type string $heading   Card heading.
 *     @type string $name      Venue name ('' = hidden or unknown).
 *     @type array  $lines     Address lines ([] = hidden or unknown).
 *     @type string $map_url   Directions URL ('' = no link).
 *     @type string $image_url Venue image URL ('' = no image).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_name    = (string) ( $args['name'] ?? '' );
$eex_lines   = array_values( array_filter( array_map( 'strval', (array) ( $args['lines'] ?? [] ) ) ) );
$eex_map_url = (string) ( $args['map_url'] ?? '' );
$eex_image   = (string) ( $args['image_url'] ?? '' );

if ( '' === $eex_name && empty( $eex_lines ) && '' === $eex_map_url ) {
	return;
}
?>
<article class="eex-card eex-venue-card">
	<?php if ( '' !== $eex_image ) : ?>
		<div class="eex-card-image eex-venue-image">
			<img src="<?php echo esc_url( $eex_image ); ?>" alt="<?php echo esc_attr( $eex_name ); ?>" loading="lazy" />
		</div>
	<?php endif; ?>
	<h3 class="eex-card-title"><?php echo esc_html( (string) ( $args['heading'] ?? '' ) ); ?></h3>
	<?php if ( '' !== $eex_name ) : ?>
		<p class="eex-venue-name"><?php echo esc_html( $eex_name ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $eex_lines ) ) : ?>
		<p class="eex-venue-address">
			<?php echo implode( '<br />', array_map( 'esc_html', $eex_lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line above. ?>
		</p>
	<?php endif; ?>
	<?php if ( '' !== $eex_map_url ) : ?>
		<p class="eex-card-actions">
			<a class="eex-cta-secondary" href="<?php echo esc_url( $eex_map_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Directions', 'emailexpert-events' ); ?></a>
		</p>
	<?php endif; ?>
</article>
