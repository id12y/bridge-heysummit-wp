<?php
/**
 * Speakers archive.
 *
 * @package Emailexpert\Events
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="eex eex-archive eex-archive-speakers">
	<header class="eex-archive-header">
		<h1><?php esc_html_e( 'Speakers', 'emailexpert-events' ); ?></h1>
	</header>

	<?php echo Components::render( 'speakers', [ 'columns' => 4 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time. ?>
</main>
<?php
get_footer();
