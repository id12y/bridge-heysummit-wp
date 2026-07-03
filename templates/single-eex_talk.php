<?php
/**
 * Session single. Override by copying to yourtheme/emailexpert-events/.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Frontend\SchemaGenerator;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$eex_data    = Components::talk_data( get_the_ID() );
	$eex_started = '' !== (string) $eex_data['starts_at'] && strtotime( (string) $eex_data['starts_at'] ) < time();
	$eex_replay  = (string) $eex_data['replay_url'];
	?>
	<main id="primary" class="eex eex-single eex-single-talk">
		<article <?php post_class(); ?><?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
			<header class="eex-single-header">
				<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

				<h1 class="eex-single-title"><?php the_title(); ?></h1>

				<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
					<p class="eex-card-time"><?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></p>
				<?php endif; ?>

				<?php if ( ! empty( $eex_data['categories'] ) ) : ?>
					<p class="eex-badges">
						<?php foreach ( $eex_data['categories'] as $eex_term ) : ?>
							<a class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>" href="<?php echo esc_url( (string) get_term_link( $eex_term ) ); ?>"><?php echo esc_html( $eex_term->name ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $eex_data['speakers'] ) ) : ?>
					<p class="eex-speaker-chips">
						<?php foreach ( $eex_data['speakers'] as $eex_speaker ) : ?>
							<?php TemplateLoader::part( 'speaker-chip', [ 'speaker' => $eex_speaker ] ); ?>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<p class="eex-card-actions">
					<?php if ( $eex_started && '' !== $eex_replay ) : ?>
						<a class="eex-cta eex-cta-replay" href="<?php echo esc_url( $eex_replay ); ?>"><?php esc_html_e( 'Watch replay', 'emailexpert-events' ); ?></a>
					<?php elseif ( ! $eex_started ) : ?>
						<?php $eex_register_url = (string) ( $eex_data['event_url'] ?: $eex_data['talk_url'] ); ?>
						<?php if ( '' !== $eex_register_url ) : ?>
							<a class="eex-cta eex-cta-register" data-eex-cta="1" href="<?php echo esc_url( $eex_register_url ); ?>"><?php esc_html_e( 'Register', 'emailexpert-events' ); ?></a>
						<?php endif; ?>
						<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
							<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::download_url( (int) $eex_data['id'] ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
							<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::google_url( $eex_data ) ); ?>" rel="noopener"><?php esc_html_e( 'Google Calendar', 'emailexpert-events' ); ?></a>
						<?php endif; ?>
					<?php endif; ?>
				</p>
			</header>

			<?php if ( $eex_started && '' !== $eex_replay ) : ?>
				<?php $eex_embed = SchemaGenerator::embed_url( $eex_replay ); ?>
				<?php if ( '' !== $eex_embed ) : ?>
					<div class="eex-replay-embed">
						<iframe src="<?php echo esc_url( $eex_embed ); ?>" title="<?php echo esc_attr( get_the_title() ); ?>" allowfullscreen loading="lazy"></iframe>
					</div>
				<?php else : ?>
					<?php
					$eex_oembed = function_exists( 'wp_oembed_get' ) ? wp_oembed_get( $eex_replay ) : false;
					if ( $eex_oembed ) {
						echo '<div class="eex-replay-embed">' . $eex_oembed . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oEmbed markup from core.
					}
					?>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( '' !== (string) $eex_data['description'] ) : ?>
				<div class="eex-single-description"><?php echo wp_kses_post( wpautop( (string) $eex_data['description'] ) ); ?></div>
			<?php endif; ?>

			<div class="eex-single-content"><?php the_content(); ?></div>

			<?php if ( $eex_data['event_post_id'] > 0 ) : ?>
				<p class="eex-parent-event">
					<a href="<?php echo esc_url( (string) get_permalink( (int) $eex_data['event_post_id'] ) ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: parent event title. */
								__( 'Part of %s', 'emailexpert-events' ),
								get_the_title( (int) $eex_data['event_post_id'] )
							)
						);
						?>
					</a>
				</p>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
