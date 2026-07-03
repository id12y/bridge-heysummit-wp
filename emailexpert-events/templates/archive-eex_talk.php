<?php
/**
 * Sessions archive: upcoming then the browsable replay library.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="eex eex-archive eex-archive-talks">
	<header class="eex-archive-header">
		<h1><?php esc_html_e( 'Sessions', 'emailexpert-events' ); ?></h1>
	</header>

	<section>
		<h2><?php esc_html_e( 'Upcoming sessions', 'emailexpert-events' ); ?></h2>
		<?php
		echo Components::render( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
			'upcoming-sessions',
			[
				'limit'          => 12,
				'show_subscribe' => 1,
			]
		);
		?>
	</section>

	<section>
		<h2><?php esc_html_e( 'Past sessions', 'emailexpert-events' ); ?></h2>
		<?php echo Components::render( 'past-sessions', [ 'limit' => 12 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
	</section>
</main>
<?php
get_footer();
