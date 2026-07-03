<?php
/**
 * Front-end assets.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the single small stylesheet and the time module, enqueuing them
 * only on pages where a component or plugin template is present. No jQuery,
 * no frameworks; theming happens through --eex-* custom properties.
 */
final class Assets {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_assets' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_on_singulars' ] );
	}

	/**
	 * Register (not enqueue) the assets.
	 */
	public function register_assets(): void {
		wp_register_style( 'eex-frontend', EEX_PLUGIN_URL . 'assets/css/eex.css', [], EEX_VERSION );
		wp_register_script( 'eex-time', EEX_PLUGIN_URL . 'assets/js/eex-time.js', [], EEX_VERSION, true );

		wp_localize_script(
			'eex-time',
			'eexTime',
			[
				'restBase'    => rest_url( 'eex/v1/' ),
				'soonMinutes' => 60,
				'i18n'        => [
					'joinNow'      => __( 'Join now', 'emailexpert-events' ),
					'startingSoon' => __( 'Starting soon', 'emailexpert-events' ),
					'liveNow'      => __( 'Live now', 'emailexpert-events' ),
					'days'         => __( 'days', 'emailexpert-events' ),
					'hours'        => __( 'hours', 'emailexpert-events' ),
					'minutes'      => __( 'minutes', 'emailexpert-events' ),
				],
			]
		);

		// Series colours become CSS custom properties.
		$colours = (array) Options::setting( 'series_colours' );
		if ( ! empty( $colours ) ) {
			$css = ':root{';
			foreach ( $colours as $slug => $colour ) {
				$css .= '--eex-series-' . sanitize_key( (string) $slug ) . ':' . sanitize_hex_color( (string) $colour ) . ';';
			}
			$css .= '}';
			wp_add_inline_style( 'eex-frontend', $css );
		}
	}

	/**
	 * Called by the component renderer: enqueue on demand, so assets load
	 * only where a component is present.
	 */
	public static function mark_needed(): void {
		if ( function_exists( 'wp_enqueue_style' ) ) {
			wp_enqueue_style( 'eex-frontend' );
			wp_enqueue_script( 'eex-time' );
		}
	}

	/**
	 * Plugin singulars and archives always carry the assets.
	 */
	public function enqueue_on_singulars(): void {
		if ( is_singular( [ PostTypes::EVENT, PostTypes::TALK, PostTypes::SPEAKER, PostTypes::SPONSOR ] )
			|| is_post_type_archive( [ PostTypes::EVENT, PostTypes::TALK, PostTypes::SPEAKER ] )
			|| is_tax( [ 'eex_category', 'eex_event_series' ] ) ) {
			self::mark_needed();
		}
	}
}
