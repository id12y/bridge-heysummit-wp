<?php
/**
 * Sponsor single.
 *
 * @package Emailexpert\Events
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) :
	the_post();

	$eex_id      = get_the_ID();
	$eex_url     = (string) get_post_meta( $eex_id, '_eex_url', true );
	$eex_logo_id = (int) get_post_meta( $eex_id, '_eex_logo_attachment_id', true );
	$eex_blurb   = (string) get_post_meta( $eex_id, '_eex_blurb', true );
	?>
	<main id="primary" class="eex eex-single eex-single-sponsor">
		<article <?php post_class(); ?>>
			<header class="eex-single-header">
				<?php if ( $eex_logo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
					<?php
					echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
						$eex_logo_id,
						'medium',
						false,
						[
							'class'   => 'eex-sponsor-logo',
							'loading' => 'lazy',
							'alt'     => get_the_title(),
						]
					);
					?>
				<?php endif; ?>
				<h1 class="eex-single-title"><?php the_title(); ?></h1>
			</header>

			<?php if ( '' !== $eex_blurb ) : ?>
				<div class="eex-single-description"><?php echo wp_kses_post( wpautop( $eex_blurb ) ); ?></div>
			<?php endif; ?>

			<div class="eex-single-content"><?php the_content(); ?></div>

			<?php if ( '' !== $eex_url ) : ?>
				<p><a class="eex-cta" href="<?php echo esc_url( $eex_url ); ?>" rel="sponsored noopener"><?php esc_html_e( 'Visit sponsor', 'emailexpert-events' ); ?></a></p>
			<?php endif; ?>
		</article>
	</main>
	<?php
endwhile;

get_footer();
