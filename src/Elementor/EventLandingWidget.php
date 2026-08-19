<?php
/**
 * Elementor widget: Event Landing Page.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Thin subclass: names the component, swaps the sections attribute for a
 * proper drag-orderable repeater, and declares the conditional map.
 */
class EventLandingWidget extends CompositeWidget {

	/**
	 * Component key.
	 */
	protected function component(): string {
		return 'event-landing';
	}

	/**
	 * Icon.
	 */
	public function get_icon(): string {
		return 'eicon-single-page';
	}

	/**
	 * Conditional controls.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function conditions(): array {
		return [
			'event'        => [ 'event_source' => 'manual' ],
			'hero_session' => [ 'hero_presentation' => 'session' ],
		];
	}

	/**
	 * The section-order repeater replaces the raw CSV control: rows drag to
	 * reorder (with keyboard support from Elementor's own repeater), and
	 * removing a row disables the section.
	 *
	 * @param string              $key       Attribute key.
	 * @param array<string,mixed> $spec      Attribute spec.
	 * @param array<string,mixed> $condition Elementor condition array.
	 */
	protected function add_composite_control( string $key, array $spec, array $condition ): void {
		if ( 'sections' !== $key ) {
			parent::add_composite_control( $key, $spec, $condition );

			return;
		}

		$labels = [
			'hero'        => __( 'Hero', 'emailexpert-events' ),
			'status'      => __( 'Status notice', 'emailexpert-events' ),
			'stats'       => __( 'Event stats', 'emailexpert-events' ),
			'intro'       => __( 'Introduction', 'emailexpert-events' ),
			'sessions'    => __( 'Featured sessions', 'emailexpert-events' ),
			'speakers'    => __( 'Speakers', 'emailexpert-events' ),
			'schedule'    => __( 'Schedule', 'emailexpert-events' ),
			'tickets'     => __( 'Tickets', 'emailexpert-events' ),
			'venue'       => __( 'Venue', 'emailexpert-events' ),
			'sponsors'    => __( 'Sponsors', 'emailexpert-events' ),
			'replays'     => __( 'Replays', 'emailexpert-events' ),
			'more_events' => __( 'More events', 'emailexpert-events' ),
			'final_cta'   => __( 'Final call to action', 'emailexpert-events' ),
		];

		$repeater = new \Elementor\Repeater();
		$repeater->add_control(
			'section',
			[
				'label'   => __( 'Section', 'emailexpert-events' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => $labels,
				'default' => 'hero',
			]
		);

		$default_rows = [];
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $spec['default'] ) ) ) as $section ) {
			$default_rows[] = [ 'section' => $section ];
		}

		$this->add_control(
			'sections',
			[
				'label'         => __( 'Sections, in order', 'emailexpert-events' ),
				'type'          => \Elementor\Controls_Manager::REPEATER,
				'fields'        => $repeater->get_controls(),
				'default'       => $default_rows,
				'title_field'   => '{{{ section }}}',
				'prevent_empty' => false,
				'description'   => __( 'Drag to reorder. Remove a row to disable its section; sections without data hide themselves anyway.', 'emailexpert-events' ),
			]
		);
	}

	/**
	 * Repeater rows become the canonical comma-separated section list.
	 *
	 * @param string $key   Attribute key.
	 * @param mixed  $value Raw setting value.
	 */
	protected function attribute_value( string $key, $value ): string {
		if ( 'sections' === $key && is_array( $value ) ) {
			$sections = [];

			foreach ( $value as $row ) {
				$section = is_array( $row ) ? (string) ( $row['section'] ?? '' ) : '';

				if ( '' !== $section ) {
					$sections[] = $section;
				}
			}

			return implode( ',', $sections );
		}

		return parent::attribute_value( $key, $value );
	}
}
