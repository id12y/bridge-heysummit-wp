<?php
/**
 * Elementor integration module.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Strictly additive Elementor support. Registered on elementor/init only, so
 * none of this namespace loads when Elementor is absent. Elementor and
 * Elementor Pro are detected separately: Pro-only features (Theme Builder
 * yield, dynamic tags in Theme Builder, Loop Grid queries) degrade silently
 * on free Elementor. The module is a thin control-mapping layer: rendering
 * is always the shared component callbacks.
 */
final class Module {

	/**
	 * Entry point, hooked to elementor/init by the main plugin class.
	 */
	public static function register(): void {
		$module = new self();

		add_action( 'elementor/elements/categories_registered', [ $module, 'register_category' ] );
		add_action( 'elementor/widgets/register', [ $module, 'register_widgets' ] );

		if ( self::has_pro() && ! \Emailexpert\Events\Options::is_lite() ) {
			// Dynamic tags, Loop Grid queries and Theme Builder need local
			// content; in Lite only the plain widgets register.
			add_action( 'elementor/dynamic_tags/register', [ $module, 'register_tags' ] );
			( new Queries() )->register();
			( new ThemeBuilder() )->register();
		}
	}

	/**
	 * Whether Elementor Pro is active.
	 */
	public static function has_pro(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Add the widget category.
	 *
	 * @param object $elements_manager Elementor elements manager.
	 */
	public function register_category( $elements_manager ): void {
		$elements_manager->add_category(
			'emailexpert-events',
			[ 'title' => __( 'emailexpert Events', 'emailexpert-events' ) ]
		);
	}

	/**
	 * Register one widget per component, all instances of the same
	 * parameterised class wrapping the shared render callbacks.
	 *
	 * @param object $widgets_manager Elementor widgets manager.
	 */
	public function register_widgets( $widgets_manager ): void {
		foreach ( array_keys( \Emailexpert\Events\Frontend\Components::available_definitions() ) as $component ) {
			$widgets_manager->register( new ComponentWidget( [], [ 'eex_component' => $component ] ) );
		}
	}

	/**
	 * Register the dynamic tag group and tags.
	 *
	 * @param object $dynamic_tags Elementor dynamic tags manager.
	 */
	public function register_tags( $dynamic_tags ): void {
		$dynamic_tags->register_group(
			'emailexpert-events',
			[ 'title' => __( 'emailexpert Events', 'emailexpert-events' ) ]
		);

		foreach ( DynamicTags\Tags::classes() as $class_name ) {
			$dynamic_tags->register( new $class_name() );
		}
	}
}
