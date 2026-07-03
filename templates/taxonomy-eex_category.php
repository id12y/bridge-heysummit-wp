<?php
/**
 * Category archive: each synced HeySummit category is an indexable page
 * listing its upcoming and past sessions.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

get_header();

$eex_term = get_queried_object();
$eex_slug = $eex_term->slug ?? '';
?>
<main id="primary" class="eex eex-archive eex-archive-category">
	<header class="eex-archive-header">
		<h1><?php echo esc_html( $eex_term->name ?? '' ); ?></h1>
		<?php if ( ! empty( $eex_term->description ) ) : ?>
			<div class="eex-archive-description"><?php echo wp_kses_post( wpautop( $eex_term->description ) ); ?></div>
		<?php endif; ?>
	</header>

	<section>
		<h2><?php esc_html_e( 'Upcoming sessions', 'emailexpert-events' ); ?></h2>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
		echo Components::render(
			'upcoming-sessions',
			[
				'category'       => $eex_slug,
				'limit'          => 12,
				'show_subscribe' => 1,
			]
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>

	<section>
		<h2><?php esc_html_e( 'Past sessions', 'emailexpert-events' ); ?></h2>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
		echo Components::render(
			'past-sessions',
			[
				'category' => $eex_slug,
				'limit'    => 12,
			]
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	</section>
</main>
<?php
get_footer();
