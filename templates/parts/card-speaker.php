<?php
/**
 * Speaker card.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $speaker Speaker data array (see Data\Repository).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

// Back-compat for overrides passing a post ID.
if ( empty( $eex_speaker ) && ! empty( $args['speaker_id'] ) ) {
	$eex_speaker = \Emailexpert\Events\Data\SyncedRepository::speaker_data( (int) $args['speaker_id'] );
}

if ( empty( $eex_speaker['name'] ) ) {
	return;
}

$eex_name      = (string) $eex_speaker['name'];
$eex_headline  = (string) ( $eex_speaker['headline'] ?? '' );
$eex_company   = (string) ( $eex_speaker['company'] ?? '' );
$eex_photo_id  = (int) ( $eex_speaker['photo_id'] ?? 0 );
$eex_photo_url = (string) ( $eex_speaker['photo_url'] ?? '' );
?>
<?php $eex_link = (string) ( $eex_speaker['url'] ?? '' ); ?>
<article class="eex-card eex-card-speaker">
	<<?php echo '' !== $eex_link ? 'a href="' . esc_url( $eex_link ) . '"' : 'span'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> class="eex-speaker-link">
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
		<h3 class="eex-card-title"><?php echo esc_html( $eex_name ); ?></h3>
	</<?php echo '' !== $eex_link ? 'a' : 'span'; ?>>
	<?php if ( '' !== $eex_headline ) : ?>
		<p class="eex-speaker-headline"><?php echo esc_html( $eex_headline ); ?></p>
	<?php endif; ?>
	<?php if ( '' !== $eex_company ) : ?>
		<p class="eex-speaker-company"><?php echo esc_html( $eex_company ); ?></p>
	<?php endif; ?>
	<?php if ( ! empty( $args['show_links'] ) ) : ?>
		<?php
		\Emailexpert\Events\Frontend\TemplateLoader::part(
			'speaker-links',
			[
				'links' => (array) ( $eex_speaker['links'] ?? [] ),
				'name'  => $eex_name,
			]
		);
		?>
	<?php endif; ?>
</article>
