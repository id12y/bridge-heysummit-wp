<?php
/**
 * Speaker chip: a small linked name used on session cards and schedules.
 *
 * @package Emailexpert\Events
 *
 * @var array $args {
 *     @type array $speaker { id, name, url }.
 * }
 */

defined( 'ABSPATH' ) || exit;

$eex_speaker = (array) ( $args['speaker'] ?? [] );

if ( empty( $eex_speaker['name'] ) ) {
	return;
}
?>
<a class="eex-chip" href="<?php echo esc_url( (string) ( $eex_speaker['url'] ?? '' ) ); ?>"><?php echo esc_html( (string) $eex_speaker['name'] ); ?></a>
