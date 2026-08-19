<?php
/**
 * Homepage featured story: the dominant editorial region of the Homepage
 * Editorial Hero. The default arrangement follows the approved design —
 * copy on the left, editorial media beside it — and drops to a deliberate
 * text-led arrangement when no image exists. Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $story          Story view model (EditorialSelector).
 *     @type string $heading_tag    'h1' (page hero) or 'h2' (embedded).
 *     @type bool   $show_eyebrow   Show the eyebrow label.
 *     @type string $eyebrow        Eyebrow text ('' = "Featured story").
 *     @type bool   $show_image     Show the story image.
 *     @type string $image_position 'beside', 'below' or 'above'.
 *     @type string $media_fit      'cover' or 'contain'.
 *     @type bool   $show_excerpt   Show the standfirst.
 *     @type int    $excerpt_length Standfirst length in words.
 *     @type bool   $show_category  Show the category.
 *     @type bool   $show_date      Show the date.
 *     @type bool   $show_readtime  Show the reading time.
 *     @type bool   $show_cta       Show the call to action.
 *     @type string $cta_text       CTA text ('' = "Read the full story").
 *     @type bool   $eager          High-priority image (the LCP candidate).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_story = (array) ( $args['story'] ?? [] );

if ( empty( $eex_story['title'] ) ) {
	return;
}

$eex_tag = in_array( (string) ( $args['heading_tag'] ?? 'h2' ), [ 'h1', 'h2', 'h3' ], true ) ? (string) $args['heading_tag'] : 'h2';
$eex_url = (string) ( $eex_story['url'] ?? '' );

$eex_image_html = '';
if ( ! empty( $args['show_image'] ) ) {
	$eex_hint = ! empty( $args['eager'] ) ? 'fetchpriority="high"' : 'loading="lazy"';
	$eex_fit  = 'contain' === (string) ( $args['media_fit'] ?? 'cover' ) ? ' eex-media--contain' : '';

	if ( (int) ( $eex_story['image_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
		// Local images go through WordPress so srcset/sizes and dimensions
		// come for free (no layout shift).
		$eex_image_html = wp_get_attachment_image(
			(int) $eex_story['image_id'],
			'large',
			false,
			array_merge(
				[
					'class' => 'eex-hh__story-img',
					// Repeats the adjacent headline: decorative here.
					'alt'   => '',
				],
				! empty( $args['eager'] ) ? [ 'fetchpriority' => 'high' ] : [ 'loading' => 'lazy' ]
			)
		);
	} elseif ( '' !== (string) ( $eex_story['image'] ?? '' ) ) {
		$eex_image_html = '<img class="eex-hh__story-img" src="' . esc_url( (string) $eex_story['image'] ) . '" alt="" ' . $eex_hint . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of two literal attributes.
	}

	if ( '' !== $eex_image_html ) {
		$eex_image_html = '<figure class="eex-hh__story-media' . esc_attr( $eex_fit ) . '">' . $eex_image_html . '</figure>';
	}
}

$eex_position = (string) ( $args['image_position'] ?? 'beside' );
if ( ! in_array( $eex_position, [ 'beside', 'below', 'above' ], true ) ) {
	$eex_position = 'beside';
}

// No image (or images off): a deliberate text-led arrangement, never an
// empty media slot.
$eex_beside = 'beside' === $eex_position && '' !== $eex_image_html;

$eex_meta = [];

if ( ! empty( $args['show_category'] ) && '' !== (string) ( $eex_story['category'] ?? '' ) ) {
	$eex_meta[] = '<span class="eex-hh__story-category">' . esc_html( (string) $eex_story['category'] ) . '</span>';
}

if ( ! empty( $args['show_date'] ) && (int) ( $eex_story['timestamp'] ?? 0 ) > 0 ) {
	$eex_meta[] = '<time datetime="' . esc_attr( gmdate( 'Y-m-d', (int) $eex_story['timestamp'] ) ) . '">'
		. esc_html( date_i18n( (string) get_option( 'date_format', 'j F Y' ), (int) $eex_story['timestamp'] ) )
		. '</time>';
}

if ( ! empty( $args['show_readtime'] ) && (int) ( $eex_story['reading_time'] ?? 0 ) > 0 ) {
	$eex_meta[] = '<span class="eex-hh__story-readtime">' . esc_html(
		sprintf(
			/* translators: %d: minutes. */
			_n( '%d min read', '%d min read', (int) $eex_story['reading_time'], 'emailexpert-events' ),
			(int) $eex_story['reading_time']
		)
	) . '</span>';
}

$eex_excerpt = '';
if ( ! empty( $args['show_excerpt'] ) && '' !== (string) ( $eex_story['excerpt'] ?? '' ) ) {
	$eex_words   = max( 5, (int) ( $args['excerpt_length'] ?? 32 ) );
	$eex_excerpt = wp_trim_words( (string) $eex_story['excerpt'], $eex_words, '…' );
}

ob_start();
?>
	<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__story-title">
		<?php if ( '' !== $eex_url ) : ?>
			<a href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="story" data-eex-position="featured-story"><?php echo esc_html( (string) $eex_story['title'] ); ?></a>
		<?php else : ?>
			<?php echo esc_html( (string) $eex_story['title'] ); ?>
		<?php endif; ?>
	</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

	<?php if ( '' !== $eex_excerpt ) : ?>
		<p class="eex-hh__story-standfirst"><?php echo esc_html( $eex_excerpt ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $eex_meta ) ) : ?>
		<p class="eex-hh__story-meta"><?php echo implode( '<span class="eex-hh__meta-dot" aria-hidden="true"> | </span>', $eex_meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per part above. ?></p>
	<?php endif; ?>

	<?php if ( ! $eex_beside && 'below' === $eex_position ) : ?>
		<?php echo $eex_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<?php endif; ?>

	<?php if ( ! empty( $args['show_cta'] ) && '' !== $eex_url ) : ?>
		<p class="eex-hh__story-cta">
			<a class="eex-cta-quiet" href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="story" data-eex-position="featured-story">
				<?php echo esc_html( '' !== (string) ( $args['cta_text'] ?? '' ) ? (string) $args['cta_text'] : __( 'Read the full story', 'emailexpert-events' ) ); ?><span class="eex-cta-arrow" aria-hidden="true">→</span>
			</a>
		</p>
	<?php endif; ?>
<?php
$eex_copy = (string) ob_get_clean();
?>
<article class="eex-hh__story-card<?php echo '' === $eex_image_html ? ' eex-hh__story-card--text' : ''; ?>">
	<?php if ( ! empty( $args['show_eyebrow'] ) ) : ?>
		<p class="eex-comp-eyebrow eex-eyebrow"><?php echo esc_html( '' !== (string) ( $args['eyebrow'] ?? '' ) ? (string) $args['eyebrow'] : __( 'Featured story', 'emailexpert-events' ) ); ?></p>
	<?php endif; ?>

	<?php if ( ! $eex_beside && 'above' === $eex_position && '' !== $eex_image_html ) : ?>
		<?php echo $eex_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<?php endif; ?>

	<?php if ( $eex_beside ) : ?>
		<div class="eex-hh__story-split">
			<div class="eex-hh__story-copy"><?php echo $eex_copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></div>
			<?php echo $eex_image_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
		</div>
	<?php else : ?>
		<?php echo $eex_copy; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<?php endif; ?>
</article>
