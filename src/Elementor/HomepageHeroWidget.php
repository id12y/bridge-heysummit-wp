<?php
/**
 * Elementor widget: Homepage Editorial Hero.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Elementor;

defined( 'ABSPATH' ) || exit;

/**
 * Thin subclass: names the component, lifts the responsive control into its
 * own section, and declares the conditional-visibility map so the panel
 * shows only controls that currently apply.
 */
class HomepageHeroWidget extends CompositeWidget {

	/**
	 * Component key.
	 */
	protected function component(): string {
		return 'homepage-hero';
	}

	/**
	 * Icon.
	 */
	public function get_icon(): string {
		return 'eicon-site-identity';
	}

	/**
	 * Sections that differ from the definition groups.
	 *
	 * @return array<string,string>
	 */
	protected function group_overrides(): array {
		return [
			'mobile_order' => __( 'Responsive behaviour', 'emailexpert-events' ),
		];
	}

	/**
	 * Conditional controls.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	protected function conditions(): array {
		return [
			'story_id'                 => [ 'story_source' => 'manual' ],
			'story_fallback'           => [ 'story_source' => 'manual' ],
			'story2_source'            => [ 'story_count' => '2' ],
			'story2_id'                => [
				'story_count'   => '2',
				'story2_source' => 'manual',
			],
			'story2_placement'         => [ 'story_count' => '2' ],
			'featured_event'           => [ 'featured_source' => 'manual_event' ],
			'featured_session'         => [ 'featured_source' => 'manual_session' ],
			'event_presentation'       => [ 'featured_source' => [ 'auto', 'manual_event' ] ],
			'presentation_session'     => [
				'featured_source'    => [ 'auto', 'manual_event' ],
				'event_presentation' => 'session',
			],
			'selection_strategy'       => [ 'featured_source' => 'auto' ],
			'pin_duration'             => [ 'featured_source' => [ 'manual_event', 'manual_session' ] ],
			'pin_until'                => [
				'featured_source' => [ 'manual_event', 'manual_session' ],
				'pin_duration'    => 'until_date',
			],
			'pin_expiry_action'        => [ 'featured_source' => [ 'manual_event', 'manual_session' ] ],
			'news_title'               => [ 'news_show' => '1' ],
			'news_count'               => [ 'news_show' => '1' ],
			'news_types'               => [ 'news_show' => '1' ],
			'news_categories'          => [ 'news_show' => '1' ],
			'news_exclude_categories'  => [ 'news_show' => '1' ],
			'news_order'               => [ 'news_show' => '1' ],
			'news_show_image'          => [ 'news_show' => '1' ],
			'news_layout'              => [ 'news_show' => '1' ],
			'news_image_position'      => [
				'news_show'       => '1',
				'news_show_image' => '1',
			],
			'news_image_size'          => [
				'news_show'           => '1',
				'news_show_image'     => '1',
				'news_image_position' => [ 'auto', 'above' ],
			],
			'news_show_category'       => [ 'news_show' => '1' ],
			'news_link_categories'     => [
				'news_show'          => '1',
				'news_show_category' => '1',
			],
			'news_link_images'         => [
				'news_show'       => '1',
				'news_show_image' => '1',
			],
			'news_show_date'           => [ 'news_show' => '1' ],
			'news_all_show'            => [ 'news_show' => '1' ],
			'news_all_text'            => [
				'news_show'     => '1',
				'news_all_show' => '1',
			],
			'news_all_url'             => [
				'news_show'     => '1',
				'news_all_show' => '1',
			],
			'more_events_mode'         => [ 'more_events' => '1' ],
			'more_events_limit'        => [ 'more_events' => '1' ],
			'more_events_placement'    => [ 'more_events' => '1' ],
			'more_events_title'        => [ 'more_events' => '1' ],
			'more_events_presentation' => [ 'more_events' => '1' ],
			'more_events_speakers'     => [
				'more_events'              => '1',
				'more_events_presentation' => [ 'speakers', 'auto' ],
			],
			'more_events_layout'       => [ 'more_events' => '1' ],
			'more_events_whisper'      => [ 'more_events' => '1' ],
		];
	}

	/**
	 * Column-ratio and news-column controls.
	 */
	protected function layout_extras(): void {
		$this->add_control(
			'eex_split_ratio',
			[
				'label'                => __( 'Desktop story / event split', 'emailexpert-events' ),
				'type'                 => \Elementor\Controls_Manager::SELECT,
				'default'              => '',
				'options'              => [
					''      => __( 'Preset default', 'emailexpert-events' ),
					'62-38' => '62 / 38',
					'60-40' => '60 / 40',
					'58-42' => '58 / 42',
					'50-50' => '50 / 50',
				],
				'selectors_dictionary' => [
					'62-38' => '--eex-hero-story-width: 62fr; --eex-hero-event-width: 38fr;',
					'60-40' => '--eex-hero-story-width: 60fr; --eex-hero-event-width: 40fr;',
					'58-42' => '--eex-hero-story-width: 58fr; --eex-hero-event-width: 42fr;',
					'50-50' => '--eex-hero-story-width: 50fr; --eex-hero-event-width: 50fr;',
				],
				'selectors'            => [
					'{{WRAPPER}} .eex .eex-hh' => '{{VALUE}}',
				],
			]
		);

		$this->add_responsive_control(
			'eex_news_columns',
			[
				'label'     => __( 'Latest News columns (desktop)', 'emailexpert-events' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 6,
				'selectors' => [
					'{{WRAPPER}} .eex' => '--eex-news-columns: {{VALUE}};',
				],
			]
		);
	}
}
