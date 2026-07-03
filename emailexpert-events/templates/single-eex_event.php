<?php
/**
 * Event single. Override by copying to yourtheme/emailexpert-events/.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TimeFormat;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$eex_id    = get_the_ID();
	$eex_hs_id = (string) get_post_meta( $eex_id, '_eex_heysummit_id', true );
	$eex_first = (string) get_post_meta( $eex_id, '_eex_first_talk_at', true );
	$eex_last  = (string) get_post_meta( $eex_id, '_eex_last_talk_at', true );
	$eex_tz    = (string) get_post_meta( $eex_id, '_eex_timezone', true );
	$eex_open  = (bool) get_post_meta( $eex_id, '_eex_is_open_for_registrations', true );
	$eex_url   = (string) get_post_meta( $eex_id, '_eex_event_url', true );
	$eex_desc  = (string) get_post_meta( $eex_id, '_eex_description', true );
	$eex_venue = (string) get_post_meta( $eex_id, '_eex_venue_name', true );
	$eex_terms = get_the_terms( $eex_id, Taxonomies::SERIES );
	?>
	<main id="primary" class="eex eex-single eex-single-event">
		<article <?php post_class(); ?>>
			<header class="eex-single-header">
				<?php if ( is_array( $eex_terms ) ) : ?>
					<p class="eex-badges">
						<?php foreach ( $eex_terms as $eex_term ) : ?>
							<span class="eex-badge eex-badge-series-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>

				<h1 class="eex-single-title"><?php the_title(); ?></h1>

				<?php if ( '' !== $eex_first ) : ?>
					<p class="eex-card-time">
						<?php echo TimeFormat::render( $eex_first, $eex_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
						<?php if ( '' !== $eex_last && $eex_last !== $eex_first ) : ?>
							&ndash; <?php echo TimeFormat::render( $eex_last, $eex_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
						<?php endif; ?>
					</p>
				<?php endif; ?>

				<?php if ( '' !== $eex_venue ) : ?>
					<p class="eex-card-venue"><?php echo esc_html( $eex_venue ); ?></p>
				<?php endif; ?>

				<?php if ( $eex_open && '' !== $eex_url ) : ?>
					<p class="eex-card-actions"><a class="eex-cta eex-cta-register" href="<?php echo esc_url( $eex_url ); ?>"><?php esc_html_e( 'Register', 'emailexpert-events' ); ?></a></p>
				<?php endif; ?>

				<?php echo Components::render( 'reg-counter', [ 'event' => $eex_hs_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
				<?php echo Components::render( 'countdown', [ 'event' => $eex_hs_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
			</header>

			<?php if ( '' !== $eex_desc ) : ?>
				<div class="eex-single-description"><?php echo wp_kses_post( wpautop( $eex_desc ) ); ?></div>
			<?php endif; ?>

			<?php // Editor-owned content renders below the synced description and is never touched by sync. ?>
			<div class="eex-single-content"><?php the_content(); ?></div>

			<section class="eex-single-schedule">
				<h2><?php esc_html_e( 'Schedule', 'emailexpert-events' ); ?></h2>
				<?php echo Components::render( 'schedule', [ 'event' => $eex_hs_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
			</section>

			<section class="eex-single-speakers">
				<h2><?php esc_html_e( 'Speakers', 'emailexpert-events' ); ?></h2>
				<?php echo Components::render( 'speakers', [ 'event' => $eex_hs_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
			</section>

			<section class="eex-single-sponsors">
				<h2><?php esc_html_e( 'Sponsors', 'emailexpert-events' ); ?></h2>
				<?php echo Components::render( 'sponsors', [ 'event' => $eex_hs_id ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
			</section>
		</article>
	</main>
	<?php
endwhile;

get_footer();
