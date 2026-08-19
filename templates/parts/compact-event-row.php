<?php
/**
 * Session / event row for the More Sessions area, in two registers:
 *
 *   Compact (default) — one quiet line: date, title, optional whisper and
 *   speakers. No artwork by design.
 *
 *   Rich — a fuller programme entry: meta line (date, format, location),
 *   title, speakers, owning-event context, restrained media and exactly
 *   one action, resolved by the shared registration system (the same RSVP
 *   form, ticket panel and routing every other surface uses). Absent
 *   information simplifies the row; nothing is ever placeholdered.
 *
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $event         Session or event row (see EventSelector).
 *     @type bool   $show_speakers Show the row's featured speakers.
 *     @type int    $speaker_limit Speakers per row (1–3).
 *     @type string $whisper       'none', 'format' or 'location'.
 *     @type bool   $rich          Rich register.
 *     @type bool   $show_time     Rich: show the localised start time.
 *     @type bool   $show_event    Rich: show the owning event context.
 *     @type bool   $show_media    Rich: show restrained session media.
 *     @type array  $cta           Rich action { kind, label, url, drawer_id, rsvp }.
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_event = (array) ( $args['event'] ?? [] );

if ( empty( $eex_event['title'] ) ) {
	return;
}

$eex_rich  = ! empty( $args['rich'] );
$eex_first = (string) ( $eex_event['first_talk_at'] ?? '' );
$eex_tz    = (string) ( $eex_event['timezone'] ?? '' );
$eex_url   = (string) ( $eex_event['url'] ?? '' );

// The optional speaker line: portraits and names, never a large card. The
// row stays event-only when no speaker information exists.
$eex_row_speakers = [];
if ( ! empty( $args['show_speakers'] ) ) {
	$eex_row_speakers = array_slice(
		array_filter( (array) ( $eex_event['speakers_row'] ?? [] ), 'is_array' ),
		0,
		min( 3, max( 1, (int) ( $args['speaker_limit'] ?? 2 ) ) )
	);
}

// Format and place come from the one shared resolver — gathering data and
// the deliberate presentation override only; URLs, checkouts and
// registration mechanisms are not inputs and cannot influence them.
$eex_format = isset( $eex_event['format_label'] )
	? (string) $eex_event['format_label']
	: Components::format_label( $eex_event, (array) ( $eex_event['presentation'] ?? [] ) );

$eex_place = implode(
	', ',
	array_filter(
		[
			trim( (string) ( $eex_event['venue_city'] ?? '' ) ),
			trim( (string) ( $eex_event['venue_country'] ?? '' ) ),
		]
	)
);
if ( '' === $eex_place ) {
	$eex_place = trim( (string) ( $eex_event['venue'] ?? '' ) );
}

// The whisper / rich meta pieces. Rich rows default to the location
// treatment (format, plus the place for in-person gatherings) unless the
// whisper control says otherwise.
$eex_mode = (string) ( $args['whisper'] ?? 'none' );
if ( $eex_rich && 'none' === $eex_mode ) {
	$eex_mode = 'location';
}

$eex_whisper = '';
if ( 'format' === $eex_mode ) {
	$eex_whisper = $eex_format;
} elseif ( 'location' === $eex_mode ) {
	$eex_whisper = $eex_format;

	if ( '' !== $eex_place && __( 'Online', 'emailexpert-events' ) !== $eex_format ) {
		$eex_whisper = '' !== $eex_whisper ? $eex_whisper . ' · ' . $eex_place : $eex_place;
	}
}

$eex_cta = is_array( $args['cta'] ?? null ) ? (array) $args['cta'] : null;

$eex_media_html = '';
if ( $eex_rich && ! empty( $args['show_media'] ) && '' !== (string) ( $eex_event['image'] ?? '' ) ) {
	$eex_media_html = '<figure class="eex-compact-event__media"><img loading="lazy" src="' . esc_url( (string) $eex_event['image'] ) . '" alt="" /></figure>';
}
?>
<article class="eex-compact-event<?php echo $eex_rich ? ' eex-compact-event--rich' : ''; ?>" data-eex-event-id="<?php echo esc_attr( (string) ( $eex_event['hs_id'] ?? '' ) ); ?>">
	<?php if ( $eex_rich ) : ?>
		<span class="eex-compact-event__meta">
			<span class="eex-compact-event__date">
				<?php
				if ( '' !== $eex_first ) {
					echo TimeFormat::render_date( $eex_first, $eex_tz, false, 'j M' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
				} elseif ( ! empty( $eex_event['evergreen'] ) ) {
					esc_html_e( 'On demand', 'emailexpert-events' );
				}
				?>
			</span>
			<?php if ( ! empty( $args['show_time'] ) && '' !== $eex_first ) : ?>
				<span class="eex-compact-event__time"><?php echo TimeFormat::render( $eex_first, $eex_tz ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?></span>
			<?php endif; ?>
			<?php if ( '' !== $eex_whisper ) : ?>
				<span class="eex-compact-event__whisper"><?php echo esc_html( $eex_whisper ); ?></span>
			<?php endif; ?>
		</span>
		<?php echo $eex_media_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built. ?>
	<?php else : ?>
		<?php if ( '' !== $eex_whisper ) : ?>
			<span class="eex-compact-event__whisper"><?php echo esc_html( $eex_whisper ); ?></span>
		<?php endif; ?>
		<span class="eex-compact-event__date">
			<?php
			if ( '' !== $eex_first ) {
				// Compact rows name the day briefly ("1 Sep"); the client-side
				// localiser keeps them date-only in the visitor's zone.
				echo TimeFormat::render_date( $eex_first, $eex_tz, false, 'j M' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
			} elseif ( ! empty( $eex_event['evergreen'] ) ) {
				esc_html_e( 'On demand', 'emailexpert-events' );
			}
			?>
		</span>
	<?php endif; ?>
	<?php if ( '' !== $eex_url ) : ?>
		<a class="eex-compact-event__title" href="<?php echo esc_url( $eex_url ); ?>" data-eex-action="more-events"><?php echo esc_html( (string) $eex_event['title'] ); ?></a>
	<?php else : ?>
		<span class="eex-compact-event__title"><?php echo esc_html( (string) $eex_event['title'] ); ?></span>
	<?php endif; ?>
	<?php if ( ! empty( $eex_row_speakers ) ) : ?>
		<span class="eex-compact-event__speakers">
			<?php foreach ( $eex_row_speakers as $eex_row_speaker ) : ?>
				<?php
				$eex_row_photo = '';
				if ( (int) ( $eex_row_speaker['photo_id'] ?? 0 ) > 0 && function_exists( 'wp_get_attachment_image' ) ) {
					$eex_row_photo = wp_get_attachment_image(
						(int) $eex_row_speaker['photo_id'],
						'thumbnail',
						false,
						[
							'class'   => 'eex-compact-event__portrait',
							'loading' => 'lazy',
							// The name sits in text right beside the portrait.
							'alt'     => '',
						]
					);
				} elseif ( '' !== (string) ( $eex_row_speaker['photo_url'] ?? '' ) ) {
					$eex_row_photo = '<img class="eex-compact-event__portrait" loading="lazy" src="' . esc_url( (string) $eex_row_speaker['photo_url'] ) . '" alt="" />';
				}
				?>
				<span class="eex-compact-event__speaker">
					<?php echo $eex_row_photo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped/core-generated above. ?>
					<span class="eex-compact-event__speaker-name"><?php echo esc_html( (string) $eex_row_speaker['name'] ); ?></span>
				</span>
			<?php endforeach; ?>
		</span>
	<?php endif; ?>
	<?php if ( $eex_rich && ! empty( $args['show_event'] ) && '' !== (string) ( $eex_event['event_title'] ?? '' ) && (string) $eex_event['event_title'] !== (string) $eex_event['title'] ) : ?>
		<span class="eex-compact-event__context"><?php echo esc_html( (string) $eex_event['event_title'] ); ?></span>
	<?php endif; ?>
	<?php if ( $eex_rich && null !== $eex_cta ) : ?>
		<span class="eex-compact-event__action">
			<?php if ( 'rsvp' === (string) $eex_cta['kind'] ) : ?>
				<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" data-eex-action="event-register" href="<?php echo esc_url( '' !== (string) ( $eex_cta['url'] ?? '' ) ? (string) $eex_cta['url'] : $eex_url ); ?>"><?php echo esc_html( (string) $eex_cta['label'] ); ?></a>
				<?php
				TemplateLoader::part(
					'register-form',
					[
						'event_id'    => (string) ( $eex_cta['rsvp']['event_id'] ?? '' ),
						'ticket_id'   => (string) ( $eex_cta['rsvp']['ticket_id'] ?? '' ),
						'price_id'    => (string) ( $eex_cta['rsvp']['price_id'] ?? '' ),
						'talk_id'     => '' !== (string) ( $eex_event['event_hs_id'] ?? '' ) ? (string) ( $eex_event['hs_id'] ?? '' ) : '',
						'submit_text' => __( 'RSVP', 'emailexpert-events' ),
						'hidden'      => true,
					]
				);
				?>
			<?php elseif ( 'drawer' === (string) $eex_cta['kind'] ) : ?>
				<?php if ( '' !== (string) ( $eex_cta['url'] ?? '' ) ) : ?>
					<a class="eex-cta eex-cta-register" data-eex-drawer="<?php echo esc_attr( (string) $eex_cta['drawer_id'] ); ?>" data-eex-talk="<?php echo esc_attr( '' !== (string) ( $eex_event['event_hs_id'] ?? '' ) ? (string) ( $eex_event['hs_id'] ?? '' ) : '' ); ?>" data-eex-talk-title="<?php echo esc_attr( '' !== (string) ( $eex_event['event_hs_id'] ?? '' ) ? (string) $eex_event['title'] : '' ); ?>" data-eex-action="event-register" href="<?php echo esc_url( (string) $eex_cta['url'] ); ?>"><?php echo esc_html( (string) $eex_cta['label'] ); ?></a>
				<?php else : ?>
					<button type="button" class="eex-cta eex-cta-register" data-eex-drawer="<?php echo esc_attr( (string) $eex_cta['drawer_id'] ); ?>" data-eex-talk="<?php echo esc_attr( '' !== (string) ( $eex_event['event_hs_id'] ?? '' ) ? (string) ( $eex_event['hs_id'] ?? '' ) : '' ); ?>" data-eex-talk-title="<?php echo esc_attr( '' !== (string) ( $eex_event['event_hs_id'] ?? '' ) ? (string) $eex_event['title'] : '' ); ?>" data-eex-action="event-register"><?php echo esc_html( (string) $eex_cta['label'] ); ?></button>
				<?php endif; ?>
			<?php elseif ( '' !== (string) ( $eex_cta['url'] ?? '' ) ) : ?>
				<a class="eex-cta-quiet" data-eex-action="event-details" href="<?php echo esc_url( (string) $eex_cta['url'] ); ?>"><?php echo esc_html( (string) $eex_cta['label'] ); ?><span class="eex-cta-arrow" aria-hidden="true">→</span></a>
			<?php endif; ?>
		</span>
	<?php endif; ?>
</article>
