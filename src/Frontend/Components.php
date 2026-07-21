<?php
/**
 * Display components.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Repository;
use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * One definition table and one render callback per component, shared by the
 * Gutenberg blocks, the shortcodes and the Elementor widgets. All data comes
 * through the Repository interface: the synced local database in Full mode,
 * the live API cache in Lite mode — the callbacks themselves are one code
 * path and never ask which.
 */
final class Components {

	/**
	 * Components that need local content and are therefore absent in Lite
	 * (hidden, not greyed out): past events have no live archive worth
	 * chasing, and the counter needs webhook attribution. Past SESSIONS and
	 * the filter bar work in Lite — the talk harvest already fetches past
	 * sessions, and the bar filters via JS with query-arg fallbacks.
	 */
	public const FULL_ONLY = [ 'past-events', 'reg-counter' ];

	/**
	 * Components whose Lite render emits inline Event JSON-LD for the items
	 * they display (structured-data value despite no local pages).
	 */
	private const LITE_SCHEMA = [ 'upcoming-sessions', 'schedule', 'featured-talks', 'upcoming-events' ];

	/**
	 * Static per-request cache of event posts by HeySummit ID.
	 *
	 * @var array<string,int>
	 */
	private static array $event_lookup = [];

	/**
	 * Drop request-scoped memos (tests and long-running processes).
	 */
	public static function reset_request_state(): void {
		self::$event_lookup = [];
	}

	/**
	 * Items rendered by the current component, collected for inline schema.
	 *
	 * @var array<int,array{type:string,data:array<string,mixed>}>
	 */
	private static array $schema_pool = [];

	/**
	 * The component definition table.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$empty_sessions = __( 'New sessions are announced soon.', 'emailexpert-events' );
		$empty_events   = __( 'New events are announced soon.', 'emailexpert-events' );

		// Shared spec fragments. 'options' whitelists an enum (and drives a
		// select control on every surface); 'flag' marks a boolean integer
		// (switch/toggle control); 'label' is the shared control label.
		$talk_layout = [
			'type'    => 'string',
			'default' => 'cards',
			'label'   => __( 'Layout', 'emailexpert-events' ),
			'options' => [
				'cards'   => __( 'Cards', 'emailexpert-events' ),
				'list'    => __( 'List', 'emailexpert-events' ),
				'agenda'  => __( 'Agenda (grouped by day)', 'emailexpert-events' ),
				'compact' => __( 'Compact', 'emailexpert-events' ),
			],
		];

		$grid_layout = [
			'type'    => 'string',
			'default' => 'grid',
			'label'   => __( 'Layout', 'emailexpert-events' ),
			'options' => [
				'grid' => __( 'Grid', 'emailexpert-events' ),
				'list' => __( 'List', 'emailexpert-events' ),
			],
		];

		$talk_columns = [
			'type'    => 'integer',
			'default' => 0,
			'label'   => __( 'Columns (0 = automatic)', 'emailexpert-events' ),
		];

		$flag = static function ( string $label, int $on = 1 ): array {
			return [
				'type'    => 'integer',
				'default' => $on,
				'flag'    => true,
				'label'   => $label,
			];
		};

		$show_speakers   = $flag( __( 'Show speakers', 'emailexpert-events' ) );
		$speaker_info    = [
			'type'    => 'string',
			'default' => 'names',
			'label'   => __( 'Speaker detail', 'emailexpert-events' ),
			'options' => [
				'names'    => __( 'Names only', 'emailexpert-events' ),
				'headline' => __( 'Names and job titles', 'emailexpert-events' ),
				'full'     => __( 'Photos, names and job titles', 'emailexpert-events' ),
			],
		];
		$show_categories = $flag( __( 'Show category badges', 'emailexpert-events' ) );
		$show_ics        = $flag( __( 'Show "Add to calendar (.ics)" link', 'emailexpert-events' ) );
		$show_google     = $flag( __( 'Show Google Calendar link', 'emailexpert-events' ) );
		$register_text   = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'Register button text (empty = "Register")', 'emailexpert-events' ),
		];
		// Two possible buttons on a session: tickets (that EVENT's ticketing
		// — its HeySummit checkout, or the external ticketing URL when the
		// event sells elsewhere) and the session's own landing page.
		$buttons         = [
			'type'    => 'string',
			'default' => 'both',
			'label'   => __( 'Buttons', 'emailexpert-events' ),
			'options' => [
				'both'    => __( 'Tickets + session page', 'emailexpert-events' ),
				'tickets' => __( 'Tickets button only', 'emailexpert-events' ),
				'session' => __( 'Session page button only', 'emailexpert-events' ),
			],
		];
		$tickets_text    = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'Tickets button text (empty = "Get tickets")', 'emailexpert-events' ),
		];
		$session_text    = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'Session button text (empty = "View session")', 'emailexpert-events' ),
		];
		$register_url    = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'External ticketing URL (empty = HeySummit checkout)', 'emailexpert-events' ),
		];
		$register_action = [
			'type'    => 'string',
			'default' => 'link',
			'label'   => __( 'Tickets button behaviour', 'emailexpert-events' ),
			'options' => [
				'link'  => __( 'Follow the link', 'emailexpert-events' ),
				'panel' => __( 'Open the ticket panel (slide-over)', 'emailexpert-events' ),
			],
		];
		$buy_on          = [
			'type'    => 'string',
			'default' => 'heysummit',
			'label'   => __( 'Paid tickets buy on', 'emailexpert-events' ),
			'options' => [
				'heysummit' => __( 'The event site (HeySummit checkout)', 'emailexpert-events' ),
				'woo'       => __( 'This site (mapped WooCommerce products)', 'emailexpert-events' ),
			],
		];
		$coupon          = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'Coupon code baked into ticket checkout links (HeySummit checkout only)', 'emailexpert-events' ),
		];
		$currency        = [
			'type'    => 'string',
			'default' => '',
			'label'   => __( 'Currency symbol shown before prices (empty = bare numbers, as the API sends them)', 'emailexpert-events' ),
		];
		$limit_label     = __( 'Number to show (0 = all)', 'emailexpert-events' );

		$definitions = [
			'upcoming-sessions' => [
				'title' => __( 'Upcoming sessions', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'category'        => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'          => $talk_layout,
					'columns'         => $talk_columns,
					'limit'           => [
						'type'    => 'integer',
						'default' => 6,
						'label'   => $limit_label,
					],
					'empty_text'      => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
					'show_speakers'   => $show_speakers,
					'speaker_info'    => $speaker_info,
					'show_categories' => $show_categories,
					'show_image'      => $flag( __( 'Show session images', 'emailexpert-events' ), 0 ),
					'show_venue'      => $flag( __( 'Show venue/stage', 'emailexpert-events' ) ),
					'show_address'    => $flag( __( 'Show the event venue address on in-person sessions', 'emailexpert-events' ), 0 ),
					'show_ics'        => $show_ics,
					'show_google'     => $show_google,
					'buttons'         => $buttons,
					'register_text'   => $tickets_text,
					'session_text'    => $session_text,
					'register_url'    => $register_url,
					'register_action' => $register_action,
					'buy_on'          => $buy_on,
					'coupon'          => $coupon,
					'currency'        => $currency,
					'tickets'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: only these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'exclude'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: hide these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'show_subscribe'  => $flag( __( 'Show subscribe link', 'emailexpert-events' ), 0 ),
				],
			],
			'past-sessions'     => [
				'title' => __( 'Past sessions', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'category'        => [
						'type'     => 'string',
						'default'  => '',
						'from_get' => 'eex_cat', // The filter bar's no-JS category links.
					],
					'layout'          => $talk_layout,
					'columns'         => $talk_columns,
					'show_speakers'   => $show_speakers,
					'speaker_info'    => $speaker_info,
					'show_categories' => $show_categories,
					'show_image'      => $flag( __( 'Show session images', 'emailexpert-events' ), 0 ),
					'show_venue'      => $flag( __( 'Show venue/stage', 'emailexpert-events' ) ),
					'show_address'    => $flag( __( 'Show the event venue address on in-person sessions', 'emailexpert-events' ), 0 ),
					'show_ics'        => $show_ics,
					'show_google'     => $show_google,
					'register_text'   => $register_text,
					'limit'           => [
						'type'    => 'integer',
						'default' => 12,
						'label'   => $limit_label,
					],
					'paginate'        => $flag( __( 'Paginate', 'emailexpert-events' ) ),
					'page'            => [
						'type'     => 'string',
						'default'  => '',
						'from_get' => 'eex_page',
					],
					'q'               => [
						'type'     => 'string',
						'default'  => '',
						'from_get' => 'eex_q',
					],
					'empty_text'      => [
						'type'    => 'string',
						'default' => __( 'Session replays appear here after each session.', 'emailexpert-events' ),
					],
				],
			],
			'upcoming-events'   => [
				'title' => __( 'Upcoming events', 'emailexpert-events' ),
				'atts'  => [
					'layout'        => $grid_layout,
					'register_text' => $register_text,
					'limit'         => [
						'type'    => 'integer',
						'default' => 3,
						'label'   => $limit_label,
					],
					'series'        => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text'    => [
						'type'    => 'string',
						'default' => $empty_events,
					],
				],
			],
			'past-events'       => [
				'title' => __( 'Past events', 'emailexpert-events' ),
				'atts'  => [
					'layout'        => $grid_layout,
					'register_text' => $register_text,
					'limit'         => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => $limit_label,
					],
					'series'        => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text'    => [
						'type'    => 'string',
						'default' => __( 'Past events appear here.', 'emailexpert-events' ),
					],
				],
			],
			'countdown'         => [
				'title' => __( 'Countdown', 'emailexpert-events' ),
				'atts'  => [
					'event' => [
						'type'    => 'string',
						'default' => '',
					],
					'talk'  => [
						'type'    => 'string',
						'default' => '',
					],
				],
			],
			'schedule'          => [
				'title' => __( 'Schedule', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'category'        => [
						'type'    => 'string',
						'default' => '',
					],
					'show_speakers'   => $show_speakers,
					'speaker_info'    => $speaker_info,
					'show_categories' => $show_categories,
					'day_nav'         => $flag( __( 'Show jump-to-day links above the schedule', 'emailexpert-events' ), 0 ),
					'show_tz_toggle'  => $flag( __( 'Show a timezone toggle (your time / event time)', 'emailexpert-events' ), 0 ),
					'empty_text'      => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
			'speakers'          => [
				'title' => __( 'Speaker grid', 'emailexpert-events' ),
				'atts'  => [
					'event'        => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Event', 'emailexpert-events' ),
					],
					'category'     => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'       => $grid_layout,
					'order'        => [
						'type'    => 'string',
						'default' => 'name',
						'label'   => __( 'Order', 'emailexpert-events' ),
						'options' => [
							'name'      => __( 'Alphabetical', 'emailexpert-events' ),
							'name-desc' => __( 'Reverse alphabetical', 'emailexpert-events' ),
							'random'    => __( 'Random (reshuffles when the cache refreshes)', 'emailexpert-events' ),
						],
					],
					'speaker_link' => [
						'type'    => 'string',
						'default' => 'default',
						'label'   => __( 'Speaker links', 'emailexpert-events' ),
						'options' => [
							'default' => __( 'Speaker page on this site', 'emailexpert-events' ),
							'hub'     => __( 'Speaker page on the HeySummit hub', 'emailexpert-events' ),
							'none'    => __( 'No link', 'emailexpert-events' ),
						],
					],
					'photo_shape'  => [
						'type'    => 'string',
						'default' => 'rounded',
						'label'   => __( 'Photo shape', 'emailexpert-events' ),
						'options' => [
							'rounded' => __( 'Rounded corners', 'emailexpert-events' ),
							'circle'  => __( 'Circle', 'emailexpert-events' ),
							'square'  => __( 'Square', 'emailexpert-events' ),
						],
					],
					'columns'      => [
						'type'    => 'integer',
						'default' => 4,
						'label'   => __( 'Columns (0 = widget controlled)', 'emailexpert-events' ),
					],
					'limit'        => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => $limit_label,
					],
					'paginate'     => $flag( __( 'Paginate', 'emailexpert-events' ), 0 ),
					'show_links'   => $flag( __( 'Show speaker social/web links', 'emailexpert-events' ), 0 ),
					'page'         => [
						'type'     => 'string',
						'default'  => '',
						'from_get' => 'eex_speaker_page',
					],
					'all_url'      => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( '"View all" link URL (empty = hidden)', 'emailexpert-events' ),
					],
					'all_text'     => [
						'type'    => 'string',
						'default' => __( 'View all speakers', 'emailexpert-events' ),
						'label'   => __( '"View all" link text', 'emailexpert-events' ),
					],
					'empty_text'   => [
						'type'    => 'string',
						'default' => __( 'Speakers are announced soon.', 'emailexpert-events' ),
					],
				],
			],
			'featured-talks'    => [
				'title' => __( 'Featured talks', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'ids'             => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'          => $talk_layout,
					'columns'         => $talk_columns,
					'show_speakers'   => $show_speakers,
					'speaker_info'    => $speaker_info,
					'show_categories' => $show_categories,
					'show_image'      => $flag( __( 'Show session images', 'emailexpert-events' ), 0 ),
					'show_venue'      => $flag( __( 'Show venue/stage', 'emailexpert-events' ) ),
					'show_address'    => $flag( __( 'Show the event venue address on in-person sessions', 'emailexpert-events' ), 0 ),
					'show_ics'        => $show_ics,
					'show_google'     => $show_google,
					'buttons'         => $buttons,
					'register_text'   => $tickets_text,
					'session_text'    => $session_text,
					'register_url'    => $register_url,
					'register_action' => $register_action,
					'buy_on'          => $buy_on,
					'coupon'          => $coupon,
					'currency'        => $currency,
					'tickets'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: only these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'exclude'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: hide these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'empty_text'      => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
			'sponsors'          => [
				'title' => __( 'Sponsors wall', 'emailexpert-events' ),
				'atts'  => [
					'event'            => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'           => [
						'type'    => 'string',
						'default' => 'grid',
						'label'   => __( 'Layout', 'emailexpert-events' ),
						'options' => [
							'grid'    => __( 'Grid of cards', 'emailexpert-events' ),
							'list'    => __( 'List rows', 'emailexpert-events' ),
							'compact' => __( 'Compact logo grid (no card chrome)', 'emailexpert-events' ),
							'strip'   => __( 'Logo strip (scrolling marquee)', 'emailexpert-events' ),
						],
					],
					'exclude'          => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Hide these sponsors', 'emailexpert-events' ),
					],
					'sponsor_link'     => [
						'type'    => 'string',
						'default' => 'website',
						'label'   => __( 'Sponsors link to', 'emailexpert-events' ),
						'options' => [
							'website' => __( 'Their own website', 'emailexpert-events' ),
							'hub'     => __( 'Their page on the event hub', 'emailexpert-events' ),
							'none'    => __( 'No link', 'emailexpert-events' ),
						],
					],
					'main_only'        => $flag( __( 'Main sponsors only', 'emailexpert-events' ), 0 ),
					'shown_on'         => [
						'type'    => 'string',
						'default' => 'any',
						'label'   => __( 'Only sponsors shown on…', 'emailexpert-events' ),
						'options' => [
							'any'        => __( 'Anywhere (no filter)', 'emailexpert-events' ),
							'landing'    => __( 'The landing page', 'emailexpert-events' ),
							'talks'      => __( 'Talk pages', 'emailexpert-events' ),
							'categories' => __( 'Category pages', 'emailexpert-events' ),
							'blog'       => __( 'Blog posts', 'emailexpert-events' ),
						],
					],
					'sponsor_category' => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Only this sponsor category (e.g. Gold)', 'emailexpert-events' ),
					],
					'group_by'         => [
						'type'    => 'string',
						'default' => 'category',
						'label'   => __( 'Grouping', 'emailexpert-events' ),
						'options' => [
							'category' => __( 'Group under category headings', 'emailexpert-events' ),
							'none'     => __( 'One flat wall, no headings', 'emailexpert-events' ),
						],
					],
					'order'            => [
						'type'    => 'string',
						'default' => 'weight',
						'label'   => __( 'Order (within each group)', 'emailexpert-events' ),
						'options' => [
							'weight'    => __( 'As weighted in HeySummit', 'emailexpert-events' ),
							'name'      => __( 'Alphabetical', 'emailexpert-events' ),
							'name-desc' => __( 'Reverse alphabetical', 'emailexpert-events' ),
							'random'    => __( 'Random (reshuffles each cache refresh)', 'emailexpert-events' ),
						],
					],
					'columns'          => $talk_columns,
					'limit'            => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => $limit_label,
					],
					'show_names'       => $flag( __( 'Show sponsor names', 'emailexpert-events' ) ),
					'show_blurb'       => $flag( __( 'Show short descriptions', 'emailexpert-events' ), 0 ),
					'blurb_length'     => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => __( 'Short description length (characters, 0 = full)', 'emailexpert-events' ),
					],
					'heading'          => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Heading above the wall (empty = none)', 'emailexpert-events' ),
					],
					'heading_level'    => [
						'type'    => 'string',
						'default' => '3',
						'label'   => __( 'Category heading level (the wall heading sits one above)', 'emailexpert-events' ),
						'options' => [
							'2' => __( 'H2', 'emailexpert-events' ),
							'3' => __( 'H3 (default)', 'emailexpert-events' ),
							'4' => __( 'H4', 'emailexpert-events' ),
						],
					],
					'new_tab'          => $flag( __( 'Open sponsor links in a new tab', 'emailexpert-events' ), 0 ),
					'utm_links'        => $flag( __( 'Add the site\'s UTM parameters to sponsor links', 'emailexpert-events' ), 0 ),
					'logo_size'        => [
						'type'    => 'string',
						'default' => 'medium',
						'label'   => __( 'Logo size', 'emailexpert-events' ),
						'options' => [
							'small'  => __( 'Small', 'emailexpert-events' ),
							'medium' => __( 'Medium', 'emailexpert-events' ),
							'large'  => __( 'Large', 'emailexpert-events' ),
						],
					],
					'empty_text'       => [
						'type'    => 'string',
						'default' => __( 'Sponsorship opportunities are available.', 'emailexpert-events' ),
					],
				],
			],
			'sponsor-spotlight' => [
				'title' => __( 'Sponsor spotlight', 'emailexpert-events' ),
				'atts'  => [
					'sponsor'            => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Sponsor (empty = random)', 'emailexpert-events' ),
					],
					'event'              => [
						'type'    => 'string',
						'default' => '',
					],
					'sponsor_category'   => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Only from this sponsor category (random picks within it)', 'emailexpert-events' ),
					],
					'sponsor_link'       => [
						'type'    => 'string',
						'default' => 'website',
						'label'   => __( 'Button links to', 'emailexpert-events' ),
						'options' => [
							'website' => __( 'Their own website', 'emailexpert-events' ),
							'hub'     => __( 'Their page on the event hub', 'emailexpert-events' ),
							'none'    => __( 'No link', 'emailexpert-events' ),
						],
					],
					'shown_on'           => [
						'type'    => 'string',
						'default' => 'any',
						'label'   => __( 'Only sponsors shown on…', 'emailexpert-events' ),
						'options' => [
							'any'        => __( 'Anywhere (no filter)', 'emailexpert-events' ),
							'landing'    => __( 'The landing page', 'emailexpert-events' ),
							'talks'      => __( 'Talk pages', 'emailexpert-events' ),
							'categories' => __( 'Category pages', 'emailexpert-events' ),
							'blog'       => __( 'Blog posts', 'emailexpert-events' ),
						],
					],
					'layout'             => [
						'type'    => 'string',
						'default' => 'card',
						'label'   => __( 'Spotlight style', 'emailexpert-events' ),
						'options' => [
							'card'   => __( 'Card — logo, blurb, actions', 'emailexpert-events' ),
							'banner' => __( 'Banner — promo image with logo overlaid', 'emailexpert-events' ),
							'full'   => __( 'Full — banner, video and description', 'emailexpert-events' ),
						],
					],
					'require_video'      => $flag( __( 'Only sponsors with an intro video', 'emailexpert-events' ), 0 ),
					'show_logo'          => $flag( __( 'Show logo', 'emailexpert-events' ) ),
					'show_name'          => $flag( __( 'Show sponsor name', 'emailexpert-events' ) ),
					'show_blurb'         => $flag( __( 'Show short description', 'emailexpert-events' ) ),
					'show_banner'        => $flag( __( 'Show promo banner image', 'emailexpert-events' ) ),
					'show_video'         => $flag( __( 'Show intro video', 'emailexpert-events' ) ),
					'show_description'   => $flag( __( 'Show full description', 'emailexpert-events' ) ),
					'show_website'       => $flag( __( 'Show website button', 'emailexpert-events' ) ),
					'show_books'         => $flag( __( 'Show booking/meeting link', 'emailexpert-events' ), 0 ),
					'show_phone'         => $flag( __( 'Show phone number', 'emailexpert-events' ), 0 ),
					'blurb_length'       => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => __( 'Short description length (characters, 0 = full)', 'emailexpert-events' ),
					],
					'description_length' => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => __( 'Full description length (characters, 0 = full text with formatting)', 'emailexpert-events' ),
					],
					'website_text'       => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Website button text (empty = the sponsor\'s own call to action)', 'emailexpert-events' ),
					],
					'books_text'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Booking button text (empty = "Book a meeting")', 'emailexpert-events' ),
					],
					'new_tab'            => $flag( __( 'Open sponsor links in a new tab', 'emailexpert-events' ), 0 ),
					'utm_links'          => $flag( __( 'Add the site\'s UTM parameters to sponsor links', 'emailexpert-events' ), 0 ),
					'empty_text'         => [
						'type'    => 'string',
						'default' => __( 'Sponsorship opportunities are available.', 'emailexpert-events' ),
					],
				],
			],
			'next-session'      => [
				'title' => __( 'Next session (hero)', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'category'        => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'          => [
						'type'    => 'string',
						'default' => 'panel',
						'label'   => __( 'Hero style', 'emailexpert-events' ),
						'options' => [
							'panel'     => __( 'Panel — action column on the right', 'emailexpert-events' ),
							'banner'    => __( 'Banner — slim full-width strip', 'emailexpert-events' ),
							'spotlight' => __( 'Spotlight — centred poster', 'emailexpert-events' ),
							'minimal'   => __( 'Minimal — plain, no card', 'emailexpert-events' ),
						],
					],
					'show_countdown'  => $flag( __( 'Show countdown', 'emailexpert-events' ) ),
					'show_speakers'   => $show_speakers,
					'show_ics'        => $show_ics,
					'show_google'     => $show_google,
					'buttons'         => $buttons,
					'register_text'   => $tickets_text,
					'session_text'    => $session_text,
					'register_url'    => $register_url,
					'register_action' => $register_action,
					'buy_on'          => $buy_on,
					'coupon'          => $coupon,
					'currency'        => $currency,
					'tickets'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: only these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'exclude'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: hide these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'empty_text'      => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
			'pricing'           => [
				'title' => __( 'Ticket pricing table', 'emailexpert-events' ),
				'atts'  => [
					'event'             => [
						'type'    => 'string',
						'default' => '',
					],
					'layout'            => [
						'type'    => 'string',
						'default' => 'columns',
						'label'   => __( 'Layout', 'emailexpert-events' ),
						'options' => [
							'columns' => __( 'Columns', 'emailexpert-events' ),
							'rows'    => __( 'Rows', 'emailexpert-events' ),
						],
					],
					'columns'           => $talk_columns,
					'tickets'           => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Only these ticket IDs (comma separated)', 'emailexpert-events' ),
					],
					'exclude'           => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Hide these ticket IDs (comma separated)', 'emailexpert-events' ),
					],
					'featured'          => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Hero ticket ID (ribbon + emphasis)', 'emailexpert-events' ),
					],
					'ribbon_text'       => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Ribbon text (empty = "Most popular")', 'emailexpert-events' ),
					],
					'show_free'         => $flag( __( 'Show free tickets', 'emailexpert-events' ) ),
					'show_paid'         => $flag( __( 'Show paid tickets', 'emailexpert-events' ) ),
					'hide_soldout'      => $flag( __( 'Hide sold-out tickets', 'emailexpert-events' ), 0 ),
					'show_description'  => $flag( __( 'Show ticket descriptions', 'emailexpert-events' ) ),
					'show_covers'       => $flag( __( 'Show what each ticket covers', 'emailexpert-events' ) ),
					'show_remaining'    => $flag( __( 'Show remaining quantity', 'emailexpert-events' ) ),
					'highlight_popular' => $flag( __( 'Highlight the popular ticket', 'emailexpert-events' ) ),
					'register_text'     => $register_text,
					'register_url'      => $register_url,
					'buy_on'            => $buy_on,
					'coupon'            => $coupon,
					'currency'          => $currency,
					'empty_text'        => [
						'type'    => 'string',
						'default' => __( 'Tickets go on sale soon.', 'emailexpert-events' ),
					],
				],
			],
			'speaker-spotlight' => [
				'title' => __( 'Speaker spotlight', 'emailexpert-events' ),
				'atts'  => [
					'speaker'      => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Speaker ID (empty = random)', 'emailexpert-events' ),
					],
					'event'        => [
						'type'    => 'string',
						'default' => '',
					],
					'speaker_link' => [
						'type'    => 'string',
						'default' => 'default',
						'label'   => __( 'Speaker links', 'emailexpert-events' ),
						'options' => [
							'default' => __( 'Speaker page on this site', 'emailexpert-events' ),
							'hub'     => __( 'Speaker page on the HeySummit hub', 'emailexpert-events' ),
							'none'    => __( 'No link', 'emailexpert-events' ),
						],
					],
					'photo_shape'  => [
						'type'    => 'string',
						'default' => 'rounded',
						'label'   => __( 'Photo shape', 'emailexpert-events' ),
						'options' => [
							'rounded' => __( 'Rounded corners', 'emailexpert-events' ),
							'circle'  => __( 'Circle', 'emailexpert-events' ),
							'square'  => __( 'Square', 'emailexpert-events' ),
						],
					],
					'show_bio'     => $flag( __( 'Show biography', 'emailexpert-events' ) ),
					'show_links'   => $flag( __( 'Show speaker social/web links', 'emailexpert-events' ), 0 ),
					'empty_text'   => [
						'type'    => 'string',
						'default' => __( 'Speakers are announced soon.', 'emailexpert-events' ),
					],
				],
			],
			'events-portfolio'  => [
				'title' => __( 'Events portfolio', 'emailexpert-events' ),
				'atts'  => [
					'status'        => [
						'type'    => 'string',
						'default' => 'live',
						'label'   => __( 'Which events', 'emailexpert-events' ),
						'options' => [
							'live'      => __( 'Live (public)', 'emailexpert-events' ),
							'evergreen' => __( 'Evergreen only', 'emailexpert-events' ),
							'archived'  => __( 'Archived only', 'emailexpert-events' ),
							'all'       => __( 'Everything', 'emailexpert-events' ),
						],
					],
					'layout'        => $grid_layout,
					'register_text' => $register_text,
					'limit'         => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => $limit_label,
					],
					'empty_text'    => [
						'type'    => 'string',
						'default' => $empty_events,
					],
				],
			],
			'live-now'          => [
				'title' => __( 'Live now bar', 'emailexpert-events' ),
				'atts'  => [
					'event' => [
						'type'    => 'string',
						'default' => '',
					],
					'limit' => [
						'type'    => 'integer',
						'default' => 3,
						'label'   => __( 'Sessions to watch', 'emailexpert-events' ),
					],
				],
			],
			'session-filter'    => [
				'title' => __( 'Session filter bar', 'emailexpert-events' ),
				'atts'  => [
					'event'       => [
						'type'    => 'string',
						'default' => '',
					],
					'category'    => [
						'type'    => 'string',
						'default' => '',
					],
					'show_search' => $flag( __( 'Show search box', 'emailexpert-events' ) ),
				],
			],
			'reg-counter'       => [
				'title' => __( 'Registration counter', 'emailexpert-events' ),
				'atts'  => [
					'event'     => [
						'type'    => 'string',
						'default' => '',
					],
					'threshold' => [
						'type'    => 'integer',
						'default' => 50,
					],
				],
			],
			'register-bar'      => [
				'title' => __( 'Sticky register bar', 'emailexpert-events' ),
				'atts'  => [
					'event'           => [
						'type'    => 'string',
						'default' => '',
					],
					'text'            => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Bar text (empty = event title)', 'emailexpert-events' ),
					],
					'register_text'   => $tickets_text,
					'position'        => [
						'type'    => 'string',
						'default' => 'bottom',
						'label'   => __( 'Pin to', 'emailexpert-events' ),
						'options' => [
							'bottom' => __( 'Bottom of the screen', 'emailexpert-events' ),
							'top'    => __( 'Top of the screen', 'emailexpert-events' ),
						],
					],
					'offset'          => [
						'type'    => 'integer',
						'default' => 400,
						'label'   => __( 'Show after scrolling this many pixels', 'emailexpert-events' ),
					],
					'show_countdown'  => $flag( __( 'Show the countdown', 'emailexpert-events' ) ),
					'show_live'       => $flag( __( 'Switch to "Join now" while a session is live', 'emailexpert-events' ) ),
					'dismissible'     => $flag( __( 'Visitors can dismiss the bar', 'emailexpert-events' ) ),
					'register_url'    => $register_url,
					'register_action' => $register_action,
					'buy_on'          => $buy_on,
					'coupon'          => $coupon,
					'currency'        => $currency,
					'tickets'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: only these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
					'exclude'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Panel: hide these tickets (comma separated IDs)', 'emailexpert-events' ),
					],
				],
			],
			'register-inline'   => [
				'title' => __( 'Registration form', 'emailexpert-events' ),
				'atts'  => [
					'event'         => [
						'type'    => 'string',
						'default' => '',
					],
					'ticket'        => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Free ticket ID (empty = the first free ticket)', 'emailexpert-events' ),
					],
					'heading'       => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Heading (empty = no heading)', 'emailexpert-events' ),
					],
					'register_text' => $register_text,
					'coupon'        => $coupon,
					'currency'      => $currency,
					'empty_text'    => [
						'type'    => 'string',
						'default' => __( 'Registration opens soon.', 'emailexpert-events' ),
					],
				],
			],
			'stats'             => [
				'title' => __( 'Event stats strip', 'emailexpert-events' ),
				'atts'  => [
					'event'   => [
						'type'    => 'string',
						'default' => '',
					],
					'items'   => [
						'type'    => 'string',
						'default' => 'speakers,sessions,days',
						'label'   => __( 'Stats, in order: speakers, sessions, days, categories, registered, members — rename any with a colon (speakers:Experts), or add your own number (1200:Subscribers)', 'emailexpert-events' ),
					],
					'animate' => $flag( __( 'Count up when scrolled into view', 'emailexpert-events' ), 0 ),
				],
			],
			'replay-gallery'    => [
				'title' => __( 'Replay gallery', 'emailexpert-events' ),
				'atts'  => [
					'event'         => [
						'type'    => 'string',
						'default' => '',
					],
					'category'      => [
						'type'    => 'string',
						'default' => '',
					],
					'limit'         => [
						'type'    => 'integer',
						'default' => 0,
						'label'   => $limit_label,
					],
					'columns'       => $talk_columns,
					'show_speakers' => $show_speakers,
					'show_image'    => $flag( __( 'Show session images', 'emailexpert-events' ) ),
					'show_soon'     => $flag( __( 'Include sessions whose replay is coming soon', 'emailexpert-events' ) ),
					'link'          => [
						'type'    => 'string',
						'default' => 'talk',
						'label'   => __( 'Cards link to', 'emailexpert-events' ),
						'options' => [
							'talk'   => __( 'The session page', 'emailexpert-events' ),
							'replay' => __( 'The replay directly', 'emailexpert-events' ),
						],
					],
					'empty_text'    => [
						'type'    => 'string',
						'default' => __( 'Replays will appear here soon.', 'emailexpert-events' ),
					],
				],
			],
			'venue'             => [
				'title' => __( 'Venue card', 'emailexpert-events' ),
				'atts'  => [
					'event'         => [
						'type'    => 'string',
						'default' => '',
					],
					'heading'       => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Heading (empty = "Venue")', 'emailexpert-events' ),
					],
					'show_name'     => $flag( __( 'Show the venue name', 'emailexpert-events' ) ),
					'show_address'  => $flag( __( 'Show the address', 'emailexpert-events' ) ),
					'show_map_link' => $flag( __( 'Show a "Directions" map link', 'emailexpert-events' ) ),
					'image'         => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Venue image URL (empty = no image)', 'emailexpert-events' ),
					],
					'name'          => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Venue name (empty = from the event)', 'emailexpert-events' ),
					],
					'street'        => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Street (empty = from the event)', 'emailexpert-events' ),
					],
					'locality'      => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'City/town (empty = from the event)', 'emailexpert-events' ),
					],
					'postcode'      => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Postcode (empty = from the event)', 'emailexpert-events' ),
					],
					'country'       => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Country (empty = from the event)', 'emailexpert-events' ),
					],
					'empty_text'    => [
						'type'    => 'string',
						'default' => __( 'Venue details coming soon.', 'emailexpert-events' ),
					],
				],
			],
			'featured-session'  => [
				'title' => __( 'Featured session card', 'emailexpert-events' ),
				'atts'  => [
					'talk'             => [
						'type'    => 'string',
						'default' => '',
						'label'   => __( 'Session ID (empty = the next upcoming session)', 'emailexpert-events' ),
					],
					'event'            => [
						'type'    => 'string',
						'default' => '',
					],
					'view'             => [
						'type'    => 'string',
						'default' => 'card',
						'label'   => __( 'View', 'emailexpert-events' ),
						'options' => [
							'card'    => __( 'Feature card (wide)', 'emailexpert-events' ),
							'compact' => __( 'Compact (sidebar)', 'emailexpert-events' ),
						],
					],
					'show_image'       => $flag( __( 'Show the session image', 'emailexpert-events' ) ),
					'show_description' => $flag( __( 'Show the description', 'emailexpert-events' ) ),
					'show_speakers'    => $show_speakers,
					'speaker_info'     => $speaker_info,
					'show_categories'  => $show_categories,
					'show_venue'       => $flag( __( 'Show the location (stage / venue)', 'emailexpert-events' ) ),
					'show_address'     => $flag( __( 'Show the event venue address and map link', 'emailexpert-events' ) ),
					'show_ics'         => $show_ics,
					'buttons'          => $buttons,
					'register_text'    => $tickets_text,
					'session_text'     => $session_text,
					'register_url'     => $register_url,
					'buy_on'           => $buy_on,
					'coupon'           => $coupon,
					'currency'         => $currency,
					'empty_text'       => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
		];

		// Shared attribute: every component with a visible empty state can
		// instead render nothing at all (sidebars, strips). Components that
		// already suppress their own empties (live-now, reg-counter) or have
		// no empty state (countdown, session-filter) are skipped — the
		// toggle would be a dead switch there.
		foreach ( $definitions as $component => $definition ) {
			if ( isset( $definition['atts']['empty_text'] ) ) {
				$definitions[ $component ]['atts']['hide_empty'] = $flag( __( 'Hide the widget entirely when empty', 'emailexpert-events' ), 0 );
			}
		}

		return $definitions;
	}

	/**
	 * Render a component, via the transient cache.
	 *
	 * @param string              $name Component name (definition key).
	 * @param array<string,mixed> $atts Attributes.
	 * @return string HTML.
	 */
	public static function render( string $name, array $atts = [] ): string {
		$definitions = self::definitions();

		if ( ! isset( $definitions[ $name ] ) ) {
			return '';
		}

		// Absent in Lite: nothing rendered, no clutter.
		if ( Options::is_lite() && in_array( $name, self::FULL_ONLY, true ) ) {
			return '';
		}

		$atts = self::sanitise_atts( $definitions[ $name ]['atts'], $atts );

		Assets::mark_needed();

		// The cache key varies by UTM campaign context: rendered HTML embeds
		// campaign-tagged URLs derived from the rendering page.
		$cache_atts = $atts + [ '_ctx' => Utm::cache_context() ];

		// Visitor-typed filter strings never mint cache entries: every
		// unique ?eex_q= (or ?eex_cat= where the component sources category
		// from GET) would otherwise write a transient row (unbounded,
		// unauthenticated). Filtered views render fresh instead.
		$cacheable = '' === (string) ( $atts['q'] ?? '' )
			&& ! ( isset( $_GET['eex_cat'] ) && 'eex_cat' === (string) ( $definitions[ $name ]['atts']['category']['from_get'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only cache decision.

		if ( $cacheable ) {
			$cached = Cache::get( $name, $cache_atts );
			if ( null !== $cached ) {
				// The debug note rides outside the cached fragment.
				return self::finalise( $name, $atts, $cached );
			}
		}

		self::$schema_pool = [];

		$method = 'render_' . str_replace( '-', '_', $name );
		$html   = method_exists( self::class, $method ) ? (string) self::$method( $atts ) : '';

		// Lite: the block itself carries Event JSON-LD for what it rendered.
		$html .= self::inline_schema( $name );

		$html = '<div class="eex eex-' . esc_attr( $name ) . '">' . $html . '</div>';

		/**
		 * Filter a component's rendered HTML.
		 *
		 * @param string              $html HTML.
		 * @param string              $name Component name.
		 * @param array<string,mixed> $atts Attributes.
		 */
		$html = (string) apply_filters( 'eex_card_html', $html, $name, $atts );

		if ( $cacheable ) {
			// Lite data and ticket fetches come from the remote API and can
			// fail; Cache::keep() then serves the last good fragment instead
			// of a fresh empty. Full-mode local queries cannot fail, so their
			// empties are authoritative.
			$fallible = Options::is_lite() || 'pricing' === $name;

			$html = Cache::keep( $name, $cache_atts, $html, $fallible );
		}

		return self::finalise( $name, $atts, $html );
	}

	/**
	 * The last step of every render: apply hide_empty and attach the admin
	 * debug note. hide_empty strips strictly AFTER caching — the real empty
	 * fragment stays cached under its 60-second guardrail TTL and the
	 * last-good copy is never clobbered with '' — and a served stale
	 * fragment contains no empty state, so it is never hidden.
	 *
	 * @param string              $name Component name.
	 * @param array<string,mixed> $atts Sanitised attributes.
	 * @param string              $html Rendered (or cached) fragment.
	 */
	private static function finalise( string $name, array $atts, string $html ): string {
		if ( ! empty( $atts['hide_empty'] ) && str_contains( $html, 'eex-empty' ) ) {
			// Admins get an explanation instead of an unexplained void.
			if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				return sprintf(
					"\n<!-- emailexpert Events (visible to administrators only): the %s widget is empty and hidden by its hide_empty setting. -->",
					esc_html( $name )
				) . self::admin_debug_note( $name, $html, $atts );
			}

			return '';
		}

		return $html . self::admin_debug_note( $name, $html, $atts );
	}

	/**
	 * An HTML comment appended for administrators only — never cached, so
	 * it can't leak to visitors — explaining why a Lite component is empty
	 * or serving degraded data. This is what turns "the block looks empty"
	 * into a debuggable report.
	 *
	 * @param string $name Component name.
	 * @param string $html The rendered (possibly cached) fragment.
	 */
	private static function admin_debug_note( string $name, string $html, array $atts = [] ): string {
		// A category filter that matched nothing explains itself in both
		// modes: field-reported as "category does not actually work".
		if ( str_contains( $html, 'eex-empty' )
			&& in_array( $name, [ 'sponsors', 'sponsor-spotlight' ], true )
			&& '' !== (string) ( $atts['sponsor_category'] ?? '' )
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'manage_options' ) ) {
			$known = \Emailexpert\Events\Data\Sponsors::known_categories();

			return sprintf(
				"\n<!-- emailexpert Events (visible to administrators only): the sponsor category filter %s matched no sponsors. Filters accept a category name, a tier name or a category ID. Category names this site has seen: %s. -->",
				esc_html( str_replace( '--', '- -', (string) $atts['sponsor_category'] ) ),
				esc_html( str_replace( '--', '- -', empty( $known ) ? '(none yet - view the sponsor wall once so they can be learned)' : implode( ', ', $known ) ) )
			);
		}

		// The venue card explains its emptiness in both modes: this was a
		// field-reported head-scratcher ("venue ticked, nothing displayed").
		if ( 'venue' === $name
			&& str_contains( $html, 'eex-empty' )
			&& function_exists( 'current_user_can' )
			&& current_user_can( 'manage_options' ) ) {
			return "\n<!-- emailexpert Events (visible to administrators only): no venue data found. The HeySummit API sent no readable venue for this event. Fill the venue fields on the event post (Full mode) or type the venue name/address into this widget's own settings. -->";
		}

		if ( ! Options::is_lite()
			|| ! function_exists( 'current_user_can' )
			|| ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$notes = [];

		if ( \Emailexpert\Events\Data\LiveCache::degraded() ) {
			$status  = \Emailexpert\Events\Data\LiveCache::status();
			$notes[] = sprintf(
				'the last HeySummit fetch failed at %s UTC: %s. Components are rendering last-good or empty-state data.',
				$status['last_failure'],
				$status['last_error'] ?: 'no reason recorded'
			);
		}

		// An empty wall now means the API returned no sponsors for the event
		// AND no manual rows exist (the sponsors endpoint arrived in v1.8.0;
		// manual rows remain as a supplement).
		if ( str_contains( $html, 'eex-empty' ) && 'sponsors' === $name ) {
			$notes[] = 'no sponsors came back from the HeySummit API for this event, and no manual rows are entered. Check the discovery diagnostics (Settings -> emailexpert Events -> Test connection) for the sponsors row, or add rows / a CSV import under Live display -> Sponsors.';
		}

		// An empty live component gets a pipeline diagnosis: which stage
		// produced nothing and where to fix it.
		if ( str_contains( $html, 'eex-empty' ) && in_array( $name, [ 'upcoming-sessions', 'upcoming-events', 'schedule', 'featured-talks', 'speakers' ], true ) ) {
			$repository = Repositories::current();

			if ( $repository instanceof \Emailexpert\Events\Data\LiveRepository ) {
				$diagnosis = $repository->diagnose();
				if ( '' !== $diagnosis ) {
					$notes[] = $diagnosis;
				}
			}
		}

		if ( empty( $notes ) ) {
			return '';
		}

		return sprintf(
			"\n<!-- emailexpert Events (visible to administrators only): %s See Dashboard -> emailexpert Events. -->",
			esc_html( str_replace( '--', '- -', implode( ' | ', $notes ) ) )
		);
	}

	/**
	 * The definitions available in the current mode. Blocks, shortcodes and
	 * Elementor widgets register only these, so Full-only components are
	 * hidden in Lite rather than offered and empty.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function available_definitions(): array {
		$definitions = self::definitions();

		if ( Options::is_lite() ) {
			$definitions = array_diff_key( $definitions, array_flip( self::FULL_ONLY ) );
		}

		return $definitions;
	}

	/**
	 * Inline Event JSON-LD for the items the current Lite render displayed;
	 * '' in Full mode (single pages carry schema there) or when disabled.
	 *
	 * @param string $name Component name.
	 */
	private static function inline_schema( string $name ): string {
		$pool              = self::$schema_pool;
		self::$schema_pool = [];

		if ( ! Options::is_lite()
			|| empty( $pool )
			|| ! in_array( $name, self::LITE_SCHEMA, true )
			|| ! (bool) Options::setting( 'schema_enabled' )
			|| ! (bool) Options::setting( 'schema_event' ) ) {
			return '';
		}

		$graph = [];
		foreach ( $pool as $item ) {
			$schema = 'event' === $item['type']
				? SchemaGenerator::inline_event_from_event( $item['data'] )
				: SchemaGenerator::inline_event_from_talk( $item['data'] );

			if ( ! empty( $schema ) ) {
				$graph[] = $schema;
			}
		}

		if ( empty( $graph ) ) {
			return '';
		}

		$payload = 1 === count( $graph ) ? $graph[0] : $graph;

		return '<script type="application/ld+json">' . wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . '</script>';
	}

	/**
	 * Coerce attributes against a schema.
	 *
	 * @param array<string,array<string,mixed>> $schema Attribute schema.
	 * @param array<string,mixed>               $atts   Raw attributes.
	 * @return array<string,mixed>
	 */
	public static function sanitise_atts( array $schema, array $atts ): array {
		$out = [];

		foreach ( $schema as $key => $spec ) {
			$value = $atts[ $key ] ?? $spec['default'];

			// Some attributes (search query) may arrive via the query string
			// so no-JS filtering works on cached-page links.
			if ( ! empty( $spec['from_get'] ) && '' === (string) $value && isset( $_GET[ $spec['from_get'] ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only filter.
				$value = sanitize_text_field( wp_unslash( $_GET[ $spec['from_get'] ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised on this line.
			}

			$out[ $key ] = 'integer' === $spec['type']
				? (int) $value
				: sanitize_text_field( (string) $value );

			// Enum attributes fall back to their default rather than letting
			// arbitrary values reach templates or cache keys.
			if ( ! empty( $spec['options'] ) && ! isset( $spec['options'][ $out[ $key ] ] ) ) {
				$out[ $key ] = $spec['default'];
			}
		}

		return $out;
	}

	/**
	 * The active data repository.
	 */
	private static function repo(): Repository {
		return Repositories::current();
	}

	/**
	 * Assemble the render data for one talk (synced-post path; the Lite
	 * repository assembles the same shape from the live API).
	 *
	 * @param int $post_id Talk post ID.
	 * @return array<string,mixed>
	 */
	public static function talk_data( int $post_id ): array {
		$event_hs_id   = (string) get_post_meta( $post_id, '_eex_source_event_id', true );
		$event_post_id = self::event_post_for_hs_id( $event_hs_id );

		$replay = (string) get_post_meta( $post_id, '_eex_replay_url', true );
		if ( '' === $replay ) {
			$replay = (string) get_post_meta( $post_id, '_eex_replay_url_synced', true );
		}

		$speaker_ids = array_filter( array_map( 'intval', (array) get_post_meta( $post_id, '_eex_speaker_ids', true ) ) );
		$speakers    = [];
		foreach ( $speaker_ids as $speaker_id ) {
			$speaker = get_post( $speaker_id );
			if ( $speaker && 'publish' === $speaker->post_status ) {
				$speakers[] = [
					'id'       => $speaker_id,
					'name'     => (string) $speaker->post_title,
					'url'      => (string) get_permalink( $speaker_id ),
					'headline' => (string) get_post_meta( $speaker_id, '_eex_headline', true ),
					'photo_id' => (int) get_post_thumbnail_id( $speaker_id ),
				];
			}
		}

		$categories = get_the_terms( $post_id, Taxonomies::CATEGORY );

		$raw_event_url = $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_event_url', true ) : '';

		return [
			'id'            => $post_id,
			'hs_id'         => (string) get_post_meta( $post_id, '_eex_heysummit_id', true ),
			'title'         => get_the_title( $post_id ),
			'permalink'     => (string) get_permalink( $post_id ),
			'description'   => (string) get_post_meta( $post_id, '_eex_description', true ),
			'starts_at'     => (string) get_post_meta( $post_id, '_eex_starts_at', true ),
			'ends_at'       => (string) get_post_meta( $post_id, '_eex_ends_at', true ),
			'talk_url'      => Utm::tag( (string) get_post_meta( $post_id, '_eex_talk_url', true ) ),
			'replay_url'    => $replay,
			'replay_soon'   => (bool) get_post_meta( $post_id, '_eex_replay_soon', true ),
			'venue'         => (string) get_post_meta( $post_id, '_eex_talk_venue', true ),
			'inperson'      => (bool) get_post_meta( $post_id, '_eex_inperson', true ),
			'image'         => (string) ( function_exists( 'get_the_post_thumbnail_url' ) ? ( get_the_post_thumbnail_url( $post_id, 'medium_large' ) ?: '' ) : '' ),
			'speakers'      => $speakers,
			'categories'    => is_array( $categories ) ? $categories : [],
			'event_hs_id'   => $event_hs_id,
			'event_post_id' => $event_post_id,
			'timezone'      => $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_timezone', true ) : '',
			'event_url'     => Utm::tag( $raw_event_url ),
			'raw_event_url' => $raw_event_url,
			'ics_ref'       => $post_id,
			'published'     => 'publish' === get_post_status( $post_id ),
		];
	}

	/**
	 * The event venue address for a talk, for display beside the session:
	 * the operator-owned venue meta in Full mode, the venue name Lite knows.
	 * The display lines drop the venue name when the talk's own venue line
	 * already names it; the maps URL always uses the full address.
	 *
	 * @param array<string,mixed> $data Talk data.
	 * @return array{lines:array<int,string>,map_url:string}
	 */
	public static function event_address( array $data ): array {
		$lines         = [];
		$event_post_id = (int) ( $data['event_post_id'] ?? 0 );

		if ( $event_post_id > 0 ) {
			foreach ( [ 'name', 'street', 'locality', 'postcode', 'country' ] as $field ) {
				$value = (string) get_post_meta( $event_post_id, '_eex_venue_' . $field, true );
				if ( '' !== $value ) {
					$lines[] = $value;
				}
			}
		} else {
			$event = self::repo()->event_summary( (string) ( $data['event_hs_id'] ?? '' ) );
			if ( null !== $event ) {
				if ( '' !== (string) ( $event['venue'] ?? '' ) ) {
					$lines[] = (string) $event['venue'];
				}
				$lines = array_merge( $lines, array_map( 'strval', (array) ( $event['venue_address'] ?? [] ) ) );
			}
		}

		if ( empty( $lines ) ) {
			return [
				'lines'   => [],
				'map_url' => '',
			];
		}

		$map_url = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( implode( ', ', $lines ) );

		$fold  = static fn( string $text ): string => (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $text ) );
		$venue = $fold( (string) ( $data['venue'] ?? '' ) );
		if ( '' !== $venue && str_contains( $venue, $fold( $lines[0] ) ) ) {
			array_shift( $lines );
		}

		return [
			'lines'   => $lines,
			'map_url' => $map_url,
		];
	}

	/**
	 * A talk's status badges (In person / Open access / custom tag), minus
	 * any that would duplicate one of its category badges — an account with
	 * an "In Person" category must not show the pill twice.
	 *
	 * @param array<string,mixed> $data Talk data.
	 * @return string[]
	 */
	public static function status_badges( array $data ): array {
		$badges = [];

		if ( ! empty( $data['inperson'] ) ) {
			$badges[] = __( 'In person', 'emailexpert-events' );
		}
		if ( ! empty( $data['open_access'] ) ) {
			$badges[] = __( 'Open access', 'emailexpert-events' );
		}
		if ( '' !== (string) ( $data['custom_tag'] ?? '' ) ) {
			$badges[] = (string) $data['custom_tag'];
		}

		// Compare with punctuation and case ignored: "In Person" and
		// "in-person" both silence the built-in pill.
		$fold  = static fn( string $text ): string => (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( $text ) );
		$taken = array_map(
			static fn( $term ): string => $fold( (string) ( $term->name ?? '' ) ),
			(array) ( $data['categories'] ?? [] )
		);

		return array_values(
			array_filter(
				$badges,
				static fn( string $badge ): bool => ! in_array( $fold( $badge ), $taken, true )
			)
		);
	}

	/**
	 * A human label for a speaker's social/web link, from its host.
	 *
	 * @param string $url Link URL.
	 */
	public static function link_label( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^www\./', '', $host );

		$known = [
			'linkedin.com'    => 'LinkedIn',
			'twitter.com'     => 'X (Twitter)',
			'x.com'           => 'X (Twitter)',
			'facebook.com'    => 'Facebook',
			'instagram.com'   => 'Instagram',
			'youtube.com'     => 'YouTube',
			'github.com'      => 'GitHub',
			'bsky.app'        => 'Bluesky',
			'mastodon.social' => 'Mastodon',
			'threads.net'     => 'Threads',
			'tiktok.com'      => 'TikTok',
		];

		foreach ( $known as $domain => $label ) {
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) {
				return $label;
			}
		}

		return '' !== $host ? $host : __( 'Website', 'emailexpert-events' );
	}

	/**
	 * Session-state data attributes for the client-side time module. The
	 * server claims no live state; JS derives it so hours-old cached HTML
	 * stays correct.
	 *
	 * @param array<string,mixed> $data Talk data.
	 */
	public static function session_attrs( array $data ): string {
		return sprintf(
			' data-eex-session="1" data-eex-start="%s" data-eex-end="%s" data-eex-join="%s"',
			esc_attr( (string) $data['starts_at'] ),
			esc_attr( (string) $data['ends_at'] ),
			esc_attr( (string) ( $data['talk_url'] ?: $data['event_url'] ) )
		);
	}

	/**
	 * Find the event post for a HeySummit event ID (per-request cached).
	 *
	 * @param string $event_hs_id HeySummit event ID.
	 */
	public static function event_post_for_hs_id( string $event_hs_id ): int {
		if ( '' === $event_hs_id ) {
			return 0;
		}

		if ( ! isset( self::$event_lookup[ $event_hs_id ] ) ) {
			$found = get_posts(
				[
					'post_type'      => PostTypes::EVENT,
					'post_status'    => 'any',
					'meta_key'       => '_eex_heysummit_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- indexed lookup, 1 result.
					'meta_value'     => $event_hs_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			self::$event_lookup[ $event_hs_id ] = empty( $found ) ? 0 : (int) $found[0];
		}

		return self::$event_lookup[ $event_hs_id ];
	}

	/**
	 * Render a list of talk cards, or the empty state.
	 *
	 * @param array<int,array<string,mixed>> $items   Talk data arrays.
	 * @param array<string,mixed>            $atts    Component attributes.
	 * @param string                         $context 'upcoming' or 'past'.
	 */
	private static function talk_cards( array $items, array $atts, string $context ): string {
		if ( empty( $items ) ) {
			return self::empty_state( (string) ( $atts['empty_text'] ?? '' ) );
		}

		foreach ( $items as $item ) {
			self::$schema_pool[] = [
				'type' => 'talk',
				'data' => $item,
			];
		}

		$layout = (string) ( $atts['layout'] ?? 'cards' );
		$show   = self::show_flags( $atts );
		$drawer = self::ticket_drawer( $atts );
		$cta    = [
			'buttons'       => (string) ( $atts['buttons'] ?? 'session' ),
			'register_text' => (string) ( $atts['register_text'] ?? '' ),
			'session_text'  => (string) ( $atts['session_text'] ?? '' ),
			'register'      => self::register_args( $atts ),
			'drawer'        => $drawer['id'],
		];

		if ( 'agenda' === $layout ) {
			return self::agenda_layout( $items, $context, $show, $cta ) . $drawer['html'];
		}

		// Wrapper classes and template part per layout; unknown values were
		// already snapped to the default by sanitise_atts().
		$layouts = [
			'cards'   => [ 'eex-grid eex-talk-grid', 'card-talk' ],
			'list'    => [ 'eex-list eex-talk-list', 'list-talk' ],
			'compact' => [ 'eex-list eex-talk-compact', 'compact-talk' ],
		];

		[ $classes, $part ] = $layouts[ $layout ] ?? $layouts['cards'];

		$columns = min( 6, max( 0, (int) ( $atts['columns'] ?? 0 ) ) );
		$style   = 'cards' === $layout && $columns > 0 ? sprintf( ' style="--eex-columns:%d"', $columns ) : '';

		ob_start();
		printf( '<ul class="%s" role="list"%s>', esc_attr( $classes ), $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an integer above.
		foreach ( $items as $data ) {
			// Filterable data attributes for the session filter bar.
			printf(
				'<li class="eex-grid-item" data-eex-title="%s" data-eex-cats="%s" data-eex-speakers="%s">',
				esc_attr( strtolower( (string) $data['title'] ) ),
				esc_attr( implode( ',', array_map( static fn( $term ): string => (string) $term->slug, (array) $data['categories'] ) ) ),
				esc_attr( strtolower( implode( ',', array_map( static fn( array $s ): string => (string) $s['name'], (array) $data['speakers'] ) ) ) )
			);
			TemplateLoader::part(
				$part,
				array_merge(
					[
						'data'    => $data,
						'context' => $context,
						'show'    => $show,
					],
					$cta
				)
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean() . $drawer['html'];
	}

	/**
	 * The display toggles for talk markup, all-on when a component's schema
	 * has no such attributes (schedule shares the row templates).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<string,bool>
	 */
	private static function show_flags( array $atts ): array {
		return [
			'speakers'     => ! isset( $atts['show_speakers'] ) || ! empty( $atts['show_speakers'] ),
			'speaker_info' => (string) ( $atts['speaker_info'] ?? 'names' ),
			'categories'   => ! isset( $atts['show_categories'] ) || ! empty( $atts['show_categories'] ),
			'ics'          => ! isset( $atts['show_ics'] ) || ! empty( $atts['show_ics'] ),
			'google'       => ! isset( $atts['show_google'] ) || ! empty( $atts['show_google'] ),
			'image'        => ! empty( $atts['show_image'] ),
			'venue'        => ! isset( $atts['show_venue'] ) || ! empty( $atts['show_venue'] ),
			'address'      => ! empty( $atts['show_address'] ),
		];
	}

	/**
	 * The register settings templates need, from component attributes.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array{url:string}
	 */
	private static function register_args( array $atts ): array {
		return [
			'url' => trim( (string) ( $atts['register_url'] ?? '' ) ),
			'woo' => 'woo' === (string) ( $atts['buy_on'] ?? 'heysummit' ),
		];
	}

	/**
	 * The tickets button destination: the event's own ticketing. HeySummit
	 * hosts one checkout per event; events sold through an external provider
	 * carry the operator's URL instead (the API exposes neither).
	 *
	 * @param array<string,mixed>  $data     Talk data (event_url/talk_url).
	 * @param array<string,string> $register Register settings (url = external ticketing).
	 */
	public static function ticketing_url( array $data, array $register ): string {
		// An externally hosted session replaces BOTH buttons' destinations.
		$talk_external = (string) ( $data['external_url'] ?? '' );

		if ( '' !== $talk_external ) {
			return $talk_external;
		}

		$external = (string) ( $register['url'] ?? '' );

		if ( '' !== $external ) {
			return $external;
		}

		$event_url = (string) ( $data['event_url'] ?? '' );

		return '' !== $event_url ? self::checkout_url( $event_url ) : (string) ( $data['talk_url'] ?? '' );
	}

	/**
	 * The ticket-selection page under an event URL, keeping any query
	 * string (UTM tags) intact. /checkout/select-tickets/ is the
	 * operator-verified path on the live hub (bare /checkout/ was an error
	 * page); filterable in case another account differs.
	 *
	 * @param string $event_url Event page URL, possibly already tagged.
	 */
	private static function checkout_url( string $event_url ): string {
		$parts = explode( '?', $event_url, 2 );
		$base  = trailingslashit( $parts[0] ) . (string) apply_filters( 'eex_checkout_path', 'checkout/select-tickets/' );

		return isset( $parts[1] ) ? $base . '?' . $parts[1] : $base;
	}

	/**
	 * The session button destination: every talk has its own landing page;
	 * the event page is the fallback for the rare talk without one.
	 *
	 * @param array<string,mixed> $data Talk data (event_url/talk_url).
	 */
	public static function session_url( array $data ): string {
		// An externally hosted session replaces BOTH buttons' destinations.
		$external = (string) ( $data['external_url'] ?? '' );

		if ( '' !== $external ) {
			return $external;
		}

		$talk_url = (string) ( $data['talk_url'] ?? '' );

		return '' !== $talk_url ? $talk_url : (string) ( $data['event_url'] ?? '' );
	}


	/**
	 * One ticket's register URL. The API's per-ticket checkout_link is the
	 * preferred destination — it lands checkout with the ticket genuinely
	 * preselected. Tickets without one (accounts predating the field, stale
	 * cache) fall back to the constructed select-tickets URL with the
	 * echo-only ?ticket= parameter, exactly as before.
	 *
	 * @param array<string,mixed>  $ticket   Display-shaped ticket row.
	 * @param array<string,string> $register Register settings (mode, url).
	 */
	private static function ticket_register_url( array $ticket, array $register ): string {
		// Opt-in per widget: a ticket mapped to a WooCommerce product sells
		// on THIS site — the only never-leaves-the-slider path for paid
		// tickets. HeySummit checkout stays the default.
		if ( ! empty( $register['woo'] ) ) {
			foreach ( (array) ( $ticket['prices'] ?? [] ) as $price ) {
				$product_url = \Emailexpert\Events\WooCommerce\Module::product_url_for_price( (string) ( $price['id'] ?? '' ) );

				if ( '' !== $product_url ) {
					return $product_url;
				}
			}
		}

		$checkout_link = (string) ( $ticket['checkout_link'] ?? '' );

		// An external ticketing override replaces HeySummit checkout links
		// wholesale, so the API link only applies without one.
		if ( '' !== $checkout_link && '' === (string) ( $register['url'] ?? '' ) ) {
			$url = self::carry_query( $checkout_link, (string) ( $ticket['register_url'] ?? '' ) );

			/**
			 * The API-provided per-ticket checkout link about to be rendered.
			 *
			 * @param string              $url    Checkout link, page query carried over.
			 * @param array<string,mixed> $ticket Display-shaped ticket row.
			 */
			return (string) apply_filters( 'eex_ticket_checkout_link', $url, $ticket );
		}

		$url = self::ticketing_url( [ 'event_url' => (string) ( $ticket['register_url'] ?? '' ) ], $register );

		// The parameter is echo-only on select-tickets; kept because it is
		// harmless and this path only serves rows without a checkout_link.
		if ( '' !== $url && '' === (string) ( $register['url'] ?? '' ) ) {
			$url = add_query_arg( 'ticket', (string) $ticket['id'], $url );
		}

		return $url;
	}

	/**
	 * Copy the query string of $source (the UTM-tagged event URL) onto $url
	 * (the API-provided checkout link, which arrives untagged), never
	 * clobbering parameters the link already carries. Keeps attribution
	 * identical to what the constructed checkout URL would have sent.
	 *
	 * @param string $url    Destination URL.
	 * @param string $source URL whose query string to carry over.
	 */
	private static function carry_query( string $url, string $source ): string {
		$query = (string) wp_parse_url( $source, PHP_URL_QUERY );

		if ( '' === $query ) {
			return $url;
		}

		parse_str( $query, $params );
		$params = array_filter( $params, 'is_scalar' );

		$existing = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' !== $existing ) {
			parse_str( $existing, $present );
			$params = array_diff_key( $params, $present );
		}

		if ( empty( $params ) ) {
			return $url;
		}

		return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $params ) ), $url );
	}

	/**
	 * The slide-over ticket panel: server-rendered (and fragment-cached with
	 * the component), revealed by eex-time.js when a Register button carries
	 * its ID. Free tickets register right here through the plugin's own
	 * allowlisted attendee-create call; paid tickets deep-link to checkout —
	 * the ticket's own checkout_link when the API provides one, the event's
	 * select-tickets page otherwise (payment can only happen on the
	 * platform). The component's tickets/exclude attributes filter what is
	 * offered. Empty when the component keeps plain links or no tickets
	 * resolve — buttons then behave as ordinary links.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array{id:string,html:string}
	 */
	private static function ticket_drawer( array $atts ): array {
		$none = [
			'id'   => '',
			'html' => '',
		];

		if ( 'panel' !== (string) ( $atts['register_action'] ?? 'link' ) ) {
			return $none;
		}

		$csv      = static fn( string $value ): array => array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		$only     = $csv( (string) ( $atts['tickets'] ?? '' ) );
		$excluded = $csv( (string) ( $atts['exclude'] ?? '' ) );

		$tickets = array_values(
			array_filter(
				self::repo()->tickets( $atts ),
				static function ( array $ticket ) use ( $only, $excluded ): bool {
					$id = (string) $ticket['id'];

					return ( empty( $only ) || in_array( $id, $only, true ) ) && ! in_array( $id, $excluded, true );
				}
			)
		);

		if ( empty( $tickets ) ) {
			return $none;
		}

		$register = self::register_args( $atts );
		$event    = self::repo()->event_summary( (string) ( $atts['event'] ?? '' ) );
		$event_id = null !== $event ? (string) $event['hs_id'] : '';
		$coupon   = (string) ( $atts['coupon'] ?? '' );
		$id       = 'eex-drawer-' . substr( md5( wp_json_encode( [ $event_id, $register, $only, $excluded, $coupon ] ) ), 0, 8 );

		ob_start();
		?>
		<div class="eex eex-drawer" id="<?php echo esc_attr( $id ); ?>" hidden>
			<div class="eex-drawer-backdrop" data-eex-drawer-close="1"></div>
			<div class="eex-drawer-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Tickets', 'emailexpert-events' ); ?>" tabindex="-1">
				<div class="eex-drawer-head">
					<h2 class="eex-drawer-title"><?php esc_html_e( 'Choose your ticket', 'emailexpert-events' ); ?></h2>
					<button type="button" class="eex-drawer-close" data-eex-drawer-close="1" aria-label="<?php esc_attr_e( 'Close', 'emailexpert-events' ); ?>">&#215;</button>
				</div>
				<?php // Which session this registration is for — filled by eex-time.js from the opening button, hidden when the opener is event-level. ?>
				<p class="eex-drawer-context" data-eex-drawer-context data-eex-prefix="<?php esc_attr_e( 'Registering for:', 'emailexpert-events' ); ?>" hidden></p>
				<ul class="eex-list eex-pricing eex-pricing-rows eex-drawer-tickets" role="list">
					<?php foreach ( $tickets as $ticket ) : ?>
						<?php
						$is_free  = empty( $ticket['is_paid'] );
						$price_id = '';
						foreach ( (array) $ticket['prices'] as $price ) {
							if ( '' !== (string) ( $price['id'] ?? '' ) ) {
								$price_id = (string) $price['id'];
								break;
							}
						}
						?>
						<li class="eex-grid-item">
							<?php
							TemplateLoader::part(
								'pricing-ticket',
								[
									'ticket'           => array_merge(
										$ticket,
										// Free tickets register in the form below, not via a link.
										[ 'register_url' => $is_free ? '' : self::ticket_register_url( $ticket, $register ) ]
									),
									'hero'             => false,
									'ribbon'           => ! empty( $ticket['popular'] ) ? __( 'Most popular', 'emailexpert-events' ) : '',
									'currency'         => (string) ( $atts['currency'] ?? '' ),
									'show_description' => true,
									'show_covers'      => false,
									'show_remaining'   => true,
									'register_text'    => (string) ( $atts['register_text'] ?? '' ),
								]
							);
							?>
							<?php if ( $is_free && '' !== $event_id ) : ?>
								<button type="button" class="eex-cta eex-reg-toggle" data-eex-reg-toggle="1" aria-expanded="false"><?php esc_html_e( 'Register free', 'emailexpert-events' ); ?></button>
								<?php
								TemplateLoader::part(
									'register-form',
									[
										'event_id'    => $event_id,
										'ticket_id'   => (string) $ticket['id'],
										'price_id'    => $price_id,
										'submit_text' => '',
										'hidden'      => true,
									]
								);
								?>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
		</div>
		<?php

		return [
			'id'   => $id,
			'html' => (string) ob_get_clean(),
		];
	}

	/**
	 * The agenda layout: sessions grouped under event-local day headings,
	 * modelled on the site's original design but vertically compact.
	 *
	 * @param array<int,array<string,mixed>> $items   Talk data arrays.
	 * @param string                         $context 'upcoming', 'past' or 'featured'.
	 * @param array<string,bool>             $show    Display toggles.
	 * @param array<string,mixed>            $cta     Register CTA part args.
	 */
	private static function agenda_layout( array $items, string $context, array $show, array $cta = [] ): string {
		$rows = self::group_rows_by_day( $items, 'j F Y' );

		ob_start();
		$current_day = null;
		$open        = false;

		foreach ( $rows as $row ) {
			if ( $row['day'] !== $current_day ) {
				if ( $open ) {
					echo '</ol></section>';
				}
				$current_day = $row['day'];
				$open        = true;
				echo '<section class="eex-agenda-day"><h3 class="eex-agenda-heading">' . esc_html( $row['day'] ) . '</h3><ol class="eex-agenda-list" role="list">';
			}

			$data = $row['data'];

			// The same filterable attributes as the grid, so the session
			// filter bar works on agendas too.
			printf(
				'<li class="eex-agenda-item" data-eex-title="%s" data-eex-cats="%s" data-eex-speakers="%s">',
				esc_attr( strtolower( (string) $data['title'] ) ),
				esc_attr( implode( ',', array_map( static fn( $term ): string => (string) $term->slug, (array) $data['categories'] ) ) ),
				esc_attr( strtolower( implode( ',', array_map( static fn( array $s ): string => (string) $s['name'], (array) $data['speakers'] ) ) ) )
			);
			TemplateLoader::part(
				'agenda-row',
				array_merge(
					[
						'data'    => $data,
						'context' => $context,
						'show'    => $show,
					],
					$cta
				)
			);
			echo '</li>';
		}

		if ( $open ) {
			echo '</ol></section>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * Order talks chronologically and stamp each with its event-local day
	 * heading. Shared by the schedule and the agenda layout.
	 *
	 * @param array<int,array<string,mixed>> $items      Talk data arrays.
	 * @param string                         $day_format Day heading format.
	 * @return array<int,array{ts:int,day:string,data:array<string,mixed>}>
	 */
	private static function group_rows_by_day( array $items, string $day_format ): array {
		$rows = [];

		foreach ( $items as $data ) {
			$ts = strtotime( (string) $data['starts_at'] );
			if ( false === $ts ) {
				continue;
			}
			$tz    = TimeFormat::timezone( (string) $data['timezone'] );
			$local = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );

			$rows[] = [
				'ts'   => $ts,
				'day'  => $local->format( $day_format ),
				'data' => $data,
			];
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['ts'] <=> $b['ts'] );

		return $rows;
	}

	/**
	 * The empty state. Components never render a blank void.
	 *
	 * @param string $text Empty text.
	 */
	private static function empty_state( string $text ): string {
		return '<p class="eex-empty">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Upcoming sessions grid.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_upcoming_sessions( array $atts ): string {
		$html = self::talk_cards( self::repo()->upcoming_talks( $atts ), $atts, 'upcoming' );

		if ( ! empty( $atts['show_subscribe'] ) ) {
			$feed_url = Feeds::url();
			$params   = array_filter(
				[
					'event'    => (string) $atts['event'],
					'category' => (string) $atts['category'],
				]
			);
			if ( ! empty( $params ) ) {
				$feed_url = add_query_arg( $params, $feed_url );
			}

			$html .= '<p class="eex-subscribe"><a href="' . esc_url( $feed_url ) . '">' . esc_html__( 'Subscribe to calendar', 'emailexpert-events' ) . '</a></p>';
		}

		return $html;
	}

	/**
	 * Past sessions archive grid with pagination.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_past_sessions( array $atts ): string {
		$limit = max( 1, (int) $atts['limit'] );

		// The page number is an attribute (fed from ?eex_page= via from_get)
		// so the fragment cache keys on it — page 2 must never serve a
		// cached page 1.
		$page = ! empty( $atts['paginate'] ) ? max( 1, (int) ( $atts['page'] ?: 1 ) ) : 1;

		$query_atts           = $atts;
		$query_atts['offset'] = ( $page - 1 ) * $limit;

		$html = self::talk_cards( self::repo()->past_talks( $query_atts ), $atts, 'past' );

		if ( ! empty( $atts['paginate'] ) ) {
			$total = self::repo()->past_talks_total( $atts );
			$pages = (int) ceil( $total / $limit );

			if ( $pages > 1 ) {
				$html .= '<nav class="eex-pagination" aria-label="' . esc_attr__( 'Past sessions pages', 'emailexpert-events' ) . '">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					$html .= sprintf(
						'<a href="%s"%s>%d</a> ',
						esc_url( add_query_arg( 'eex_page', $i ) ),
						$i === $page ? ' aria-current="page" class="eex-current"' : '',
						(int) $i
					);
				}
				$html .= '</nav>';
			}
		}

		return $html;
	}

	/**
	 * Upcoming events cards.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_upcoming_events( array $atts ): string {
		return self::event_cards( self::repo()->upcoming_events( $atts ), $atts, 'upcoming' );
	}

	/**
	 * Past events archive.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_past_events( array $atts ): string {
		return self::event_cards( self::repo()->past_events( $atts ), $atts, 'past' );
	}

	/**
	 * Render a list of event cards, or the empty state.
	 *
	 * @param array<int,array<string,mixed>> $items   Event data arrays.
	 * @param array<string,mixed>            $atts    Component attributes.
	 * @param string                         $context 'upcoming' or 'past'.
	 */
	private static function event_cards( array $items, array $atts, string $context ): string {
		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		foreach ( $items as $item ) {
			self::$schema_pool[] = [
				'type' => 'event',
				'data' => $item,
			];
		}

		$list = 'list' === (string) ( $atts['layout'] ?? 'grid' );

		ob_start();
		echo $list ? '<ul class="eex-list eex-event-list" role="list">' : '<ul class="eex-grid eex-event-grid" role="list">';
		foreach ( $items as $event ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				$list ? 'list-event' : 'card-event',
				[
					'event'         => $event,
					'context'       => $context,
					'register_text' => (string) ( $atts['register_text'] ?? '' ),
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Countdown to an event's first talk or a specific session.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_countdown( array $atts ): string {
		$target   = '';
		$timezone = '';
		$label    = '';

		if ( '' !== (string) $atts['talk'] ) {
			$data = self::repo()->talk( (string) $atts['talk'] );
			if ( null !== $data ) {
				$target   = (string) $data['starts_at'];
				$timezone = (string) $data['timezone'];
				$label    = (string) $data['title'];
			}
		} else {
			$event = self::repo()->event_summary( (string) $atts['event'] );
			if ( null !== $event ) {
				$label    = (string) $event['title'];
				$timezone = (string) $event['timezone'];
				$first    = (string) $event['first_talk_at'];

				// For an evergreen hub, count to the next upcoming session instead.
				$next = self::repo()->upcoming_talks(
					[
						'event' => (string) $atts['event'],
						'limit' => 1,
					]
				);
				if ( ! empty( $next ) ) {
					$target = (string) $next[0]['starts_at'];
					$label  = (string) $next[0]['title'];
				} else {
					$target = $first;
				}
			}
		}

		if ( '' === $target || false === strtotime( $target ) ) {
			return '';
		}

		// Internal callers (the register bar) already name the subject.
		if ( ! empty( $atts['bare'] ) ) {
			$label = '';
		}

		// Graceful no-JS fallback: the event-local start time, no live claims.
		return sprintf(
			'<p class="eex-countdown" data-eex-countdown="%s" aria-live="polite">%s %s</p>',
			esc_attr( gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $target ) ) ),
			esc_html( $label ? sprintf( '%s —', $label ) : '' ),
			TimeFormat::render( $target, $timezone ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
	}

	/**
	 * Schedule grouped by day in event-local time.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_schedule( array $atts ): string {
		$items = array_merge( self::repo()->upcoming_talks( $atts + [ 'limit' => 0 ] ), self::repo()->past_talks( $atts + [ 'limit' => 0 ] ) );

		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		// Order every talk chronologically and group by event-local day.
		$rows = self::group_rows_by_day( $items, 'l j F Y' );
		$show = self::show_flags( $atts );

		foreach ( $rows as $row ) {
			self::$schema_pool[] = [
				'type' => 'talk',
				'data' => $row['data'],
			];
		}

		// Both extras are opt-in and additive: with the flags off the markup
		// below is unchanged from previous releases.
		$days = array_values( array_unique( array_column( $rows, 'day' ) ) );
		$uid  = ! empty( $atts['day_nav'] ) ? substr( md5( wp_json_encode( $atts ) ), 0, 6 ) : '';

		ob_start();

		if ( ! empty( $atts['show_tz_toggle'] ) ) {
			// JS-only affordance (time zones cannot be switched server-side);
			// eex-time.js reveals it. aria-pressed = "showing MY timezone".
			printf(
				'<p class="eex-tz-toggle" hidden><button type="button" class="eex-chip" data-eex-tz-toggle="1" aria-pressed="true" data-label-local="%s" data-label-event="%s">%s</button></p>',
				esc_attr__( 'Show times in your timezone', 'emailexpert-events' ),
				esc_attr__( 'Show times in event time', 'emailexpert-events' ),
				esc_html__( 'Show times in event time', 'emailexpert-events' )
			);
		}

		if ( '' !== $uid && count( $days ) > 1 ) {
			echo '<nav class="eex-day-nav" aria-label="' . esc_attr__( 'Jump to day', 'emailexpert-events' ) . '">';
			foreach ( $days as $i => $day ) {
				printf(
					'<a class="eex-chip" href="#eex-day-%s-%d">%s</a> ',
					esc_attr( $uid ),
					(int) $i,
					esc_html( (string) $day )
				);
			}
			echo '</nav>';
		}

		$current_day = null;
		$open        = false;

		foreach ( $rows as $row ) {
			if ( $row['day'] !== $current_day ) {
				if ( $open ) {
					echo '</ol></section>';
				}
				$current_day = $row['day'];
				$open        = true;
				$anchor      = '' !== $uid ? sprintf( ' id="eex-day-%s-%d"', esc_attr( $uid ), (int) array_search( $row['day'], $days, true ) ) : '';
				echo '<section class="eex-schedule-day"' . $anchor . '><h3 class="eex-schedule-heading">' . esc_html( $row['day'] ) . '</h3><ol class="eex-schedule-list" role="list">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			}

			TemplateLoader::part(
				'schedule-row',
				[
					'data' => $row['data'],
					'show' => $show,
				]
			);
		}

		if ( $open ) {
			echo '</ol></section>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * Speaker grid.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_speakers( array $atts ): string {
		$limit = max( 0, (int) $atts['limit'] );

		// Pagination mirrors past-sessions: the page number is an attribute
		// (fed from ?eex_speaker_page= via from_get) so the fragment cache
		// keys on it. It needs a positive limit to mean anything.
		$paginate = ! empty( $atts['paginate'] ) && $limit > 0;
		$page     = $paginate ? max( 1, (int) ( $atts['page'] ?: 1 ) ) : 1;

		$order = (string) ( $atts['order'] ?? 'name' );

		if ( 'name' === $order ) {
			$query_atts = $atts;
			if ( $paginate ) {
				$query_atts['offset'] = ( $page - 1 ) * $limit;
			}

			$items = self::apply_speaker_link( self::repo()->speakers( $query_atts ), $atts );
		} else {
			// Non-default orders need the whole set before slicing.
			$all = self::repo()->speakers(
				array_merge(
					$atts,
					[
						'limit'  => 0,
						'offset' => 0,
					]
				)
			);

			if ( 'random' === $order ) {
				// The shuffled fragment is cached, so the selection stays
				// stable until the display cache refreshes — then reshuffles.
				// A random sample has no stable pages.
				$paginate = false;
				shuffle( $all );
			} else {
				$all = array_reverse( $all );
			}

			$offset = $paginate ? ( $page - 1 ) * $limit : 0;
			$items  = self::apply_speaker_link( $limit > 0 ? array_slice( $all, $offset, $limit ) : $all, $atts );
		}

		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$list = 'list' === (string) ( $atts['layout'] ?? 'grid' );

		// 0 = leave the CSS variable to the stylesheet or a widget's
		// responsive columns control.
		$columns = min( 6, max( 0, (int) $atts['columns'] ) );
		$style   = ! $list && $columns > 0 ? sprintf( ' style="--eex-columns:%d"', $columns ) : '';

		$classes = $list ? 'eex-list eex-speaker-list' : 'eex-grid eex-speaker-grid';
		$shape   = (string) ( $atts['photo_shape'] ?? 'rounded' );
		if ( 'rounded' !== $shape && '' !== $shape ) {
			$classes .= ' eex-photos-' . $shape;
		}

		ob_start();
		printf( '<ul class="%s" role="list"%s>', esc_attr( $classes ), $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an integer above.
		foreach ( $items as $speaker ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				$list ? 'list-speaker' : 'card-speaker',
				[
					'speaker'    => $speaker,
					'show_links' => ! empty( $atts['show_links'] ),
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		$html = (string) ob_get_clean();

		if ( $paginate ) {
			$total = self::repo()->speakers_total( $atts );
			$pages = (int) ceil( $total / $limit );

			if ( $pages > 1 ) {
				$html .= '<nav class="eex-pagination" aria-label="' . esc_attr__( 'Speaker pages', 'emailexpert-events' ) . '">';
				for ( $i = 1; $i <= $pages; $i++ ) {
					$html .= sprintf(
						'<a href="%s"%s>%d</a> ',
						esc_url( add_query_arg( 'eex_speaker_page', $i ) ),
						$i === $page ? ' aria-current="page" class="eex-current"' : '',
						(int) $i
					);
				}
				$html .= '</nav>';
			}
		}

		if ( '' !== (string) $atts['all_url'] ) {
			$html .= sprintf(
				'<p class="eex-view-all"><a class="eex-cta-secondary" href="%s">%s</a></p>',
				esc_url( (string) $atts['all_url'] ),
				esc_html( (string) $atts['all_text'] )
			);
		}

		return $html;
	}

	/**
	 * Featured talks by manual selection.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_featured_talks( array $atts ): string {
		$requested = array_filter( array_map( 'trim', explode( ',', (string) $atts['ids'] ) ) );

		$items = [];
		foreach ( $requested as $ref ) {
			$data = self::repo()->talk( $ref );
			if ( null !== $data && ! empty( $data['published'] ) ) {
				$items[] = $data;
			}
		}

		return self::talk_cards( $items, $atts, 'featured' );
	}

	/**
	 * Whether a sponsor matches a category filter: by category name, tier
	 * name (both case-insensitive) or raw category ID — operators have
	 * stored all three over time, and every one of them should just work.
	 *
	 * @param array<string,mixed> $sponsor  Display-shaped sponsor row.
	 * @param string              $category Filter value, lowercased.
	 */
	private static function sponsor_matches_category( array $sponsor, string $category ): bool {
		if ( '' === $category ) {
			return true;
		}

		$names = array_map( 'strtolower', (array) ( $sponsor['sponsor_categories'] ?? [] ) );

		return in_array( $category, $names, true )
			|| strtolower( (string) ( $sponsor['tier_name'] ?? '' ) ) === $category
			|| in_array( $category, array_map( 'strval', (array) ( $sponsor['sponsor_category_ids'] ?? [] ) ), true );
	}

	/**
	 * Sponsors wall grouped by tier.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_sponsors( array $atts ): string {
		$shown_on = (string) ( $atts['shown_on'] ?? 'any' );
		$category = strtolower( trim( (string) ( $atts['sponsor_category'] ?? '' ) ) );

		// Filter first (manual rows carry none of the API's flags: they pass
		// every visibility filter — the operator typed them in on purpose —
		// but are never "main" and have no categories).
		$rows     = [];
		$excluded = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $atts['exclude'] ?? '' ) ) ) ) );

		foreach ( self::repo()->sponsors( $atts ) as $sponsor ) {
			if ( in_array( (string) $sponsor['id'], $excluded, true ) ) {
				continue;
			}

			if ( ! empty( $atts['main_only'] ) && empty( $sponsor['main'] ) ) {
				continue;
			}

			if ( 'any' !== $shown_on && isset( $sponsor['show'] ) && empty( $sponsor['show'][ $shown_on ] ) ) {
				continue;
			}

			if ( ! self::sponsor_matches_category( $sponsor, $category ) ) {
				continue;
			}

			$rows[] = $sponsor;
		}

		if ( empty( $rows ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		// Order (within each group when grouped; the whole wall when flat),
		// then cap. Random is cache-stable: the fragment cache holds each
		// shuffle for the display TTL, exactly like random speakers.
		switch ( (string) ( $atts['order'] ?? 'weight' ) ) {
			case 'name':
				usort( $rows, static fn( array $a, array $b ): int => strcasecmp( (string) $a['name'], (string) $b['name'] ) );
				break;
			case 'name-desc':
				usort( $rows, static fn( array $a, array $b ): int => strcasecmp( (string) $b['name'], (string) $a['name'] ) );
				break;
			case 'random':
				shuffle( $rows );
				break;
		}

		$limit = max( 0, (int) ( $atts['limit'] ?? 0 ) );
		if ( $limit > 0 ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		// Group by tier AFTER ordering, so each group keeps the chosen order.
		// The weight is zero-padded: string-sorted keys would put 100 before
		// 99 and betray the weighting the operator set.
		$tiers = [];
		foreach ( $rows as $sponsor ) {
			$tiers[ sprintf( '%05d|%s', min( 99999, max( 0, (int) $sponsor['tier_order'] ) ), (string) $sponsor['tier_name'] ) ][] = $sponsor;
		}

		ksort( $tiers );

		$layout = (string) ( $atts['layout'] ?? 'grid' );
		$list   = 'list' === $layout;
		$flat   = 'none' === (string) ( $atts['group_by'] ?? 'category' );
		$show   = [
			'names' => ! isset( $atts['show_names'] ) || ! empty( $atts['show_names'] ),
			'blurb' => ! empty( $atts['show_blurb'] ),
		];

		// Where a sponsor's link lands: their own site (default), their page
		// on the event hub, or nowhere. UTM tagging is opt-in per widget;
		// hub URLs arrive pre-tagged and Utm::tag() never double-tags.
		$link_mode = (string) ( $atts['sponsor_link'] ?? 'website' );
		$utm_links = ! empty( $atts['utm_links'] );
		foreach ( $tiers as $tier_key => $tier_rows ) {
			$tiers[ $tier_key ] = array_map(
				static function ( array $sponsor ) use ( $link_mode, $utm_links ): array {
					$sponsor = self::linked_sponsor( $sponsor, $link_mode );

					if ( $utm_links && '' !== (string) ( $sponsor['url'] ?? '' ) ) {
						$sponsor['url'] = Utm::tag( (string) $sponsor['url'] );
					}

					return $sponsor;
				},
				$tier_rows
			);
		}

		// The optional wall heading tops every layout; the category headings
		// (grouped layouts only) sit one level below it.
		$tier_level   = min( 4, max( 2, (int) ( $atts['heading_level'] ?? 3 ) ) );
		$heading_text = trim( (string) ( $atts['heading'] ?? '' ) );
		$heading_html = '' !== $heading_text
			? sprintf( '<h%1$d class="eex-wall-heading">%2$s</h%1$d>', max( 2, $tier_level - 1 ), esc_html( $heading_text ) )
			: '';

		$logo_sizes_map = [
			'small'  => '2em',
			'medium' => '3.25em',
			'large'  => '5em',
		];

		// The strip is a flat scrolling marquee of logos: grouping, names and
		// blurbs do not apply, and the track is doubled (second copy hidden
		// from assistive tech) for a seamless CSS loop.
		$new_tab = ! empty( $atts['new_tab'] );

		if ( 'strip' === $layout ) {
			ob_start();
			echo $heading_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built above.
			printf(
				'<div class="eex-sponsor-strip" style="--eex-sponsor-logo:%s"><ul class="eex-strip-track" role="list">',
				esc_attr( $logo_sizes_map[ (string) ( $atts['logo_size'] ?? 'medium' ) ] ?? '3.25em' )
			);
			foreach ( [ false, true ] as $decorative ) {
				foreach ( $tiers as $tier_rows ) {
					foreach ( $tier_rows as $sponsor ) {
						self::strip_item( $sponsor, $decorative, $new_tab );
					}
				}
			}
			echo '</ul></div>';

			return (string) ob_get_clean();
		}

		$columns    = min( 6, max( 0, (int) ( $atts['columns'] ?? 0 ) ) );
		$logo_style = sprintf(
			' style="--eex-sponsor-logo:%s%s"',
			$logo_sizes_map[ (string) ( $atts['logo_size'] ?? 'medium' ) ] ?? '3.25em',
			! $list && $columns > 0 ? sprintf( ';--eex-columns:%d', $columns ) : ''
		);

		$grid_class = 'compact' === $layout ? 'eex-grid eex-sponsor-grid eex-sponsor-compact' : 'eex-grid eex-sponsor-grid';
		$open_list  = ( $list ? '<ul class="eex-list eex-sponsor-list" role="list"' : '<ul class="' . $grid_class . '" role="list"' ) . $logo_style . '>';
		$sponsor_li = static function ( array $sponsor ) use ( $list, $show, $new_tab, $atts ): void {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				$list ? 'list-sponsor' : 'card-sponsor',
				[
					'sponsor'      => $sponsor,
					'show'         => $show,
					'new_tab'      => $new_tab,
					'blurb_length' => (int) ( $atts['blurb_length'] ?? 0 ),
				]
			);
			echo '</li>';
		};

		ob_start();

		echo $heading_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built above.

		if ( $flat ) {
			// One wall, no headings — order still respects the categories.
			echo $open_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup and em values above.
			foreach ( $tiers as $tier_sponsors ) {
				foreach ( $tier_sponsors as $sponsor ) {
					$sponsor_li( $sponsor );
				}
			}
			echo '</ul>';

			return (string) ob_get_clean();
		}

		foreach ( $tiers as $key => $tier_sponsors ) {
			[ , $tier_name ] = explode( '|', $key, 2 );
			printf( '<section class="eex-sponsor-tier"><h%1$d class="eex-tier-heading">%2$s</h%1$d>', $tier_level, esc_html( $tier_name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- integer level, name escaped.
			echo $open_list; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal markup and em values above.
			foreach ( $tier_sponsors as $sponsor ) {
				$sponsor_li( $sponsor );
			}
			echo '</ul></section>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * Hero banner for the single soonest upcoming session.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_next_session( array $atts ): string {
		$items = self::repo()->upcoming_talks( array_merge( $atts, [ 'limit' => 1 ] ) );

		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		self::$schema_pool[] = [
			'type' => 'talk',
			'data' => $items[0],
		];

		$drawer = self::ticket_drawer( $atts );

		ob_start();
		TemplateLoader::part(
			'hero-talk',
			[
				'data'           => $items[0],
				'layout'         => (string) ( $atts['layout'] ?? 'panel' ),
				'show'           => self::show_flags( $atts ),
				'show_countdown' => ! empty( $atts['show_countdown'] ),
				'buttons'        => (string) ( $atts['buttons'] ?? 'both' ),
				'register_text'  => (string) ( $atts['register_text'] ?? '' ),
				'session_text'   => (string) ( $atts['session_text'] ?? '' ),
				'register'       => self::register_args( $atts ),
				'drawer'         => $drawer['id'],
			]
		);

		return (string) ob_get_clean() . $drawer['html'];
	}

	/**
	 * Ticket pricing table for one event.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_pricing( array $atts ): string {
		$csv = static fn( string $value ): array => array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );

		$only     = $csv( (string) ( $atts['tickets'] ?? '' ) );
		$excluded = $csv( (string) ( $atts['exclude'] ?? '' ) );
		$featured = trim( (string) ( $atts['featured'] ?? '' ) );

		$tickets = array_values(
			array_filter(
				self::repo()->tickets( $atts ),
				static function ( array $ticket ) use ( $atts, $only, $excluded ): bool {
					$id = (string) $ticket['id'];

					if ( ! empty( $only ) && ! in_array( $id, $only, true ) ) {
						return false;
					}

					if ( in_array( $id, $excluded, true ) ) {
						return false;
					}

					if ( empty( $atts['show_free'] ) && empty( $ticket['is_paid'] ) ) {
						return false;
					}

					if ( empty( $atts['show_paid'] ) && ! empty( $ticket['is_paid'] ) ) {
						return false;
					}

					if ( ! empty( $atts['hide_soldout'] ) && '0' === (string) $ticket['remaining'] ) {
						return false;
					}

					return true;
				}
			)
		);

		if ( empty( $tickets ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$rows   = 'rows' === (string) ( $atts['layout'] ?? 'columns' );
		$ribbon = (string) ( $atts['ribbon_text'] ?? '' );
		if ( '' === $ribbon ) {
			$ribbon = __( 'Most popular', 'emailexpert-events' );
		}

		$columns  = min( 6, max( 0, (int) ( $atts['columns'] ?? 0 ) ) );
		$style    = ! $rows && $columns > 0 ? sprintf( ' style="--eex-columns:%d"', $columns ) : '';
		$register = self::register_args( $atts );

		ob_start();
		printf( '<ul class="%s" role="list"%s>', esc_attr( $rows ? 'eex-list eex-pricing eex-pricing-rows' : 'eex-grid eex-pricing' ), $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an integer above.
		foreach ( $tickets as $ticket ) {
			// The hero ticket is the explicitly chosen one; otherwise the
			// API's own popular flag when highlighting is on.
			$is_hero    = '' !== $featured && (string) $ticket['id'] === $featured;
			$has_ribbon = $is_hero || ( '' === $featured && ! empty( $atts['highlight_popular'] ) && ! empty( $ticket['popular'] ) );

			$ticket['register_url'] = self::ticket_register_url( $ticket, $register );

			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				'pricing-ticket',
				[
					'ticket'           => $ticket,
					'hero'             => $is_hero,
					'ribbon'           => $has_ribbon ? $ribbon : '',
					'currency'         => (string) ( $atts['currency'] ?? '' ),
					'show_description' => ! empty( $atts['show_description'] ),
					'show_covers'      => ! empty( $atts['show_covers'] ),
					'show_remaining'   => ! empty( $atts['show_remaining'] ),
					'register_text'    => (string) ( $atts['register_text'] ?? '' ),
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * A sponsor row with its link resolved to the chosen destination:
	 * 'website' keeps the sponsor's own URL, 'hub' prefers their page on
	 * the event hub (falling back to the website when the API gave no
	 * slug), 'none' removes the link.
	 *
	 * @param array<string,mixed> $sponsor Display row.
	 * @param string              $mode    website|hub|none.
	 */
	private static function linked_sponsor( array $sponsor, string $mode ): array {
		if ( 'none' === $mode ) {
			$sponsor['url'] = '';
		} elseif ( 'hub' === $mode && '' !== (string) ( $sponsor['hub_url'] ?? '' ) ) {
			$sponsor['url'] = (string) $sponsor['hub_url'];
		}

		return $sponsor;
	}

	/**
	 * One marquee item: the logo (or the name when there is none), linked
	 * unless the wall says otherwise. The duplicate loop copy is decorative.
	 *
	 * @param array<string,mixed> $sponsor    Display row.
	 * @param bool                $decorative Second copy for the seamless loop.
	 */
	private static function strip_item( array $sponsor, bool $decorative, bool $new_tab = false ): void {
		$name = (string) $sponsor['name'];
		$logo = (string) ( $sponsor['logo_url'] ?? '' );
		$url  = (string) ( $sponsor['url'] ?? '' );

		$visual = '' !== $logo
			? '<img class="eex-sponsor-logo" loading="lazy" src="' . esc_url( $logo ) . '" alt="' . esc_attr( $decorative ? '' : $name ) . '" />'
			: '<span class="eex-sponsor-name">' . esc_html( $name ) . '</span>';

		echo '<li class="eex-strip-item"' . ( $decorative ? ' aria-hidden="true"' : '' ) . '>';

		if ( '' !== $url && ! $decorative ) {
			echo '<a href="' . esc_url( $url ) . '" rel="sponsored noopener"' . ( $new_tab ? ' target="_blank"' : '' ) . ' aria-label="' . esc_attr( $name ) . '">' . $visual . '</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		} else {
			echo $visual; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}

		echo '</li>';
	}

	/**
	 * One sponsor, prominently: banner, video, description and actions from
	 * the fields the wall has no room for. Chosen by ID, or a cache-stable
	 * random pick (rotates each cache refresh).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_sponsor_spotlight( array $atts ): string {
		$sponsors = self::repo()->sponsors( $atts );
		$category = strtolower( trim( (string) ( $atts['sponsor_category'] ?? '' ) ) );
		$shown_on = (string) ( $atts['shown_on'] ?? 'any' );

		// Surface filter first: "a random sponsor from those shown on the
		// blog" is a pool definition, exactly like the category filter.
		// Manual rows carry no flags and pass (typed in on purpose).
		if ( 'any' !== $shown_on ) {
			$sponsors = array_values(
				array_filter(
					$sponsors,
					static fn( array $sponsor ): bool => ! isset( $sponsor['show'] ) || ! empty( $sponsor['show'][ $shown_on ] )
				)
			);
		}

		if ( '' !== $category ) {
			$sponsors = array_values(
				array_filter(
					$sponsors,
					static fn( array $sponsor ): bool => self::sponsor_matches_category( $sponsor, $category )
				)
			);
		}

		// A video spotlight should never draw a videoless sponsor.
		if ( ! empty( $atts['require_video'] ) ) {
			$sponsors = array_values(
				array_filter(
					$sponsors,
					static fn( array $sponsor ): bool => '' !== self::video_embed_url( (array) ( $sponsor['video'] ?? [] ) )
				)
			);
		}

		if ( empty( $sponsors ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$pick = null;
		$ref  = (string) ( $atts['sponsor'] ?? '' );

		if ( '' !== $ref ) {
			foreach ( $sponsors as $sponsor ) {
				if ( (string) $sponsor['id'] === $ref ) {
					$pick = $sponsor;
					break;
				}
			}
		} else {
			$pick = $sponsors[ array_rand( $sponsors ) ];
		}

		if ( null === $pick ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$pick = self::linked_sponsor( $pick, (string) ( $atts['sponsor_link'] ?? 'website' ) );

		if ( ! empty( $atts['utm_links'] ) && '' !== (string) ( $pick['url'] ?? '' ) ) {
			$pick['url'] = Utm::tag( (string) $pick['url'] );
		}

		ob_start();
		TemplateLoader::part(
			'spotlight-sponsor',
			[
				'sponsor'            => $pick,
				'layout'             => (string) ( $atts['layout'] ?? 'card' ),
				'show'               => [
					'logo'        => ! empty( $atts['show_logo'] ),
					'name'        => ! empty( $atts['show_name'] ),
					'blurb'       => ! empty( $atts['show_blurb'] ),
					'banner'      => ! empty( $atts['show_banner'] ),
					'video'       => ! empty( $atts['show_video'] ),
					'description' => ! empty( $atts['show_description'] ),
					'website'     => ! empty( $atts['show_website'] ),
					'books'       => ! empty( $atts['show_books'] ),
					'phone'       => ! empty( $atts['show_phone'] ),
				],
				'blurb_length'       => (int) ( $atts['blurb_length'] ?? 0 ),
				'description_length' => (int) ( $atts['description_length'] ?? 0 ),
				'website_text'       => (string) ( $atts['website_text'] ?? '' ),
				'books_text'         => (string) ( $atts['books_text'] ?? '' ),
				'new_tab'            => ! empty( $atts['new_tab'] ),
			]
		);

		return (string) ob_get_clean();
	}

	/**
	 * Trim text to a character budget on a word boundary with an ellipsis.
	 * 0 or negative means no limit.
	 *
	 * @param string $text  Plain text.
	 * @param int    $chars Character budget.
	 */
	public static function truncate( string $text, int $chars ): string {
		$text = trim( $text );

		if ( $chars <= 0 || mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $chars );
		$space = mb_strrpos( $cut, ' ' );

		// Break at the last word unless that throws away too much.
		if ( false !== $space && $space > (int) floor( $chars * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " \t\n\r.,;:—–-" ) . '…';
	}

	/**
	 * A privacy-friendly embed URL for a sponsor intro video. Only known
	 * providers embed; anything else returns '' and the template skips it.
	 * Autoplay is muted — browsers refuse it otherwise.
	 *
	 * @param array<string,mixed> $video type, id, autoplay.
	 */
	public static function video_embed_url( array $video ): string {
		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) ( $video['id'] ?? '' ) );

		if ( '' === $id ) {
			return '';
		}

		$autoplay = ! empty( $video['autoplay'] ) ? 1 : 0;

		switch ( (string) ( $video['type'] ?? '' ) ) {
			case 'youtube':
				return sprintf( 'https://www.youtube-nocookie.com/embed/%s?rel=0&autoplay=%d&mute=%d', $id, $autoplay, $autoplay );
			case 'vimeo':
				return sprintf( 'https://player.vimeo.com/video/%s?autoplay=%d&muted=%d', $id, $autoplay, $autoplay );
			case 'wistia':
				return sprintf( 'https://fast.wistia.net/embed/iframe/%s?autoPlay=%s&muted=true', $id, $autoplay ? 'true' : 'false' );
		}

		return '';
	}

	/**
	 * One featured speaker (chosen by ID, or a cache-stable random pick).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_speaker_spotlight( array $atts ): string {
		$speakers = self::repo()->speakers(
			array_merge(
				$atts,
				[
					'limit'  => 0,
					'offset' => 0,
				]
			)
		);

		if ( empty( $speakers ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$pick = null;
		$ref  = (string) ( $atts['speaker'] ?? '' );

		if ( '' !== $ref ) {
			foreach ( $speakers as $speaker ) {
				if ( (string) $speaker['id'] === $ref ) {
					$pick = $speaker;
					break;
				}
			}
		}

		if ( null === $pick ) {
			// Random pick inside the cached fragment: stable until the
			// display cache refreshes (the speakers random-order pattern).
			$pick = $speakers[ array_rand( $speakers ) ];
		}

		$pick = self::apply_speaker_link( [ $pick ], $atts )[0];

		$shape   = (string) ( $atts['photo_shape'] ?? 'rounded' );
		$classes = 'eex-spotlight' . ( 'rounded' !== $shape && '' !== $shape ? ' eex-photos-' . $shape : '' );

		ob_start();
		printf( '<div class="%s">', esc_attr( $classes ) );
		TemplateLoader::part(
			'spotlight-speaker',
			[
				'speaker'    => $pick,
				'show_bio'   => ! empty( $atts['show_bio'] ),
				'show_links' => ! empty( $atts['show_links'] ),
			]
		);
		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Every event on the account, filtered by status.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_events_portfolio( array $atts ): string {
		$status = (string) ( $atts['status'] ?? 'live' );

		$events = array_values(
			array_filter(
				self::repo()->all_events( $atts ),
				static function ( array $event ) use ( $status ): bool {
					switch ( $status ) {
						case 'evergreen':
							return ! empty( $event['evergreen'] ) && empty( $event['archived'] );
						case 'archived':
							return ! empty( $event['archived'] );
						case 'all':
							return true;
						default: // The default filter keeps public events.
							return ! empty( $event['live'] ) && empty( $event['archived'] );
					}
				}
			)
		);

		$limit = (int) ( $atts['limit'] ?? 0 );
		if ( $limit > 0 ) {
			$events = array_slice( $events, 0, $limit );
		}

		return self::event_cards( $events, $atts, 'upcoming' );
	}

	/**
	 * Live-now bar: renders hidden with the next sessions' timing data; the
	 * session-state JS reveals it while one is live. Cached HTML never
	 * claims live state (existing rule).
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_live_now( array $atts ): string {
		$items = self::repo()->current_and_next( array_merge( $atts, [ 'limit' => max( 1, (int) ( $atts['limit'] ?? 3 ) ) ] ) );

		if ( empty( $items ) ) {
			return '';
		}

		ob_start();
		echo '<div class="eex-live-bar" data-eex-live-bar="1" hidden>';
		printf(
			'<span class="eex-live-bar-label">%s</span> <a class="eex-live-bar-title" data-eex-live-bar-link href="#"></a>',
			esc_html__( 'Live now:', 'emailexpert-events' )
		);
		echo '<ul class="eex-live-bar-watch" hidden>';
		foreach ( $items as $data ) {
			printf(
				'<li data-eex-bar-title="%s"%s></li>',
				esc_attr( (string) $data['title'] ),
				self::session_attrs( $data ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
			);
		}
		echo '</ul></div>';

		return (string) ob_get_clean();
	}

	/**
	 * Rewrite speaker URLs per the speaker_link attribute: this site's
	 * pages (default), best-effort HeySummit hub speaker pages, or no link.
	 *
	 * @param array<int,array<string,mixed>> $speakers Speaker data arrays.
	 * @param array<string,mixed>            $atts     Attributes.
	 * @return array<int,array<string,mixed>>
	 */
	private static function apply_speaker_link( array $speakers, array $atts ): array {
		$mode = (string) ( $atts['speaker_link'] ?? 'default' );

		if ( 'none' === $mode ) {
			return array_map(
				static function ( array $speaker ): array {
					$speaker['url'] = '';

					return $speaker;
				},
				$speakers
			);
		}

		if ( 'hub' !== $mode ) {
			return $speakers;
		}

		$event = self::repo()->event_summary( (string) ( $atts['event'] ?? '' ) );
		$base  = null !== $event ? (string) ( $event['raw_event_url'] ?? '' ) : '';

		if ( '' === $base ) {
			return $speakers; // No hub to point at; keep the default links.
		}

		return array_map(
			static function ( array $speaker ) use ( $base ): array {
				// The hub's speaker slugs default to the name; the API's
				// read payload carries no slug, so this is best-effort.
				$slug = (string) ( $speaker['slug'] ?? '' );
				if ( '' === $slug ) {
					$slug = sanitize_title( (string) $speaker['name'] );
				}

				if ( '' !== $slug ) {
					$speaker['url'] = Utm::tag( trailingslashit( $base ) . 'speakers/' . $slug . '/' );
				}

				return $speaker;
			},
			$speakers
		);
	}

	/**
	 * Session library filter bar: server-rendered category and speaker
	 * links that work without JS; with JS, instant client-side filtering of
	 * the rendered session list.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_session_filter( array $atts ): string {
		$categories = self::repo()->categories( $atts );
		$speakers   = self::repo()->speakers( $atts + [ 'limit' => 0 ] );

		ob_start();
		echo '<div class="eex-filter-bar" data-eex-filter="1">';

		if ( ! empty( $atts['show_search'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only filter.
			$current_q = isset( $_GET['eex_q'] ) ? sanitize_text_field( wp_unslash( $_GET['eex_q'] ) ) : '';
			echo '<form class="eex-filter-search" method="get" role="search">';
			printf(
				'<label class="screen-reader-text" for="eex-filter-q">%s</label><input type="search" id="eex-filter-q" name="eex_q" value="%s" placeholder="%s" data-eex-filter-text="1" />',
				esc_html__( 'Search sessions', 'emailexpert-events' ),
				esc_attr( $current_q ),
				esc_attr__( 'Search sessions…', 'emailexpert-events' )
			);
			printf( '<button type="submit" class="eex-cta-secondary">%s</button>', esc_html__( 'Search', 'emailexpert-events' ) );
			echo '</form>';
		}

		if ( ! empty( $categories ) ) {
			echo '<nav class="eex-filter-categories" aria-label="' . esc_attr__( 'Filter by category', 'emailexpert-events' ) . '">';
			foreach ( $categories as $category ) {
				// Lite categories have no local archive page: the no-JS
				// fallback filters the sessions list on the current page
				// instead (?eex_cat= feeds the category att via from_get).
				$category_url = '' !== (string) $category['url']
					? (string) $category['url']
					: add_query_arg( 'eex_cat', (string) $category['slug'] );

				printf(
					'<a class="eex-badge" href="%s" data-eex-filter-cat="%s">%s</a> ',
					esc_url( $category_url ),
					esc_attr( (string) $category['slug'] ),
					esc_html( (string) $category['name'] )
				);
			}
			echo '</nav>';
		}

		if ( ! empty( $speakers ) ) {
			echo '<nav class="eex-filter-speakers" aria-label="' . esc_attr__( 'Filter by speaker', 'emailexpert-events' ) . '">';
			foreach ( $speakers as $speaker ) {
				printf(
					'<a class="eex-chip" href="%s" data-eex-filter-speaker="%s">%s</a> ',
					esc_url( (string) $speaker['url'] ),
					esc_attr( strtolower( (string) $speaker['name'] ) ),
					esc_html( (string) $speaker['name'] )
				);
			}
			echo '</nav>';
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	/**
	 * Registration counter with threshold and REST-refreshing figure.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_reg_counter( array $atts ): string {
		$event = self::repo()->event_summary( (string) $atts['event'] );

		if ( null === $event ) {
			return '';
		}

		$count     = (int) $event['reg_count'];
		$threshold = max( 0, (int) $atts['threshold'] );

		if ( $count < $threshold ) {
			return '';
		}

		$event_hs_id = (string) $event['hs_id'];

		return sprintf(
			'<p class="eex-reg-counter" data-eex-counter="%s" data-eex-threshold="%d"><span class="eex-reg-count">%s</span> %s</p>',
			esc_attr( $event_hs_id ),
			(int) $threshold,
			esc_html( number_format_i18n( $count ) ),
			esc_html__( 'people registered', 'emailexpert-events' )
		);
	}

	/**
	 * Sticky register bar: a slim always-reachable CTA. Server-rendered in
	 * normal flow (the no-JS presentation); eex-time.js pins it and reveals
	 * it after the scroll offset, and remembers a dismissal for the session.
	 * The button reuses the whole register stack — external override, Woo,
	 * coupon, and the ticket drawer in panel mode — and, wrapped in session
	 * attributes, flips to "Join now" while a session is live.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_register_bar( array $atts ): string {
		$event = self::repo()->event_summary( (string) $atts['event'] );

		if ( null === $event ) {
			return '';
		}

		$register = self::register_args( $atts );
		$url      = self::ticketing_url( [ 'event_url' => (string) $event['event_url'] ], $register );

		if ( '' === $url ) {
			return '';
		}

		$drawer = self::ticket_drawer( $atts );
		$text   = '' !== (string) $atts['text'] ? (string) $atts['text'] : (string) $event['title'];
		$label  = '' !== (string) $atts['register_text'] ? (string) $atts['register_text'] : __( 'Get tickets', 'emailexpert-events' );

		// The next (or current) session powers the live flip and countdown.
		$next      = ! empty( $atts['show_live'] ) ? self::repo()->current_and_next(
			[
				'event' => (string) $atts['event'],
				'limit' => 1,
			]
		) : [];
		$countdown = ! empty( $atts['show_countdown'] ) ? self::render_countdown(
			[
				'talk'  => '',
				'event' => (string) $atts['event'],
				// The bar already names the event; a labelled countdown
				// would say it twice when nothing upcoming carries a title.
				'bare'  => 1,
			]
		) : '';

		$id = 'eex-bar-' . substr( md5( wp_json_encode( [ $atts, (string) $event['hs_id'] ] ) ), 0, 8 );

		ob_start();
		TemplateLoader::part(
			'register-bar',
			[
				'id'          => $id,
				'text'        => $text,
				'label'       => $label,
				'url'         => $url,
				'position'    => (string) $atts['position'],
				'offset'      => max( 0, (int) $atts['offset'] ),
				'dismissible' => ! empty( $atts['dismissible'] ),
				'countdown'   => $countdown,
				'session'     => ! empty( $next ) ? $next[0] : [],
				'drawer_id'   => $drawer['id'],
			]
		);

		return (string) ob_get_clean() . $drawer['html'];
	}

	/**
	 * Inline registration form: the drawer's free-ticket form as a placeable
	 * component. Events selling paid tickets only degrade to a checkout CTA
	 * (payment can only happen on the platform); no tickets at all shows the
	 * empty state.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_register_inline( array $atts ): string {
		$event    = self::repo()->event_summary( (string) $atts['event'] );
		$event_id = null !== $event ? (string) $event['hs_id'] : '';
		$tickets  = self::repo()->tickets( $atts );

		if ( '' === $event_id || empty( $tickets ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$wanted = (string) $atts['ticket'];
		$free   = null;

		foreach ( $tickets as $ticket ) {
			if ( ! empty( $ticket['is_paid'] ) ) {
				continue;
			}
			if ( '' === $wanted || (string) $ticket['id'] === $wanted ) {
				$free = $ticket;
				break;
			}
		}

		$heading = '' !== (string) $atts['heading']
			? sprintf( '<h3 class="eex-reg-inline-heading">%s</h3>', esc_html( (string) $atts['heading'] ) )
			: '';

		$event_title = null !== $event ? (string) ( $event['title'] ?? '' ) : '';

		if ( null === $free ) {
			// Paid-only event: a working checkout button beats a dead form —
			// but a bare button explains nothing, so say why there is no form.
			$paid = $tickets[0];
			$url  = self::ticket_register_url( $paid, self::register_args( $atts ) );

			if ( '' === $url ) {
				return self::empty_state( (string) $atts['empty_text'] );
			}

			$context = '' !== $event_title
				? sprintf(
					/* translators: %s: event title. */
					esc_html__( 'Tickets for %s are paid — checkout happens on the event site.', 'emailexpert-events' ),
					esc_html( $event_title )
				)
				: esc_html__( 'Tickets for this event are paid — checkout happens on the event site.', 'emailexpert-events' );

			return sprintf(
				'%s<p class="eex-reg-inline-context">%s</p><p class="eex-reg-inline-cta"><a class="eex-cta eex-cta-register" href="%s">%s</a></p>',
				$heading, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				$context, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				esc_url( $url ),
				esc_html( '' !== (string) $atts['register_text'] ? (string) $atts['register_text'] : __( 'Get tickets', 'emailexpert-events' ) )
			);
		}

		$price_id = '';
		foreach ( (array) $free['prices'] as $price ) {
			if ( '' !== (string) ( $price['id'] ?? '' ) ) {
				$price_id = (string) $price['id'];
				break;
			}
		}

		ob_start();
		TemplateLoader::part(
			'register-form',
			[
				'event_id'    => $event_id,
				'ticket_id'   => (string) $free['id'],
				'price_id'    => $price_id,
				'submit_text' => (string) $atts['register_text'],
				'hidden'      => false,
			]
		);

		// Without context the bare form never says WHAT it registers for;
		// name the event and the (free) ticket unless the operator supplied
		// their own heading, which is assumed to do that job.
		$context = '';
		if ( '' === (string) $atts['heading'] && '' !== $event_title ) {
			$context = sprintf(
				'<p class="eex-reg-inline-context">%s</p>',
				sprintf(
					/* translators: 1: event title, 2: ticket title. */
					esc_html__( 'Free registration for %1$s — %2$s.', 'emailexpert-events' ),
					esc_html( $event_title ),
					esc_html( (string) ( $free['title'] ?? __( 'free ticket', 'emailexpert-events' ) ) )
				)
			);
		}

		return sprintf( '%s%s<div class="eex-reg-inline">%s</div>', $heading, $context, (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped parts.
	}

	/**
	 * Event stats strip: social proof from numbers the plugin already has.
	 * Each item is a stat slug, optionally relabelled with a colon
	 * (speakers:Experts), or an operator-supplied number with its label
	 * (1200:Subscribers) for figures the API cannot know. A stat that is
	 * zero or unavailable renders nothing — "0 speakers" sells no tickets —
	 * and a strip with no stats renders empty.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_stats( array $atts ): string {
		$event = self::repo()->event_summary( (string) $atts['event'] );

		if ( null === $event ) {
			return '';
		}

		$wanted = array_values( array_filter( array_map( 'trim', explode( ',', (string) $atts['items'] ) ) ) );

		if ( empty( $wanted ) ) {
			return '';
		}

		$talks = null;
		$lazy  = function () use ( &$talks, $atts ): array {
			if ( null === $talks ) {
				$talks = array_merge(
					self::repo()->upcoming_talks( $atts + [ 'limit' => 0 ] ),
					self::repo()->past_talks( $atts + [ 'limit' => 0 ] )
				);
			}

			return $talks;
		};

		$values = [];

		foreach ( $wanted as $item ) {
			[ $slug, $label ] = array_pad( explode( ':', $item, 2 ), 2, '' );
			$slug             = trim( $slug );
			$label            = trim( $label );

			// An operator-supplied figure: "1200:Subscribers".
			if ( preg_match( '/^\d+$/', $slug ) ) {
				if ( '' !== $label ) {
					$values[] = [ (int) $slug, $label ];
				}
				continue;
			}

			switch ( $slug ) {
				case 'speakers':
					$values[] = [ self::repo()->speakers_total( $atts ), '' !== $label ? $label : __( 'Speakers', 'emailexpert-events' ) ];
					break;
				case 'sessions':
					$values[] = [ count( $lazy() ), '' !== $label ? $label : __( 'Sessions', 'emailexpert-events' ) ];
					break;
				case 'days':
					$days     = array_unique( array_column( self::group_rows_by_day( $lazy(), 'Y-m-d' ), 'day' ) );
					$values[] = [ count( $days ), '' !== $label ? $label : __( 'Days', 'emailexpert-events' ) ];
					break;
				case 'categories':
					$values[] = [ count( self::repo()->categories( $atts ) ), '' !== $label ? $label : __( 'Topics', 'emailexpert-events' ) ];
					break;
				case 'registered':
					$values[] = [ (int) ( $event['reg_count'] ?? 0 ), '' !== $label ? $label : __( 'Registered', 'emailexpert-events' ) ];
					break;
				case 'members':
					// This site's community: every registered user, unless the
					// operator narrows it (e.g. to a membership role).
					$count = function_exists( 'count_users' ) ? (int) ( count_users()['total_users'] ?? 0 ) : 0;

					/**
					 * The figure behind the "members" stat.
					 *
					 * @param int $count Registered-user count.
					 */
					$count    = (int) apply_filters( 'eex_stats_members', $count );
					$values[] = [ $count, '' !== $label ? $label : __( 'Members', 'emailexpert-events' ) ];
					break;
			}
		}

		$values = array_filter( $values, static fn( array $stat ): bool => $stat[0] > 0 );

		if ( empty( $values ) ) {
			return '';
		}

		$animate = ! empty( $atts['animate'] );

		ob_start();
		echo '<ul class="eex-stats" role="list">';
		foreach ( $values as [ $count, $label ] ) {
			printf(
				'<li class="eex-stat"><span class="eex-stat-number"%s>%s</span> <span class="eex-stat-label">%s</span></li>',
				$animate ? ' data-eex-countup="' . (int) $count . '"' : '',
				esc_html( number_format_i18n( (int) $count ) ),
				esc_html( $label )
			);
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Replay gallery: the post-event content library. Past sessions with a
	 * replay URL (manual `_eex_replay_url` still beats the synced value —
	 * the existing rule), plus optionally the ones HeySummit has flagged
	 * replay_planned, badged "Replay soon" until the URL exists.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_replay_gallery( array $atts ): string {
		$items = array_values(
			array_filter(
				self::repo()->past_talks( $atts + [ 'limit' => 0 ] ),
				static function ( array $data ) use ( $atts ): bool {
					if ( '' !== (string) ( $data['replay_url'] ?? '' ) ) {
						return true;
					}

					return ! empty( $atts['show_soon'] ) && ! empty( $data['replay_soon'] );
				}
			)
		);

		$limit = max( 0, (int) $atts['limit'] );
		if ( $limit > 0 ) {
			$items = array_slice( $items, 0, $limit );
		}

		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$columns = min( 6, max( 0, (int) $atts['columns'] ) );
		$style   = $columns > 0 ? sprintf( ' style="--eex-columns:%d"', $columns ) : '';

		ob_start();
		printf( '<ul class="eex-grid eex-replay-grid" role="list"%s>', $style ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from an integer above.
		foreach ( $items as $data ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				'replay-card',
				[
					'data'          => $data,
					'link'          => (string) $atts['link'],
					'show_speakers' => ! empty( $atts['show_speakers'] ),
					'show_image'    => ! empty( $atts['show_image'] ),
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Featured session card: one session — hand-picked or the next upcoming
	 * — with its physical location given equal billing to the programme
	 * details: stage/venue line, "In person" badge, and optionally the
	 * event venue's address with a directions link. Two views: a wide
	 * feature card and a compact sidebar card.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_featured_session( array $atts ): string {
		$ref  = (string) $atts['talk'];
		$data = null;

		if ( '' !== $ref ) {
			$data = self::repo()->talk( $ref );
		} else {
			$next = self::repo()->upcoming_talks(
				[
					'event' => (string) $atts['event'],
					'limit' => 1,
				]
			);
			$data = $next[0] ?? null;
		}

		if ( null === $data ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		self::$schema_pool[] = [
			'type' => 'talk',
			'data' => $data,
		];

		// The event venue address (the venue component's data) joins the
		// card when requested: Full mode carries the full address, Lite
		// knows the venue name only.
		$address = [];
		$map_url = '';

		if ( ! empty( $atts['show_address'] ) ) {
			[ 'lines' => $address, 'map_url' => $map_url ] = self::event_address( $data );
		}

		ob_start();
		TemplateLoader::part(
			'featured-session',
			[
				'data'          => $data,
				'view'          => (string) $atts['view'],
				'show'          => self::show_flags( $atts ) + [ 'description' => ! empty( $atts['show_description'] ) ],
				'address'       => $address,
				'map_url'       => $map_url,
				'buttons'       => (string) $atts['buttons'],
				'register_text' => (string) $atts['register_text'],
				'session_text'  => (string) $atts['session_text'],
				'register'      => self::register_args( $atts ),
			]
		);

		return (string) ob_get_clean();
	}

	/**
	 * Venue card (Full mode): the operator-owned `_eex_venue_*` meta — the
	 * same fields Event schema publishes — finally gets a front-end surface.
	 * The map link is a link, not an embed: no third-party iframe weight.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_venue( array $atts ): string {
		$event   = self::repo()->event_summary( (string) $atts['event'] );
		$post_id = null !== $event ? (int) ( $event['id'] ?? 0 ) : 0;

		// Three data sources, most explicit first: what the operator typed
		// into the widget, the event post's venue fields (Full mode), the
		// venue the API serialised (the only Lite source).
		$fields = [];
		foreach ( [ 'name', 'street', 'locality', 'postcode', 'country' ] as $field ) {
			$manual           = trim( (string) $atts[ $field ] );
			$fields[ $field ] = '' !== $manual
				? $manual
				: ( $post_id > 0 ? (string) get_post_meta( $post_id, '_eex_venue_' . $field, true ) : '' );
		}

		$name  = $fields['name'];
		$lines = array_values(
			array_filter(
				[
					$fields['street'],
					trim( $fields['locality'] . ' ' . $fields['postcode'] ),
					$fields['country'],
				]
			)
		);

		if ( '' === $name && null !== $event ) {
			$name = (string) ( $event['venue'] ?? '' );
		}
		if ( empty( $lines ) && null !== $event ) {
			$lines = array_map( 'strval', (array) ( $event['venue_address'] ?? [] ) );
		}

		if ( '' === $name && empty( $lines ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$map_query = implode( ', ', array_filter( array_merge( [ $name ], $lines ) ) );
		$map_url   = ! empty( $atts['show_map_link'] ) && '' !== $map_query
			? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $map_query )
			: '';

		ob_start();
		TemplateLoader::part(
			'venue-card',
			[
				'heading'   => '' !== (string) $atts['heading'] ? (string) $atts['heading'] : __( 'Venue', 'emailexpert-events' ),
				'name'      => ! empty( $atts['show_name'] ) ? $name : '',
				'lines'     => ! empty( $atts['show_address'] ) ? $lines : [],
				'map_url'   => $map_url,
				'image_url' => esc_url_raw( (string) $atts['image'] ),
			]
		);

		return (string) ob_get_clean();
	}
}
