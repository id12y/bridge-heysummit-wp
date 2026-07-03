<?php
/**
 * Speaker card.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type int $speaker_id Speaker post ID.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker_id = (int) ( $args['speaker_id'] ?? 0 );

if ( 0 === $eex_speaker_id ) {
	return;
}

$eex_name     = get_the_title( $eex_speaker_id );
$eex_headline = (string) get_post_meta( $eex_speaker_id, '_eex_headline', true );
$eex_company  = (string) get_post_meta( $eex_speaker_id, '_eex_company', true );
$eex_photo_id = (int) get_post_meta( $eex_speaker_id, '_eex_photo_attachment_id', true );
?>
<article class="eex-card eex-card-speaker">
	<a href="<?php echo esc_url( (string) get_permalink( $eex_speaker_id ) ); ?>" class="eex-speaker-link">
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
		<?php endif; ?>
		<h3 class="eex-card-title"><?php echo esc_html( $eex_name ); ?></h3>
	</a>
	<?php if ( '' !== $eex_headline ) : ?>
		<p class="eex-speaker-headline"><?php echo esc_html( $eex_headline ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $eex_company ) : ?>
		<p class="eex-speaker-company"><?php echo esc_html( $eex_company ); ?></p>
	<?php endif; ?>
</article>
