<?php
/**
 * Event series archive.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

get_header();

$eex_term = get_queried_object();
$eex_slug = $eex_term->slug ?? '';
?>
<main id="primary" class="eex eex-archive eex-archive-series">
	<header class="eex-archive-header">
		<h1><?php echo esc_html( $eex_term->name ?? '' ); ?></h1>
		<?php if ( ! empty( $eex_term->description ) ) : ?>
			<div class="eex-archive-description"><?php echo wp_kses_post( wpautop( $eex_term->description ) ); ?></div>
		<?php endif; ?>
	</header>

	<section>
		<h2><?php esc_html_e( 'Upcoming events', 'emailexpert-events' ); ?></h2>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
		echo Components::render(
			'upcoming-events',
			[
				'series' => $eex_slug,
				'limit'  => 12,
			]
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>

	<section>
		<h2><?php esc_html_e( 'Past events', 'emailexpert-events' ); ?></h2>
		<?php echo Components::render( 'past-events', [ 'series' => $eex_slug ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
	</section>
</main>
<?php
get_footer();
