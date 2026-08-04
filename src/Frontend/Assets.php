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

		// The site skin: tokens + a deliberate look, layered over the
		// neutral base. Two are shipped and EXACTLY ONE loads, under the
		// same handle, so choosing a skin never costs a second stylesheet:
		//
		//   editorial (default) — the current design: date-led rows, quiet
		//                         secondary actions, soft badges.
		//   classic             — the look through 1.41, kept as a
		//                         supported choice rather than a shim.
		//
		// Dequeuing the handle still drops to the neutral base, since every
		// base rule carries its own fallback.
		$skin = 'classic' === (string) Options::setting( 'skin' ) ? 'classic' : 'editorial';

		wp_register_style( 'eex-skin', EEX_PLUGIN_URL . 'assets/css/eex-skin-' . $skin . '.css', [ 'eex-frontend' ], EEX_VERSION );
		wp_register_script( 'eex-time', EEX_PLUGIN_URL . 'assets/js/eex-time.js', [], EEX_VERSION, true );

		// The logged-in visitor's own identity, for prefilling RSVP forms and
		// the self-only my-schedule lookup. Localised per request — never part
		// of any cached fragment — and absent entirely for anonymous visitors.
		$viewer = [];
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$account = wp_get_current_user();
			$viewer  = [
				'name'  => (string) $account->display_name,
				'email' => (string) $account->user_email,
				'nonce' => wp_create_nonce( 'wp_rest' ),
			];
		}

		wp_localize_script(
			'eex-time',
			'eexTime',
			[
				'restBase'    => rest_url( 'eex/v1/' ),
				'viewer'      => $viewer,
				'soonMinutes' => 60,
				'i18n'        => [
					'joinNow'           => __( 'Join now', 'emailexpert-events' ),
					'startingSoon'      => __( 'Starting soon', 'emailexpert-events' ),
					'liveNow'           => __( 'Live now', 'emailexpert-events' ),
					'days'              => __( 'days', 'emailexpert-events' ),
					'hours'             => __( 'hours', 'emailexpert-events' ),
					'minutes'           => __( 'minutes', 'emailexpert-events' ),
					'regDone'           => __( "You're registered — check your inbox for the confirmation.", 'emailexpert-events' ),
					'regAlready'        => __( "You're already registered for this event — check your inbox.", 'emailexpert-events' ),
					'regDoneTalk'       => __( "You're registered — this session is on your schedule. Check your inbox for the confirmation.", 'emailexpert-events' ),
					'regAlreadyTalk'    => __( "You're already registered — this session has been added to your schedule.", 'emailexpert-events' ),
					'regError'          => __( 'Something went wrong — please try again.', 'emailexpert-events' ),
					'rsvpKnownTalk'     => __( "You're going — this session is on your schedule.", 'emailexpert-events' ),
					'rsvpKnownEvent'    => __( "You're registered for this event.", 'emailexpert-events' ),
					'rsvpOther'         => __( 'Use a different email', 'emailexpert-events' ),
					'regSubmitted'      => __( 'Almost done — check your inbox for the next step.', 'emailexpert-events' ),
					'regConfirmDone'    => __( "You're registered — your session is on your schedule.", 'emailexpert-events' ),
					'regConfirmFailed'  => __( 'That confirmation could not be completed — please register again.', 'emailexpert-events' ),
					'regConfirmInvalid' => __( 'That confirmation link is no longer valid — please register again.', 'emailexpert-events' ),
					'dismiss'           => __( 'Dismiss', 'emailexpert-events' ),
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

			/**
			 * Filter whether the emailexpert site skin loads (default true).
			 * false = neutral base styling only.
			 *
			 * @param bool $enabled Load the skin stylesheet.
			 */
			if ( apply_filters( 'eex_skin_enabled', true ) ) {
				wp_enqueue_style( 'eex-skin' );
			}

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

		// A confirmation-link landing (?eex_reg=...) must show its banner
		// wherever the redirect ends up, components on the page or not.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check only.
		if ( isset( $_GET['eex_reg'] ) ) {
			self::mark_needed();
		}
	}
}
