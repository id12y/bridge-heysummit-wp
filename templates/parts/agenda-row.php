<?php
/**
 * Agenda row (layout="agenda"): one session inside a day group, modelled on
 * the site's original design — time and badge, title, speaker with photo and
 * role, prominent action — kept vertically compact. Override by copying to
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
<article class="eex-agenda-row"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<p class="eex-agenda-meta">
		<span class="eex-agenda-time">
			<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'], 'H:i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
		</span>
		<?php if ( ! empty( $eex_show['venue'] ) && '' !== (string) ( $eex_data['venue'] ?? '' ) ) : ?>
			<span class="eex-agenda-venue"><?php echo esc_html( (string) $eex_data['venue'] ); ?></span>
		<?php endif; ?>
		<span class="eex-badge eex-badge-online"><?php esc_html_e( 'Online', 'emailexpert-events' ); ?></span>
		<?php if ( $eex_show['categories'] && ! empty( $eex_data['categories'] ) ) : ?>
			<?php foreach ( $eex_data['categories'] as $eex_term ) : ?>
				<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
			<?php endforeach; ?>
		<?php endif; ?>
	</p>

	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<h4 class="eex-agenda-title">
		<a href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
	</h4>

	<?php if ( $eex_show['speakers'] && ! empty( $eex_data['speakers'] ) ) : ?>
		<p class="eex-agenda-speakers">
			<?php foreach ( $eex_data['speakers'] as $eex_speaker ) : ?>
				<?php
				$eex_speaker   = (array) $eex_speaker;
				$eex_photo_id  = (int) ( $eex_speaker['photo_id'] ?? 0 );
				$eex_photo_url = (string) ( $eex_speaker['photo_url'] ?? '' );
				?>
				<span class="eex-agenda-speaker">
					<?php if ( $eex_photo_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
						<?php
						echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
							$eex_photo_id,
							'thumbnail',
							false,
							[
								'class'   => 'eex-agenda-speaker-photo',
								'loading' => 'lazy',
								'alt'     => (string) ( $eex_speaker['name'] ?? '' ),
							]
						);
						?>
					<?php elseif ( '' !== $eex_photo_url ) : ?>
						<img class="eex-agenda-speaker-photo" loading="lazy" src="<?php echo esc_url( $eex_photo_url ); ?>" alt="<?php echo esc_attr( (string) ( $eex_speaker['name'] ?? '' ) ); ?>" />
					<?php endif; ?>
					<span class="eex-agenda-speaker-text">
						<a class="eex-agenda-speaker-name" href="<?php echo esc_url( (string) ( $eex_speaker['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) ( $eex_speaker['name'] ?? '' ) ); ?></a>
						<?php if ( '' !== (string) ( $eex_speaker['headline'] ?? '' ) ) : ?>
							<span class="eex-agenda-speaker-role"><?php echo esc_html( (string) $eex_speaker['headline'] ); ?></span>
						<?php endif; ?>
					</span>
				</span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<p class="eex-agenda-actions">
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
				<a class="eex-cta eex-cta-register"<?php echo '' === $eex_session_url ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?><?php echo '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( (string) ( $eex_data['hs_id'] ?? '' ) ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> href="<?php echo esc_url( $eex_tickets_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
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
