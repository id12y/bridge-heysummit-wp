<?php
/**
 * Elementor base widget for the composition components.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Frontend\Components;

defined( 'ABSPATH' ) || exit;

/**
 * The two compositions need organised controls, conditions and real
 * pickers rather than the generic widget's single flat Content section —
 * without making that widget more complex for the twenty simple
 * components it serves. This base builds one Elementor section per
 * attribute group (the same groups the block inspector shows), applies
 * per-widget conditions and picker upgrades, and adds a Style tab that
 * writes the composition's --eex-* tokens at widget scope.
 *
 * Rendering is the shared component renderer; this class maps controls
 * and nothing else.
 */
abstract class CompositeWidget extends \Elementor\Widget_Base {

	/**
	 * The component key (a Components::COMPOSITES member).
	 */
	abstract protected function component(): string;

	/**
	 * Elementor conditions per attribute key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function conditions(): array {
		return [];
	}

	/**
	 * Attribute keys lifted into a different section than their definition
	 * group (e.g. responsive behaviour).
	 *
	 * @return array<string,string>
	 */
	protected function group_overrides(): array {
		return [];
	}

	/**
	 * Widget slug.
	 */
	public function get_name(): string {
		return 'eex-' . $this->component();
	}

	/**
	 * Widget title from the shared definition table.
	 */
	public function get_title(): string {
		$definitions = Components::definitions();

		return (string) ( $definitions[ $this->component() ]['title'] ?? $this->component() );
	}

	/**
	 * Category.
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
		return [ 'emailexpert', 'heysummit', 'event', 'homepage', 'landing', 'hero', 'editorial' ];
	}

	/**
	 * Build the grouped content sections, then the style tab.
	 */
	protected function register_controls(): void {
		$definitions = Components::definitions();
		$atts        = (array) ( $definitions[ $this->component() ]['atts'] ?? [] );
		$overrides   = $this->group_overrides();
		$conditions  = $this->conditions();

		// Partition the attributes into their groups, in definition order.
		$groups = [];
		foreach ( $atts as $key => $spec ) {
			$group = (string) ( $overrides[ $key ] ?? $spec['group'] ?? __( 'General', 'emailexpert-events' ) );

			$groups[ $group ][ $key ] = $spec;
		}

		$index = 0;
		foreach ( $groups as $group => $group_atts ) {
			$this->start_controls_section(
				'eex_group_' . $index,
				[
					'label' => $group,
					'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
				]
			);

			foreach ( $group_atts as $key => $spec ) {
				$this->add_composite_control( (string) $key, (array) $spec, (array) ( $conditions[ $key ] ?? [] ) );
			}

			$this->end_controls_section();
			++$index;
		}

		$this->register_style_sections();
	}

	/**
	 * One attribute control, with picker upgrades and conditions.
	 *
	 * @param string              $key       Attribute key.
	 * @param array<string,mixed> $spec      Attribute spec.
	 * @param array<string,mixed> $condition Elementor condition array.
	 */
	protected function add_composite_control( string $key, array $spec, array $condition ): void {
		$args = [
			'label'       => (string) ( $spec['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ),
			'default'     => (string) $spec['default'],
			'label_block' => true,
		];

		if ( ! empty( $condition ) ) {
			$args['condition'] = $condition;
		}

		if ( ! empty( $spec['description'] ) ) {
			$args['description'] = (string) $spec['description'];
		}

		// Picker upgrades: events, sessions and posts by name, never raw IDs
		// once the data source has answered.
		if ( in_array( $key, [ 'featured_event', 'event' ], true ) ) {
			$options = $this->event_choices();

			if ( ! empty( $options ) ) {
				$args['type']    = \Elementor\Controls_Manager::SELECT;
				$args['options'] = [ '' => __( 'Choose an event…', 'emailexpert-events' ) ] + $options;
				$args['default'] = '';
				$this->add_control( $key, $args );

				return;
			}
		}

		if ( in_array( $key, [ 'featured_session', 'presentation_session', 'hero_session' ], true ) ) {
			$options = $this->session_choices();

			if ( ! empty( $options ) ) {
				$args['type']    = \Elementor\Controls_Manager::SELECT2;
				$args['options'] = $options;
				$args['default'] = '';
				$this->add_control( $key, $args );

				return;
			}
		}

		if ( in_array( $key, [ 'story_id', 'story2_id' ], true ) ) {
			$options = $this->story_choices();

			if ( ! empty( $options ) ) {
				$args['type']        = \Elementor\Controls_Manager::SELECT2;
				$args['options']     = $options;
				$args['default']     = '';
				$args['description'] = __( 'Search by title. Posts published later appear after the editor reloads.', 'emailexpert-events' );
				$this->add_control( $key, $args );

				return;
			}
		}

		if ( in_array( $key, [ 'pin_until', 'preview_at' ], true ) ) {
			$args['type']           = \Elementor\Controls_Manager::DATE_TIME;
			$args['picker_options'] = [ 'enableTime' => true ];
			$this->add_control( $key, $args );

			return;
		}

		if ( ! empty( $spec['options'] ) ) {
			$args['type']        = \Elementor\Controls_Manager::SELECT;
			$args['options']     = (array) $spec['options'];
			$args['label_block'] = false;
			$this->add_control( $key, $args );

			return;
		}

		if ( ! empty( $spec['flag'] ) ) {
			$args['type']         = \Elementor\Controls_Manager::SWITCHER;
			$args['return_value'] = '1';
			$args['default']      = $spec['default'] ? '1' : '';
			$args['label_block']  = false;
			$this->add_control( $key, $args );

			return;
		}

		if ( 'integer' === ( $spec['type'] ?? 'string' ) ) {
			$args['type']        = \Elementor\Controls_Manager::NUMBER;
			$args['default']     = (int) $spec['default'];
			$args['min']         = 0;
			$args['label_block'] = false;
			$this->add_control( $key, $args );

			return;
		}

		$args['type'] = \Elementor\Controls_Manager::TEXT;
		$this->add_control( $key, $args );
	}

	/**
	 * Events as "name — date" options, from either repository.
	 *
	 * @return array<string,string>
	 */
	protected function event_choices(): array {
		$options = [];

		foreach ( Repositories::current()->all_events( [] ) as $event ) {
			$hs_id = (string) ( $event['hs_id'] ?? '' );

			if ( '' === $hs_id ) {
				continue;
			}

			$when = '';
			$ts   = strtotime( (string) ( $event['first_talk_at'] ?? '' ) );

			if ( false !== $ts && $ts > 0 ) {
				$when = ' — ' . gmdate( 'j M Y', $ts );
			}

			$options[ $hs_id ] = (string) ( $event['title'] ?? $hs_id ) . $when;
		}

		return $options;
	}

	/**
	 * Sessions as "title — event, date" options (upcoming plus a few recent,
	 * so post-event pinning still finds its session).
	 *
	 * @return array<string,string>
	 */
	protected function session_choices(): array {
		$repository = Repositories::current();

		$talks = array_merge(
			$repository->upcoming_talks( [ 'limit' => 40 ] ),
			$repository->past_talks( [ 'limit' => 10 ] )
		);

		$options = [];

		foreach ( $talks as $talk ) {
			$ref = (string) ( $talk['hs_id'] ?: (string) ( $talk['id'] ?? '' ) );

			if ( '' === $ref || isset( $options[ $ref ] ) ) {
				continue;
			}

			$when = '';
			$ts   = strtotime( (string) ( $talk['starts_at'] ?? '' ) );

			if ( false !== $ts && $ts > 0 ) {
				$when = ', ' . gmdate( 'j M Y', $ts );
			}

			$event_title = \Emailexpert\Events\Data\EventTitles::known()[ (string) ( $talk['event_hs_id'] ?? '' ) ] ?? '';

			$options[ $ref ] = (string) $talk['title'] . ( '' !== $event_title || '' !== $when ? ' — ' . trim( $event_title . $when, ', ' ) : '' );
		}

		return $options;
	}

	/**
	 * Recent public posts as story options (searchable in the SELECT2).
	 *
	 * @return array<string,string>
	 */
	protected function story_choices(): array {
		$posts = get_posts(
			[
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'no_found_rows'  => true,
			]
		);

		$options = [];

		foreach ( (array) $posts as $post ) {
			$options[ (string) $post->ID ] = (string) $post->post_title;
		}

		return $options;
	}

	/**
	 * The Style tab: composition tokens at widget scope, grouped the way an
	 * editor thinks (layout, typography, colours, buttons, images).
	 */
	protected function register_style_sections(): void {
		$this->start_controls_section(
			'eex_style_layout',
			[
				'label' => __( 'Layout and spacing', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'eex_page_max',
			[
				'label'      => __( 'Maximum width', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem', '%' ],
				'range'      => [
					'px' => [
						'min' => 600,
						'max' => 1800,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-page-max: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_composition_gap',
			[
				'label'      => __( 'Composition gap', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 8,
						'max' => 80,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-composition-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_composition_space',
			[
				'label'      => __( 'Section spacing', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 16,
						'max' => 140,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-composition-space: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->layout_extras();

		$this->end_controls_section();

		$this->start_controls_section(
			'eex_style_typography',
			[
				'label' => __( 'Typography', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$typography = [
			'eex_typo_lead'    => [ __( 'Lead headline', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-hh__story-title, {{WRAPPER}} .eex .eex-el__hero-title' ],
			'eex_typo_titles'  => [ __( 'Card and section titles', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-hh__event-title, {{WRAPPER}} .eex .eex-hh__news-title, {{WRAPPER}} .eex .eex-el__title, {{WRAPPER}} .eex .eex-el__final-title' ],
			'eex_typo_eyebrow' => [ __( 'Eyebrows', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-comp-eyebrow, {{WRAPPER}} .eex .eex-hh__label' ],
			'eex_typo_stand'   => [ __( 'Standfirst and intros', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-hh__story-standfirst, {{WRAPPER}} .eex .eex-hh__event-intro, {{WRAPPER}} .eex .eex-el__intro-description' ],
			'eex_typo_meta'    => [ __( 'Meta lines', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-hh__story-meta, {{WRAPPER}} .eex .eex-hh__news-meta, {{WRAPPER}} .eex .eex-hh__event-time, {{WRAPPER}} .eex .eex-el__hero-meta' ],
		];

		foreach ( $typography as $id => [ $label, $selector ] ) {
			$this->add_group_control(
				\Elementor\Group_Control_Typography::get_type(),
				[
					'name'     => $id,
					'label'    => $label,
					'selector' => $selector,
				]
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'eex_style_colours',
			[
				'label' => __( 'Colours and surfaces', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$colours = [
			'ink'           => __( 'Ink (headings and body)', 'emailexpert-events' ),
			'muted'         => __( 'Muted text', 'emailexpert-events' ),
			'whisper-color' => __( 'Eyebrow colour', 'emailexpert-events' ),
			'accent'        => __( 'Accent', 'emailexpert-events' ),
			'surface'       => __( 'Surface tint', 'emailexpert-events' ),
			'line'          => __( 'Lines and borders', 'emailexpert-events' ),
			'card-bg'       => __( 'Card background', 'emailexpert-events' ),
		];

		foreach ( $colours as $prop => $label ) {
			$this->add_control(
				'eex_colour_' . str_replace( '-', '_', $prop ),
				[
					'label'     => $label,
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [
						'{{WRAPPER}} .eex' => '--eex-' . $prop . ': {{VALUE}};',
					],
				]
			);
		}

		$this->end_controls_section();

		$this->start_controls_section(
			'eex_style_buttons',
			[
				'label' => __( 'Buttons', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'eex_colour_accent_bg',
			[
				'label'     => __( 'Primary button background', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-accent: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'eex_colour_accent_fg',
			[
				'label'     => __( 'Primary button text', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'default'   => '#ffffff',
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-accent-fg: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'eex_button_size',
			[
				'label'                => __( 'Button size', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''   => __( 'Default', 'emailexpert-events' ),
					'sm' => __( 'Small', 'emailexpert-events' ),
					'md' => __( 'Medium', 'emailexpert-events' ),
					'lg' => __( 'Large', 'emailexpert-events' ),
				],
				'selectors_dictionary' => [
					'sm' => '--eex-cta-pad: 0.3em 0.75em; --eex-cta-font: 0.85em;',
					'md' => '--eex-cta-pad: 0.45em 1em; --eex-cta-font: 1em;',
					'lg' => '--eex-cta-pad: 0.6em 1.4em; --eex-cta-font: 1.1em;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex' => '{{VALUE}}',
				],
			]
		);

		$this->add_responsive_control(
			'eex_button_radius',
			[
				'label'      => __( 'Button corner radius', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 40,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-cta' => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'eex_style_images',
			[
				'label' => __( 'Images', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$ratios = [
			''         => __( 'Wide 16:9 (default)', 'emailexpert-events' ),
			'classic'  => __( 'Classic 4:3', 'emailexpert-events' ),
			'square'   => __( 'Square 1:1', 'emailexpert-events' ),
			'cinema'   => __( 'Panoramic 21:9', 'emailexpert-events' ),
			'portrait' => __( 'Portrait 3:4', 'emailexpert-events' ),
		];

		$ratio_values = [
			'classic'  => '4 / 3',
			'square'   => '1 / 1',
			'cinema'   => '21 / 9',
			'portrait' => '3 / 4',
		];

		foreach ( [
			'eex_story_ratio' => [ __( 'Story media ratio', 'emailexpert-events' ), '--eex-story-media-ratio' ],
			'eex_event_ratio' => [ __( 'Event media ratio', 'emailexpert-events' ), '--eex-event-media-ratio' ],
		] as $id => [ $label, $token ] ) {
			$dictionary = [];
			foreach ( $ratio_values as $ratio_key => $value ) {
				$dictionary[ $ratio_key ] = $token . ': ' . $value . ';';
			}

			$this->add_control(
				$id,
				[
					'label'                => $label,
					'type'                 => \Elementor\Controls_Manager::SELECT,
					'default'              => '',
					'options'              => $ratios,
					'selectors_dictionary' => $dictionary,
					'selectors'            => [
						'{{WRAPPER}} .eex' => '{{VALUE}}',
					],
				]
			);
		}

		$this->add_responsive_control(
			'eex_portrait_size',
			[
				'label'      => __( 'Speaker portrait size', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 24,
						'max' => 96,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-hh__portrait' => 'width: {{SIZE}}{{UNIT}}; height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();
	}

	/**
	 * Extra widget-specific layout controls (column ratios, news columns).
	 */
	protected function layout_extras(): void {}

	/**
	 * Render via the shared component renderer — identical to the block and
	 * the shortcode.
	 */
	protected function render(): void {
		$settings = (array) $this->get_settings_for_display();
		$atts     = [];

		$definitions = Components::definitions();
		foreach ( array_keys( (array) ( $definitions[ $this->component() ]['atts'] ?? [] ) ) as $key ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$atts[ $key ] = $this->attribute_value( $key, $settings[ $key ] );
		}

		echo Components::render( $this->component(), $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output is escaped at build time.
	}

	/**
	 * Map one Elementor setting to its attribute value (SELECT2 multiples
	 * arrive as arrays; subclasses map richer controls like repeaters).
	 *
	 * @param string $key   Attribute key.
	 * @param mixed  $value Raw setting value.
	 */
	protected function attribute_value( string $key, $value ): string {
		unset( $key );

		return is_array( $value ) ? implode( ',', array_map( 'strval', $value ) ) : (string) $value;
	}
}
