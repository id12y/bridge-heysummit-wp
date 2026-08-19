<?php
/**
 * Speaker block: portrait, name and role — the featured-event speaker
 * treatment from the approved design. Falls back to clean text when no
 * photo exists (portraits are never fabricated). Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $speaker { id, name, url, headline, photo_id, photo_url }.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

if ( empty( $eex_speaker['name'] ) ) {
	return;
}

$eex_name     = (string) $eex_speaker['name'];
$eex_role     = (string) ( $eex_speaker['headline'] ?? '' );
$eex_link     = (string) ( $eex_speaker['url'] ?? '' );
$eex_photo_id = (int) ( $eex_speaker['photo_id'] ?? 0 );
$eex_photo    = (string) ( $eex_speaker['photo_url'] ?? '' );

$eex_portrait = '';
if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
	$eex_portrait = wp_get_attachment_image(
		$eex_photo_id,
		'thumbnail',
		false,
		[
			'class'   => 'eex-speaker-block__photo',
			'loading' => 'lazy',
			// The name sits in text right beside the portrait.
			'alt'     => '',
		]
	);
} elseif ( '' !== $eex_photo ) {
	$eex_portrait = '<img class="eex-speaker-block__photo" loading="lazy" src="' . esc_url( $eex_photo ) . '" alt="" />';
}
?>
<span class="eex-speaker-block<?php echo '' === $eex_portrait ? ' eex-speaker-block--text' : ''; ?>">
	<?php echo $eex_portrait; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped/core-generated above. ?>
	<span class="eex-speaker-block__text">
		<?php if ( '' !== $eex_link ) : ?>
			<a class="eex-speaker-block__name" href="<?php echo esc_url( $eex_link ); ?>"><?php echo esc_html( $eex_name ); ?></a>
		<?php else : ?>
			<span class="eex-speaker-block__name"><?php echo esc_html( $eex_name ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $eex_role ) : ?>
			<span class="eex-speaker-block__role"><?php echo esc_html( $eex_role ); ?></span>
		<?php endif; ?>
	</span>
</span>
