<?php
/**
 * Speaker chip: a small linked name used on session cards and schedules.
 * The info arg deepens it: 'names' (default), 'headline' adds the job
 * title, 'full' adds the photo too.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $speaker { id, name, url, headline, photo_id, photo_url }.
 *     @type string $info    'names', 'headline' or 'full'.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

if ( empty( $eex_speaker['name'] ) ) {
	return;
}

$eex_chip_url = (string) ( $eex_speaker['url'] ?? '' );
$eex_info     = (string) ( $args['info'] ?? 'names' );
$eex_headline = in_array( $eex_info, [ 'headline', 'full' ], true ) ? (string) ( $eex_speaker['headline'] ?? '' ) : '';

$eex_photo = '';
if ( 'full' === $eex_info ) {
	$eex_photo_id  = (int) ( $eex_speaker['photo_id'] ?? 0 );
	$eex_photo_url = (string) ( $eex_speaker['photo_url'] ?? '' );

	if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) {
		$eex_photo = wp_get_attachment_image(
			$eex_photo_id,
			'thumbnail',
			false,
			[
				'class'   => 'eex-chip-photo',
				'loading' => 'lazy',
				'alt'     => '',
			]
		);
	} elseif ( '' !== $eex_photo_url ) {
		$eex_photo = '<img class="eex-chip-photo" loading="lazy" src="' . esc_url( $eex_photo_url ) . '" alt="" />';
	}
}

$eex_chip_class = 'eex-chip' . ( 'full' === $eex_info ? ' eex-chip-full' : '' );

ob_start();
echo $eex_photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated markup / escaped above.
echo esc_html( (string) $eex_speaker['name'] );
if ( '' !== $eex_headline ) {
	echo ' <span class="eex-chip-headline">' . esc_html( $eex_headline ) . '</span>';
}
$eex_chip_inner = (string) ob_get_clean();
?>
<?php if ( '' !== $eex_chip_url ) : ?>
	<a class="<?php echo esc_attr( $eex_chip_class ); ?>" href="<?php echo esc_url( $eex_chip_url ); ?>"><?php echo $eex_chip_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></a>
<?php else : ?>
	<span class="<?php echo esc_attr( $eex_chip_class ); ?>"><?php echo $eex_chip_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?></span>
<?php endif; ?>
