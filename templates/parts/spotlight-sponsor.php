<?php
/**
 * Sponsor spotlight: one sponsor with the rich fields the wall has no room
 * for — promo banner, intro video, full description, actions.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $sponsor Sponsor data (see Data\Sponsors::for_display()).
 *     @type string $layout  card|banner|full.
 *     @type array  $show    Toggles: banner, video, description, website, books, phone.
 * }
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

$eex_sponsor = (array) ( $args['sponsor'] ?? [] );

if ( empty( $eex_sponsor['name'] ) ) {
	return;
}

$eex_layout = (string) ( $args['layout'] ?? 'card' );
if ( ! in_array( $eex_layout, [ 'card', 'banner', 'full' ], true ) ) {
	$eex_layout = 'card';
}

$eex_show = array_merge(
	[
		'banner'      => true,
		'video'       => true,
		'description' => true,
		'website'     => true,
		'books'       => false,
		'phone'       => false,
	],
	(array) ( $args['show'] ?? [] )
);

$eex_name   = (string) $eex_sponsor['name'];
$eex_url    = (string) ( $eex_sponsor['url'] ?? '' );
$eex_banner = $eex_show['banner'] ? (string) ( $eex_sponsor['banner'] ?? '' ) : '';
$eex_video  = $eex_show['video'] ? Components::video_embed_url( (array) ( $eex_sponsor['video'] ?? [] ) ) : '';
$eex_long   = $eex_show['description'] ? (string) ( $eex_sponsor['long_blurb'] ?? '' ) : '';
$eex_blurb  = (string) ( $eex_sponsor['blurb'] ?? '' );
$eex_books  = $eex_show['books'] ? (string) ( $eex_sponsor['books_url'] ?? '' ) : '';
$eex_phone  = $eex_show['phone'] ? (string) ( $eex_sponsor['phone'] ?? '' ) : '';

$eex_link_label = (string) ( $eex_sponsor['link_title'] ?? '' );
if ( '' === $eex_link_label ) {
	$eex_link_label = __( 'Visit website', 'emailexpert-events' );
}

$eex_logo_url = (string) ( $eex_sponsor['logo_url'] ?? '' );
$eex_logo_id  = (int) ( $eex_sponsor['logo_id'] ?? 0 );

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
<article class="eex-card eex-sponsor-spotlight eex-sponsor-spotlight-<?php echo esc_attr( $eex_layout ); ?>">
	<?php if ( '' !== $eex_banner ) : ?>
		<div class="eex-spotlight-banner">
			<img src="<?php echo esc_url( $eex_banner ); ?>" alt="" loading="lazy" />
		</div>
	<?php endif; ?>

	<div class="eex-spotlight-body">
		<div class="eex-spotlight-identity">
			<?php if ( '' !== $eex_logo ) : ?>
				<span class="eex-sponsor-row-logo"><?php echo $eex_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above. ?></span>
			<?php endif; ?>
			<h3 class="eex-spotlight-name"><?php echo esc_html( $eex_name ); ?></h3>
		</div>

		<?php if ( 'banner' !== $eex_layout && '' !== $eex_video ) : ?>
			<div class="eex-spotlight-video">
				<iframe src="<?php echo esc_url( $eex_video ); ?>" title="<?php echo esc_attr( $eex_name ); ?>" loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
			</div>
		<?php endif; ?>

		<?php if ( 'full' === $eex_layout && '' !== $eex_long ) : ?>
			<div class="eex-spotlight-description">
				<?php echo wp_kses_post( $eex_long ); // Sponsor descriptions carry their own markup. ?>
			</div>
		<?php elseif ( '' !== $eex_blurb ) : ?>
			<p class="eex-sponsor-blurb"><?php echo esc_html( $eex_blurb ); ?></p>
		<?php endif; ?>

		<p class="eex-card-actions">
			<?php if ( $eex_show['website'] && '' !== $eex_url ) : ?>
				<a class="eex-cta" href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener"><?php echo esc_html( $eex_link_label ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_books ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( $eex_books ); ?>" rel="sponsored noopener"><?php esc_html_e( 'Book a meeting', 'emailexpert-events' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_phone ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $eex_phone ) ); ?>"><?php echo esc_html( $eex_phone ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</article>
