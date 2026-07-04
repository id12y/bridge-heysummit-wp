<?php
/**
 * Sponsor list row (layout="list"): logo and name side by side.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $sponsor Sponsor data array (see Data\Repository).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_sponsor = (array) ( $args['sponsor'] ?? [] );

if ( empty( $eex_sponsor['name'] ) ) {
	return;
}

$eex_show = array_merge(
	[
		'names' => true,
		'blurb' => false,
	],
	(array) ( $args['show'] ?? [] )
);

$eex_name     = (string) $eex_sponsor['name'];
$eex_url      = (string) ( $eex_sponsor['url'] ?? '' );
$eex_logo_id  = (int) ( $eex_sponsor['logo_id'] ?? 0 );
$eex_logo_url = (string) ( $eex_sponsor['logo_url'] ?? '' );

$eex_logo = '';
if ( $eex_logo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
	$eex_logo = wp_get_attachment_image(
		$eex_logo_id,
		'thumbnail',
		false,
		[
			'class'   => 'eex-sponsor-logo',
			'loading' => 'lazy',
			'alt'     => $eex_name,
		]
	);
} elseif ( '' !== $eex_logo_url ) {
	$eex_logo = '<img class="eex-sponsor-logo" loading="lazy" src="' . esc_url( $eex_logo_url ) . '" alt="' . esc_attr( $eex_name ) . '" />';
}
?>
<article class="eex-list-row eex-sponsor-row">
	<?php if ( '' !== $eex_logo ) : ?>
		<?php if ( ! $eex_show['names'] && '' !== $eex_url ) : ?>
			<a class="eex-sponsor-row-logo" href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener" aria-label="<?php echo esc_attr( $eex_name ); ?>"><?php echo $eex_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above. ?></a>
		<?php else : ?>
			<span class="eex-sponsor-row-logo"><?php echo $eex_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above. ?></span>
		<?php endif; ?>
	<?php endif; ?>
	<span class="eex-list-main">
		<?php if ( $eex_show['names'] ) : ?>
			<?php if ( '' !== $eex_url ) : ?>
				<a class="eex-list-title" href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener"><?php echo esc_html( $eex_name ); ?></a>
			<?php else : ?>
				<span class="eex-list-title"><?php echo esc_html( $eex_name ); ?></span>
			<?php endif; ?>
		<?php elseif ( '' !== $eex_url && '' !== $eex_logo ) : ?>
			<?php /* Logo-only walls keep the link on the logo via the row below. */ ?>
		<?php endif; ?>
		<?php if ( $eex_show['blurb'] && '' !== (string) ( $eex_sponsor['blurb'] ?? '' ) ) : ?>
			<span class="eex-sponsor-blurb"><?php echo esc_html( (string) $eex_sponsor['blurb'] ); ?></span>
		<?php endif; ?>
	</span>
</article>
