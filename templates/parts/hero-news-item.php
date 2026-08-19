<?php
/**
 * Latest News item: one compact editorial entry — category eyebrow,
 * headline, date, optional square thumbnail — per the approved design.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $story         Story view model (EditorialSelector).
 *     @type string $heading_tag   Heading tag for the item title.
 *     @type bool   $show_image    Show the compact thumbnail.
 *     @type bool   $show_category Show the category label.
 *     @type bool   $show_date     Show the date.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_story = (array) ( $args['story'] ?? [] );

if ( empty( $eex_story['title'] ) ) {
	return;
}

$eex_tag = in_array( (string) ( $args['heading_tag'] ?? 'h4' ), [ 'h2', 'h3', 'h4', 'h5' ], true ) ? (string) $args['heading_tag'] : 'h4';
$eex_url = (string) ( $eex_story['url'] ?? '' );

$eex_thumb = '';
if ( ! empty( $args['show_image'] ) ) {
	if ( (int) ( $eex_story['image_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
		$eex_thumb = wp_get_attachment_image(
			(int) $eex_story['image_id'],
			'thumbnail',
			false,
			[
				'class'   => 'eex-hh__news-img',
				'loading' => 'lazy',
				'alt'     => '',
			]
		);
	} elseif ( '' !== (string) ( $eex_story['image'] ?? '' ) ) {
		$eex_thumb = '<img class="eex-hh__news-img" loading="lazy" src="' . esc_url( (string) $eex_story['image'] ) . '" alt="" />';
	}
}
?>
<article class="eex-hh__news-card<?php echo '' !== $eex_thumb ? ' eex-hh__news-card--thumb' : ''; ?>">
	<?php echo $eex_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<div class="eex-hh__news-body">
		<?php if ( ! empty( $args['show_category'] ) && '' !== (string) ( $eex_story['category'] ?? '' ) ) : ?>
			<p class="eex-hh__news-category"><?php echo esc_html( (string) $eex_story['category'] ); ?></p>
		<?php endif; ?>

		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__news-title">
			<?php if ( '' !== $eex_url ) : ?>
				<a href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="news"><?php echo esc_html( (string) $eex_story['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $eex_story['title'] ); ?>
			<?php endif; ?>
		</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

		<?php if ( ! empty( $args['show_date'] ) && (int) ( $eex_story['timestamp'] ?? 0 ) > 0 ) : ?>
			<p class="eex-hh__news-date"><time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $eex_story['timestamp'] ) ); ?>"><?php echo esc_html( date_i18n( (string) get_option( 'date_format', 'j F Y' ), (int) $eex_story['timestamp'] ) ); ?></time></p>
		<?php endif; ?>
	</div>
</article>
