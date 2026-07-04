<?php
/**
 * Ultra-compact session row (layout="compact"): time and title only.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data    Talk data from Components::talk_data().
 *     @type string $context 'upcoming', 'past' or 'featured'.
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_data = (array) ( $args['data'] ?? [] );

if ( empty( $eex_data['id'] ) ) {
	return;
}
?>
<article class="eex-compact-row"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<span class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></span>
	<span class="eex-compact-time">
		<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
	</span>
	<a class="eex-compact-title" href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
</article>
