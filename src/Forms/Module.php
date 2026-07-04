<?php
/**
 * Forms bridge module wiring.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Form plugins → HeySummit attendee creation. Loaded in both modes (like
 * the WooCommerce bridge — pushing a lead does not need synced content).
 * Each adapter hook is registered unconditionally but only ever fires when
 * its host plugin processes a submission; a site with none of the four form
 * plugins pays four dormant add_action calls and nothing else.
 */
final class Module {

	/**
	 * Hook up.
	 */
	public function register(): void {
		( new Pusher() )->register();

		// Elementor Pro Forms: the action registers itself with the form
		// widget; the registrar guards on Action_Base existing.
		add_action( 'elementor_pro/forms/actions/register', [ Adapters\Elementor::class, 'register_action' ] );

		// Hook-based adapters: each fires only on its own plugin's submissions.
		add_action( 'gform_after_submission', [ Adapters\GravityForms::class, 'on_submission' ], 10, 2 );
		add_action( 'wpforms_process_complete', [ Adapters\WpForms::class, 'on_complete' ], 10, 4 );
		add_action( 'fluentform/submission_inserted', [ Adapters\FluentForms::class, 'on_inserted' ], 10, 3 );

		if ( is_admin() ) {
			( new Admin() )->register();
		}
	}
}
