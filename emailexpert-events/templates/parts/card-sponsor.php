<?php
/**
 * Sponsor card: logo linking out with rel="sponsored noopener".
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type int $sponsor_id Sponsor post ID.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_sponsor_id = (int) ( $args['sponsor_id'] ?? 0 );

if ( 0 === $eex_sponsor_id ) {
	return;
}

$eex_name    = get_the_title( $eex_sponsor_id );
$eex_url     = (string) get_post_meta( $eex_sponsor_id, '_eex_url', true );
$eex_logo_id = (int) get_post_meta( $eex_sponsor_id, '_eex_logo_attachment_id', true );
$eex_blurb   = (string) get_post_meta( $eex_sponsor_id, '_eex_blurb', true );

$eex_logo = '';
if ( $eex_logo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
	$eex_logo = wp_get_attachment_image(
		$eex_logo_id,
		'medium',
		false,
		[
			'class'   => 'eex-sponsor-logo',
			'loading' => 'lazy',
			'alt'     => $eex_name,
		]
	);
}
?>
<article class="eex-card eex-card-sponsor">
	<?php if ( '' !== $eex_url ) : ?>
		<a href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener">
			<?php echo $eex_logo ?: '<span class="eex-sponsor-name">' . esc_html( $eex_name ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated image markup or escaped name. ?>
		</a>
	<?php else : ?>
		<?php echo $eex_logo ?: '<span class="eex-sponsor-name">' . esc_html( $eex_name ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated image markup or escaped name. ?>
	<?php endif; ?>
	<?php if ( '' !== $eex_blurb ) : ?>
		<p class="eex-sponsor-blurb"><?php echo esc_html( $eex_blurb ); ?></p>
	<?php endif; ?>
</article>
