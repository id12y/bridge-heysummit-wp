<?php
/**
 * A speaker's social/web links as a chip row.
 * Override by copying to yourtheme/emailexpert-events/parts/.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array  $links Link URLs.
 *     @type string $name  Speaker name (for accessible labels).
 * }
 */

use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

$eex_links = array_values( array_filter( array_map( 'strval', (array) ( $args['links'] ?? [] ) ) ) );
$eex_name  = (string) ( $args['name'] ?? '' );

if ( empty( $eex_links ) ) {
	return;
}
?>
<p class="eex-speaker-links">
	<?php foreach ( $eex_links as $eex_link_url ) : ?>
		<?php $eex_link_label = Components::link_label( $eex_link_url ); ?>
		<a
			class="eex-chip"
			href="<?php echo esc_url( $eex_link_url ); ?>"
			target="_blank"
			rel="noopener"
			<?php if ( '' !== $eex_name ) : ?>
				aria-label="<?php echo esc_attr( sprintf( /* translators: 1: speaker name, 2: network name. */ __( '%1$s on %2$s', 'emailexpert-events' ), $eex_name, $eex_link_label ) ); ?>"
			<?php endif; ?>
		><?php echo esc_html( $eex_link_label ); ?></a>
	<?php endforeach; ?>
</p>
