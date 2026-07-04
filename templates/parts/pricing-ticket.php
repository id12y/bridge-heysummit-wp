<?php
/**
 * One ticket in the pricing table.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $ticket            Ticket data (see Data\Tickets::for_display()).
 *     @type bool   $show_description  Show the ticket description.
 *     @type bool   $show_remaining    Show remaining quantity.
 *     @type bool   $highlight_popular Ribbon on the popular ticket.
 *     @type string $register_text     CTA label ('' = "Register").
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_ticket = (array) ( $args['ticket'] ?? [] );

if ( empty( $eex_ticket['id'] ) ) {
	return;
}

$eex_register_text = (string) ( $args['register_text'] ?? '' );
if ( '' === $eex_register_text ) {
	$eex_register_text = __( 'Register', 'emailexpert-events' );
}

$eex_popular   = ! empty( $args['highlight_popular'] ) && ! empty( $eex_ticket['popular'] );
$eex_remaining = ! empty( $args['show_remaining'] ) ? (string) ( $eex_ticket['remaining'] ?? '' ) : '';
$eex_applies   = (array) ( $eex_ticket['applies'] ?? [] );

$eex_covers = array_keys(
	array_filter(
		[
			__( 'Live sessions', 'emailexpert-events' ) => ! empty( $eex_applies['live'] ),
			__( 'Replays', 'emailexpert-events' )       => ! empty( $eex_applies['replays'] ),
			__( 'In person', 'emailexpert-events' )     => ! empty( $eex_applies['inperson'] ),
		]
	)
);
?>
<article class="eex-card eex-pricing-ticket<?php echo $eex_popular ? ' eex-pricing-popular' : ''; ?>">
	<?php if ( $eex_popular ) : ?>
		<p class="eex-badge eex-badge-popular"><?php esc_html_e( 'Most popular', 'emailexpert-events' ); ?></p>
	<?php endif; ?>

	<h3 class="eex-card-title"><?php echo esc_html( (string) $eex_ticket['title'] ); ?></h3>

	<?php if ( ! empty( $eex_ticket['prices'] ) ) : ?>
		<p class="eex-pricing-prices">
			<?php foreach ( (array) $eex_ticket['prices'] as $eex_price ) : ?>
				<span class="eex-pricing-price">
					<span class="eex-pricing-amount"><?php echo esc_html( (string) ( $eex_price['amount'] ?: $eex_price['title'] ) ); ?></span>
					<?php if ( '' !== (string) $eex_price['title'] && '' !== (string) $eex_price['amount'] ) : ?>
						<span class="eex-pricing-price-label"><?php echo esc_html( (string) $eex_price['title'] ); ?></span>
					<?php endif; ?>
				</span>
			<?php endforeach; ?>
		</p>
	<?php elseif ( empty( $eex_ticket['is_paid'] ) ) : ?>
		<p class="eex-pricing-prices"><span class="eex-pricing-amount"><?php esc_html_e( 'Free', 'emailexpert-events' ); ?></span></p>
	<?php endif; ?>

	<?php if ( ! empty( $args['show_description'] ) && '' !== (string) $eex_ticket['description'] ) : ?>
		<p class="eex-pricing-description"><?php echo esc_html( (string) $eex_ticket['description'] ); ?></p>
	<?php endif; ?>

	<?php if ( ! empty( $eex_covers ) ) : ?>
		<p class="eex-badges">
			<?php foreach ( $eex_covers as $eex_cover ) : ?>
				<span class="eex-badge"><?php echo esc_html( $eex_cover ); ?></span>
			<?php endforeach; ?>
		</p>
	<?php endif; ?>

	<?php if ( '' !== $eex_remaining ) : ?>
		<p class="eex-pricing-remaining">
			<?php
			printf(
				/* translators: %s: remaining quantity. */
				esc_html__( 'Only %s left', 'emailexpert-events' ),
				esc_html( $eex_remaining )
			);
			?>
		</p>
	<?php endif; ?>

	<p class="eex-card-actions">
		<?php if ( '' !== (string) $eex_ticket['register_url'] ) : ?>
			<a class="eex-cta eex-cta-register" href="<?php echo esc_url( (string) $eex_ticket['register_url'] ); ?>"><?php echo esc_html( $eex_register_text ); ?></a>
		<?php endif; ?>
	</p>
</article>
