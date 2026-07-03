<?php
/**
 * Shared admin assets.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * One place that enqueues the admin stylesheet, the admin script and its
 * eexAdmin localisation (AJAX URL, nonce, strings). Every screen with an
 * eex-* button must call this — a bound button without the script or the
 * nonce is a dead button.
 */
final class AdminAssets {

	/**
	 * Enqueue everything the admin script needs on the current screen.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( 'eex-admin', EEX_PLUGIN_URL . 'assets/css/eex-admin.css', [], EEX_VERSION );
		wp_enqueue_script( 'eex-admin', EEX_PLUGIN_URL . 'assets/js/eex-admin.js', [], EEX_VERSION, true );
		wp_localize_script(
			'eex-admin',
			'eexAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eex_admin' ),
				'i18n'    => [
					'working'  => __( 'Working…', 'emailexpert-events' ),
					'failed'   => __( 'Request failed.', 'emailexpert-events' ),
					'keySaved' => __( 'Key saved (leave blank to keep)', 'emailexpert-events' ),
				],
			]
		);
	}
}
