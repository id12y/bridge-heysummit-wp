<?php
/**
 * Schedule row: one session inside a day group.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $data Talk data from Components::talk_data().
 * }
 */

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Frontend\TimeFormat;

defined( 'ABSPATH' ) || exit;

$eex_data = (array) ( $args['data'] ?? [] );

if ( empty( $eex_data['id'] ) ) {
	return;
}
?>
<li class="eex-schedule-row"<?php echo Components::session_attrs( $eex_data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>>
	<span class="eex-schedule-time">
		<?php echo TimeFormat::render( (string) $eex_data['starts_at'], (string) $eex_data['timezone'], 'H:i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper. ?>
	</span>
	<span class="eex-schedule-main">
		<a class="eex-schedule-title" href="<?php echo esc_url( (string) $eex_data['permalink'] ); ?>"><?php echo esc_html( (string) $eex_data['title'] ); ?></a>
		<?php if ( ! empty( $eex_data['categories'] ) ) : ?>
			<?php foreach ( $eex_data['categories'] as $eex_term ) : ?>
				<span class="eex-badge eex-badge-<?php echo esc_attr( $eex_term->slug ); ?>"><?php echo esc_html( $eex_term->name ); ?></span>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php if ( ! empty( $eex_data['speakers'] ) ) : ?>
			<span class="eex-speaker-chips">
				<?php foreach ( $eex_data['speakers'] as $eex_speaker ) : ?>
					<?php TemplateLoader::part( 'speaker-chip', [ 'speaker' => $eex_speaker ] ); ?>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>
	</span>
</li>
