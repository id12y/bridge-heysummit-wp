<?php
/**
 * Template loading.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Theme-overridable templates: a file in the theme's emailexpert-events/
 * directory wins, the plugin fallback otherwise. Yields completely to
 * Elementor Theme Builder when one of its templates targets the view
 * (src/Elementor/ThemeBuilder sets the yield flag).
 */
final class TemplateLoader {

	/**
	 * When true, the loader steps aside entirely (Elementor Theme Builder owns
	 * the view).
	 *
	 * @var bool
	 */
	public static bool $yield = false;

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'template_include', [ $this, 'template_include' ], 20 );
	}

	/**
	 * Swap in plugin templates for our views when the theme provides none.
	 *
	 * @param string $template Resolved template path.
	 * @return string
	 */
	public function template_include( string $template ): string {
		if ( self::$yield ) {
			return $template;
		}

		/**
		 * Allow integrations (Elementor Theme Builder) to take over entirely.
		 *
		 * @param bool $yield True to leave template resolution alone.
		 */
		if ( apply_filters( 'eex_template_yield', false ) ) {
			return $template;
		}

		$candidates = [];

		if ( is_singular( PostTypes::EVENT ) ) {
			$candidates[] = 'single-eex_event.php';
		} elseif ( is_singular( PostTypes::TALK ) ) {
			$candidates[] = 'single-eex_talk.php';
		} elseif ( is_singular( PostTypes::SPEAKER ) ) {
			$candidates[] = 'single-eex_speaker.php';
		} elseif ( is_singular( PostTypes::SPONSOR ) ) {
			$candidates[] = 'single-eex_sponsor.php';
		} elseif ( is_post_type_archive( PostTypes::EVENT ) ) {
			$candidates[] = 'archive-eex_event.php';
		} elseif ( is_post_type_archive( PostTypes::TALK ) ) {
			$candidates[] = 'archive-eex_talk.php';
		} elseif ( is_post_type_archive( PostTypes::SPEAKER ) ) {
			$candidates[] = 'archive-eex_speaker.php';
		} elseif ( is_tax( Taxonomies::CATEGORY ) ) {
			$candidates[] = 'taxonomy-eex_category.php';
		} elseif ( is_tax( Taxonomies::SERIES ) ) {
			$candidates[] = 'taxonomy-eex_event_series.php';
		}

		if ( empty( $candidates ) ) {
			return $template;
		}

		// A template the theme resolved specifically for this view wins.
		$basename = basename( $template );
		if ( in_array( $basename, $candidates, true ) ) {
			return $template;
		}

		// Belt-and-braces: never displace a template a page builder resolved.
		if ( str_contains( $template, '/elementor/' ) || str_contains( $template, '/elementor-pro/' ) ) {
			return $template;
		}

		$located = self::locate( $candidates );

		return '' !== $located ? $located : $template;
	}

	/**
	 * Locate a template: theme's emailexpert-events/ directory, then plugin.
	 *
	 * @param string[] $candidates Template file names in priority order.
	 * @return string Path or ''.
	 */
	public static function locate( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			$theme = locate_template( [ 'emailexpert-events/' . $candidate ] );
			if ( '' !== $theme ) {
				return $theme;
			}

			$plugin = EEX_PLUGIN_DIR . 'templates/' . $candidate;
			if ( file_exists( $plugin ) ) {
				return $plugin;
			}
		}

		return '';
	}

	/**
	 * Render an overridable template part with variables.
	 *
	 * @param string              $slug Part name, e.g. 'card-talk'.
	 * @param array<string,mixed> $args Variables exposed to the part as $args.
	 */
	public static function part( string $slug, array $args = [] ): void {
		$path = self::locate( [ 'parts/' . $slug . '.php' ] );

		if ( '' === $path ) {
			return;
		}

		load_template( $path, false, $args );
	}
}
