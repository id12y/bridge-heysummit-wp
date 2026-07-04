<?php
/**
 * Speaker spotlight: one featured speaker with photo, role and biography.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $speaker  Speaker data array (see Data\Repository).
 *     @type bool  $show_bio Show the biography.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

if ( empty( $eex_speaker['name'] ) ) {
	return;
}

$eex_name      = (string) $eex_speaker['name'];
$eex_link      = (string) ( $eex_speaker['url'] ?? '' );
$eex_headline  = (string) ( $eex_speaker['headline'] ?? '' );
$eex_company   = (string) ( $eex_speaker['company'] ?? '' );
$eex_bio       = ! empty( $args['show_bio'] ) ? (string) ( $eex_speaker['bio'] ?? '' ) : '';
$eex_photo_id  = (int) ( $eex_speaker['photo_id'] ?? 0 );
$eex_photo_url = (string) ( $eex_speaker['photo_url'] ?? '' );
?>
<article class="eex-card eex-spotlight-speaker">
	<?php if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
		<?php
		echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
			$eex_photo_id,
			'medium',
			false,
			[
				'class'   => 'eex-speaker-photo',
				'loading' => 'lazy',
				'alt'     => $eex_name,
			]
		);
		?>
	<?php elseif ( '' !== $eex_photo_url ) : ?>
		<img class="eex-speaker-photo" loading="lazy" src="<?php echo esc_url( $eex_photo_url ); ?>" alt="<?php echo esc_attr( $eex_name ); ?>" />
	<?php endif; ?>

	<div class="eex-spotlight-body">
		<h3 class="eex-card-title">
			<?php if ( '' !== $eex_link ) : ?>
				<a href="<?php echo esc_url( $eex_link ); ?>"><?php echo esc_html( $eex_name ); ?></a>
			<?php else : ?>
				<?php echo esc_html( $eex_name ); ?>
			<?php endif; ?>
		</h3>

		<?php if ( '' !== $eex_headline ) : ?>
			<p class="eex-speaker-headline"><?php echo esc_html( $eex_headline ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $eex_company ) : ?>
			<p class="eex-speaker-company"><?php echo esc_html( $eex_company ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $eex_bio ) : ?>
			<p class="eex-spotlight-bio"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $eex_bio ), 60 ) ); ?></p>
		<?php endif; ?>
	</div>
</article>
