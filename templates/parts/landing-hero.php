<?php
/**
 * Event landing hero: the opening act of the Event Landing Page. Three
 * styles (light editorial, dark event, compact) share this one markup;
 * the style only changes classes and tokens. Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $target         Feature target (event or presented session).
 *     @type array  $event          Enriched owning event.
 *     @type array  $cta            CTA view model (CtaResolver).
 *     @type array  $media          Resolved media (MediaResolver).
 *     @type string $drawer         Ticket panel element ID ('' = plain link).
 *     @type array  $rsvp           RSVP form context ([] = none).
 *     @type string $heading_tag    'h1' (single event template) or 'h2' (embedded).
 *     @type string $style          'editorial', 'dark' or 'compact'.
 *     @type bool   $show_countdown Show a countdown while upcoming.
 *     @type string $reg_count_html Pre-rendered registration counter ('' = none).
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_target = (array) ( $args['target'] ?? [] );
$eex_event  = (array) ( $args['event'] ?? [] );

if ( empty( $eex_event['title'] ) ) {
	return;
}

$eex_session   = isset( $eex_target['session'] ) && is_array( $eex_target['session'] ) ? $eex_target['session'] : null;
$eex_lifecycle = (array) ( $eex_target['lifecycle'] ?? [] );
$eex_status    = (string) ( $eex_lifecycle['status'] ?? 'scheduled' );
$eex_cta       = (array) ( $args['cta'] ?? [] );
$eex_media     = (array) ( $args['media'] ?? [] );
$eex_rsvp      = (array) ( $args['rsvp'] ?? [] );
$eex_drawer_id = (string) ( $args['drawer'] ?? '' );
$eex_style     = in_array( (string) ( $args['style'] ?? 'editorial' ), [ 'editorial', 'dark', 'compact' ], true ) ? (string) $args['style'] : 'editorial';
$eex_tag       = 'h1' === (string) ( $args['heading_tag'] ?? 'h2' ) ? 'h1' : 'h2';

if ( null !== $eex_session && '' !== (string) ( $eex_session['external_url'] ?? '' ) ) {
	$eex_rsvp = [];
}

$eex_primary   = isset( $eex_cta['primary'] ) && is_array( $eex_cta['primary'] ) ? $eex_cta['primary'] : null;
$eex_secondary = isset( $eex_cta['secondary'] ) && is_array( $eex_cta['secondary'] ) ? $eex_cta['secondary'] : null;
$eex_calendar  = (array) ( $eex_cta['calendar'] ?? [] );

$eex_status_labels = [
	'cancelled' => __( 'Cancelled', 'emailexpert-events' ),
	'postponed' => __( 'Postponed', 'emailexpert-events' ),
	'replay'    => __( 'Replays available', 'emailexpert-events' ),
	'ended'     => __( 'Event finished', 'emailexpert-events' ),
];

$eex_session_attrs = null !== $eex_session ? Components::session_attrs( $eex_session ) : '';

$eex_countdown = ! empty( $args['show_countdown'] )
	&& in_array( $eex_status, [ 'scheduled', 'starting_soon' ], true )
	&& (int) ( $eex_lifecycle['starts_ts'] ?? 0 ) > 0;

$eex_registration_line = '';
if ( in_array( $eex_status, [ 'scheduled', 'starting_soon', 'live', 'evergreen' ], true ) ) {
	$eex_registration_line = ! empty( $eex_lifecycle['registration_open'] )
		? __( 'Registration is open', 'emailexpert-events' )
		: __( 'Registration opens soon', 'emailexpert-events' );
}

$eex_has_media = 'image' === (string) ( $eex_media['type'] ?? '' ) || ( 'portraits' === (string) ( $eex_media['type'] ?? '' ) && ! empty( $eex_media['speakers'] ) );
?>
<header class="eex-el__hero eex-el__hero--<?php echo esc_attr( $eex_style ); ?><?php echo $eex_has_media ? '' : ' eex-el__hero--text'; ?>"<?php echo $eex_session_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<div class="eex-el__hero-body">
		<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

		<?php if ( ! empty( $eex_event['series'] ) || isset( $eex_status_labels[ $eex_status ] ) ) : ?>
			<p class="eex-badges">
				<?php if ( isset( $eex_status_labels[ $eex_status ] ) ) : ?>
					<span class="eex-badge eex-badge-status eex-badge--<?php echo esc_attr( $eex_status ); ?>"><?php echo esc_html( $eex_status_labels[ $eex_status ] ); ?></span>
				<?php endif; ?>
				<?php foreach ( (array) $eex_event['series'] as $eex_series ) : ?>
					<?php $eex_series = (array) $eex_series; ?>
					<?php if ( '' !== (string) ( $eex_series['name'] ?? '' ) ) : ?>
						<span class="eex-badge eex-badge-series-<?php echo esc_attr( (string) ( $eex_series['slug'] ?? '' ) ); ?>"><?php echo esc_html( (string) $eex_series['name'] ); ?></span>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-el__hero-title"><?php echo esc_html( (string) $eex_event['title'] ); ?></<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

		<?php if ( null !== $eex_session && (string) ( $eex_session['title'] ?? '' ) !== (string) $eex_event['title'] ) : ?>
			<p class="eex-el__hero-session"><?php echo esc_html( (string) $eex_session['title'] ); ?></p>
		<?php endif; ?>

		<div class="eex-el__hero-meta">
			<?php if ( null !== $eex_session && '' !== (string) ( $eex_session['starts_at'] ?? '' ) ) : ?>
				<p class="eex-el__hero-time"><?php echo TimeFormat::render( (string) $eex_session['starts_at'], (string) ( $eex_session['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></p>
			<?php elseif ( '' !== (string) ( $eex_event['first_talk_at'] ?? '' ) ) : ?>
				<p class="eex-el__hero-time"><?php echo TimeFormat::render_range( (string) $eex_event['first_talk_at'], (string) ( $eex_event['last_talk_at'] ?? '' ), (string) ( $eex_event['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></p>
			<?php endif; ?>

			<?php
			$eex_place = null !== $eex_session ? (string) ( $eex_session['venue'] ?? '' ) : (string) ( $eex_event['venue'] ?? '' );

			$eex_format_bits = [];
			if ( null !== $eex_session ) {
				$eex_format_bits = Components::status_badges( $eex_session, true );
			} elseif ( '' !== $eex_place ) {
				$eex_format_bits[] = __( 'In person', 'emailexpert-events' );
			}
			?>
			<?php if ( ! empty( $eex_format_bits ) || '' !== $eex_place ) : ?>
				<p class="eex-el__hero-where">
					<?php foreach ( $eex_format_bits as $eex_bit ) : ?>
						<span class="eex-badge"><?php echo esc_html( $eex_bit ); ?></span>
					<?php endforeach; ?>
					<?php if ( '' !== $eex_place ) : ?>
						<span class="eex-el__hero-venue"><?php echo esc_html( $eex_place ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<?php if ( '' !== $eex_registration_line ) : ?>
				<p class="eex-el__hero-reg"><?php echo esc_html( $eex_registration_line ); ?></p>
			<?php endif; ?>

			<?php echo (string) ( $args['reg_count_html'] ?? '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output escaped at build time. ?>
		</div>

		<?php if ( $eex_countdown ) : ?>
			<p class="eex-countdown" data-eex-countdown="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', (int) $eex_lifecycle['starts_ts'] ) ); ?>" aria-live="polite"></p>
		<?php endif; ?>

		<?php if ( null !== $eex_primary || null !== $eex_secondary ) : ?>
			<p class="eex-card-actions">
				<?php if ( null !== $eex_primary && ! empty( $eex_primary['rsvp'] ) && ! empty( $eex_rsvp ) ) : ?>
					<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
				<?php elseif ( null !== $eex_primary ) : ?>
					<a class="eex-cta eex-cta-register"<?php echo ! empty( $eex_primary['drawer'] ) && '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['hs_id'] ?? '' ) : '' ) . '" data-eex-talk-title="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['title'] ?? '' ) : '' ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
				<?php endif; ?>
				<?php if ( null !== $eex_secondary ) : ?>
					<a class="eex-cta eex-cta-session"<?php echo ! empty( $eex_cta['flip'] ) ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?> data-eex-action="<?php echo esc_attr( (string) $eex_secondary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_secondary['url'] ); ?>"><?php echo esc_html( (string) $eex_secondary['label'] ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $eex_calendar ) ) : ?>
			<p class="eex-el__hero-calendar">
				<?php if ( isset( $eex_calendar['ics'] ) ) : ?>
					<a class="eex-cta-secondary" data-eex-action="calendar" href="<?php echo esc_url( (string) $eex_calendar['ics'] ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
				<?php if ( isset( $eex_calendar['google'] ) ) : ?>
					<a class="eex-cta-secondary" data-eex-action="calendar" href="<?php echo esc_url( (string) $eex_calendar['google'] ); ?>" rel="noopener"><?php esc_html_e( 'Google Calendar', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
				<?php if ( isset( $eex_calendar['subscribe'] ) ) : ?>
					<a class="eex-cta-secondary" data-eex-action="calendar" href="<?php echo esc_url( (string) $eex_calendar['subscribe'] ); ?>"><?php esc_html_e( 'Subscribe to calendar', 'emailexpert-events' ); ?></a>
				<?php endif; ?>
			</p>
		<?php endif; ?>

		<?php if ( null !== $eex_primary && ! empty( $eex_primary['rsvp'] ) && ! empty( $eex_rsvp ) ) : ?>
			<?php
			TemplateLoader::part(
				'register-form',
				[
					'event_id'    => (string) ( $eex_rsvp['event_id'] ?? '' ),
					'ticket_id'   => (string) ( $eex_rsvp['ticket_id'] ?? '' ),
					'price_id'    => (string) ( $eex_rsvp['price_id'] ?? '' ),
					'talk_id'     => null !== $eex_session ? (string) ( $eex_session['hs_id'] ?? '' ) : '',
					'submit_text' => __( 'RSVP', 'emailexpert-events' ),
					'hidden'      => true,
				]
			);
			?>
		<?php endif; ?>
	</div>

	<?php if ( 'portraits' === (string) ( $eex_media['type'] ?? '' ) && ! empty( $eex_media['speakers'] ) ) : ?>
		<div class="eex-el__hero-media eex-hh__portraits">
			<?php foreach ( array_slice( (array) $eex_media['speakers'], 0, 5 ) as $eex_speaker ) : ?>
				<?php $eex_speaker = (array) $eex_speaker; ?>
				<?php if ( (int) ( $eex_speaker['photo_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
					<?php
					echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
						(int) $eex_speaker['photo_id'],
						'thumbnail',
						false,
						[
							'class'   => 'eex-hh__portrait',
							'loading' => 'lazy',
							'alt'     => (string) ( $eex_speaker['name'] ?? '' ),
						]
					);
					?>
				<?php elseif ( '' !== (string) ( $eex_speaker['photo_url'] ?? '' ) ) : ?>
					<img class="eex-hh__portrait" loading="lazy" src="<?php echo esc_url( (string) $eex_speaker['photo_url'] ); ?>" alt="<?php echo esc_attr( (string) ( $eex_speaker['name'] ?? '' ) ); ?>" />
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
	<?php elseif ( 'image' === (string) ( $eex_media['type'] ?? '' ) ) : ?>
		<figure class="eex-el__hero-media<?php echo 'contain' === (string) ( $eex_media['fit'] ?? 'cover' ) ? ' eex-media--contain' : ''; ?>">
			<?php if ( (int) ( $eex_media['id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
				<?php
				echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
					(int) $eex_media['id'],
					'large',
					false,
					[
						'class'         => 'eex-el__hero-img',
						'alt'           => '',
						'fetchpriority' => 'high',
					]
				);
				?>
			<?php elseif ( '' !== (string) ( $eex_media['url'] ?? '' ) ) : ?>
				<img class="eex-el__hero-img" src="<?php echo esc_url( (string) $eex_media['url'] ); ?>" alt="" fetchpriority="high" />
			<?php endif; ?>
		</figure>
	<?php endif; ?>
</header>
