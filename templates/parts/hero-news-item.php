<?php
/**
 * Latest News item: one compact editorial entry — category eyebrow,
 * headline, date, with the image beside the text, above the headline, or
 * absent. A no-image story aligns cleanly with its neighbours: the media
 * frame only exists when an image does. Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $story          Story view model (EditorialSelector).
 *     @type string $heading_tag    Heading tag for the item title.
 *     @type string $image_position 'none', 'beside' or 'above' (resolved).
 *     @type string $image_size     'compact', 'medium' or 'large' (above only).
 *     @type bool   $show_category  Show the category label.
 *     @type bool   $link_category  Link the category to its term archive.
 *     @type bool   $link_image     Link the image to the article.
 *     @type bool   $show_date      Show the date.
 *     @type string $role           Editorial role in a composed layout:
 *                                  'lead', 'secondary', 'standard', 'brief'
 *                                  or '' (flat grid item).
 *     @type bool   $show_standfirst Lead only: show a short standfirst.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_story = (array) ( $args['story'] ?? [] );

if ( empty( $eex_story['title'] ) ) {
	return;
}

$eex_tag = in_array( (string) ( $args['heading_tag'] ?? 'h4' ), [ 'h2', 'h3', 'h4', 'h5' ], true ) ? (string) $args['heading_tag'] : 'h4';
$eex_url = (string) ( $eex_story['url'] ?? '' );

$eex_role = in_array( (string) ( $args['role'] ?? '' ), [ 'lead', 'secondary', 'standard', 'brief' ], true )
	? (string) ( $args['role'] ?? '' )
	: '';

// A brief is typography-led by definition: no image markup is ever built
// for it — twenty stories must not mean twenty thumbnail requests.
if ( 'brief' === $eex_role ) {
	$eex_category_link = ! empty( $args['link_category'] ) ? (string) ( $eex_story['category_link'] ?? '' ) : '';
	?>
	<article class="eex-hh__news-card eex-hh__news-card--brief">
		<?php if ( ! empty( $args['show_category'] ) && '' !== (string) ( $eex_story['category'] ?? '' ) ) : ?>
			<p class="eex-hh__news-category"><?php if ( '' !== $eex_category_link ) : ?><a href="<?php echo esc_url( $eex_category_link ); ?>" data-eex-action="news-category"><?php echo esc_html( (string) $eex_story['category'] ); ?></a><?php else : ?><?php echo esc_html( (string) $eex_story['category'] ); ?><?php endif; ?></p>
		<?php endif; ?>
		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__news-title">
			<?php if ( '' !== $eex_url ) : ?>
				<a href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="news"><?php echo esc_html( (string) $eex_story['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $eex_story['title'] ); ?>
			<?php endif; ?>
		</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>
		<?php if ( ! empty( $args['show_date'] ) && (int) ( $eex_story['timestamp'] ?? 0 ) > 0 ) : ?>
			<p class="eex-hh__news-date"><time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $eex_story['timestamp'] ) ); ?>"><?php echo esc_html( date_i18n( 'j M Y', (int) $eex_story['timestamp'] ) ); ?></time></p>
		<?php endif; ?>
	</article>
	<?php
	return;
}

$eex_position = in_array( (string) ( $args['image_position'] ?? 'beside' ), [ 'none', 'beside', 'above' ], true )
	? (string) ( $args['image_position'] ?? 'beside' )
	: 'beside';
$eex_size     = in_array( (string) ( $args['image_size'] ?? 'medium' ), [ 'compact', 'medium', 'large' ], true )
	? (string) ( $args['image_size'] ?? 'medium' )
	: 'medium';

$eex_thumb = '';
if ( 'none' !== $eex_position ) {
	// Above-the-headline images render at content width, so the generated
	// medium size (with srcset) replaces the tiny square thumbnail.
	$eex_wp_size   = 'above' === $eex_position ? ( 'large' === $eex_size ? 'large' : 'medium_large' ) : 'thumbnail';
	$eex_img_class = 'above' === $eex_position ? 'eex-hh__news-img eex-hh__news-img--wide' : 'eex-hh__news-img';

	if ( (int) ( $eex_story['image_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
		$eex_thumb = wp_get_attachment_image(
			(int) $eex_story['image_id'],
			$eex_wp_size,
			false,
			[
				'class'   => $eex_img_class,
				'loading' => 'lazy',
				'alt'     => '',
			]
		);
	} elseif ( '' !== (string) ( $eex_story['image'] ?? '' ) ) {
		$eex_thumb = '<img class="' . esc_attr( $eex_img_class ) . '" loading="lazy" src="' . esc_url( (string) $eex_story['image'] ) . '" alt="" />';
	}
}

$eex_classes = 'eex-hh__news-card';
if ( '' !== $eex_role ) {
	$eex_classes .= ' eex-hh__news-card--' . $eex_role;
}
if ( '' !== $eex_thumb ) {
	$eex_classes .= 'above' === $eex_position
		? ' eex-hh__news-card--stacked eex-hh__news-card--' . $eex_size
		: ' eex-hh__news-card--thumb';
}

// The lead's short standfirst — the only role that carries one.
$eex_standfirst = '';
if ( 'lead' === $eex_role && ! empty( $args['show_standfirst'] ) && '' !== (string) ( $eex_story['excerpt'] ?? '' ) ) {
	$eex_standfirst = wp_trim_words( (string) $eex_story['excerpt'], 24, '…' );
}

// The image as a pointer-only shortcut to the article: hidden from the
// accessibility tree and the tab order, because the adjacent headline link
// is the accessible route to the same destination — one announcement, not
// two, and never a nested link.
if ( '' !== $eex_thumb && ! empty( $args['link_image'] ) && '' !== $eex_url ) {
	$eex_thumb = '<a class="eex-hh__news-imglink" href="' . esc_url( $eex_url ) . '" tabindex="-1" aria-hidden="true" data-eex-action="news">' . $eex_thumb . '</a>';
}

// The category label: a link to its real term archive when one exists and
// the option is on; plain text otherwise — never a hand-built URL, never a
// broken link.
$eex_category = '';
if ( ! empty( $args['show_category'] ) && '' !== (string) ( $eex_story['category'] ?? '' ) ) {
	$eex_category_link = ! empty( $args['link_category'] ) ? (string) ( $eex_story['category_link'] ?? '' ) : '';

	$eex_category = '<p class="eex-hh__news-category">'
		. ( '' !== $eex_category_link
			? '<a href="' . esc_url( $eex_category_link ) . '" data-eex-action="news-category">' . esc_html( (string) $eex_story['category'] ) . '</a>'
			: esc_html( (string) $eex_story['category'] ) )
		. '</p>';
}
?>
<article class="<?php echo esc_attr( $eex_classes ); ?>">
	<?php if ( 'above' === $eex_position && '' !== $eex_thumb ) : ?>
		<?php echo $eex_category; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
		<figure class="eex-hh__news-media"><?php echo $eex_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></figure>
	<?php elseif ( '' !== $eex_thumb ) : ?>
		<?php echo $eex_thumb; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<?php endif; ?>
	<div class="eex-hh__news-body">
		<?php if ( 'above' !== $eex_position || '' === $eex_thumb ) : ?>
			<?php echo $eex_category; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
		<?php endif; ?>

		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__news-title">
			<?php if ( '' !== $eex_url ) : ?>
				<a href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="news"><?php echo esc_html( (string) $eex_story['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $eex_story['title'] ); ?>
			<?php endif; ?>
		</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

		<?php if ( '' !== $eex_standfirst ) : ?>
			<p class="eex-hh__news-standfirst"><?php echo esc_html( $eex_standfirst ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $args['show_date'] ) && (int) ( $eex_story['timestamp'] ?? 0 ) > 0 ) : ?>
			<?php // The compact editorial date, local to this component ("19 Aug 2026"); month names still localise through date_i18n. Other widgets keep the site format. ?>
			<p class="eex-hh__news-date"><time datetime="<?php echo esc_attr( gmdate( 'Y-m-d', (int) $eex_story['timestamp'] ) ); ?>"><?php echo esc_html( date_i18n( 'j M Y', (int) $eex_story['timestamp'] ) ); ?></time></p>
		<?php endif; ?>
	</div>
</article>
