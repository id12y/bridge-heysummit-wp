<?php
/**
 * One session in the replay gallery.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $data          Talk data (see Components::talk_data()).
 *     @type string $link          'talk' (session page) or 'replay' (direct).
 *     @type bool   $show_speakers Show speaker names.
 *     @type bool   $show_image    Show the session image.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_data = (array) ( $args['data'] ?? [] );

if ( empty( $eex_data['title'] ) ) {
	return;
}

$eex_replay = (string) ( $eex_data['replay_url'] ?? '' );
$eex_soon   = '' === $eex_replay && ! empty( $eex_data['replay_soon'] );

// The session page keeps its VideoObject schema; direct replay links are
// the operator's explicit choice.
$eex_link = 'replay' === (string) ( $args['link'] ?? 'talk' ) && '' !== $eex_replay
	? $eex_replay
	: (string) ( ( $eex_data['permalink'] ?? '' ) ?: ( $eex_data['talk_url'] ?? '' ) );

// Lite carries an image URL; Full talks use their featured image.
$eex_image_url = (string) ( $eex_data['image'] ?? '' );
$eex_image_id  = '' === $eex_image_url && ! empty( $eex_data['id'] ) && function_exists( 'get_post_thumbnail_id' )
	? (int) get_post_thumbnail_id( (int) $eex_data['id'] )
	: 0;

$eex_speakers = ! empty( $args['show_speakers'] )
	? implode( ', ', array_filter( array_map( static fn( array $s ): string => (string) ( $s['name'] ?? '' ), (array) ( $eex_data['speakers'] ?? [] ) ) ) )
	: '';
?>
<article class="eex-card eex-replay-card<?php echo $eex_soon ? ' eex-replay-soon' : ''; ?>">
	<?php if ( ! empty( $args['show_image'] ) ) : ?>
		<div class="eex-card-image eex-replay-media">
			<?php if ( '' !== $eex_image_url ) : ?>
				<img src="<?php echo esc_url( $eex_image_url ); ?>" alt="" loading="lazy" />
			<?php elseif ( $eex_image_id > 0 && function_exists( 'wp_get_attachment_image' ) ) : ?>
				<?php echo wp_get_attachment_image( $eex_image_id, 'medium_large', false, [ 'loading' => 'lazy' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core generates escaped markup. ?>
			<?php endif; ?>
			<?php if ( ! $eex_soon ) : ?>
				<span class="eex-replay-play" aria-hidden="true"><svg viewBox="0 0 24 24" width="44" height="44" focusable="false"><circle cx="12" cy="12" r="11" fill="currentColor" opacity="0.85"/><path d="M9.8 7.5v9l7-4.5z" fill="#fff"/></svg></span>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<h3 class="eex-card-title">
		<?php if ( '' !== $eex_link && ! $eex_soon ) : ?>
			<a href="<?php echo esc_url( $eex_link ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
		<?php else : ?>
			<?php echo esc_html( (string) $eex_data['title'] ); ?>
		<?php endif; ?>
	</h3>

	<?php if ( '' !== $eex_speakers ) : ?>
		<p class="eex-replay-speakers"><?php echo esc_html( $eex_speakers ); ?></p>
	<?php endif; ?>

	<?php if ( $eex_soon ) : ?>
		<p class="eex-badges"><span class="eex-badge"><?php esc_html_e( 'Replay available soon', 'emailexpert-events' ); ?></span></p>
	<?php elseif ( '' !== $eex_link ) : ?>
		<p class="eex-card-actions"><a class="eex-cta-secondary" href="<?php echo esc_url( $eex_link ); ?>"><?php esc_html_e( 'Watch the replay', 'emailexpert-events' ); ?></a></p>
	<?php endif; ?>
</article>
