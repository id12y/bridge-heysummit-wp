<?php
/**
 * Session list row (layout="list"). Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data    Talk data from Components::talk_data().
 *     @type string $context 'upcoming', 'past' or 'featured'.
 *     @type array  $show    Display toggles (speakers, categories, ics, google).
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_data    = (array) ( $args['data'] ?? [] );
$eex_context = (string) ( $args['context'] ?? 'upcoming' );
$eex_show    = array_merge(
	[
		'speakers'   => true,
		'categories' => true,
		'ics'        => true,
		'google'     => true,
	],
	(array) ( $args['show'] ?? [] )
);

if ( empty( $eex_data['id'] ) ) {
	return;
}
?>
<article class="eex-list-row"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<span class="eex-list-time">
		<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
	</span>

	<span class="eex-list-main">
		<a class="eex-list-title" href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>

		<?php if ( $eex_show['categories'] && ! empty( $eex_data['categories'] ) ) : ?>
			<?php foreach ( $eex_data['categories'] as $eex_term ) : ?>
				<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $eex_show['speakers'] && ! empty( $eex_data['speakers'] ) ) : ?>
			<span class="eex-speaker-chips">
				<?php foreach ( $eex_data['speakers'] as $eex_speaker ) : ?>
					<?php TemplateLoader::part( 'speaker-chip', [ 'speaker' => $eex_speaker ] ); ?>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>
	</span>

	<span class="eex-list-actions">
		<?php if ( 'past' === $eex_context && '' !== (string) $eex_data['replay_url'] ) : ?>
			<a class="eex-cta eex-cta-replay" href="<?php echo esc_url( (string) $eex_data['replay_url'] ); ?>"><?php esc_html_e( 'Watch replay', 'emailexpert-events' ); ?></a>
		<?php elseif ( 'past' !== $eex_context ) : ?>
			<?php $eex_register_url = (string) ( $eex_data['event_url'] ?: $eex_data['talk_url'] ); ?>
			<?php if ( '' !== $eex_register_url ) : ?>
				<a class="eex-cta eex-cta-register" data-eex-cta="1" href="<?php echo esc_url( $eex_register_url ); ?>"><?php esc_html_e( 'Register', 'emailexpert-events' ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
				<?php if ( $eex_show['ics'] ) : ?>
					<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::download_url( $eex_data ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
				<?php if ( $eex_show['google'] ) : ?>
					<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::google_url( $eex_data ) ); ?>" rel="noopener"><?php esc_html_e( 'Google Calendar', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</span>
</article>
