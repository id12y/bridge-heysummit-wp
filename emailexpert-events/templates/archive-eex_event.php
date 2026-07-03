<?php
/**
 * Events archive: upcoming then past.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="eex eex-archive eex-archive-events">
	<header class="eex-archive-header">
		<h1><?php esc_html_e( 'Events', 'emailexpert-events' ); ?></h1>
	</header>

	<section>
		<h2><?php esc_html_e( 'Upcoming events', 'emailexpert-events' ); ?></h2>
		<?php echo Components::render( 'upcoming-events', [ 'limit' => 12 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
	</section>

	<section>
		<h2><?php esc_html_e( 'Past events', 'emailexpert-events' ); ?></h2>
		<?php echo Components::render( 'past-events', [] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
	</section>
</main>
<?php
get_footer();
