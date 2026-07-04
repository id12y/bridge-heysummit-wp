<?php
/**
 * Event list row (layout="list"). Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $event   Event data array (see Data\Repository).
 *     @type string $context 'upcoming' or 'past'.
 * }
 */

use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_event   = (array) ( $args['event'] ?? [] );
$eex_context = (string) ( $args['context'] ?? 'upcoming' );

if ( empty( $eex_event['title'] ) && empty( $eex_event['id'] ) ) {
	return;
}

$eex_first = (string) ( $eex_event['first_talk_at'] ?? '' );
$eex_tz    = (string) ( $eex_event['timezone'] ?? '' );
$eex_open  = ! empty( $eex_event['open'] );
$eex_url   = (string) ( $eex_event['event_url'] ?? '' );
?>
<article class="eex-list-row eex-event-row">
	<span class="eex-list-time">
		<?php echo '' !== $eex_first ? TimeFormat::render( $eex_first, $eex_tz ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
	</span>
	<span class="eex-list-main">
		<a class="eex-list-title" href="<?php echo esc_url( (string) ( $eex_event['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $eex_event['title'] ?? '' ) ); ?></a>
	</span>
	<span class="eex-list-actions">
		<?php if ( 'upcoming' === $eex_context && $eex_open && '' !== $eex_url ) : ?>
			<a class="eex-cta eex-cta-register" href="<?php echo esc_url( $eex_url ); ?>"><?php esc_html_e( 'Register', 'emailexpert-events' ); ?></a>
		<?php endif; ?>
		<a class="eex-cta-secondary" href="<?php echo esc_url( (string) ( $eex_event['url'] ?? '' ) ); ?>"><?php esc_html_e( 'View details', 'emailexpert-events' ); ?></a>
	</span>
</article>
