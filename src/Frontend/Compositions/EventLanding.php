<?php
/**
 * Event Landing Page composition.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Compositions;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Selection\Diagnostics;
use Emailexpert\Events\Frontend\Selection\EventSelector;
use Emailexpert\Events\Frontend\Selection\FeatureTargetResolver;
use Emailexpert\Events\Frontend\TemplateLoader;
use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A complete event website in one component: hero, status notice, stats,
 * introduction, featured sessions, speakers, schedule, tickets, venue,
 * sponsors, replays, More Events and a final CTA — every content section
 * rendered through the existing component renderers (Components::partial),
 * so no business logic is forked. Section order is one canonical
 * whitelisted attribute; sections with no usable data hide themselves.
 *
 * The page transforms across the lifecycle: before the event registration
 * and programme lead; while live the programme leads; afterwards replays
 * and resources lead and dead registration surfaces are dropped (automatic
 * mode — the operator may keep the original layout).
 */
final class EventLanding {

	/**
	 * The canonical section keys, in default order.
	 */
	public const SECTIONS = [ 'hero', 'status', 'stats', 'intro', 'sessions', 'speakers', 'schedule', 'tickets', 'venue', 'sponsors', 'replays', 'more_events', 'final_cta' ];

	/**
	 * Render the composition.
	 *
	 * @param array<string,mixed> $atts Sanitised attributes.
	 * @return string HTML.
	 */
	public static function render( array $atts ): string {
		$event = self::resolve_event( $atts );

		if ( null === $event ) {
			return Components::empty_state( (string) $atts['empty_text'] );
		}

		// One feature target drives the hero, the CTAs, the media and the
		// lifecycle for the whole page.
		$target = FeatureTargetResolver::resolve(
			[
				'featured_source'      => 'manual_event',
				'featured_event'       => (string) $event['identity'],
				'event_presentation'   => (string) $atts['hero_presentation'],
				'presentation_session' => (string) $atts['hero_session'],
				'selection_strategy'   => 'chronological',
				'pin_duration'         => 'never',
				'pin_expiry_action'    => 'keep',
			]
		);

		if ( null === $target ) {
			return Components::empty_state( (string) $atts['empty_text'] );
		}

		$lifecycle = (array) $target['lifecycle'];
		$event_ref = (string) ( $event['hs_id'] ?: (string) ( $event['id'] ?? '' ) );

		// Replay availability, resolved once (feeds the lifecycle-aware CTA
		// and the post-event transformation).
		$has_replays = self::has_replays( $event_ref );

		if ( $has_replays && empty( $lifecycle['replay_available'] ) && null === $target['session'] ) {
			$lifecycle['replay_available'] = true;

			if ( 'ended' === (string) $lifecycle['status'] ) {
				$lifecycle['status'] = 'replay';
			}

			$target['lifecycle'] = $lifecycle;
		}

		$sections = self::sections( $atts, $lifecycle );

		$replay_anchor = in_array( 'replays', $sections, true ) && $has_replays ? '#eex-landing-replays' : '';

		$cta = CtaResolver::resolve(
			$target,
			$atts,
			[
				'check_tickets' => true,
				'replay_anchor' => $replay_anchor,
			]
		);

		$media = MediaResolver::resolve( $target, (string) $atts['hero_media'] );

		$commerce = CtaResolver::commerce_atts( $atts, $event );

		// The interaction the existing registration system resolved ('auto'
		// becomes 'form' or 'panel' there); the same value flows into the
		// child components below, so the whole landing page follows one
		// answer from one system.
		$commerce['register_action'] = (string) ( $cta['register_action'] ?? $commerce['register_action'] ?? 'link' );

		if ( 'auto' === $commerce['register_action'] ) {
			// A closed lifecycle never resolved the interaction; the classic
			// default applies (registration is not on offer anyway).
			$commerce['register_action'] = 'link';
		}

		$atts['register_action'] = $commerce['register_action'];

		if ( null !== $target['session'] ) {
			$commerce['drawer_talk'] = (string) ( $target['session']['hs_id'] ?? '' );
		}

		$drawer = [
			'id'   => '',
			'html' => '',
		];

		if ( ! empty( $cta['primary']['drawer'] ) ) {
			$drawer = Components::ticket_drawer( $commerce );
		}

		$page_context = 'page' === (string) $atts['heading_context'];
		$section_tag  = $page_context ? 'h2' : 'h3';

		ob_start();

		printf(
			'<div class="eex-el eex-el--%s" data-eex-component="event-landing" data-eex-event-id="%s" data-eex-lifecycle="%s">',
			esc_attr( (string) $atts['hero_style'] ),
			esc_attr( (string) ( $event['hs_id'] ?? '' ) ),
			esc_attr( (string) $lifecycle['status'] )
		);

		foreach ( $sections as $section ) {
			self::section( $section, $atts, $event, $target, $cta, $media, $drawer, $event_ref, $section_tag, $page_context );
		}

		echo '</div>';

		$html = (string) ob_get_clean() . $drawer['html'];

		// Inline Event schema in Lite only; Full single-event pages keep the
		// existing schema integration (no duplication from a composition).
		$html .= CompositionSchema::inline( $target );

		return $html;
	}

	/**
	 * Resolve the event from the source attribute.
	 *
	 * @param array<string,mixed> $atts Attributes.
	 * @return array<string,mixed>|null Enriched event.
	 */
	private static function resolve_event( array $atts ): ?array {
		$source = (string) $atts['event_source'];

		if ( 'manual' === $source ) {
			$event = EventSelector::find_event( (string) $atts['event'] );

			if ( null === $event ) {
				Diagnostics::note( __( 'The selected event could not be resolved; nothing to render.', 'emailexpert-events' ) );
			}

			return $event;
		}

		if ( 'current' === $source ) {
			$contextual = self::current_context_event();

			if ( null !== $contextual ) {
				return $contextual;
			}

			Diagnostics::note( __( 'No event in the current context; falling back to the next eligible event.', 'emailexpert-events' ) );
		}

		return EventSelector::auto_target( 'chronological' );
	}

	/**
	 * The event of the current view: the event being viewed, or the owning
	 * event of the session being viewed. Null elsewhere (Lite mode has no
	 * event pages, so context only ever resolves in Full).
	 *
	 * @return array<string,mixed>|null
	 */
	private static function current_context_event(): ?array {
		if ( Options::is_lite() || ! function_exists( 'get_queried_object' ) ) {
			return null;
		}

		$queried = get_queried_object();

		// Duck-typed: a post-like object with a type and an ID (WP_Post in
		// real WordPress; the stub layer has no such class).
		if ( ! is_object( $queried ) || ! isset( $queried->post_type, $queried->ID ) ) {
			return null;
		}

		if ( PostTypes::EVENT === (string) $queried->post_type ) {
			return EventSelector::find_event( (string) $queried->ID );
		}

		if ( PostTypes::TALK === (string) $queried->post_type ) {
			$owner = (string) get_post_meta( (int) $queried->ID, '_eex_source_event_id', true );

			return '' !== $owner ? EventSelector::find_event( $owner ) : null;
		}

		return null;
	}

	/**
	 * Whether the event has at least one replay.
	 *
	 * @param string $event_ref Event reference.
	 */
	private static function has_replays( string $event_ref ): bool {
		foreach ( Repositories::current()->past_talks(
			[
				'event' => $event_ref,
				'limit' => 0,
			]
		) as $talk ) {
			if ( '' !== (string) ( $talk['replay_url'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The section order: the canonical whitelisted attribute sanitised
	 * (unknown removed, duplicates collapsed, order preserved), the status
	 * notice re-appended when a notice exists (a cancelled or postponed
	 * event must never render as merely scheduled), and the lifecycle
	 * transformation applied in automatic mode.
	 *
	 * @param array<string,mixed> $atts      Attributes.
	 * @param array<string,mixed> $lifecycle Lifecycle reading.
	 * @return string[]
	 */
	public static function sections( array $atts, array $lifecycle ): array {
		$wanted = [];

		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $atts['sections'] ) ) ) as $key ) {
			$key = strtolower( $key );

			if ( in_array( $key, self::SECTIONS, true ) && ! in_array( $key, $wanted, true ) ) {
				$wanted[] = $key;
			}
		}

		if ( empty( $wanted ) ) {
			$wanted = self::SECTIONS;
		}

		// A live status notice is required content while one exists.
		if ( '' !== (string) ( $lifecycle['notice'] ?? '' ) || in_array( (string) $lifecycle['status'], [ 'cancelled', 'postponed' ], true ) ) {
			if ( ! in_array( 'status', $wanted, true ) ) {
				$position = in_array( 'hero', $wanted, true ) ? (int) array_search( 'hero', $wanted, true ) + 1 : 0;
				array_splice( $wanted, $position, 0, [ 'status' ] );
			}
		}

		if ( 'keep' === (string) $atts['post_event'] ) {
			return $wanted;
		}

		$status = (string) $lifecycle['status'];

		if ( in_array( $status, [ 'ended', 'replay' ], true ) ) {
			// Post-event: replays and resources lead; dead registration
			// surfaces (tickets, the forward-looking schedule and session
			// promos) drop out. Disabled sections stay disabled.
			$post_order = [ 'hero', 'status', 'replays', 'intro', 'stats', 'speakers', 'sponsors', 'more_events', 'final_cta' ];

			return array_values( array_intersect( $post_order, $wanted ) );
		}

		if ( 'live' === $status ) {
			// While live the programme leads.
			$live_order = [ 'hero', 'status', 'sessions', 'schedule', 'stats', 'intro', 'speakers', 'tickets', 'venue', 'sponsors', 'replays', 'more_events', 'final_cta' ];

			return array_values( array_intersect( $live_order, $wanted ) );
		}

		return $wanted;
	}

	/**
	 * Render one section. Sections whose partial comes back empty (the
	 * eex-empty marker or nothing at all) render nothing — no heading, no
	 * gap.
	 *
	 * @param string                        $section      Section key.
	 * @param array<string,mixed>           $atts         Attributes.
	 * @param array<string,mixed>           $event        Enriched event.
	 * @param array<string,mixed>           $target       Feature target.
	 * @param array<string,mixed>           $cta          CTA view model.
	 * @param array<string,mixed>           $media        Resolved media.
	 * @param array{id:string,html:string}  $drawer       Ticket drawer.
	 * @param string                        $event_ref    Repository event reference.
	 * @param string                        $section_tag  Heading tag for section titles.
	 * @param bool                          $page_context Whether the hero owns the page H1.
	 */
	private static function section( string $section, array $atts, array $event, array $target, array $cta, array $media, array $drawer, string $event_ref, string $section_tag, bool $page_context ): void {
		switch ( $section ) {
			case 'hero':
				TemplateLoader::part(
					'landing-hero',
					[
						'target'         => $target,
						'event'          => $event,
						'cta'            => $cta,
						'media'          => $media,
						'drawer'         => $drawer['id'],
						'rsvp'           => (array) $cta['rsvp_context'],
						'heading_tag'    => $page_context ? 'h1' : 'h2',
						'style'          => (string) $atts['hero_style'],
						'show_countdown' => ! empty( $atts['show_countdown'] ),
						'reg_count_html' => ! empty( $atts['show_reg_count'] ) ? Components::partial( 'reg-counter', [ 'event' => $event_ref ] ) : '',
					]
				);
				break;

			case 'status':
				TemplateLoader::part(
					'landing-status',
					[
						'lifecycle' => (array) $target['lifecycle'],
						'event'     => $event,
					]
				);
				break;

			case 'stats':
				self::wrapped_section( 'stats', '', '', Components::partial( 'stats', [ 'event' => $event_ref, 'items' => (string) $atts['stats_items'] ] ), $section_tag ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				break;

			case 'intro':
				self::wrapped_section( 'intro', self::title( $atts, 'intro_title', __( 'About this event', 'emailexpert-events' ) ), '', self::intro_html( $event ), $section_tag );
				break;

			case 'sessions':
				$ids  = trim( (string) $atts['sessions_ids'] );
				$part = '' !== $ids
					? Components::partial(
						'featured-talks',
						self::pass_cta(
							$atts,
							[
								'ids'        => $ids,
								'event'      => $event_ref,
								'show_image' => 1,
							]
						)
					)
					: Components::partial(
						'upcoming-sessions',
						self::pass_cta(
							$atts,
							[
								'event'      => $event_ref,
								'limit'      => max( 1, (int) $atts['sessions_limit'] ),
								'show_image' => 1,
								'hide_empty' => 1,
							]
						)
					);
				self::wrapped_section( 'sessions', self::title( $atts, 'sessions_title', __( 'Featured sessions', 'emailexpert-events' ) ), __( 'Programme', 'emailexpert-events' ), $part, $section_tag );
				break;

			case 'speakers':
				$part = Components::partial(
					'speakers',
					[
						'event' => $event_ref,
						'limit' => max( 0, (int) $atts['speakers_limit'] ),
					]
				);
				self::wrapped_section( 'speakers', self::title( $atts, 'speakers_title', __( 'Speakers', 'emailexpert-events' ) ), __( 'Who you will hear from', 'emailexpert-events' ), $part, $section_tag );
				break;

			case 'schedule':
				$part = Components::partial(
					'schedule',
					[
						'event'   => $event_ref,
						'day_nav' => 1,
					]
				);
				self::wrapped_section( 'schedule', self::title( $atts, 'schedule_title', __( 'Schedule', 'emailexpert-events' ) ), __( 'Plan your time', 'emailexpert-events' ), $part, $section_tag );
				break;

			case 'tickets':
				$part = Components::partial(
					'pricing',
					self::pass_cta(
						$atts,
						[
							'event' => $event_ref,
						]
					)
				);
				self::wrapped_section( 'tickets', self::title( $atts, 'tickets_title', __( 'Tickets', 'emailexpert-events' ) ), __( 'Be there', 'emailexpert-events' ), $part, $section_tag );
				break;

			case 'venue':
				$part = Components::partial( 'venue', [ 'event' => $event_ref, 'hide_empty' => 1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				self::wrapped_section( 'venue', self::title( $atts, 'venue_title', __( 'Venue', 'emailexpert-events' ) ), '', $part, $section_tag );
				break;

			case 'sponsors':
				$part = Components::partial( 'sponsors', [ 'event' => $event_ref, 'hide_empty' => 1 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				self::wrapped_section( 'sponsors', self::title( $atts, 'sponsors_title', __( 'Sponsors', 'emailexpert-events' ) ), '', $part, $section_tag );
				break;

			case 'replays':
				$part = Components::partial(
					'replay-gallery',
					[
						'event'      => $event_ref,
						'limit'      => max( 0, (int) $atts['replays_limit'] ),
						'show_image' => 1,
					]
				);
				self::wrapped_section( 'replays', self::title( $atts, 'replays_title', __( 'Watch the sessions', 'emailexpert-events' ) ), __( 'On demand', 'emailexpert-events' ), $part, $section_tag, 'eex-landing-replays' );
				break;

			case 'more_events':
				$more = EventSelector::more_events( (string) $atts['more_events_mode'], max( 0, (int) $atts['more_events_limit'] ), $event );

				if ( empty( $more ) ) {
					break;
				}

				ob_start();
				echo '<ul class="eex-hh__more-list" role="list">';
				foreach ( $more as $row ) {
					echo '<li>';
					TemplateLoader::part( 'compact-event-row', [ 'event' => $row ] );
					echo '</li>';
				}
				echo '</ul>';
				self::wrapped_section( 'more-events', self::title( $atts, 'more_events_title', __( 'More from emailexpert', 'emailexpert-events' ) ), __( 'Next from emailexpert', 'emailexpert-events' ), (string) ob_get_clean(), $section_tag );
				break;

			case 'final_cta':
				TemplateLoader::part(
					'landing-cta',
					[
						'target'      => $target,
						'event'       => $event,
						'cta'         => $cta,
						'drawer'      => $drawer['id'],
						'rsvp'        => (array) $cta['rsvp_context'],
						'title'       => self::title( $atts, 'cta_title', '' ),
						'heading_tag' => $section_tag,
					]
				);
				break;
		}
	}

	/**
	 * A section wrapper with an optional eyebrow and heading — emitted only
	 * when the inner content has substance. The empty-state marker from a
	 * nested component means "no usable data": the section hides itself
	 * rather than leaving a heading over a gap.
	 *
	 * @param string $key     Section key (class suffix).
	 * @param string $title   Heading ('' = none).
	 * @param string $eyebrow Eyebrow label ('' = none).
	 * @param string $inner   Inner HTML.
	 * @param string $tag     Heading tag.
	 * @param string $id      Optional anchor id.
	 */
	private static function wrapped_section( string $key, string $title, string $eyebrow, string $inner, string $tag, string $id = '' ): void {
		if ( '' === trim( $inner ) || str_contains( $inner, 'eex-empty' ) ) {
			return;
		}

		printf(
			'<section class="eex-el__section eex-el__section--%s"%s>',
			esc_attr( $key ),
			'' !== $id ? ' id="' . esc_attr( $id ) . '"' : ''
		);

		if ( '' !== $eyebrow || '' !== $title ) {
			echo '<header class="eex-el__heading">';
			if ( '' !== $eyebrow ) {
				echo '<p class="eex-comp-eyebrow eex-eyebrow">' . esc_html( $eyebrow ) . '</p>';
			}
			if ( '' !== $title ) {
				printf( '<%1$s class="eex-el__title">%2$s</%1$s>', esc_attr( $tag ), esc_html( $title ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
			}
			echo '</header>';
		}

		echo $inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- component output escaped at build time.
		echo '</section>';
	}

	/**
	 * A section title override, or its default.
	 *
	 * @param array<string,mixed> $atts     Attributes.
	 * @param string              $key      Attribute key.
	 * @param string              $fallback Default title.
	 */
	private static function title( array $atts, string $key, string $fallback ): string {
		$title = trim( (string) ( $atts[ $key ] ?? '' ) );

		return '' !== $title ? $title : $fallback;
	}

	/**
	 * The introduction: the presentation's promotional intro, then the
	 * event description (sync-owned in Full, normalised in Lite), then —
	 * Full mode — the event post's editor-owned content. The pieces stack;
	 * none overwrites another.
	 *
	 * @param array<string,mixed> $event Enriched event.
	 */
	private static function intro_html( array $event ): string {
		$pieces = [];

		$intro = trim( (string) ( $event['presentation']['intro'] ?? '' ) );
		if ( '' !== $intro ) {
			$pieces[] = '<div class="eex-el__intro-promo">' . wp_kses_post( wpautop( $intro ) ) . '</div>';
		}

		$description = trim( (string) ( $event['description'] ?? '' ) );
		if ( '' !== $description ) {
			$pieces[] = '<div class="eex-el__intro-description">' . wp_kses_post( wpautop( $description ) ) . '</div>';
		}

		$post_id = (int) ( $event['id'] ?? 0 );
		if ( $post_id > 0 && ! Options::is_lite() ) {
			$content = trim( (string) get_post_field( 'post_content', $post_id ) );

			if ( '' !== $content ) {
				$pieces[] = '<div class="eex-el__intro-content">' . wp_kses_post( wpautop( $content ) ) . '</div>';
			}
		}

		return implode( '', $pieces );
	}

	/**
	 * Pass the shared CTA/commerce attributes through to a nested component
	 * so registration behaves identically in every section.
	 *
	 * @param array<string,mixed> $atts      Composition attributes.
	 * @param array<string,mixed> $overrides Section-specific attributes.
	 * @return array<string,mixed>
	 */
	private static function pass_cta( array $atts, array $overrides ): array {
		$shared = [];

		foreach ( [ 'buttons', 'register_text', 'session_text', 'register_url', 'register_action', 'buy_on', 'coupon', 'currency', 'tickets', 'exclude' ] as $key ) {
			if ( isset( $atts[ $key ] ) ) {
				$shared[ $key ] = $atts[ $key ];
			}
		}

		return array_merge( $shared, $overrides );
	}
}
