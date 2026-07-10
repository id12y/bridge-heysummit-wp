<?php
/**
 * Session card. Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data    Talk data from Components::talk_data().
 *     @type string $eex_context 'upcoming', 'past' or 'featured'.
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

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = __( 'Get tickets', 'emailexpert-events' );
}
?>
<article class="eex-card eex-card-talk eex-context-<?php echo esc_attr( $eex_context ); ?>"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<?php if ( ! empty( $eex_show['image'] ) && '' !== (string) ( $eex_data['image'] ?? '' ) ) : ?>
		<div class="eex-card-image">
			<img src="<?php echo esc_url( (string) $eex_data['image'] ); ?>" alt="" loading="lazy" />
		</div>
	<?php endif; ?>
	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<h3 class="eex-card-title">
		<a href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
	</h3>

	<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
		<p class="eex-card-time">
			<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $eex_show['venue'] ) && '' !== (string) ( $eex_data['venue'] ?? '' ) ) : ?>
		<p class="eex-card-venue"><?php echo esc_html( (string) $eex_data['venue'] ); ?></p>
	<?php endif; ?>

	<?php $eex_status_badges = Components::status_badges( $eex_data ); ?>
	<?php if ( $eex_show['categories'] && ( ! empty( $eex_data['categories'] ) || ! empty( $eex_status_badges ) ) ) : ?>
		<p class="eex-badges">
			<?php foreach ( (array) $eex_data['categories'] as $eex_term ) : ?>
				<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
			<?php endforeach; ?>
			<?php foreach ( $eex_status_badges as $eex_status_badge ) : ?>
				<span class="eex-badge eex-badge-status"><?php echo esc_html( $eex_status_badge ); ?></span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $eex_data['brand_instead'] ) && '' !== (string) ( $eex_data['brand_logo'] ?? '' ) ) : ?>
		<p class="eex-brand-logo">
			<img src="<?php echo esc_url( (string) $eex_data['brand_logo'] ); ?>" alt="<?php echo esc_attr( (string) ( $eex_data['brand_name'] ?? '' ) ); ?>" loading="lazy" />
		</p>
	<?php elseif ( $eex_show['speakers'] && ! empty( $eex_data['speakers'] ) ) : ?>
		<p class="eex-speaker-chips">
			<?php foreach ( $eex_data['speakers'] as $eex_speaker ) : ?>
				<?php TemplateLoader::part( 'speaker-chip', [ 'speaker' => $eex_speaker ] ); ?>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p class="eex-card-actions">
		<?php if ( 'past' === $eex_context && '' !== (string) $eex_data['replay_url'] ) : ?>
			<a class="eex-cta eex-cta-replay" href="<?php echo esc_url( (string) $eex_data['replay_url'] ); ?>"><?php esc_html_e( 'Watch replay', 'emailexpert-events' ); ?></a>
		<?php elseif ( 'past' !== $eex_context ) : ?>
			<?php
			$eex_buttons     = (string) ( $args['buttons'] ?? 'session' );
			$eex_tickets_url = 'session' === $eex_buttons ? '' : Components::ticketing_url( $eex_data, (array) ( $args['register'] ?? [] ) );
			$eex_session_url = 'tickets' === $eex_buttons ? '' : Components::session_url( $eex_data );
			$eex_drawer_id   = (string) ( $args['drawer'] ?? '' );

			$eex_session_text = (string) ( $args['session_text'] ?? '' );
			if ( '' === $eex_session_text ) {
				$eex_session_text = __( 'View session', 'emailexpert-events' );
			}
			?>
			<?php if ( '' !== $eex_tickets_url ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo '' === $eex_session_url ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?><?php echo '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> href="<?php echo esc_url( $eex_tickets_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_session_url ) : ?>
				<a class="eex-cta eex-cta-session" data-eex-cta="1" href="<?php echo esc_url( $eex_session_url ); ?>"><?php echo esc_html( $eex_session_text ); ?></a>
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
	</p>
</article>
