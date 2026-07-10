<?php
/**
 * Shortcodes.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * One shortcode per component, sharing the component render callbacks so a
 * shortcode's output always matches its block.
 */
final class Shortcodes {

	/**
	 * Component name => shortcode tag.
	 */
	public const MAP = [
		'upcoming-sessions' => 'eex_upcoming_sessions',
		'past-sessions'     => 'eex_past_sessions',
		'upcoming-events'   => 'eex_upcoming_events',
		'past-events'       => 'eex_past_events',
		'countdown'         => 'eex_countdown',
		'schedule'          => 'eex_schedule',
		'speakers'          => 'eex_speakers',
		'featured-talks'    => 'eex_featured_talks',
		'sponsors'          => 'eex_sponsors',
		'reg-counter'       => 'eex_reg_counter',
		'session-filter'    => 'eex_session_filter',
		'next-session'      => 'eex_next_session',
		'pricing'           => 'eex_pricing',
		'speaker-spotlight' => 'eex_speaker_spotlight',
		'sponsor-spotlight' => 'eex_sponsor_spotlight',
		'events-portfolio'  => 'eex_events_portfolio',
		'live-now'          => 'eex_live_now',
		'register-bar'      => 'eex_register_bar',
		'register-inline'   => 'eex_register_inline',
		'stats'             => 'eex_stats',
		'replay-gallery'    => 'eex_replay_gallery',
		'venue'             => 'eex_venue',
		'featured-session'  => 'eex_featured_session',
	];

	/**
	 * Hook up.
	 */
	public function register(): void {
		$available = Components::available_definitions();

		foreach ( self::MAP as $component => $tag ) {
			if ( ! isset( $available[ $component ] ) ) {
				continue; // Absent in the current mode.
			}

			add_shortcode(
				$tag,
				static function ( $atts ) use ( $component ): string {
					return Components::render( $component, is_array( $atts ) ? $atts : [] );
				}
			);
		}
	}
}
