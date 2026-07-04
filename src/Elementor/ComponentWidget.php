<?php
/**
 * Parameterised Elementor widget wrapping a display component.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * One class, ten widgets: the component name arrives via the registration
 * $args (Elementor passes default args back on every instantiation), content
 * controls map one-to-one to the component attributes, and style controls
 * write to the --eex-* custom properties scoped to the widget wrapper — the
 * same theming mechanism themes use, so the two cannot conflict. Rendering
 * is the exact shared component callback; output matches the block and
 * shortcode byte-for-byte apart from Elementor's own wrapper.
 */
class ComponentWidget extends \Elementor\Widget_Base {

	/**
	 * Component key for this instance.
	 *
	 * @var string
	 */
	private string $component;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data Widget data from the editor.
	 * @param array<string,mixed> $args Registration args (eex_component).
	 */
	public function __construct( $data = [], $args = null ) {
		$this->component = (string) ( $args['eex_component'] ?? ( $data['settings']['eex_component'] ?? 'upcoming-sessions' ) );

		parent::__construct( $data, $args );
	}

	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'eex-' . $this->component;
	}

	/**
	 * Widget title.
	 */
	public function get_title(): string {
		$definitions = Components::definitions();

		return (string) ( $definitions[ $this->component ]['title'] ?? $this->component );
	}

	/**
	 * Widget icon.
	 */
	public function get_icon(): string {
		return 'eicon-calendar';
	}

	/**
	 * Widget category.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ 'emailexpert-events' ];
	}

	/**
	 * Search keywords.
	 *
	 * @return string[]
	 */
	public function get_keywords(): array {
		return [ 'emailexpert', 'heysummit', 'event', 'session', 'speaker' ];
	}

	/**
	 * Controls: content controls mirror the component attributes; style
	 * controls write CSS custom properties.
	 */
	protected function register_controls(): void {
		$definitions = Components::definitions();
		$atts        = (array) ( $definitions[ $this->component ]['atts'] ?? [] );

		$this->start_controls_section(
			'eex_content',
			[
				'label' => __( 'Content', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			]
		);

		foreach ( $atts as $key => $spec ) {
			$this->add_attribute_control( $key, $spec );
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'eex_style',
			[
				'label' => __( 'Style', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$colour_props = [
			'accent'    => __( 'Accent colour', 'emailexpert-events' ),
			'accent-fg' => __( 'Accent text colour', 'emailexpert-events' ),
			'badge-bg'  => __( 'Badge background', 'emailexpert-events' ),
			'badge-fg'  => __( 'Badge text', 'emailexpert-events' ),
			'border'    => __( 'Border colour', 'emailexpert-events' ),
			'card-bg'   => __( 'Card background', 'emailexpert-events' ),
			'live'      => __( 'Live indicator colour', 'emailexpert-events' ),
			'muted'     => __( 'Muted text colour', 'emailexpert-events' ),
		];

		foreach ( $colour_props as $prop => $label ) {
			$args = [
				'label'     => $label,
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-' . $prop . ': {{VALUE}};',
				],
			];

			// The accent foreground must stay readable on the accent
			// background whatever the theme's link colour does.
			if ( 'accent-fg' === $prop ) {
				$args['default'] = '#ffffff';
			}

			$this->add_control( 'eex_colour_' . str_replace( '-', '_', $prop ), $args );
		}

		$this->add_control(
			'eex_radius',
			[
				'label'      => __( 'Corner radius', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'eex_gap',
			[
				'label'      => __( 'Grid gap', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 64,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Map one component attribute to a control.
	 *
	 * @param string              $key  Attribute key.
	 * @param array<string,mixed> $spec Attribute spec (type, default).
	 */
	private function add_attribute_control( string $key, array $spec ): void {
		if ( 'event' === $key ) {
			$this->add_control(
				$key,
				[
					'label'   => __( 'Event', 'emailexpert-events' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => [ '' => __( 'Default (sole synced event)', 'emailexpert-events' ) ] + $this->event_options(),
					'default' => (string) $spec['default'],
				]
			);

			return;
		}

		if ( 'category' === $key ) {
			$this->add_control(
				$key,
				[
					'label'       => __( 'Categories', 'emailexpert-events' ),
					'type'        => \Elementor\Controls_Manager::SELECT2,
					'multiple'    => true,
					'options'     => $this->category_options(),
					'label_block' => true,
				]
			);

			return;
		}

		if ( in_array( $key, [ 'paginate', 'show_subscribe' ], true ) ) {
			$this->add_control(
				$key,
				[
					'label'        => 'paginate' === $key ? __( 'Paginate', 'emailexpert-events' ) : __( 'Show subscribe link', 'emailexpert-events' ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => '1',
					'default'      => $spec['default'] ? '1' : '',
				]
			);

			return;
		}

		if ( 'integer' === $spec['type'] ) {
			$this->add_control(
				$key,
				[
					'label'   => ucwords( str_replace( '_', ' ', $key ) ),
					'type'    => \Elementor\Controls_Manager::NUMBER,
					'default' => (int) $spec['default'],
					'min'     => 0,
				]
			);

			return;
		}

		$this->add_control(
			$key,
			[
				'label'       => ucwords( str_replace( '_', ' ', $key ) ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => (string) $spec['default'],
				'label_block' => true,
			]
		);
	}

	/**
	 * Synced events as select options (HeySummit ID => title).
	 *
	 * @return array<string,string>
	 */
	private function event_options(): array {
		$options = [];

		$events = get_posts(
			[
				'post_type'      => PostTypes::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			]
		);

		foreach ( $events as $event ) {
			$hs_id = (string) get_post_meta( (int) $event->ID, '_eex_heysummit_id', true );
			if ( '' !== $hs_id ) {
				$options[ $hs_id ] = (string) $event->post_title;
			}
		}

		return $options;
	}

	/**
	 * Synced categories as select options (slug => name).
	 *
	 * @return array<string,string>
	 */
	private function category_options(): array {
		$options = [];
		$terms   = get_terms(
			[
				'taxonomy'   => Taxonomies::CATEGORY,
				'hide_empty' => false,
			]
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$options[ (string) $term->slug ] = (string) $term->name;
			}
		}

		return $options;
	}

	/**
	 * Render: the shared component callback, identical to blocks and
	 * shortcodes. Also runs for editor previews (server-side render).
	 */
	protected function render(): void {
		$settings = (array) $this->get_settings_for_display();
		$atts     = [];

		$definitions = Components::definitions();
		foreach ( array_keys( (array) ( $definitions[ $this->component ]['atts'] ?? [] ) ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}
			$value = $settings[ $key ];

			// SELECT2 multiple returns an array of slugs.
			$atts[ $key ] = is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : $value;
		}

		echo Components::render( $this->component, $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
	}
}
