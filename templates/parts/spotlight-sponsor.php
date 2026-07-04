<?php
/**
 * Sponsor spotlight: one sponsor with the rich fields the wall has no room
 * for — promo banner, intro video, full description, actions.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $sponsor            Sponsor data (see Data\Sponsors::for_display()).
 *     @type string $layout             card|banner|full.
 *     @type array  $show               Toggles: logo, name, blurb, banner, video, description, website, books, phone.
 *     @type int    $blurb_length       Short-description character cap (0 = full).
 *     @type int    $description_length Full-description character cap (0 = full, with formatting).
 *     @type string $website_text       Website button label override ('' = the sponsor's own CTA).
 *     @type string $books_text         Booking button label override ('' = "Book a meeting").
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
		'logo'        => true,
		'name'        => true,
		'blurb'       => true,
		'banner'      => true,
		'video'       => true,
		'description' => true,
		'website'     => true,
		'books'       => false,
		'phone'       => false,
	],
	(array) ( $args['show'] ?? [] )
);

$eex_blurb_length = (int) ( $args['blurb_length'] ?? 0 );
$eex_long_length  = (int) ( $args['description_length'] ?? 0 );
$eex_target       = ! empty( $args['new_tab'] ) ? ' target="_blank"' : '';

$eex_name   = (string) $eex_sponsor['name'];
$eex_url    = (string) ( $eex_sponsor['url'] ?? '' );
$eex_banner = $eex_show['banner'] ? (string) ( $eex_sponsor['banner'] ?? '' ) : '';
$eex_video  = $eex_show['video'] ? Components::video_embed_url( (array) ( $eex_sponsor['video'] ?? [] ) ) : '';
$eex_long   = $eex_show['description'] ? (string) ( $eex_sponsor['long_blurb'] ?? '' ) : '';
$eex_blurb  = $eex_show['blurb'] ? Components::truncate( (string) ( $eex_sponsor['blurb'] ?? '' ), $eex_blurb_length ) : '';
$eex_books  = $eex_show['books'] ? (string) ( $eex_sponsor['books_url'] ?? '' ) : '';
$eex_phone  = $eex_show['phone'] ? (string) ( $eex_sponsor['phone'] ?? '' ) : '';

// A capped full description loses its markup deliberately: cutting HTML at
// a character count risks broken tags, so the cap renders plain text.
$eex_long_plain = '';
if ( '' !== $eex_long && $eex_long_length > 0 ) {
	$eex_long_plain = Components::truncate( wp_strip_all_tags( $eex_long ), $eex_long_length );
	$eex_long       = '';
}

// The website button label: the operator's override wins over the CTA the
// sponsor set in HeySummit, which wins over the generic fallback.
$eex_link_label = trim( (string) ( $args['website_text'] ?? '' ) );
if ( '' === $eex_link_label ) {
	$eex_link_label = (string) ( $eex_sponsor['link_title'] ?? '' );
}
if ( '' === $eex_link_label ) {
	$eex_link_label = __( 'Visit website', 'emailexpert-events' );
}

$eex_books_label = trim( (string) ( $args['books_text'] ?? '' ) );
if ( '' === $eex_books_label ) {
	$eex_books_label = __( 'Book a meeting', 'emailexpert-events' );
}

$eex_logo_url = (string) ( $eex_sponsor['logo_url'] ?? '' );
$eex_logo_id  = (int) ( $eex_sponsor['logo_id'] ?? 0 );

$eex_logo = '';
if ( ! $eex_show['logo'] ) {
	$eex_logo = '';
} elseif ( $eex_logo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
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
		<?php if ( '' !== $eex_logo || $eex_show['name'] ) : ?>
			<div class="eex-spotlight-identity">
				<?php if ( '' !== $eex_logo ) : ?>
					<span class="eex-sponsor-row-logo"><?php echo $eex_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- image markup escaped above. ?></span>
				<?php endif; ?>
				<?php if ( $eex_show['name'] ) : ?>
					<h3 class="eex-spotlight-name"><?php echo esc_html( $eex_name ); ?></h3>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( 'banner' !== $eex_layout && '' !== $eex_video ) : ?>
			<div class="eex-spotlight-video">
				<iframe src="<?php echo esc_url( $eex_video ); ?>" title="<?php echo esc_attr( $eex_name ); ?>" loading="lazy" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
			</div>
		<?php endif; ?>

		<?php if ( 'full' === $eex_layout && '' !== $eex_long ) : ?>
			<div class="eex-spotlight-description">
				<?php echo wp_kses_post( $eex_long ); // Sponsor descriptions carry their own markup. ?>
			</div>
		<?php elseif ( 'full' === $eex_layout && '' !== $eex_long_plain ) : ?>
			<div class="eex-spotlight-description">
				<p><?php echo esc_html( $eex_long_plain ); ?></p>
			</div>
		<?php elseif ( '' !== $eex_blurb ) : ?>
			<p class="eex-sponsor-blurb"><?php echo esc_html( $eex_blurb ); ?></p>
		<?php endif; ?>

		<p class="eex-card-actions">
			<?php if ( $eex_show['website'] && '' !== $eex_url ) : ?>
				<a class="eex-cta" href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener"<?php echo $eex_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute. ?>><?php echo esc_html( $eex_link_label ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_books ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( $eex_books ); ?>" rel="sponsored noopener"<?php echo $eex_target; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute. ?>><?php echo esc_html( $eex_books_label ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_phone ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $eex_phone ) ); ?>"><?php echo esc_html( $eex_phone ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</article>
