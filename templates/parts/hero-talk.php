<?php
/**
 * Next-session hero: the single soonest upcoming session, prominently.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data           Talk data from Components::talk_data().
 *     @type string $layout         Hero style: panel|banner|spotlight|minimal.
 *     @type array  $show           Display toggles (speakers, ics, google).
 *     @type bool   $show_countdown Whether to render the countdown.
 *     @type string $register_text  CTA label ('' = "Register").
 *     @type array  $register       Register settings (mode, url).
 *     @type string $drawer         Ticket panel element ID ('' = plain link).
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_data = (array) ( $args['data'] ?? [] );
$eex_show = array_merge(
	[
		'speakers' => true,
		'ics'      => true,
		'google'   => true,
	],
	(array) ( $args['show'] ?? [] )
);

if ( empty( $eex_data['id'] ) ) {
	return;
}

$eex_layout = (string) ( $args['layout'] ?? 'panel' );
if ( ! in_array( $eex_layout, [ 'panel', 'banner', 'spotlight', 'minimal' ], true ) ) {
	$eex_layout = 'panel';
}

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = __( 'Register', 'emailexpert-events' );
}

$eex_register_url = Components::register_url( $eex_data, (array) ( $args['register'] ?? [] ) );
$eex_drawer_id    = (string) ( $args['drawer'] ?? '' );

$eex_countdown = ! empty( $args['show_countdown'] ) && '' !== (string) $eex_data['starts_at'];
// The panel style groups the countdown with the actions; every other style
// keeps it beside the time.
$eex_countdown_aside = $eex_countdown && 'panel' === $eex_layout;

$eex_countdown_html = '';
if ( $eex_countdown ) {
	$eex_countdown_html = sprintf(
		'<p class="eex-countdown" data-eex-countdown="%s" aria-live="polite"></p>',
		esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( (string) $eex_data['starts_at'] ) ) )
	);
}
?>
<article class="eex-hero eex-hero-<?php echo esc_attr( $eex_layout ); ?>"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<div class="eex-hero-main">
	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<p class="eex-hero-kicker"><?php esc_html_e( 'Up next', 'emailexpert-events' ); ?></p>

	<h2 class="eex-hero-title">
		<a href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
	</h2>

	<div class="eex-hero-meta">
		<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
			<p class="eex-hero-time">
				<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
			</p>
		<?php endif; ?>

		<?php if ( $eex_countdown && ! $eex_countdown_aside ) : ?>
			<?php echo $eex_countdown_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		<?php endif; ?>
	</div>

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

	</div>

	<div class="eex-hero-aside">
		<?php if ( $eex_countdown_aside ) : ?>
			<?php echo $eex_countdown_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
		<?php endif; ?>

		<p class="eex-card-actions">
			<?php if ( '' !== $eex_register_url ) : ?>
				<a class="eex-cta eex-cta-register" data-eex-cta="1"<?php echo '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '"' : ''; ?> href="<?php echo esc_url( $eex_register_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== (string) $eex_data['starts_at'] ) : ?>
				<?php if ( $eex_show['ics'] ) : ?>
					<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::download_url( $eex_data ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
				<?php if ( $eex_show['google'] ) : ?>
					<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::google_url( $eex_data ) ); ?>" rel="noopener"><?php esc_html_e( 'Google Calendar', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
			<?php endif; ?>
		</p>
	</div>
</article>
