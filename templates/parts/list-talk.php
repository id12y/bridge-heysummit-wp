<?php
/**
 * Session list row (layout="list"). Override by copying to
 * yourtheme/emailexpert-events/parts/.
 *
 * Source order is the reading order: when the session is, what kind it
 * is, what it is called, who is on it, what you can do about it. The
 * badge sits before the title in the markup rather than being floated
 * above it in CSS, so a screen reader and a sighted visitor meet the
 * same sequence.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data        Talk data from Components::talk_data().
 *     @type string $context     'upcoming', 'past' or 'featured'.
 *     @type array  $show        Display toggles (speakers, categories, ics, google).
 *     @type bool   $zone_in_row Name the timezone on this row (false when the
 *                               listing states it once above).
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

$eex_rsvp = (array) ( $args['rsvp'] ?? [] );

// An externally hosted session (external_url) is ticketed elsewhere:
// its register button must follow that link, never the in-place RSVP
// form (which registers on a HeySummit free ticket). Per session, so
// other cards in the same widget keep their forms.
if ( '' !== (string) ( $eex_data['external_url'] ?? '' ) ) {
	$eex_rsvp = [];
}

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = empty( $eex_rsvp ) ? __( 'Get tickets', 'emailexpert-events' ) : __( 'RSVP free', 'emailexpert-events' );
}

// One line of metadata, in one place, for every row: the people, then
// where it happens. The speakers used to sit in a chip row that wrapped
// differently depending on how many there were, which made rows with two
// speakers look structurally unlike rows with one.
$eex_meta = [];
if ( $eex_show['speakers'] && ! empty( $eex_data['speakers'] ) ) {
	foreach ( (array) $eex_data['speakers'] as $eex_speaker ) {
		$eex_name = trim( (string) ( ( (array) $eex_speaker )['name'] ?? '' ) );
		if ( '' !== $eex_name ) {
			$eex_meta[] = $eex_name;
		}
	}
}
if ( ! empty( $eex_show['venue'] ) && '' !== (string) ( $eex_data['venue'] ?? '' ) ) {
	$eex_meta[] = (string) $eex_data['venue'];
}

$eex_badges = ! empty( $eex_show['format'] )
	? Components::status_badges( $eex_data, true, ! empty( $eex_show['categories'] ) )
	: [];
$eex_terms  = $eex_show['categories'] ? (array) ( $eex_data['categories'] ?? [] ) : [];
?>
<article class="eex-list-row"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

	<span class="eex-list-time">
		<?php
		$eex_time_html = TimeFormat::render_stacked(
			(string) $eex_data['starts_at'],
			(string) $eex_data['timezone'],
			! isset( $args['zone_in_row'] ) || (bool) $args['zone_in_row']
		);
		echo $eex_time_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
		?>
	</span>

	<span class="eex-list-main">
		<?php if ( ! empty( $eex_badges ) || ! empty( $eex_terms ) ) : ?>
			<span class="eex-list-eyebrow">
				<?php foreach ( $eex_badges as $eex_status_badge ) : ?>
					<span class="eex-badge eex-badge-status"><?php echo esc_html( $eex_status_badge ); ?></span>
				<?php endforeach; ?>
				<?php foreach ( $eex_terms as $eex_term ) : ?>
					<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>

		<a class="eex-list-title" href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>

		<?php if ( ! empty( $eex_meta ) ) : ?>
			<span class="eex-list-meta"><?php echo esc_html( implode( ' · ', $eex_meta ) ); ?></span>
		<?php endif; ?>
	</span>

	<span class="eex-list-actions">
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
				$eex_session_text = __( 'View details', 'emailexpert-events' );
			}
			?>
			<?php if ( '' !== $eex_tickets_url && ! empty( $eex_rsvp ) ) : ?>
				<a class="eex-cta eex-cta-register eex-rsvp-toggle" data-eex-reg-toggle="1" aria-expanded="false" href="<?php echo esc_url( $eex_tickets_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
			<?php elseif ( '' !== $eex_tickets_url ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo '' === $eex_session_url ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?><?php echo '' !== $eex_drawer_id ? ' data-eex-drawer="' . esc_attr( $eex_drawer_id ) . '" data-eex-talk="' . esc_attr( (string) ( $eex_data['hs_id'] ?? '' ) ) . '" data-eex-talk-title="' . esc_attr( (string) ( $eex_data['title'] ?? '' ) ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?> href="<?php echo esc_url( $eex_tickets_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_session_url ) : ?>
				<a class="eex-cta eex-cta-session eex-cta-quiet" data-eex-cta="1" href="<?php echo esc_url( $eex_session_url ); ?>"><?php echo esc_html( $eex_session_text ); ?><span class="eex-cta-arrow" aria-hidden="true">&rarr;</span></a>
			<?php endif; ?>
		<?php endif; ?>
	</span>

	<?php if ( 'past' !== $eex_context && '' !== (string) $eex_data['starts_at'] && ( $eex_show['ics'] || $eex_show['google'] ) ) : ?>
		<span class="eex-list-calendar">
			<?php if ( $eex_show['ics'] ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::download_url( $eex_data ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
			<?php endif; ?>
			<?php if ( $eex_show['google'] ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::google_url( $eex_data ) ); ?>" rel="noopener"><?php esc_html_e( 'Google Calendar', 'emailexpert-events' ); ?></a>
			<?php endif; ?>
		</span>
	<?php endif; ?>
	<?php if ( ! empty( $eex_rsvp ) && ! empty( $eex_tickets_url ) ) : ?>
		<?php
		TemplateLoader::part(
			'register-form',
			[
				'event_id'    => (string) ( $eex_rsvp['event_id'] ?? '' ),
				'ticket_id'   => (string) ( $eex_rsvp['ticket_id'] ?? '' ),
				'price_id'    => (string) ( $eex_rsvp['price_id'] ?? '' ),
				'talk_id'     => (string) ( $eex_data['hs_id'] ?? '' ),
				'submit_text' => __( 'RSVP', 'emailexpert-events' ),
				'hidden'      => true,
			]
		);
		?>
	<?php endif; ?>
</article>
