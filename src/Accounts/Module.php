<?php
/**
 * Accounts module bootstrap.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * Registers account holders as HeySummit attendees under granular
 * admin-defined rules. Gated by the master toggle: with
 * `accounts_enabled` off, Plugin never touches this namespace — zero code
 * loads and zero queries run (the toggle lives in the one autoloaded
 * settings option). The Bridge settings Accounts tab shows only the enable
 * switch until the module is on.
 */
final class Module {

	/**
	 * Entry point, called by Plugin only when the master toggle is on.
	 */
	public static function register(): void {
		( new Consent() )->register();
		( new Triggers() )->register();
		( new Pusher() )->register();
		( new Backfill() )->register();

		if ( is_admin() ) {
			( new AdminUi() )->register();
		}
	}
}
