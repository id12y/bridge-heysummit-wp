<?php
/**
 * Display components.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * One definition table and one render callback per component, shared by the
 * Gutenberg blocks, the shortcodes and the Elementor widgets. Everything
 * renders from the local database; there is no HTTP in any render path.
 */
final class Components {

	/**
	 * Static per-request cache of event posts by HeySummit ID.
	 *
	 * @var array<string,int>
	 */
	private static array $event_lookup = [];

	/**
	 * The component definition table.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function definitions(): array {
		$empty_sessions = __( 'New sessions are announced soon.', 'emailexpert-events' );
		$empty_events   = __( 'New events are announced soon.', 'emailexpert-events' );

		return [
			'upcoming-sessions' => [
				'title' => __( 'Upcoming sessions', 'emailexpert-events' ),
				'atts'  => [
					'event'          => [
						'type'    => 'string',
						'default' => '',
					],
					'category'       => [
						'type'    => 'string',
						'default' => '',
					],
					'limit'          => [
						'type'    => 'integer',
						'default' => 6,
					],
					'empty_text'     => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
					'show_subscribe' => [
						'type'    => 'integer',
						'default' => 0,
					],
				],
			],
			'past-sessions'     => [
				'title' => __( 'Past sessions', 'emailexpert-events' ),
				'atts'  => [
					'event'      => [
						'type'    => 'string',
						'default' => '',
					],
					'category'   => [
						'type'    => 'string',
						'default' => '',
					],
					'limit'      => [
						'type'    => 'integer',
						'default' => 12,
					],
					'paginate'   => [
						'type'    => 'integer',
						'default' => 1,
					],
					'q'          => [
						'type'     => 'string',
						'default'  => '',
						'from_get' => 'eex_q',
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => __( 'Session replays appear here after each session.', 'emailexpert-events' ),
					],
				],
			],
			'upcoming-events'   => [
				'title' => __( 'Upcoming events', 'emailexpert-events' ),
				'atts'  => [
					'limit'      => [
						'type'    => 'integer',
						'default' => 3,
					],
					'series'     => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => $empty_events,
					],
				],
			],
			'past-events'       => [
				'title' => __( 'Past events', 'emailexpert-events' ),
				'atts'  => [
					'limit'      => [
						'type'    => 'integer',
						'default' => 0,
					],
					'series'     => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text' => [
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
					'event'      => [
						'type'    => 'string',
						'default' => '',
					],
					'category'   => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
			'speakers'          => [
				'title' => __( 'Speaker grid', 'emailexpert-events' ),
				'atts'  => [
					'event'      => [
						'type'    => 'string',
						'default' => '',
					],
					'category'   => [
						'type'    => 'string',
						'default' => '',
					],
					'columns'    => [
						'type'    => 'integer',
						'default' => 4,
					],
					'limit'      => [
						'type'    => 'integer',
						'default' => 0,
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => __( 'Speakers are announced soon.', 'emailexpert-events' ),
					],
				],
			],
			'featured-talks'    => [
				'title' => __( 'Featured talks', 'emailexpert-events' ),
				'atts'  => [
					'event'      => [
						'type'    => 'string',
						'default' => '',
					],
					'ids'        => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => $empty_sessions,
					],
				],
			],
			'sponsors'          => [
				'title' => __( 'Sponsors wall', 'emailexpert-events' ),
				'atts'  => [
					'event'      => [
						'type'    => 'string',
						'default' => '',
					],
					'empty_text' => [
						'type'    => 'string',
						'default' => __( 'Sponsorship opportunities are available.', 'emailexpert-events' ),
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
					'show_search' => [
						'type'    => 'integer',
						'default' => 1,
					],
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
		];
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

		$atts = self::sanitise_atts( $definitions[ $name ]['atts'], $atts );

		Assets::mark_needed();

		// The cache key varies by UTM campaign context: rendered HTML embeds
		// campaign-tagged URLs derived from the rendering page.
		$cache_atts = $atts + [ '_ctx' => Utm::cache_context() ];

		$cached = Cache::get( $name, $cache_atts );
		if ( null !== $cached ) {
			return $cached;
		}

		$method = 'render_' . str_replace( '-', '_', $name );
		$html   = method_exists( self::class, $method ) ? (string) self::$method( $atts ) : '';

		$html = '<div class="eex eex-' . esc_attr( $name ) . '">' . $html . '</div>';

		/**
		 * Filter a component's rendered HTML.
		 *
		 * @param string              $html HTML.
		 * @param string              $name Component name.
		 * @param array<string,mixed> $atts Attributes.
		 */
		$html = (string) apply_filters( 'eex_card_html', $html, $name, $atts );

		Cache::set( $name, $cache_atts, $html );

		return $html;
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
		}

		return $out;
	}

	/**
	 * Assemble the render data for one talk.
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
					'id'   => $speaker_id,
					'name' => (string) $speaker->post_title,
					'url'  => (string) get_permalink( $speaker_id ),
				];
			}
		}

		$categories = get_the_terms( $post_id, Taxonomies::CATEGORY );

		return [
			'id'            => $post_id,
			'title'         => get_the_title( $post_id ),
			'permalink'     => (string) get_permalink( $post_id ),
			'description'   => (string) get_post_meta( $post_id, '_eex_description', true ),
			'starts_at'     => (string) get_post_meta( $post_id, '_eex_starts_at', true ),
			'ends_at'       => (string) get_post_meta( $post_id, '_eex_ends_at', true ),
			'talk_url'      => Utm::tag( (string) get_post_meta( $post_id, '_eex_talk_url', true ) ),
			'replay_url'    => $replay,
			'speakers'      => $speakers,
			'categories'    => is_array( $categories ) ? $categories : [],
			'event_post_id' => $event_post_id,
			'timezone'      => $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_timezone', true ) : '',
			'event_url'     => Utm::tag( $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_event_url', true ) : '' ),
		];
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
	 * @param int[]               $ids     Talk post IDs.
	 * @param array<string,mixed> $atts    Component attributes.
	 * @param string              $context 'upcoming' or 'past'.
	 */
	private static function talk_cards( array $ids, array $atts, string $context ): string {
		if ( empty( $ids ) ) {
			return self::empty_state( (string) ( $atts['empty_text'] ?? '' ) );
		}

		ob_start();
		echo '<ul class="eex-grid eex-talk-grid" role="list">';
		foreach ( $ids as $id ) {
			$data = self::talk_data( $id );

			// Filterable data attributes for the session filter bar.
			printf(
				'<li class="eex-grid-item" data-eex-title="%s" data-eex-cats="%s" data-eex-speakers="%s">',
				esc_attr( strtolower( (string) $data['title'] ) ),
				esc_attr( implode( ',', array_map( static fn( $term ): string => (string) $term->slug, (array) $data['categories'] ) ) ),
				esc_attr( strtolower( implode( ',', array_map( static fn( array $s ): string => (string) $s['name'], (array) $data['speakers'] ) ) ) )
			);
			TemplateLoader::part(
				'card-talk',
				[
					'data'    => $data,
					'context' => $context,
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
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
		$html = self::talk_cards( Query::upcoming_talks( $atts ), $atts, 'upcoming' );

		if ( ! empty( $atts['show_subscribe'] ) ) {
			$feed_url = home_url( '/feeds/eex/calendar.ics' );
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
		$page  = 1;

		if ( ! empty( $atts['paginate'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public pagination.
			$page = isset( $_GET['eex_page'] ) ? max( 1, (int) $_GET['eex_page'] ) : 1;
		}

		$query_atts           = $atts;
		$query_atts['offset'] = ( $page - 1 ) * $limit;

		$html = self::talk_cards( Query::past_talks( $query_atts ), $atts, 'past' );

		if ( ! empty( $atts['paginate'] ) ) {
			$total = Query::past_talks_total( $atts );
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
		$ids = Query::upcoming_events( $atts );

		if ( empty( $ids ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		ob_start();
		echo '<ul class="eex-grid eex-event-grid" role="list">';
		foreach ( $ids as $id ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				'card-event',
				[
					'event_id' => $id,
					'context'  => 'upcoming',
				]
			);
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Past events archive.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_past_events( array $atts ): string {
		$ids = Query::past_events( $atts );

		if ( empty( $ids ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		ob_start();
		echo '<ul class="eex-grid eex-event-grid" role="list">';
		foreach ( $ids as $id ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				'card-event',
				[
					'event_id' => $id,
					'context'  => 'past',
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
			$talk_id = self::resolve_talk( (string) $atts['talk'] );
			if ( $talk_id > 0 ) {
				$data     = self::talk_data( $talk_id );
				$target   = (string) $data['starts_at'];
				$timezone = (string) $data['timezone'];
				$label    = (string) $data['title'];
			}
		} else {
			$event_post_id = Query::resolve_event( (string) $atts['event'] );
			if ( $event_post_id > 0 ) {
				$label    = get_the_title( $event_post_id );
				$timezone = (string) get_post_meta( $event_post_id, '_eex_timezone', true );
				$first    = (string) get_post_meta( $event_post_id, '_eex_first_talk_at', true );

				// For an evergreen hub, count to the next upcoming session instead.
				$next = Query::upcoming_talks(
					[
						'event' => (string) $atts['event'],
						'limit' => 1,
					]
				);
				if ( ! empty( $next ) ) {
					$data   = self::talk_data( $next[0] );
					$target = (string) $data['starts_at'];
					$label  = (string) $data['title'];
				} else {
					$target = $first;
				}
			}
		}

		if ( '' === $target || false === strtotime( $target ) ) {
			return '';
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
	 * Resolve the `talk` attribute (HeySummit ID or post ID).
	 *
	 * @param string $talk Attribute.
	 */
	private static function resolve_talk( string $talk ): int {
		$by_hs = \Emailexpert\Events\Sync\Upserter::find_by_hs_id( PostTypes::TALK, $talk );
		if ( $by_hs > 0 ) {
			return $by_hs;
		}

		$post = get_post( (int) $talk );

		return ( $post && PostTypes::TALK === $post->post_type ) ? (int) $post->ID : 0;
	}

	/**
	 * Schedule grouped by day in event-local time.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_schedule( array $atts ): string {
		$ids = array_merge( Query::upcoming_talks( $atts + [ 'limit' => 0 ] ), Query::past_talks( $atts + [ 'limit' => 0 ] ) );

		if ( empty( $ids ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		// Order every talk chronologically and group by event-local day.
		$rows = [];
		foreach ( $ids as $id ) {
			$data = self::talk_data( $id );
			$ts   = strtotime( (string) $data['starts_at'] );
			if ( false === $ts ) {
				continue;
			}
			$tz    = TimeFormat::timezone( (string) $data['timezone'] );
			$local = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );

			$rows[] = [
				'ts'   => $ts,
				'day'  => $local->format( 'l j F Y' ),
				'data' => $data,
			];
		}

		usort( $rows, static fn( array $a, array $b ): int => $a['ts'] <=> $b['ts'] );

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
				echo '<section class="eex-schedule-day"><h3 class="eex-schedule-heading">' . esc_html( $row['day'] ) . '</h3><ol class="eex-schedule-list" role="list">';
			}

			TemplateLoader::part( 'schedule-row', [ 'data' => $row['data'] ] );
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
		$ids = Query::speakers( $atts );

		if ( empty( $ids ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$columns = min( 6, max( 1, (int) $atts['columns'] ) );

		ob_start();
		printf( '<ul class="eex-grid eex-speaker-grid" style="--eex-columns:%d" role="list">', (int) $columns );
		foreach ( $ids as $id ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part( 'card-speaker', [ 'speaker_id' => $id ] );
			echo '</li>';
		}
		echo '</ul>';

		return (string) ob_get_clean();
	}

	/**
	 * Featured talks by manual selection.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_featured_talks( array $atts ): string {
		$requested = array_filter( array_map( 'trim', explode( ',', (string) $atts['ids'] ) ) );

		$ids = [];
		foreach ( $requested as $ref ) {
			$talk_id = self::resolve_talk( $ref );
			if ( $talk_id > 0 && 'publish' === get_post_status( $talk_id ) ) {
				$ids[] = $talk_id;
			}
		}

		return self::talk_cards( $ids, $atts, 'featured' );
	}

	/**
	 * Sponsors wall grouped by tier.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_sponsors( array $atts ): string {
		$event_post_id = Query::resolve_event( (string) $atts['event'] );

		$sponsors = get_posts(
			[
				'post_type'      => PostTypes::SPONSOR,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		);

		// Group by tier in tier order.
		$tiers = [];
		foreach ( $sponsors as $sponsor ) {
			$sponsor_id = (int) $sponsor->ID;

			if ( $event_post_id > 0 ) {
				$event_ids = array_map( 'intval', (array) get_post_meta( $sponsor_id, '_eex_event_ids', true ) );
				if ( ! empty( $event_ids ) && ! in_array( $event_post_id, $event_ids, true ) ) {
					continue;
				}
			}

			$terms = get_the_terms( $sponsor_id, Taxonomies::TIER );
			$term  = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
			$order = $term ? (int) get_term_meta( (int) $term->term_id, '_eex_tier_order', true ) : 99;
			$name  = $term ? (string) $term->name : __( 'Partner', 'emailexpert-events' );

			$tiers[ $order . '|' . $name ][] = $sponsor_id;
		}

		if ( empty( $tiers ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		ksort( $tiers );

		ob_start();
		foreach ( $tiers as $key => $sponsor_ids ) {
			[ , $tier_name ] = explode( '|', $key, 2 );
			echo '<section class="eex-sponsor-tier"><h3 class="eex-tier-heading">' . esc_html( $tier_name ) . '</h3><ul class="eex-grid eex-sponsor-grid" role="list">';
			foreach ( $sponsor_ids as $sponsor_id ) {
				echo '<li class="eex-grid-item">';
				TemplateLoader::part( 'card-sponsor', [ 'sponsor_id' => $sponsor_id ] );
				echo '</li>';
			}
			echo '</ul></section>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * Session library filter bar: server-rendered category and speaker
	 * links that work without JS; with JS, instant client-side filtering of
	 * the rendered session list.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_session_filter( array $atts ): string {
		$categories  = get_terms(
			[
				'taxonomy'   => Taxonomies::CATEGORY,
				'hide_empty' => false,
			]
		);
		$speaker_ids = Query::speakers( $atts + [ 'limit' => 0 ] );

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

		if ( is_array( $categories ) && ! empty( $categories ) ) {
			echo '<nav class="eex-filter-categories" aria-label="' . esc_attr__( 'Filter by category', 'emailexpert-events' ) . '">';
			foreach ( $categories as $term ) {
				$link = function_exists( 'get_term_link' ) ? get_term_link( $term ) : '';
				printf(
					'<a class="eex-badge" href="%s" data-eex-filter-cat="%s">%s</a> ',
					esc_url( is_string( $link ) ? $link : '' ),
					esc_attr( (string) $term->slug ),
					esc_html( (string) $term->name )
				);
			}
			echo '</nav>';
		}

		if ( ! empty( $speaker_ids ) ) {
			echo '<nav class="eex-filter-speakers" aria-label="' . esc_attr__( 'Filter by speaker', 'emailexpert-events' ) . '">';
			foreach ( $speaker_ids as $speaker_id ) {
				printf(
					'<a class="eex-chip" href="%s" data-eex-filter-speaker="%s">%s</a> ',
					esc_url( (string) get_permalink( $speaker_id ) ),
					esc_attr( strtolower( get_the_title( $speaker_id ) ) ),
					esc_html( get_the_title( $speaker_id ) )
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
		$event_post_id = Query::resolve_event( (string) $atts['event'] );

		if ( 0 === $event_post_id ) {
			return '';
		}

		$count     = (int) get_post_meta( $event_post_id, '_eex_registration_count', true );
		$threshold = max( 0, (int) $atts['threshold'] );

		if ( $count < $threshold ) {
			return '';
		}

		$event_hs_id = (string) get_post_meta( $event_post_id, '_eex_heysummit_id', true );

		return sprintf(
			'<p class="eex-reg-counter" data-eex-counter="%s" data-eex-threshold="%d"><span class="eex-reg-count">%s</span> %s</p>',
			esc_attr( $event_hs_id ),
			(int) $threshold,
			esc_html( number_format_i18n( $count ) ),
			esc_html__( 'people registered', 'emailexpert-events' )
		);
	}
}
