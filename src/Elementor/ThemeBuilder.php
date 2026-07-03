<?php
/**
 * Elementor Pro Theme Builder compatibility.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * When an Elementor Pro theme template's display conditions match one of the
 * plugin's single or archive views, the plugin's template loader yields
 * completely and lets Elementor render — no double headers, no fallback
 * markup leaking through. A correctness requirement, not a feature.
 */
final class ThemeBuilder {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'eex_template_yield', [ $this, 'should_yield' ] );
	}

	/**
	 * Whether an Elementor Pro theme template covers the current view.
	 *
	 * @param bool $should_yield Current value.
	 */
	public function should_yield( $should_yield ): bool {
		if ( $should_yield ) {
			return true;
		}

		if ( ! class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			return false;
		}

		$location = '';
		if ( is_singular( [ 'eex_event', 'eex_talk', 'eex_speaker', 'eex_sponsor' ] ) ) {
			$location = 'single';
		} elseif ( is_post_type_archive( [ 'eex_event', 'eex_talk', 'eex_speaker' ] ) || is_tax( [ 'eex_category', 'eex_event_series' ] ) ) {
			$location = 'archive';
		}

		if ( '' === $location ) {
			return false;
		}

		try {
			$module    = \ElementorPro\Modules\ThemeBuilder\Module::instance();
			$documents = $module->get_conditions_manager()->get_documents_for_location( $location );
		} catch ( \Throwable $e ) {
			return false;
		}

		return ! empty( $documents );
	}
}
