<?php
/**
 * Compact event row: one More Events line — date, title, nothing louder.
 * No event artwork here by design (the homepage default keeps the rail
 * quiet). Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $event         Event data array (see Data\Repository).
 *     @type bool   $show_speakers Show the row's featured speakers.
 *     @type int    $speaker_limit Speakers per row (1–3).
 *     @type string $whisper       'none', 'format' or 'location'.
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

// The optional whisper: the format (the same venue rule as the featured
// card's pill — a venue means in person, venue-less means online), or the
// location when the venue data names one, falling back to the format.
$eex_whisper = '';
$eex_mode    = (string) ( $args['whisper'] ?? 'none' );

if ( 'format' === $eex_mode || 'location' === $eex_mode ) {
	$eex_in_person = ! empty( $eex_event['inperson'] ) || '' !== trim( (string) ( $eex_event['venue'] ?? '' ) );
	$eex_whisper   = $eex_in_person ? __( 'In person', 'emailexpert-events' ) : __( 'Online', 'emailexpert-events' );

	if ( 'location' === $eex_mode && $eex_in_person ) {
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

		if ( '' !== $eex_place ) {
			$eex_whisper = $eex_place;
		}
	}
}
?>
<article class="eex-compact-event" data-eex-event-id="<?php echo esc_attr( (string) ( $eex_event['hs_id'] ?? '' ) ); ?>">
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
</article>
