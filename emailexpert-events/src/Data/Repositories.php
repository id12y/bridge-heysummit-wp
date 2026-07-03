<?php
/**
 * Repository selection.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses the active repository for the request: the synced database in
 * Full mode, the live API cache in Lite mode. This is the single switch —
 * render callbacks never ask which mode is active.
 */
final class Repositories {

	/**
	 * Per-request instance.
	 *
	 * @var Repository|null
	 */
	private static ?Repository $instance = null;

	/**
	 * The active repository.
	 */
	public static function current(): Repository {
		if ( null === self::$instance ) {
			$repository = Options::is_lite() ? new LiveRepository() : new SyncedRepository();

			/**
			 * Filter the active component data repository.
			 *
			 * @param Repository $repository Chosen implementation.
			 */
			$filtered = apply_filters( 'eex_repository', $repository );

			self::$instance = $filtered instanceof Repository ? $filtered : $repository;
		}

		return self::$instance;
	}

	/**
	 * Reset the per-request instance (tests, mode switches).
	 */
	public static function reset(): void {
		self::$instance = null;
	}
}
