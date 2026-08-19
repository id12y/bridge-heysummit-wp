<?php
/**
 * Homepage featured event/session card: prominent but compact — the event
 * column of the Homepage Editorial Hero. Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $target         Feature target (FeatureTargetResolver).
 *     @type array  $cta            CTA view model (CtaResolver).
 *     @type array  $media          Resolved media (MediaResolver).
 *     @type string $drawer         Ticket panel element ID ('' = plain link).
 *     @type array  $rsvp           RSVP form context ([] = none).
 *     @type string $heading_tag    Heading tag for the card title.
 *     @type string $eyebrow        Eyebrow ('' = "Next from emailexpert").
 *     @type bool   $show_speakers  Show speaker names.
 *     @type bool   $show_countdown Show a countdown.
 *     @type bool   $eager          High-priority image.
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_target = (array) ( $args['target'] ?? [] );

if ( empty( $eex_target['event'] ) ) {
	return;
}

$eex_event     = (array) $eex_target['event'];
$eex_session   = isset( $eex_target['session'] ) && is_array( $eex_target['session'] ) ? $eex_target['session'] : null;
$eex_lifecycle = (array) ( $eex_target['lifecycle'] ?? [] );
$eex_status    = (string) ( $eex_lifecycle['status'] ?? 'scheduled' );
$eex_cta       = (array) ( $args['cta'] ?? [] );
$eex_media     = (array) ( $args['media'] ?? [] );
$eex_rsvp      = (array) ( $args['rsvp'] ?? [] );
$eex_drawer_id = (string) ( $args['drawer'] ?? '' );
$eex_tag       = in_array( (string) ( $args['heading_tag'] ?? 'h2' ), [ 'h2', 'h3' ], true ) ? (string) $args['heading_tag'] : 'h2';

// An externally hosted session never uses the in-place form (existing rule).
if ( null !== $eex_session && '' !== (string) ( $eex_session['external_url'] ?? '' ) ) {
	$eex_rsvp = [];
}

$eex_eyebrow = '' !== trim( (string) ( $args['eyebrow'] ?? '' ) )
	? (string) $args['eyebrow']
	: __( 'Next from emailexpert', 'emailexpert-events' );

$eex_title = (string) ( $eex_target['title'] ?? '' );

$eex_title_url = null !== $eex_session
	? Components::session_url( $eex_session )
	: (string) ( $eex_event['url'] ?? '' );

// Status labels are text, never colour alone.
$eex_status_labels = [
	'cancelled' => __( 'Cancelled', 'emailexpert-events' ),
	'postponed' => __( 'Postponed', 'emailexpert-events' ),
	'replay'    => __( 'Replays available', 'emailexpert-events' ),
	'ended'     => __( 'Event finished', 'emailexpert-events' ),
];

$eex_notice = (string) ( $eex_lifecycle['notice'] ?? '' );

// Session attributes wire the client-side live handling (the Join-now flip
// and the live slot); an event-level card carries none, and the server
// never claims live state in text.
$eex_session_attrs = null !== $eex_session ? Components::session_attrs( $eex_session ) : '';

$eex_analytics = sprintf(
	' data-eex-position="featured" data-eex-source="%s" data-eex-lifecycle="%s" data-eex-event-id="%s"%s',
	esc_attr( (string) ( $eex_target['source'] ?? '' ) ),
	esc_attr( $eex_status ),
	esc_attr( (string) ( $eex_event['hs_id'] ?? '' ) ),
	null !== $eex_session ? ' data-eex-session-id="' . esc_attr( (string) ( $eex_session['hs_id'] ?? '' ) ) . '"' : ''
);

$eex_countdown = ! empty( $args['show_countdown'] )
	&& in_array( $eex_status, [ 'scheduled', 'starting_soon' ], true )
	&& (int) ( $eex_lifecycle['starts_ts'] ?? 0 ) > 0;

$eex_primary   = isset( $eex_cta['primary'] ) && is_array( $eex_cta['primary'] ) ? $eex_cta['primary'] : null;
$eex_secondary = isset( $eex_cta['secondary'] ) && is_array( $eex_cta['secondary'] ) ? $eex_cta['secondary'] : null;
$eex_calendar  = (array) ( $eex_cta['calendar'] ?? [] );
?>
<article class="eex-hh__event-card eex-hh__event-card--<?php echo esc_attr( $eex_status ); ?>"<?php echo $eex_session_attrs . $eex_analytics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helpers above. ?>>
	<p class="eex-comp-eyebrow eex-eyebrow"><?php echo esc_html( $eex_eyebrow ); ?></p>

	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<?php if ( isset( $eex_status_labels[ $eex_status ] ) ) : ?>
		<p class="eex-badges"><span class="eex-badge eex-badge-status eex-badge--<?php echo esc_attr( $eex_status ); ?>"><?php echo esc_html( $eex_status_labels[ $eex_status ] ); ?></span></p>
	<?php endif; ?>

	<?php if ( 'portraits' === (string) ( $eex_media['type'] ?? '' ) && ! empty( $eex_media['speakers'] ) ) : ?>
		<div class="eex-hh__portraits">
			<?php foreach ( array_slice( (array) $eex_media['speakers'], 0, 4 ) as $eex_speaker ) : ?>
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
		<figure class="eex-hh__event-media<?php echo 'contain' === (string) ( $eex_media['fit'] ?? 'cover' ) ? ' eex-media--contain' : ''; ?>">
			<?php if ( (int) ( $eex_media['id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
				<?php
				echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup.
					(int) $eex_media['id'],
					'large',
					false,
					array_merge(
						[
							'class' => 'eex-hh__event-img',
							'alt'   => '',
						],
						! empty( $args['eager'] ) ? [ 'fetchpriority' => 'high' ] : [ 'loading' => 'lazy' ]
					)
				);
				?>
			<?php elseif ( '' !== (string) ( $eex_media['url'] ?? '' ) ) : ?>
				<img class="eex-hh__event-img" src="<?php echo esc_url( (string) $eex_media['url'] ); ?>" alt="" <?php echo ! empty( $args['eager'] ) ? 'fetchpriority="high"' : 'loading="lazy"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- one of two literal attributes. ?> />
			<?php endif; ?>
		</figure>
	<?php endif; ?>

	<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__event-title">
		<?php if ( '' !== $eex_title_url ) : ?>
			<a href="<?php echo esc_url( $eex_title_url ); ?>" data-eex-action="event-details"><?php echo esc_html( $eex_title ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $eex_title ); ?>
		<?php endif; ?>
	</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

	<?php if ( null !== $eex_session && '' !== (string) ( $eex_session['starts_at'] ?? '' ) ) : ?>
		<p class="eex-hh__event-time">
			<?php echo TimeFormat::render( (string) $eex_session['starts_at'], (string) ( $eex_session['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
		</p>
	<?php elseif ( '' !== (string) ( $eex_event['first_talk_at'] ?? '' ) ) : ?>
		<p class="eex-hh__event-time">
			<?php echo TimeFormat::render_range( (string) $eex_event['first_talk_at'], (string) ( $eex_event['last_talk_at'] ?? '' ), (string) ( $eex_event['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
		</p>
	<?php endif; ?>

	<?php
	$eex_format_bits = [];
	if ( null !== $eex_session ) {
		$eex_format_bits = Components::status_badges( $eex_session, true );
	} elseif ( '' !== (string) ( $eex_event['venue'] ?? '' ) ) {
		$eex_format_bits[] = __( 'In person', 'emailexpert-events' );
	}

	$eex_place = null !== $eex_session ? (string) ( $eex_session['venue'] ?? '' ) : (string) ( $eex_event['venue'] ?? '' );
	?>
	<?php if ( ! empty( $eex_format_bits ) || '' !== $eex_place ) : ?>
		<p class="eex-hh__event-where">
			<?php foreach ( $eex_format_bits as $eex_bit ) : ?>
				<span class="eex-badge"><?php echo esc_html( $eex_bit ); ?></span>
			<?php endforeach; ?>
			<?php if ( '' !== $eex_place ) : ?>
				<span class="eex-hh__event-venue"><?php echo esc_html( $eex_place ); ?></span>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $eex_notice ) : ?>
		<p class="eex-hh__event-notice"><?php echo esc_html( $eex_notice ); ?></p>
	<?php endif; ?>

	<?php $eex_intro = trim( (string) ( $eex_event['presentation']['intro'] ?? '' ) ); ?>
	<?php if ( '' !== $eex_intro ) : ?>
		<p class="eex-hh__event-intro"><?php echo esc_html( Components::truncate( wp_strip_all_tags( $eex_intro ), 160 ) ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $args['show_speakers'] ) && ! empty( $eex_target['speakers'] ) ) : ?>
		<p class="eex-speaker-chips">
			<?php foreach ( array_slice( (array) $eex_target['speakers'], 0, 4 ) as $eex_speaker ) : ?>
				<?php
				TemplateLoader::part(
					'speaker-chip',
					[
						'speaker' => (array) $eex_speaker,
						'info'    => 'headline',
					]
				);
				?>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php if ( $eex_countdown ) : ?>
		<p class="eex-countdown" data-eex-countdown="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', (int) $eex_lifecycle['starts_ts'] ) ); ?>" aria-live="polite"></p>
	<?php endif; ?>

	<?php if ( null !== $eex_primary || null !== $eex_secondary ) : ?>
		<p class="eex-card-actions">
			<?php if ( null !== $eex_primary && ! empty( $eex_primary['rsvp'] ) && ! empty( $eex_rsvp ) ) : ?>
				<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
			<?php elseif ( null !== $eex_primary ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo null === $eex_secondary && null !== $eex_session ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?><?php echo ! empty( $eex_primary['drawer'] ) && '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['hs_id'] ?? '' ) : '' ) . '" data-eex-talk-title="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['title'] ?? '' ) : '' ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
			<?php endif; ?>
			<?php if ( null !== $eex_secondary ) : ?>
				<a class="eex-cta eex-cta-session"<?php echo ! empty( $eex_cta['flip'] ) ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?> data-eex-action="<?php echo esc_attr( (string) $eex_secondary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_secondary['url'] ); ?>"><?php echo esc_html( (string) $eex_secondary['label'] ); ?></a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $eex_calendar ) ) : ?>
		<p class="eex-hh__event-calendar">
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
</article>
