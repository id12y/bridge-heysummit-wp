<?php
/**
 * Speaker list row (layout="list"): photo left, name and role right.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $speaker Speaker data array (see Data\Repository).
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

if ( empty( $eex_speaker['name'] ) ) {
	return;
}

$eex_name     = (string) $eex_speaker['name'];
$eex_headline = (string) ( $eex_speaker['headline'] ?? '' );
$eex_company  = (string) ( $eex_speaker['company'] ?? '' );
// A headline that already names the company ("VP at Example Corp") makes
// a separate company line pure repetition.
if ( '' !== $eex_company && false !== stripos( $eex_headline, $eex_company ) ) {
	$eex_company = '';
}
$eex_photo_id  = (int) ( $eex_speaker['photo_id'] ?? 0 );
$eex_photo_url = (string) ( $eex_speaker['photo_url'] ?? '' );
?>
<article class="eex-list-row eex-speaker-row">
	<?php if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
		<?php
		echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
			$eex_photo_id,
			'thumbnail',
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
	<span class="eex-list-main">
		<?php $eex_link = (string) ( $eex_speaker['url'] ?? '' ); ?>
		<?php if ( '' !== $eex_link ) : ?>
			<a class="eex-list-title eex-speaker-name" href="<?php echo esc_url( $eex_link ); ?>"><?php echo esc_html( $eex_name ); ?></a>
		<?php else : ?>
			<span class="eex-list-title eex-speaker-name"><?php echo esc_html( $eex_name ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $eex_headline ) : ?>
			<span class="eex-speaker-headline"><?php echo esc_html( $eex_headline ); ?></span>
		<?php endif; ?>
		<?php if ( '' !== $eex_company ) : ?>
			<span class="eex-speaker-company"><?php echo esc_html( $eex_company ); ?></span>
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
	</span>
</article>
