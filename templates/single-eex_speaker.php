<?php
/**
 * Speaker single: a genuine profile with the speaker's upcoming and past
 * sessions. Override by copying to yourtheme/emailexpert-events/.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Query;
use Emailexpert\Events\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$eex_id       = get_the_ID();
	$eex_headline = (string) get_post_meta( $eex_id, '_eex_headline', true );
	$eex_company  = (string) get_post_meta( $eex_id, '_eex_company', true );
	$eex_bio      = (string) get_post_meta( $eex_id, '_eex_description', true );
	$eex_photo_id = (int) get_post_meta( $eex_id, '_eex_photo_attachment_id', true );
	$eex_links    = array_filter( array_map( 'strval', (array) get_post_meta( $eex_id, '_eex_links', true ) ) );

	// This speaker's sessions, split by start time.
	$eex_all_talks = Query::talks( [] );
	$eex_mine      = [];
	foreach ( $eex_all_talks as $eex_talk ) {
		$eex_speaker_ids = array_map( 'intval', (array) get_post_meta( $eex_talk['id'], '_eex_speaker_ids', true ) );
		if ( in_array( $eex_id, $eex_speaker_ids, true ) ) {
			$eex_mine[] = $eex_talk;
		}
	}
	$eex_now      = time();
	$eex_upcoming = array_filter( $eex_mine, static fn( array $t ): bool => $t['start_ts'] >= $eex_now );
	$eex_past     = array_filter( $eex_mine, static fn( array $t ): bool => $t['start_ts'] > 0 && $t['start_ts'] < $eex_now );
	usort( $eex_upcoming, static fn( array $a, array $b ): int => $a['start_ts'] <=> $b['start_ts'] );
	usort( $eex_past, static fn( array $a, array $b ): int => $b['start_ts'] <=> $a['start_ts'] );
	?>
	<main id="primary" class="eex eex-single eex-single-speaker">
		<article <?php post_class(); ?>>
			<header class="eex-single-header eex-speaker-header">
				<?php if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
					<?php
					echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
						$eex_photo_id,
						'medium',
						false,
						[
							'class'   => 'eex-speaker-photo',
							'loading' => 'lazy',
							'alt'     => get_the_title(),
						]
					);
					?>
				<?php endif; ?>

				<h1 class="eex-single-title"><?php the_title(); ?></h1>

				<?php if ( '' !== $eex_headline ) : ?>
					<p class="eex-speaker-headline"><?php echo esc_html( $eex_headline ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $eex_company ) : ?>
					<p class="eex-speaker-company"><?php echo esc_html( $eex_company ); ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $eex_links ) ) : ?>
					<p class="eex-speaker-links">
						<?php foreach ( $eex_links as $eex_link ) : ?>
							<a class="eex-chip" href="<?php echo esc_url( $eex_link ); ?>" rel="noopener"><?php echo esc_html( (string) wp_parse_url( $eex_link, PHP_URL_HOST ) ?: $eex_link ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</header>

			<?php if ( '' !== $eex_bio ) : ?>
				<div class="eex-single-description"><?php echo wp_kses_post( wpautop( $eex_bio ) ); ?></div>
			<?php endif; ?>

			<div class="eex-single-content"><?php the_content(); ?></div>

			<?php if ( ! empty( $eex_upcoming ) ) : ?>
				<section class="eex-speaker-upcoming">
					<h2><?php esc_html_e( 'Upcoming sessions', 'emailexpert-events' ); ?></h2>
					<ul class="eex-grid eex-talk-grid" role="list">
						<?php foreach ( $eex_upcoming as $eex_talk ) : ?>
							<li class="eex-grid-item">
								<?php
								TemplateLoader::part(
									'card-talk',
									[
										'data'    => Components::talk_data( (int) $eex_talk['id'] ),
										'context' => 'upcoming',
									]
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $eex_past ) ) : ?>
				<section class="eex-speaker-past">
					<h2><?php esc_html_e( 'Past sessions', 'emailexpert-events' ); ?></h2>
					<ul class="eex-grid eex-talk-grid" role="list">
						<?php foreach ( $eex_past as $eex_talk ) : ?>
							<li class="eex-grid-item">
								<?php
								TemplateLoader::part(
									'card-talk',
									[
										'data'    => Components::talk_data( (int) $eex_talk['id'] ),
										'context' => 'past',
									]
								);
								?>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
