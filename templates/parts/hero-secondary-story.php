<?php
/**
 * Secondary featured story: the smaller second feature of the two-story
 * frontage — compact image, category, headline, short standfirst, quiet
 * CTA. Clearly subordinate to the primary story; drops to clean text when
 * no image exists. Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $story         Story view model (EditorialSelector).
 *     @type string $heading_tag   Heading tag (one below the lead story's).
 *     @type bool   $show_image    Show the compact image.
 *     @type bool   $show_category Show the category label.
 *     @type bool   $show_date     Show the date.
 *     @type string $cta_text      CTA text ('' = "Read the full story").
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_story = (array) ( $args['story'] ?? [] );

if ( empty( $eex_story['title'] ) ) {
	return;
}

$eex_tag = in_array( (string) ( $args['heading_tag'] ?? 'h3' ), [ 'h2', 'h3', 'h4' ], true ) ? (string) $args['heading_tag'] : 'h3';
$eex_url = (string) ( $eex_story['url'] ?? '' );

$eex_image_html = '';
if ( ! empty( $args['show_image'] ) ) {
	if ( (int) ( $eex_story['image_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
		$eex_image_html = wp_get_attachment_image(
			(int) $eex_story['image_id'],
			'medium',
			false,
			[
				'class'   => 'eex-hh__story2-img',
				'loading' => 'lazy',
				// Repeats the adjacent headline: decorative here.
				'alt'     => '',
			]
		);
	} elseif ( '' !== (string) ( $eex_story['image'] ?? '' ) ) {
		$eex_image_html = '<img class="eex-hh__story2-img" loading="lazy" src="' . esc_url( (string) $eex_story['image'] ) . '" alt="" />';
	}

	if ( '' !== $eex_image_html ) {
		$eex_image_html = '<figure class="eex-hh__story2-media">' . $eex_image_html . '</figure>';
	}
}

$eex_excerpt = '' !== (string) ( $eex_story['excerpt'] ?? '' ) ? wp_trim_words( (string) $eex_story['excerpt'], 18, '…' ) : '';
?>
<article class="eex-hh__story2<?php echo '' === $eex_image_html ? ' eex-hh__story2--text' : ''; ?>">
	<?php echo $eex_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<div class="eex-hh__story2-body">
		<?php if ( ! empty( $args['show_category'] ) && '' !== (string) ( $eex_story['category'] ?? '' ) ) : ?>
			<p class="eex-hh__news-category"><?php echo esc_html( (string) $eex_story['category'] ); ?></p>
		<?php endif; ?>

		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__story2-title">
			<?php if ( '' !== $eex_url ) : ?>
				<a href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="story" data-eex-position="secondary-story"><?php echo esc_html( (string) $eex_story['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $eex_story['title'] ); ?>
			<?php endif; ?>
		</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

		<?php if ( '' !== $eex_excerpt ) : ?>
			<p class="eex-hh__story2-standfirst"><?php echo esc_html( $eex_excerpt ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $args['show_date'] ) && (int) ( $eex_story['timestamp'] ?? 0 ) > 0 ) : ?>
			<p class="eex-hh__news-date"><time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $eex_story['timestamp'] ) ); ?>"><?php echo esc_html( date_i18n( (string) get_option( 'date_format', 'j F Y' ), (int) $eex_story['timestamp'] ) ); ?></time></p>
		<?php endif; ?>

		<?php if ( '' !== $eex_url ) : ?>
			<p class="eex-hh__story-cta">
				<a class="eex-cta-quiet" href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="story" data-eex-position="secondary-story">
					<?php echo esc_html( '' !== (string) ( $args['cta_text'] ?? '' ) ? (string) $args['cta_text'] : __( 'Read the full story', 'emailexpert-events' ) ); ?><span class="eex-cta-arrow" aria-hidden="true">→</span>
				</a>
			</p>
		<?php endif; ?>
	</div>
</article>
