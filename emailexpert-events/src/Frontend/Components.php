<?php
/**
 * Display components.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Repository;
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
					'id'   => $speaker_id,
					'name' => (string) $speaker->post_title,
					'url'  => (string) get_permalink( $speaker_id ),
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

		ob_start();
		echo '<ul class="eex-grid eex-talk-grid" role="list">';
		foreach ( $items as $data ) {
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
		$html = self::talk_cards( self::repo()->upcoming_talks( $atts ), $atts, 'upcoming' );

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

		ob_start();
		echo '<ul class="eex-grid eex-event-grid" role="list">';
		foreach ( $items as $event ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part(
				'card-event',
				[
					'event'   => $event,
					'context' => $context,
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
		$items = self::repo()->speakers( $atts );

		if ( empty( $items ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		$columns = min( 6, max( 1, (int) $atts['columns'] ) );

		ob_start();
		printf( '<ul class="eex-grid eex-speaker-grid" style="--eex-columns:%d" role="list">', (int) $columns );
		foreach ( $items as $speaker ) {
			echo '<li class="eex-grid-item">';
			TemplateLoader::part( 'card-speaker', [ 'speaker' => $speaker ] );
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
	 * Sponsors wall grouped by tier.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 */
	private static function render_sponsors( array $atts ): string {
		// Group by tier in tier order.
		$tiers = [];
		foreach ( self::repo()->sponsors( $atts ) as $sponsor ) {
			$tiers[ (int) $sponsor['tier_order'] . '|' . (string) $sponsor['tier_name'] ][] = $sponsor;
		}

		if ( empty( $tiers ) ) {
			return self::empty_state( (string) $atts['empty_text'] );
		}

		ksort( $tiers );

		ob_start();
		foreach ( $tiers as $key => $tier_sponsors ) {
			[ , $tier_name ] = explode( '|', $key, 2 );
			echo '<section class="eex-sponsor-tier"><h3 class="eex-tier-heading">' . esc_html( $tier_name ) . '</h3><ul class="eex-grid eex-sponsor-grid" role="list">';
			foreach ( $tier_sponsors as $sponsor ) {
				echo '<li class="eex-grid-item">';
				TemplateLoader::part( 'card-sponsor', [ 'sponsor' => $sponsor ] );
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
				printf(
					'<a class="eex-badge" href="%s" data-eex-filter-cat="%s">%s</a> ',
					esc_url( (string) $category['url'] ),
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
}
