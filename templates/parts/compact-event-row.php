<?php
/**
 * Compact event row: one More Events line — date, title, nothing louder.
 * No event artwork here by design (the homepage default keeps the rail
 * quiet). Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $event Event data array (see Data\Repository).
 * }
 */

use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_event = (array) ( $args['event'] ?? [] );

if ( empty( $eex_event['title'] ) ) {
	return;
}

$eex_first = (string) ( $eex_event['first_talk_at'] ?? '' );
$eex_tz    = (string) ( $eex_event['timezone'] ?? '' );
$eex_url   = (string) ( $eex_event['url'] ?? '' );
?>
<article class="eex-compact-event" data-eex-event-id="<?php echo esc_attr( (string) ( $eex_event['hs_id'] ?? '' ) ); ?>">
	<span class="eex-compact-event__date">
		<?php
		if ( '' !== $eex_first ) {
			echo TimeFormat::render_date( $eex_first, $eex_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		} elseif ( ! empty( $eex_event['evergreen'] ) ) {
			esc_html_e( 'On demand', 'emailexpert-events' );
		}
		?>
	</span>
	<?php if ( '' !== $eex_url ) : ?>
		<a class="eex-compact-event__title" href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="more-events"><?php echo esc_html( (string) $eex_event['title'] ); ?></a>
	<?php else : ?>
		<span class="eex-compact-event__title"><?php echo esc_html( (string) $eex_event['title'] ); ?></span>
	<?php endif; ?>
</article>
