<?php
/**
 * Sponsor card: logo linking out with rel="sponsored noopener".
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $sponsor Sponsor data array (see Data\Repository).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_sponsor = (array) ( $args['sponsor'] ?? [] );

// Back-compat for overrides passing a post ID.
if ( empty( $eex_sponsor ) && ! empty( $args['sponsor_id'] ) ) {
	$eex_repo    = new \Emailexpert\Events\Data\SyncedRepository();
	$eex_matches = array_filter( $eex_repo->sponsors( [] ), static fn( array $s ): bool => (int) $s['id'] === (int) $args['sponsor_id'] );
	$eex_sponsor = $eex_matches ? reset( $eex_matches ) : [];
}

if ( empty( $eex_sponsor['name'] ) ) {
	return;
}

$eex_show = array_merge(
	[
		'names' => true,
		'blurb' => true,
	],
	(array) ( $args['show'] ?? [] )
);

$eex_name     = (string) $eex_sponsor['name'];
$eex_url      = (string) ( $eex_sponsor['url'] ?? '' );
$eex_logo_id  = (int) ( $eex_sponsor['logo_id'] ?? 0 );
$eex_logo_url = (string) ( $eex_sponsor['logo_url'] ?? '' );
$eex_blurb    = (string) ( $eex_sponsor['blurb'] ?? '' );

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
} elseif ( '' !== $eex_logo_url ) {
	$eex_logo = '<img class="eex-sponsor-logo" loading="lazy" src="' . esc_url( $eex_logo_url ) . '" alt="' . esc_attr( $eex_name ) . '" />';
}
?>
<?php
// The logo is the card; the name rides under it unless hidden (or stands
// in when there is no logo at all — a nameless, logoless card is nothing).
$eex_visual = $eex_logo ?: '<span class="eex-sponsor-name">' . esc_html( $eex_name ) . '</span>';
?>
<article class="eex-card eex-card-sponsor">
	<?php if ( '' !== $eex_url ) : ?>
		<a href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener" aria-label="<?php echo esc_attr( $eex_name ); ?>">
			<?php echo $eex_visual; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above or escaped name. ?>
		</a>
	<?php else : ?>
		<?php echo $eex_visual; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above or escaped name. ?>
	<?php endif; ?>
	<?php if ( $eex_show['names'] && '' !== $eex_logo ) : ?>
		<p class="eex-sponsor-card-name"><?php echo esc_html( $eex_name ); ?></p>
	<?php endif; ?>
	<?php if ( $eex_show['blurb'] && '' !== $eex_blurb ) : ?>
		<p class="eex-sponsor-blurb"><?php echo esc_html( $eex_blurb ); ?></p>
	<?php endif; ?>
</article>
