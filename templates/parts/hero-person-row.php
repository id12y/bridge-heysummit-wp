<?php
/**
 * Featured person row: the person-led More Events treatment — portrait,
 * name, then the session context (title and date) as one quiet line. The
 * portrait is decorative (the name sits in text beside it) and is never
 * fabricated. Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $person { name, url, headline, photo_id, photo_url,
 *                           context, date, timezone, link }.
 * }
 */

use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_person = (array) ( $args['person'] ?? [] );

if ( empty( $eex_person['name'] ) ) {
	return;
}

$eex_name = (string) $eex_person['name'];
$eex_link = (string) ( $eex_person['link'] ?? '' );
$eex_date = (string) ( $eex_person['date'] ?? '' );

$eex_portrait = '';
if ( (int) ( $eex_person['photo_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
	$eex_portrait = wp_get_attachment_image(
		(int) $eex_person['photo_id'],
		'thumbnail',
		false,
		[
			'class'   => 'eex-hh__person-photo',
			'loading' => 'lazy',
			'alt'     => '',
		]
	);
} elseif ( '' !== (string) ( $eex_person['photo_url'] ?? '' ) ) {
	$eex_portrait = '<img class="eex-hh__person-photo" loading="lazy" src="' . esc_url( (string) $eex_person['photo_url'] ) . '" alt="" />';
}
?>
<article class="eex-hh__person<?php echo '' === $eex_portrait ? ' eex-hh__person--text' : ''; ?>">
	<?php echo $eex_portrait; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped/core-generated above. ?>
	<div class="eex-hh__person-body">
		<span class="eex-hh__person-name"><?php echo esc_html( $eex_name ); ?></span>
		<span class="eex-hh__person-context">
			<?php if ( '' !== $eex_link && '' !== (string) ( $eex_person['context'] ?? '' ) ) : ?>
				<a href="<?php echo esc_url( $eex_link ); ?>" data-eex-action="more-events"><?php echo esc_html( (string) $eex_person['context'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) ( $eex_person['context'] ?? '' ) ); ?>
			<?php endif; ?>
			<?php if ( '' !== $eex_date ) : ?>
				<span class="eex-hh__meta-dot" aria-hidden="true"> · </span>
				<?php echo TimeFormat::render_date( $eex_date, (string) ( $eex_person['timezone'] ?? '' ), false, 'j M' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
			<?php endif; ?>
		</span>
	</div>
</article>
