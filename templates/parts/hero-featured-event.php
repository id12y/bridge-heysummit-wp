<?php
/**
 * Homepage featured event/session card: the event column of the Homepage
 * Editorial Hero — prominent, professionally produced, never a sidebar
 * widget. Format pill, icon-led meta, portrait/name/role speaker blocks
 * and a single CTA hierarchy, per the approved design. Override by
 * copying to yourtheme/emailexpert-events/parts/.
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
 *     @type bool   $show_speakers  Show speakers.
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

// The format pill: the platform's own label leads (D107); otherwise the
// in-person flag / venue decides, and a venue-less target reads as online —
// the same claim the schema layer already makes for it.
$eex_place = null !== $eex_session ? (string) ( $eex_session['venue'] ?? '' ) : (string) ( $eex_event['venue'] ?? '' );

$eex_pill = '';
if ( null !== $eex_session && '' !== trim( (string) ( $eex_session['format'] ?? '' ) ) ) {
	$eex_pill = trim( (string) $eex_session['format'] );
} elseif ( ( null !== $eex_session && ! empty( $eex_session['inperson'] ) ) || ( null === $eex_session && '' !== $eex_place ) ) {
	$eex_pill = __( 'In person', 'emailexpert-events' );
} else {
	$eex_pill = __( 'Online', 'emailexpert-events' );
}

// Lifecycle states outrank the format pill.
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

// Speakers render as portrait/name/role blocks; the separate portrait strip
// only stands in when the speaker list itself is switched off.
$eex_speakers       = null !== $eex_session ? array_slice( (array) ( $eex_target['speakers'] ?? [] ), 0, 3 ) : [];
$eex_show_speakers  = ! empty( $args['show_speakers'] ) && ! empty( $eex_speakers );
$eex_portrait_strip = ! $eex_show_speakers && 'portraits' === (string) ( $eex_media['type'] ?? '' ) && ! empty( $eex_media['speakers'] );

// Tiny inline glyphs (aria-hidden, stroke = currentColor): no icon library.
$eex_icon_calendar = '<svg class="eex-hh__icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><rect x="1.5" y="2.5" width="13" height="12" rx="1.5" fill="none" stroke="currentColor"/><path d="M1.5 6h13M5 1v3M11 1v3" fill="none" stroke="currentColor"/></svg>';
$eex_icon_globe    = '<svg class="eex-hh__icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><circle cx="8" cy="8" r="6.5" fill="none" stroke="currentColor"/><path d="M1.5 8h13M8 1.5c-4.5 4-4.5 9 0 13c4.5-4 4.5-9 0-13z" fill="none" stroke="currentColor"/></svg>';
$eex_icon_pin      = '<svg class="eex-hh__icon" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path d="M8 1.5a4.5 4.5 0 0 1 4.5 4.5c0 3.5-4.5 8.5-4.5 8.5S3.5 9.5 3.5 6A4.5 4.5 0 0 1 8 1.5z" fill="none" stroke="currentColor"/><circle cx="8" cy="6" r="1.6" fill="none" stroke="currentColor"/></svg>';
?>
<article class="eex-hh__event-card eex-hh__event-card--<?php echo esc_attr( $eex_status ); ?>"<?php echo $eex_session_attrs . $eex_analytics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helpers above. ?>>
	<p class="eex-comp-eyebrow eex-eyebrow"><?php echo esc_html( $eex_eyebrow ); ?></p>

	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<?php if ( isset( $eex_status_labels[ $eex_status ] ) ) : ?>
		<p class="eex-badges"><span class="eex-badge eex-badge-status eex-badge--<?php echo esc_attr( $eex_status ); ?>"><?php echo esc_html( $eex_status_labels[ $eex_status ] ); ?></span></p>
	<?php elseif ( '' !== $eex_pill ) : ?>
		<p class="eex-badges"><span class="eex-hh__event-pill"><?php echo esc_html( $eex_pill ); ?></span></p>
	<?php endif; ?>

	<<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?> class="eex-hh__event-title">
		<?php if ( '' !== $eex_title_url ) : ?>
			<a href="<?php echo esc_url( $eex_title_url ); ?>" data-eex-action="event-details"><?php echo esc_html( $eex_title ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $eex_title ); ?>
		<?php endif; ?>
	</<?php echo esc_attr( $eex_tag ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag. ?>>

	<p class="eex-hh__event-meta">
		<?php if ( null !== $eex_session && '' !== (string) ( $eex_session['starts_at'] ?? '' ) ) : ?>
			<span class="eex-hh__event-meta-item"><?php echo $eex_icon_calendar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG above. ?><?php echo TimeFormat::render( (string) $eex_session['starts_at'], (string) ( $eex_session['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></span>
		<?php elseif ( '' !== (string) ( $eex_event['first_talk_at'] ?? '' ) ) : ?>
			<span class="eex-hh__event-meta-item"><?php echo $eex_icon_calendar; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG above. ?><?php echo TimeFormat::render_range( (string) $eex_event['first_talk_at'], (string) ( $eex_event['last_talk_at'] ?? '' ), (string) ( $eex_event['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></span>
		<?php endif; ?>
		<?php if ( '' !== $eex_place ) : ?>
			<span class="eex-hh__event-meta-item"><?php echo $eex_icon_pin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG above. ?><?php echo esc_html( $eex_place ); ?></span>
		<?php elseif ( __( 'In person', 'emailexpert-events' ) !== $eex_pill && ! isset( $eex_status_labels[ $eex_status ] ) ) : ?>
			<span class="eex-hh__event-meta-item"><?php echo $eex_icon_globe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal SVG above. ?><?php esc_html_e( 'Online', 'emailexpert-events' ); ?></span>
		<?php endif; ?>
	</p>

	<?php if ( '' !== $eex_notice ) : ?>
		<p class="eex-hh__event-notice"><?php echo esc_html( $eex_notice ); ?></p>
	<?php endif; ?>

	<?php $eex_intro = trim( (string) ( $eex_event['presentation']['intro'] ?? '' ) ); ?>
	<?php if ( '' !== $eex_intro ) : ?>
		<p class="eex-hh__event-intro"><?php echo esc_html( Components::truncate( wp_strip_all_tags( $eex_intro ), 160 ) ); ?></p>
	<?php endif; ?>

	<?php if ( $eex_show_speakers ) : ?>
		<ul class="eex-hh__speakers" role="list">
			<?php foreach ( $eex_speakers as $eex_speaker ) : ?>
				<li><?php TemplateLoader::part( 'speaker-block', [ 'speaker' => (array) $eex_speaker ] ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php elseif ( $eex_portrait_strip ) : ?>
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
	<?php endif; ?>

	<?php if ( 'image' === (string) ( $eex_media['type'] ?? '' ) ) : ?>
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

	<?php if ( $eex_countdown ) : ?>
		<p class="eex-countdown" data-eex-countdown="<?php echo esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', (int) $eex_lifecycle['starts_ts'] ) ); ?>" aria-live="polite"></p>
	<?php endif; ?>

	<?php if ( null !== $eex_primary || null !== $eex_secondary || ! empty( $eex_calendar ) ) : ?>
		<p class="eex-card-actions eex-hh__event-actions">
			<?php if ( null !== $eex_primary && ! empty( $eex_primary['rsvp'] ) && ! empty( $eex_rsvp ) ) : ?>
				<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
			<?php elseif ( null !== $eex_primary ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo null === $eex_secondary && null !== $eex_session ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?><?php echo ! empty( $eex_primary['drawer'] ) && '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['hs_id'] ?? '' ) : '' ) . '" data-eex-talk-title="' . esc_attr( null !== $eex_session ? (string) ( $eex_session['title'] ?? '' ) : '' ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> data-eex-action="<?php echo esc_attr( (string) $eex_primary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_primary['url'] ); ?>"><?php echo esc_html( (string) $eex_primary['label'] ); ?></a>
			<?php endif; ?>
			<?php if ( null !== $eex_secondary ) : ?>
				<a class="eex-cta eex-cta-session"<?php echo ! empty( $eex_cta['flip'] ) ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?> data-eex-action="<?php echo esc_attr( (string) $eex_secondary['action'] ); ?>" href="<?php echo esc_url( (string) $eex_secondary['url'] ); ?>"><?php echo esc_html( (string) $eex_secondary['label'] ); ?></a>
			<?php endif; ?>
			<?php // One calendar action here — the .ics download (the landing page offers the full set); calendar never competes with registration. ?>
			<?php if ( isset( $eex_calendar['ics'] ) ) : ?>
				<a class="eex-cta-secondary" data-eex-action="calendar" href="<?php echo esc_url( (string) $eex_calendar['ics'] ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
			<?php elseif ( isset( $eex_calendar['subscribe'] ) ) : ?>
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
