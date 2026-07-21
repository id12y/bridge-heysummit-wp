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
	 * Widget icon: one per component so the panel reads at a glance.
	 */
	public function get_icon(): string {
		$icons = [
			'past-sessions'     => 'eicon-history',
			'past-events'       => 'eicon-archive',
			'countdown'         => 'eicon-countdown',
			'schedule'          => 'eicon-time-line',
			'speakers'          => 'eicon-person',
			'featured-talks'    => 'eicon-star',
			'sponsors'          => 'eicon-gallery-grid',
			'sponsor-spotlight' => 'eicon-banner',
			'next-session'      => 'eicon-flash',
			'pricing'           => 'eicon-price-table',
			'speaker-spotlight' => 'eicon-testimonial',
			'events-portfolio'  => 'eicon-gallery-grid',
			'live-now'          => 'eicon-play',
			'session-filter'    => 'eicon-filter',
			'reg-counter'       => 'eicon-counter',
			'register-bar'      => 'eicon-call-to-action',
			'register-inline'   => 'eicon-form-horizontal',
			'stats'             => 'eicon-number-field',
			'replay-gallery'    => 'eicon-video-playlist',
			'venue'             => 'eicon-map-pin',
			'featured-session'  => 'eicon-single-post',
		];

		return $icons[ $this->component ] ?? 'eicon-calendar';
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

		// A starting point, not a straitjacket: each preset writes a bundle
		// of the same variables the individual controls below fine-tune.
		$this->add_control(
			'eex_skin',
			[
				'label'                => __( 'Design preset', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''           => __( 'Theme default', 'emailexpert-events' ),
					'boxed'      => __( 'Boxed — subtle background and border', 'emailexpert-events' ),
					'outlined'   => __( 'Outlined — border only', 'emailexpert-events' ),
					'soft'       => __( 'Soft — tinted, borderless, rounded', 'emailexpert-events' ),
					'chromeless' => __( 'Chromeless — no card styling at all', 'emailexpert-events' ),
					'inverted'   => __( 'Inverted — dark panels', 'emailexpert-events' ),
				],
				'selectors_dictionary' => [
					'boxed'      => '--eex-card-bg: rgba(127, 127, 127, 0.06); --eex-border: rgba(127, 127, 127, 0.25);',
					'outlined'   => '--eex-card-bg: transparent; --eex-border: rgba(127, 127, 127, 0.45);',
					'soft'       => '--eex-card-bg: rgba(127, 127, 127, 0.09); --eex-border: transparent; --eex-radius: 14px;',
					'chromeless' => '--eex-card-bg: transparent; --eex-border: transparent;',
					'inverted'   => '--eex-card-bg: #1a1d23; --eex-border: #2d323b; --eex-muted: #a7aeba; color: #f2f4f7;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex' => '{{VALUE}}',
				],
			]
		);

		$colour_props = [
			'accent'    => __( 'Button / accent background', 'emailexpert-events' ),
			'accent-fg' => __( 'Button / accent text', 'emailexpert-events' ),
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

		// Text colours write concrete color properties (not custom
		// properties): an always-on rule consuming an unset variable computes
		// as inherit and would silently override theme heading and link
		// colours on pages that never touch these controls. A direct
		// property only exists once the user sets a value.
		$title_selector   = '{{WRAPPER}} .eex .eex-card-title, {{WRAPPER}} .eex .eex-card-title a, {{WRAPPER}} .eex .eex-list-title, {{WRAPPER}} .eex .eex-agenda-title, {{WRAPPER}} .eex .eex-agenda-title a, {{WRAPPER}} .eex .eex-schedule-title, {{WRAPPER}} .eex .eex-compact-title, {{WRAPPER}} .eex .eex-sponsor-card-name, {{WRAPPER}} .eex .eex-sponsor-name';
		$heading_selector = '{{WRAPPER}} .eex .eex-schedule-heading, {{WRAPPER}} .eex .eex-agenda-heading, {{WRAPPER}} .eex .eex-tier-heading, {{WRAPPER}} .eex .eex-wall-heading, {{WRAPPER}} .eex .eex-spotlight-name, {{WRAPPER}} .eex .eex-hero-title';
		$desc_selector    = '{{WRAPPER}} .eex .eex-sponsor-blurb, {{WRAPPER}} .eex .eex-spotlight-description, {{WRAPPER}} .eex .eex-spotlight-bio, {{WRAPPER}} .eex .eex-pricing-description';

		$text_colours = [
			'eex_colour_text'    => [ __( 'Text colour', 'emailexpert-events' ), '{{WRAPPER}} .eex' ],
			'eex_colour_title'   => [ __( 'Title colour', 'emailexpert-events' ), $title_selector ],
			'eex_colour_heading' => [ __( 'Heading colour', 'emailexpert-events' ), $heading_selector ],
			'eex_colour_desc'    => [ __( 'Description colour', 'emailexpert-events' ), $desc_selector ],
			'eex_colour_link'    => [ __( 'Link colour', 'emailexpert-events' ), '{{WRAPPER}} .eex a' ],
		];

		foreach ( $text_colours as $id => [ $label, $selector ] ) {
			$this->add_control(
				$id,
				[
					'label'     => $label,
					'type'      => \Elementor\Controls_Manager::COLOR,
					'selectors' => [ $selector => 'color: {{VALUE}};' ],
				]
			);
		}

		$this->add_responsive_control(
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

		$this->add_responsive_control(
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

		$this->add_responsive_control(
			'eex_section_gap',
			[
				'label'      => __( 'Section spacing (day groups, tiers)', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 96,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex' => '--eex-section-gap: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_card_padding',
			[
				'label'      => __( 'Card padding', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-card, {{WRAPPER}} .eex .eex-agenda-row' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_row_padding',
			[
				'label'      => __( 'List row padding', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em', 'rem' ],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-list-row, {{WRAPPER}} .eex .eex-compact-row, {{WRAPPER}} .eex .eex-schedule-row' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
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
					'xl' => __( 'Extra large', 'emailexpert-events' ),
				],
				'selectors_dictionary' => [
					'sm' => '--eex-cta-pad: 0.3em 0.75em; --eex-cta-font: 0.85em;',
					'md' => '--eex-cta-pad: 0.45em 1em; --eex-cta-font: 1em;',
					'lg' => '--eex-cta-pad: 0.6em 1.4em; --eex-cta-font: 1.1em;',
					'xl' => '--eex-cta-pad: 0.8em 1.8em; --eex-cta-font: 1.25em;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex' => '{{VALUE}}',
				],
			]
		);

		$this->add_responsive_control(
			'eex_actions_align',
			[
				'label'     => __( 'Button row alignment', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => [
					'flex-start' => [
						'title' => __( 'Left', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center'     => [
						'title' => __( 'Centre', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-center',
					],
					'flex-end'   => [
						'title' => __( 'Right', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-actions-justify: {{VALUE}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_button_padding',
			[
				'label'       => __( 'Button padding', 'emailexpert-events' ),
				'description' => __( 'Fine-tune override; leave empty to use the Button size preset.', 'emailexpert-events' ),
				'type'        => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units'  => [ 'px', 'em', 'rem' ],
				'selectors'   => [
					'{{WRAPPER}} .eex .eex-cta' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_content_align',
			[
				'label'     => __( 'Content alignment', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => __( 'Left', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Centre', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => __( 'Right', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-content-align: {{VALUE}};',
				],
			]
		);

		// Direct text-align (not a variable): headings inherit the theme's
		// alignment until the operator chooses one here.
		$this->add_responsive_control(
			'eex_heading_align',
			[
				'label'     => __( 'Heading alignment', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => [
					'left'   => [
						'title' => __( 'Left', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-left',
					],
					'center' => [
						'title' => __( 'Centre', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-center',
					],
					'right'  => [
						'title' => __( 'Right', 'emailexpert-events' ),
						'icon'  => 'eicon-text-align-right',
					],
				],
				'selectors' => [
					$heading_selector => 'text-align: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'eex_colour_accent_hover',
			[
				'label'     => __( 'Button hover background', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eex .eex-cta:hover, {{WRAPPER}} .eex .eex-cta:focus-visible' => 'background: {{VALUE}};',
				],
			]
		);

		$this->add_control(
			'eex_colour_accent_hover_fg',
			[
				'label'     => __( 'Button hover text', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eex .eex-cta:hover, {{WRAPPER}} .eex .eex-cta:focus-visible' => 'color: {{VALUE}};',
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
					'em' => [
						'min'  => 0,
						'max'  => 3,
						'step' => 0.1,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-cta, {{WRAPPER}} .eex .eex-cta-secondary' => 'border-radius: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'eex_buttons_full',
			[
				'label'                => __( 'Full-width buttons in cards', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SWITCHER,
				'return_value'         => 'yes',
				'selectors_dictionary' => [
					'yes' => 'display: block; width: 100%; text-align: center; box-sizing: border-box;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex .eex-card .eex-cta, {{WRAPPER}} .eex .eex-card .eex-cta-secondary' => '{{VALUE}}',
				],
			]
		);

		// The classic sponsor-wall chip: a tile behind each logo, for
		// transparent logos that vanish on tinted or dark page backgrounds.
		$this->add_control(
			'eex_logo_tile',
			[
				'label'     => __( 'Logo tile background', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .eex .eex-card-sponsor > a, {{WRAPPER}} .eex .eex-card-sponsor > .eex-sponsor-logo, {{WRAPPER}} .eex .eex-sponsor-row-logo, {{WRAPPER}} .eex .eex-strip-item' => 'background: {{VALUE}}; border-radius: var(--eex-radius); padding: 0.5em;',
				],
			]
		);

		$this->add_responsive_control(
			'eex_logo_height',
			[
				'label'      => __( 'Sponsor logo size', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em' ],
				'range'      => [
					'px' => [
						'min' => 16,
						'max' => 200,
					],
					'em' => [
						'min'  => 1,
						'max'  => 12,
						'step' => 0.25,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-sponsor-row .eex-sponsor-logo, {{WRAPPER}} .eex .eex-sponsor-row-logo img, {{WRAPPER}} .eex .eex-strip-item .eex-sponsor-logo' => 'height: {{SIZE}}{{UNIT}};',
					'{{WRAPPER}} .eex .eex-card-sponsor .eex-sponsor-logo' => 'max-height: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Box_Shadow::get_type(),
			[
				'name'     => 'eex_card_shadow',
				'label'    => __( 'Card shadow', 'emailexpert-events' ),
				'selector' => '{{WRAPPER}} .eex .eex-card, {{WRAPPER}} .eex .eex-agenda-row',
			]
		);

		$this->add_control(
			'eex_card_hover',
			[
				'label'        => __( 'Card hover effect', 'emailexpert-events' ),
				'type'         => \Elementor\Controls_Manager::SELECT,
				'default'      => '',
				'options'      => [
					''           => __( 'None', 'emailexpert-events' ),
					'lift'       => __( 'Lift', 'emailexpert-events' ),
					'shadow'     => __( 'Shadow', 'emailexpert-events' ),
					'liftshadow' => __( 'Lift + shadow', 'emailexpert-events' ),
				],
				'prefix_class' => 'eex-hover-',
			]
		);

		$this->add_responsive_control(
			'eex_border_width',
			[
				'label'      => __( 'Card border width', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 6,
					],
				],
				'selectors'  => [
					'{{WRAPPER}} .eex .eex-card' => 'border-width: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_responsive_control(
			'eex_heading_spacing',
			[
				'label'      => __( 'Heading spacing below', 'emailexpert-events' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => [ 'px', 'em', 'rem' ],
				'range'      => [
					'px' => [
						'min' => 0,
						'max' => 64,
					],
				],
				'selectors'  => [
					$heading_selector => 'margin-bottom: {{SIZE}}{{UNIT}};',
				],
			]
		);

		$this->add_control(
			'eex_image_ratio',
			[
				'label'                => __( 'Image ratio (session images)', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''         => __( 'Wide 16:9 (default)', 'emailexpert-events' ),
					'classic'  => __( 'Classic 4:3', 'emailexpert-events' ),
					'square'   => __( 'Square 1:1', 'emailexpert-events' ),
					'cinema'   => __( 'Panoramic 21:9', 'emailexpert-events' ),
					'portrait' => __( 'Portrait 3:4', 'emailexpert-events' ),
				],
				'selectors_dictionary' => [
					'classic'  => '--eex-image-ratio: 4 / 3;',
					'square'   => '--eex-image-ratio: 1 / 1;',
					'cinema'   => '--eex-image-ratio: 21 / 9;',
					'portrait' => '--eex-image-ratio: 3 / 4;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex' => '{{VALUE}}',
				],
			]
		);

		if ( 'sponsors' === $this->component ) {
			$this->add_control(
				'eex_strip_speed',
				[
					'label'       => __( 'Strip scroll duration (seconds)', 'emailexpert-events' ),
					'description' => __( 'One full loop of the logo strip; higher is slower. Applies to the strip layout.', 'emailexpert-events' ),
					'type'        => \Elementor\Controls_Manager::NUMBER,
					'min'         => 5,
					'max'         => 240,
					'selectors'   => [
						'{{WRAPPER}} .eex' => '--eex-strip-speed: {{VALUE}}s;',
					],
				]
			);
		}

		$this->add_control(
			'eex_logo_effect',
			[
				'label'                => __( 'Logo treatment', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''        => __( 'Full colour', 'emailexpert-events' ),
					'grey'    => __( 'Greyscale until hover', 'emailexpert-events' ),
					'dim'     => __( 'Dimmed until hover', 'emailexpert-events' ),
					'greydim' => __( 'Greyscale + dimmed until hover', 'emailexpert-events' ),
				],
				'selectors_dictionary' => [
					'grey'    => '--eex-logo-filter: grayscale(1);',
					'dim'     => '--eex-logo-opacity: 0.6;',
					'greydim' => '--eex-logo-filter: grayscale(1); --eex-logo-opacity: 0.6;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex' => '{{VALUE}}',
				],
			]
		);

		// Per-device grid columns. Set from Elementor's breakpoints (tablet
		// 1024px by default) but consumed by this plugin's own 900/600px
		// media queries — the variable is simply defined a little earlier
		// than it is used. The speakers content-tab columns attribute wins
		// over these until it is set to 0.
		$this->add_responsive_control(
			'eex_columns_css',
			[
				'label'       => __( 'Grid columns', 'emailexpert-events' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 1,
				'max'         => 6,
				'selectors'   => [
					'{{WRAPPER}} .eex' => '--eex-columns: {{VALUE}};',
				],
				'device_args' => [
					'tablet' => [
						'selectors' => [
							'{{WRAPPER}} .eex' => '--eex-columns-tablet: {{VALUE}};',
						],
					],
					'mobile' => [
						'selectors' => [
							'{{WRAPPER}} .eex' => '--eex-columns-mobile: {{VALUE}};',
						],
					],
				],
			]
		);

		$this->end_controls_section();

		// Typography: theme fonts are inherited by default; these groups only
		// emit rules once the user changes something.
		$this->start_controls_section(
			'eex_typography',
			[
				'label' => __( 'Typography', 'emailexpert-events' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			]
		);

		$meta_selector = '{{WRAPPER}} .eex .eex-card-time, {{WRAPPER}} .eex .eex-card-venue, {{WRAPPER}} .eex .eex-list-time, {{WRAPPER}} .eex .eex-compact-time, {{WRAPPER}} .eex .eex-agenda-time, {{WRAPPER}} .eex .eex-schedule-time, {{WRAPPER}} .eex .eex-speaker-headline, {{WRAPPER}} .eex .eex-speaker-company, {{WRAPPER}} .eex .eex-agenda-speaker-role, {{WRAPPER}} .eex .eex-tz';

		$typography = [
			'eex_typo_heading' => [ __( 'Headings (hero title, category and wall headings, spotlight name)', 'emailexpert-events' ), $heading_selector ],
			'eex_typo_title'   => [ __( 'Titles (cards, rows, sponsor names)', 'emailexpert-events' ), $title_selector ],
			'eex_typo_meta'    => [ __( 'Times and meta', 'emailexpert-events' ), $meta_selector ],
			'eex_typo_desc'    => [ __( 'Descriptions and blurbs', 'emailexpert-events' ), $desc_selector ],
			'eex_typo_button'  => [ __( 'Buttons', 'emailexpert-events' ), '{{WRAPPER}} .eex .eex-cta, {{WRAPPER}} .eex .eex-cta-secondary' ],
			'eex_typo_body'    => [ __( 'Body', 'emailexpert-events' ), '{{WRAPPER}} .eex' ],
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
	}

	/**
	 * Map one component attribute to a control.
	 *
	 * @param string              $key  Attribute key.
	 * @param array<string,mixed> $spec Attribute spec (type, default).
	 */
	private function add_attribute_control( string $key, array $spec ): void {
		// Query-string-driven attributes (page numbers, search terms) must
		// not be baked into a widget as fixed values.
		if ( isset( $spec['from_get'] ) ) {
			return;
		}

		// Event picker: a dropdown of this site's actual events instead of a
		// raw HeySummit ID box. Both repositories can list events (synced
		// posts in Full, the cached account listing in Lite).
		if ( 'event' === $key ) {
			$options = [];
			foreach ( \Emailexpert\Events\Data\Repositories::current()->all_events( [] ) as $event ) {
				$hs_id = (string) ( $event['hs_id'] ?? '' );
				if ( '' !== $hs_id ) {
					$options[ $hs_id ] = sprintf( '%s (%s)', (string) ( $event['title'] ?? $hs_id ), $hs_id );
				}
			}

			if ( ! empty( $options ) ) {
				$this->add_control(
					$key,
					[
						'label'   => __( 'Event', 'emailexpert-events' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => [ '' => __( 'Default event', 'emailexpert-events' ) ] + $options,
						'default' => '',
					]
				);

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => __( 'Event (HeySummit ID, empty = default)', 'emailexpert-events' ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => (string) $spec['default'],
					'description' => __( 'Event names appear here as a dropdown once the connection has loaded events.', 'emailexpert-events' ),
				]
			);

			return;
		}

		// Coupon picker: a dropdown of the event's live coupon codes instead
		// of a raw code box. The picked code feeds the same coupon attribute
		// the buy buttons already bake into checkout links (D91). Falls back
		// to a text field before the connection has loaded any coupons, so
		// manual entry (and blocks/shortcodes) keep working.
		if ( 'coupon' === $key ) {
			$options = [];
			foreach ( \Emailexpert\Events\Data\Repositories::current()->all_events( [] ) as $event ) {
				$conn  = (string) ( $event['connection'] ?? '' );
				$hs_id = (string) ( $event['hs_id'] ?? '' );
				if ( '' === $conn || '' === $hs_id ) {
					continue;
				}

				$codes = \Emailexpert\Events\Data\Coupons::code_options( $conn, $hs_id );
				if ( is_wp_error( $codes ) ) {
					continue;
				}

				foreach ( $codes as $option ) {
					$options[ (string) $option['code'] ] = sprintf( '%s (%s)', (string) $option['title'], (string) $option['code'] );
				}
			}

			if ( ! empty( $options ) ) {
				$this->add_control(
					$key,
					[
						'label'   => __( 'Coupon', 'emailexpert-events' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => [ '' => __( 'No coupon', 'emailexpert-events' ) ] + $options,
						'default' => '',
					]
				);

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => (string) ( $spec['label'] ?? $key ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => (string) $spec['default'],
					'description' => __( 'Coupon codes appear here as a dropdown once the connection has loaded this event\'s coupons.', 'emailexpert-events' ),
				]
			);

			return;
		}

		// Sponsor picker for the spotlight: names seen on any sponsor fetch.
		if ( 'sponsor' === $key && 'sponsor-spotlight' === $this->component ) {
			$names = \Emailexpert\Events\Data\Sponsors::known_names();

			if ( ! empty( $names ) ) {
				$options = [];
				foreach ( $names as $sponsor_id => $sponsor_name ) {
					$options[ (string) $sponsor_id ] = $sponsor_name;
				}

				$this->add_control(
					$key,
					[
						'label'   => __( 'Sponsor', 'emailexpert-events' ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => [ '' => __( 'Random (rotates each cache refresh)', 'emailexpert-events' ) ] + $options,
						'default' => '',
					]
				);

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => (string) ( $spec['label'] ?? $key ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => '',
					'description' => __( 'Sponsor names appear here as a dropdown once sponsors have been loaded once (view the sponsor wall).', 'emailexpert-events' ),
				]
			);

			return;
		}

		// Sponsor category picker: names seen on any sponsor fetch — and
		// when none are known yet, fetched right now (editor only).
		if ( 'sponsor_category' === $key ) {
			$categories = \Emailexpert\Events\Data\Sponsors::known_categories();

			if ( empty( $categories ) ) {
				$this->seed_commerce_names();
				$categories = \Emailexpert\Events\Data\Sponsors::known_categories();
			}

			if ( ! empty( $categories ) ) {
				$options = [];
				foreach ( $categories as $category ) {
					$options[ strtolower( $category ) ] = $category;
				}

				$this->add_control(
					$key,
					[
						'label'   => (string) ( $spec['label'] ?? $key ),
						'type'    => \Elementor\Controls_Manager::SELECT,
						'options' => [ '' => __( 'All categories', 'emailexpert-events' ) ] + $options,
						'default' => '',
					]
				);

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => (string) ( $spec['label'] ?? $key ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => (string) $spec['default'],
					'description' => __( 'Category names appear here as a dropdown once sponsors have been loaded once (view the sponsor wall).', 'emailexpert-events' ),
				]
			);

			return;
		}

		// Ticket pickers: dropdowns with the ticket names this site has
		// seen (populated by any tickets fetch — the pricing table itself,
		// the Woo picker, the wizard). Falls back to a text field before the
		// first fetch.
		// The sponsors wall's exclude field picks sponsors by NAME.
		if ( 'exclude' === $key && 'sponsors' === $this->component ) {
			$sponsor_names = \Emailexpert\Events\Data\Sponsors::known_names();

			if ( ! empty( $sponsor_names ) ) {
				$options = [];
				foreach ( $sponsor_names as $sponsor_id => $sponsor_name ) {
					$options[ (string) $sponsor_id ] = $sponsor_name;
				}

				$this->add_control(
					$key,
					[
						'label'       => (string) ( $spec['label'] ?? $key ),
						'type'        => \Elementor\Controls_Manager::SELECT2,
						'multiple'    => true,
						'options'     => $options,
						'label_block' => true,
					]
				);

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => (string) ( $spec['label'] ?? $key ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => (string) $spec['default'],
					'label_block' => true,
					'description' => __( 'Sponsor names appear here as a dropdown once sponsors have been loaded once (view the sponsor wall).', 'emailexpert-events' ),
				]
			);

			return;
		}

		if ( in_array( $key, [ 'tickets', 'exclude', 'featured' ], true ) && in_array( $this->component, [ 'pricing', 'upcoming-sessions', 'featured-talks', 'next-session', 'register-bar' ], true ) ) {
			$titles = \Emailexpert\Events\Data\Tickets::known_titles();

			if ( empty( $titles ) ) {
				$this->seed_commerce_names();
				$titles = \Emailexpert\Events\Data\Tickets::known_titles();
			}

			if ( ! empty( $titles ) ) {
				$options = [];
				foreach ( $titles as $ticket_id => $ticket_title ) {
					$options[ (string) $ticket_id ] = sprintf( '%s (%s)', $ticket_title, $ticket_id );
				}

				if ( 'featured' === $key ) {
					$this->add_control(
						$key,
						[
							'label'   => (string) ( $spec['label'] ?? $key ),
							'type'    => \Elementor\Controls_Manager::SELECT,
							'options' => [ '' => __( 'None (use HeySummit\'s popular flag)', 'emailexpert-events' ) ] + $options,
							'default' => '',
						]
					);
				} else {
					$this->add_control(
						$key,
						[
							'label'       => (string) ( $spec['label'] ?? $key ),
							'type'        => \Elementor\Controls_Manager::SELECT2,
							'multiple'    => true,
							'options'     => $options,
							'label_block' => true,
						]
					);
				}

				return;
			}

			$this->add_control(
				$key,
				[
					'label'       => (string) ( $spec['label'] ?? $key ),
					'type'        => \Elementor\Controls_Manager::TEXT,
					'default'     => (string) $spec['default'],
					'label_block' => true,
					'description' => __( 'Ticket names appear here as a dropdown once tickets have been loaded once (view the pricing widget or use a ticket picker).', 'emailexpert-events' ),
				]
			);

			return;
		}

		if ( ! empty( $spec['options'] ) ) {
			$this->add_control(
				$key,
				[
					'label'   => (string) ( $spec['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => (array) $spec['options'],
					'default' => (string) $spec['default'],
				]
			);

			return;
		}

		if ( ! empty( $spec['flag'] ) ) {
			$this->add_control(
				$key,
				[
					'label'        => (string) ( $spec['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ),
					'type'         => \Elementor\Controls_Manager::SWITCHER,
					'return_value' => '1',
					'default'      => $spec['default'] ? '1' : '',
				]
			);

			return;
		}

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

		if ( 'integer' === $spec['type'] ) {
			$args = [
				'label'   => (string) ( $spec['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => (int) $spec['default'],
				'min'     => 0,
			];

			// The speakers content columns beat the wrapper-level responsive
			// columns control; explain the hand-over.
			if ( 'columns' === $key ) {
				$args['description'] = __( 'Set to 0 to control columns from the Style tab (responsive per device).', 'emailexpert-events' );
			}

			$this->add_control( $key, $args );

			return;
		}

		$this->add_control(
			$key,
			[
				'label'       => (string) ( $spec['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => (string) $spec['default'],
				'label_block' => true,
			]
		);
	}

	/**
	 * Editor-only: when a picker's learned names are empty, fetch them now
	 * from the configured events instead of sending the operator off to
	 * view a widget first. Cached fetches, bounded to the first three
	 * events, once per request; never runs on the front end.
	 */
	private function seed_commerce_names(): void {
		static $seeded = false;

		if ( $seeded || ! is_admin() ) {
			return;
		}
		$seeded = true;

		foreach ( array_slice( $this->configured_event_pairs(), 0, 3 ) as [ $conn_id, $event_id ] ) {
			\Emailexpert\Events\Data\Sponsors::for_display( $conn_id, $event_id );
			\Emailexpert\Events\Data\Tickets::raw( $conn_id, $event_id );
		}
	}

	/**
	 * The configured events as [connection_id, event_hs_id] pairs, in both
	 * modes.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	private function configured_event_pairs(): array {
		$pairs = [];

		if ( \Emailexpert\Events\Options::is_lite() ) {
			foreach ( (array) \Emailexpert\Events\Options::setting( 'lite_events' ) as $key ) {
				[ $conn_id, $event_id ] = array_pad( explode( '|', (string) $key, 2 ), 2, '' );
				if ( '' !== $conn_id && '' !== $event_id ) {
					$pairs[] = [ $conn_id, $event_id ];
				}
			}

			return $pairs;
		}

		$events = get_posts(
			[
				'post_type'      => PostTypes::EVENT,
				'post_status'    => 'publish',
				'posts_per_page' => 3,
				'no_found_rows'  => true,
				'fields'         => 'ids',
			]
		);

		foreach ( $events as $post_id ) {
			$conn_id  = (string) get_post_meta( (int) $post_id, '_eex_connection_id', true );
			$event_id = (string) get_post_meta( (int) $post_id, '_eex_heysummit_id', true );
			if ( '' !== $conn_id && '' !== $event_id ) {
				$pairs[] = [ $conn_id, $event_id ];
			}
		}

		return $pairs;
	}

	/**
	 * Events as select options (HeySummit ID => title): synced posts in
	 * Full mode; in Lite (no local posts) the titles remembered from live
	 * fetches, so the picker works there too.
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

		if ( empty( $options ) ) {
			$options = \Emailexpert\Events\Data\EventTitles::known();
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

		// Lite has no terms: the names remembered from live fetches fill
		// the picker instead (the event/ticket-titles pattern).
		if ( empty( $options ) ) {
			$options = \Emailexpert\Events\Data\CategoryTitles::known();
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
