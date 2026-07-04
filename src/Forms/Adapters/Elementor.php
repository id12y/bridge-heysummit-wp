<?php
/**
 * Elementor Pro Forms adapter (registrar).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms\Adapters;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the "HeySummit registration" form action with Elementor Pro.
 * This class is deliberately plain: the class that extends Elementor Pro's
 * Action_Base lives in its own file and is only autoloaded after the
 * class_exists guard confirms the parent is present, so a site without
 * Elementor Pro never attempts to load it.
 */
final class Elementor {

	/**
	 * elementor_pro/forms/actions/register callback.
	 *
	 * @param object $registrar Elementor Pro's form-actions registrar.
	 */
	public static function register_action( $registrar ): void {
		if ( ! class_exists( '\ElementorPro\Modules\Forms\Classes\Action_Base' ) || ! is_object( $registrar ) ) {
			return;
		}

		if ( method_exists( $registrar, 'register' ) ) {
			$registrar->register( new ElementorAction() );
		} elseif ( method_exists( $registrar, 'add_action' ) ) {
			// Elementor Pro < 3.5 used add_action( name, instance ).
			$registrar->add_action( 'eex_heysummit', new ElementorAction() );
		}
	}
}
