<?php
/**
 * Featured session card: one session with its physical location given
 * equal billing. Two views: 'card' (wide feature) and 'compact' (sidebar).
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data          Talk data (see Components::talk_data()).
 *     @type string $view          'card' or 'compact'.
 *     @type array  $show          Display toggles (image/speakers/categories/venue/ics/description).
 *     @type array  $address       Event venue address lines ([] = none).
 *     @type string $map_url       Directions URL ('' = none).
 *     @type string $buttons       'both', 'tickets' or 'session'.
 *     @type string $register_text Tickets button label ('' = default).
 *     @type string $session_text  Session button label ('' = default).
 *     @type array  $register      Register settings for ticketing_url().
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Ics;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_data = (array) ( $args['data'] ?? [] );
$eex_show = (array) ( $args['show'] ?? [] );

if ( empty( $eex_data['title'] ) ) {
	return;
}

$eex_compact = 'compact' === (string) ( $args['view'] ?? 'card' );
$eex_venue   = ! empty( $eex_show['venue'] ) ? (string) ( $eex_data['venue'] ?? '' ) : '';
$eex_address = (array) ( $args['address'] ?? [] );
$eex_link    = (string) ( ( $eex_data['permalink'] ?? '' ) ?: ( $eex_data['talk_url'] ?? '' ) );

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = __( 'Get tickets', 'emailexpert-events' );
}
$eex_session_text = (string) ( $args['session_text'] ?? '' );
if ( '' === $eex_session_text ) {
	$eex_session_text = __( 'View session', 'emailexpert-events' );
}

$eex_buttons     = (string) ( $args['buttons'] ?? 'both' );
$eex_tickets_url = 'session' === $eex_buttons ? '' : Components::ticketing_url( $eex_data, (array) ( $args['register'] ?? [] ) );
$eex_session_url = 'tickets' === $eex_buttons ? '' : Components::session_url( $eex_data );
?>
<article class="eex-card eex-feature-session <?php echo $eex_compact ? 'eex-feature-compact' : 'eex-feature-card'; ?>"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<?php if ( ! empty( $eex_show['image'] ) && '' !== (string) ( $eex_data['image'] ?? '' ) ) : ?>
		<div class="eex-card-image eex-feature-media">
			<img src="<?php echo esc_url( (string) $eex_data['image'] ); ?>" alt="" loading="lazy" />
		</div>
	<?php endif; ?>

	<div class="eex-feature-body">
		<p class="eex-live-indicator" data-eex-live-slot="1" hidden aria-live="polite"></p>

		<?php $eex_status_badges = Components::status_badges( $eex_data ); ?>
		<?php if ( ! empty( $eex_show['categories'] ) && ( ! empty( $eex_data['categories'] ) || ! empty( $eex_status_badges ) ) ) : ?>
			<p class="eex-badges">
				<?php foreach ( $eex_status_badges as $eex_status_badge ) : ?>
					<span class="eex-badge eex-badge-status"><?php echo esc_html( $eex_status_badge ); ?></span>
				<?php endforeach; ?>
				<?php foreach ( (array) $eex_data['categories'] as $eex_term ) : ?>
					<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<h3 class="eex-card-title">
			<?php if ( '' !== $eex_link ) : ?>
				<a href="<?php echo esc_url( $eex_link ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
			<?php else : ?>
				<?php echo esc_html( (string) $eex_data['title'] ); ?>
			<?php endif; ?>
		</h3>

		<?php if ( '' !== (string) ( $eex_data['starts_at'] ?? '' ) ) : ?>
			<p class="eex-card-time">
				<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) ( $eex_data['timezone'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
			</p>
		<?php endif; ?>

		<?php if ( '' !== $eex_venue || ! empty( $eex_address ) ) : ?>
			<div class="eex-feature-location">
				<?php if ( '' !== $eex_venue ) : ?>
					<p class="eex-card-venue"><?php echo esc_html( $eex_venue ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $eex_address ) ) : ?>
					<p class="eex-venue-address"><?php echo implode( '<br />', array_map( 'esc_html', $eex_address ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line above. ?></p>
				<?php endif; ?>
				<?php if ( '' !== (string) ( $args['map_url'] ?? '' ) ) : ?>
					<p class="eex-venue-directions"><a class="eex-cta-secondary" href="<?php echo esc_url( (string) $args['map_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Directions', 'emailexpert-events' ); ?></a></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $eex_compact && ! empty( $eex_show['description'] ) && '' !== (string) ( $eex_data['description'] ?? '' ) ) : ?>
			<div class="eex-feature-description">
				<?php echo wp_kses_post( wpautop( (string) $eex_data['description'] ) ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $eex_show['speakers'] ) && ! empty( $eex_data['speakers'] ) ) : ?>
			<p class="eex-speaker-chips">
				<?php foreach ( (array) $eex_data['speakers'] as $eex_speaker ) : ?>
					<?php TemplateLoader::part( 'speaker-chip', [ 'speaker' => $eex_speaker ] ); ?>
				<?php endforeach; ?>
			</p>
		<?php endif; ?>

		<p class="eex-card-actions">
			<?php if ( '' !== $eex_tickets_url ) : ?>
				<a class="eex-cta eex-cta-register"<?php echo '' === $eex_session_url ? ' data-eex-cta="1"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal. ?> href="<?php echo esc_url( $eex_tickets_url ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
			<?php endif; ?>
			<?php if ( '' !== $eex_session_url ) : ?>
				<a class="eex-cta eex-cta-session" data-eex-cta="1" href="<?php echo esc_url( $eex_session_url ); ?>"><?php echo esc_html( $eex_session_text ); ?></a>
			<?php endif; ?>
			<?php if ( ! empty( $eex_show['ics'] ) && '' !== (string) ( $eex_data['starts_at'] ?? '' ) ) : ?>
				<a class="eex-cta-secondary" href="<?php echo esc_url( Ics::download_url( $eex_data ) ); ?>"><?php esc_html_e( 'Add to calendar (.ics)', 'emailexpert-events' ); ?></a>
			<?php endif; ?>
		</p>
	</div>
</article>
