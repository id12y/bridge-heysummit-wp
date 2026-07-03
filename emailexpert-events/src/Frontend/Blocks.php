<?php
/**
 * Gutenberg blocks.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Registers every component as a dynamic (server-rendered) block under the
 * "emailexpert Events" category. No block stores data in post content; the
 * editor scripts are plain JS using ServerSideRender, so previews use the
 * exact front-end render path.
 */
final class Blocks {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'init', [ $this, 'register_blocks' ] );
		add_filter( 'block_categories_all', [ $this, 'add_category' ] );
	}

	/**
	 * Add the block category.
	 *
	 * @param array<int,array<string,mixed>> $categories Existing categories.
	 * @return array<int,array<string,mixed>>
	 */
	public function add_category( array $categories ): array {
		array_unshift(
			$categories,
			[
				'slug'  => 'emailexpert-events',
				'title' => __( 'emailexpert Events', 'emailexpert-events' ),
			]
		);

		return $categories;
	}

	/**
	 * Register block types from the component definition table.
	 */
	public function register_blocks(): void {
		wp_register_script(
			'eex-blocks',
			EEX_PLUGIN_URL . 'blocks/editor.js',
			[ 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ],
			EEX_VERSION,
			true
		);

		wp_localize_script( 'eex-blocks', 'eexBlocks', [ 'definitions' => $this->editor_definitions() ] );

		foreach ( Components::definitions() as $name => $definition ) {
			$attributes = [];
			foreach ( $definition['atts'] as $key => $spec ) {
				$attributes[ $key ] = [
					'type'    => 'integer' === $spec['type'] ? 'number' : 'string',
					'default' => $spec['default'],
				];
			}

			register_block_type(
				'eex/' . $name,
				[
					'api_version'     => 3,
					'title'           => (string) $definition['title'],
					'category'        => 'emailexpert-events',
					'attributes'      => $attributes,
					'editor_script'   => 'eex-blocks',
					'style'           => 'eex-frontend',
					'render_callback' => static function ( $atts ) use ( $name ): string {
						return Components::render( $name, is_array( $atts ) ? $atts : [] );
					},
				]
			);
		}
	}

	/**
	 * Definition data for the editor script (labels and attribute schemas).
	 *
	 * @return array<string,mixed>
	 */
	private function editor_definitions(): array {
		$out = [];

		foreach ( Components::definitions() as $name => $definition ) {
			$out[ $name ] = [
				'title' => (string) $definition['title'],
				'atts'  => $definition['atts'],
			];
		}

		return $out;
	}
}
