<?php
/**
 * Runtime upgrade detection.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Install;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin files are replaced without activation ever re-running (zip
 * overwrite, git deploy, auto-update), yet cached component fragments hold
 * markup from the previous build and pages keep it for up to the display
 * cache TTL. Comparing the version stored in the autoloaded settings option
 * against EEX_VERSION on every load costs no extra query and lets each
 * update invalidate its own leftovers.
 */
final class Upgrade {

	/**
	 * Flush the display and live caches once per version change.
	 *
	 * The live flush is soft: fresh copies are invalidated so the new build
	 * refetches promptly, but the last-good tier survives — a deploy must
	 * not leave the first visitor's shallow sweep with nothing to fall back
	 * on, or the homepage flashes its empty state after every update.
	 */
	public static function check(): void {
		if ( (string) Options::setting( 'version' ) === EEX_VERSION ) {
			return;
		}

		\Emailexpert\Events\Frontend\Cache::flush();
		\Emailexpert\Events\Data\LiveCache::flush( true );

		Options::update_settings( [ 'version' => EEX_VERSION ] );
	}
}
