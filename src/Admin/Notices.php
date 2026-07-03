<?php
/**
 * Persistent admin notices.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Stores keyed persistent notices in an option; they show on every admin
 * screen for users with manage_options until dismissed or cleared by code
 * (for example, a successful sync clears the failure notice).
 */
final class Notices {

	private const OPTION = 'eex_notices';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'admin_notices', [ $this, 'render' ] );
	}

	/**
	 * Add or replace a persistent notice.
	 *
	 * @param string $id      Stable notice key.
	 * @param string $message Message (plain text).
	 * @param string $level   error|warning|info|success.
	 */
	public static function add( string $id, string $message, string $level = 'error' ): void {
		$notices        = (array) get_option( self::OPTION, [] );
		$notices[ $id ] = [
			'message' => $message,
			'level'   => $level,
		];
		update_option( self::OPTION, $notices, false );
	}

	/**
	 * Remove a notice by key.
	 *
	 * @param string $id Notice key.
	 */
	public static function remove( string $id ): void {
		$notices = (array) get_option( self::OPTION, [] );

		if ( isset( $notices[ $id ] ) ) {
			unset( $notices[ $id ] );
			update_option( self::OPTION, $notices, false );
		}
	}

	/**
	 * Render all stored notices.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle dismissal.
		if ( isset( $_GET['eex_dismiss'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'eex_dismiss_notice' ) ) {
			self::remove( sanitize_key( wp_unslash( $_GET['eex_dismiss'] ) ) );
		}

		foreach ( (array) get_option( self::OPTION, [] ) as $id => $notice ) {
			$level   = in_array( $notice['level'] ?? 'error', [ 'error', 'warning', 'info', 'success' ], true ) ? $notice['level'] : 'error';
			$dismiss = wp_nonce_url( add_query_arg( 'eex_dismiss', $id ), 'eex_dismiss_notice' );

			printf(
				'<div class="notice notice-%1$s"><p>%2$s <a href="%3$s">%4$s</a></p></div>',
				esc_attr( $level ),
				esc_html( (string) ( $notice['message'] ?? '' ) ),
				esc_url( $dismiss ),
				esc_html__( 'Dismiss', 'emailexpert-events' )
			);
		}
	}
}
