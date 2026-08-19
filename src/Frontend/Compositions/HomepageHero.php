<?php
/**
 * Homepage Editorial Hero composition.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Compositions;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Selection\Diagnostics;
use Emailexpert\Events\Frontend\Selection\EditorialSelector;
use Emailexpert\Events\Frontend\Selection\EventSelector;
use Emailexpert\Events\Frontend\Selection\FeatureTargetResolver;
use Emailexpert\Events\Frontend\TemplateLoader;

defined( 'ABSPATH' ) || exit;

/**
 * The homepage composition: a dominant editorial story, a prominent but
 * compact featured event or session, compact More Events rows and a Latest
 * News strip — one renderer behind the block, the shortcode and the
 * Elementor widget. Layout presets change classes and design tokens only.
 *
 * Data is collected once per render (the editorial selection, the feature
 * target, the More Events list, the CTA model, the media resolution) and
 * handed to template parts; no section rediscovers it.
 */
final class HomepageHero {

	/**
	 * Render the composition.
	 *
	 * @param array<string,mixed> $atts Sanitised attributes.
	 * @return string HTML.
	 */
	public static function render( array $atts ): string {
		$news_on = ! empty( $atts['news_show'] );

		$editorial = EditorialSelector::select( $atts );
		$lead      = 'none' !== (string) $atts['story_source'] ? $editorial['lead'] : null;
		$news      = $news_on ? $editorial['items'] : [];

		// The secondary featured story (two-story frontage). Its placement
		// resolves to a concrete slot here so templates stay declarative.
		$second_placement = (string) $atts['story2_placement'];
		$second           = null !== $lead && 'hidden' !== $second_placement ? ( $editorial['second'] ?? null ) : null;

		if ( 'auto' === $second_placement || ! in_array( $second_placement, [ 'beneath', 'side' ], true ) ) {
			$second_placement = 'beneath';
		}

		$target = FeatureTargetResolver::resolve( $atts );

		$placement = (string) $atts['more_events_placement'];
		$more      = [];

		if ( ! empty( $atts['more_events'] ) && 'hidden' !== $placement ) {
			$mode = (string) $atts['more_events_mode'];

			if ( 'upcoming_sessions' === $mode ) {
				$featured_session = null !== $target && is_array( $target['session'] ?? null ) ? (array) $target['session'] : null;

				$more = EventSelector::more_sessions(
					max( 0, (int) $atts['more_events_limit'] ),
					$featured_session
				);
			} else {
				$more = EventSelector::more_events(
					$mode,
					max( 0, (int) $atts['more_events_limit'] ),
					null !== $target ? (array) $target['event'] : null
				);
			}
		}

		// More Events presentation: speaker and people treatments reuse the
		// session data the page already loads; absent speaker information
		// degrades to the event-only rows.
		$more_presentation = (string) $atts['more_events_presentation'];
		$more_people       = [];

		if ( ! empty( $more ) && in_array( $more_presentation, [ 'speakers', 'auto' ], true ) ) {
			$more = EventSelector::attach_speakers( $more );

			$has_portrait = false;
			foreach ( $more as $row ) {
				foreach ( (array) ( $row['speakers_row'] ?? [] ) as $speaker ) {
					if ( (int) ( $speaker['photo_id'] ?? 0 ) > 0 || '' !== (string) ( $speaker['photo_url'] ?? '' ) ) {
						$has_portrait = true;
						break 2;
					}
				}
			}

			if ( 'auto' === $more_presentation ) {
				$more_presentation = $has_portrait ? 'speakers' : 'events';
			}
		}

		if ( ! empty( $more ) && 'people' === $more_presentation ) {
			// Names already on the featured card never repeat below it.
			$shown = [];
			if ( null !== $target && ! empty( $atts['event_show_speakers'] ) && is_array( $target['session'] ?? null ) ) {
				foreach ( (array) ( $target['session']['speakers'] ?? [] ) as $speaker ) {
					$shown[] = (string) ( is_array( $speaker ) ? ( $speaker['name'] ?? '' ) : '' );
				}
			}

			$more_people = EventSelector::people( max( 0, (int) $atts['more_events_limit'] ), $shown );

			if ( empty( $more_people ) ) {
				Diagnostics::note( __( 'More Events is set to "featured people", but no upcoming session carries speaker information — falling back to the event rows.', 'emailexpert-events' ) );
				$more_presentation = 'events';
			}
		}

		if ( null === $lead && null === $target && empty( $news ) && empty( $more ) ) {
			return Components::empty_state( (string) $atts['empty_text'] );
		}

		// Heading hierarchy: the lead story carries the page H1 only in the
		// explicit "page hero" context; embedded components lead with H2 and
		// everything below follows one level down.
		$page_context = 'page' === (string) $atts['heading_context'];
		$lead_tag     = $page_context ? 'h1' : 'h2';
		$section_tag  = $page_context ? 'h2' : 'h3';
		$item_tag     = $page_context ? 'h3' : 'h4';

		// Exactly one image may load eagerly (the LCP candidate); everything
		// else is lazy. Auto prefers the story image when one is shown.
		$eager       = (string) $atts['eager_media'];
		$story_image = null !== $lead && ! empty( $atts['story_show_image'] )
			&& ( '' !== (string) $lead['image'] || (int) $lead['image_id'] > 0 );

		$media = null !== $target
			? MediaResolver::resolve( $target, (string) $atts['event_media'] )
			: [
				'type'     => 'none',
				'url'      => '',
				'id'       => 0,
				'fit'      => 'cover',
				'speakers' => [],
			];

		if ( 'auto' === $eager ) {
			$eager = $story_image ? 'story' : ( 'image' === $media['type'] ? 'event' : 'none' );
		}

		$cta    = null;
		$drawer = [
			'id'   => '',
			'html' => '',
		];
		$rsvp   = [];

		if ( null !== $target ) {
			$cta = CtaResolver::resolve( $target, $atts, [ 'check_tickets' => true ] );

			$commerce = CtaResolver::commerce_atts( $atts, (array) $target['event'] );

			if ( null !== $target['session'] ) {
				$commerce['drawer_talk'] = (string) ( $target['session']['hs_id'] ?? '' );
			}

			if ( ! empty( $cta['primary']['drawer'] ) ) {
				$drawer = Components::ticket_drawer( $commerce );
			}

			$rsvp = (array) $cta['rsvp_context'];
		}

		$classes = [
			'eex-hh',
			'eex-hh--' . (string) $atts['layout'],
		];

		if ( null === $lead ) {
			$classes[] = 'eex-hh--no-story';
		}

		if ( null === $target && empty( $more ) ) {
			$classes[] = 'eex-hh--no-event';
		}

		if ( null !== $second ) {
			$classes[] = 'eex-hh--two-story';
		}

		$event_first = 'event-first' === (string) $atts['mobile_order'];

		ob_start();

		printf(
			'<div class="%s" data-eex-component="homepage-hero">',
			esc_attr( implode( ' ', $classes ) )
		);

		echo '<div class="eex-hh__main">';

		// DOM order follows the mobile stacking order (the desktop grid
		// places the two columns by named area, so reading order and visual
		// order stay consistent at every width).
		$story_column = static function () use ( $lead, $second, $second_placement, $atts, $lead_tag, $section_tag, $eager ): void {
			if ( null === $lead ) {
				return;
			}

			printf(
				'<div class="eex-hh__story%s">',
				null !== $second && 'side' === $second_placement ? ' eex-hh__story--with-side' : ''
			);
			echo '<div class="eex-hh__story-primary">';
			TemplateLoader::part(
				'hero-story',
				[
					'story'          => $lead,
					'heading_tag'    => $lead_tag,
					'show_eyebrow'   => ! empty( $atts['story_show_eyebrow'] ),
					'eyebrow'        => (string) $atts['story_eyebrow'],
					'show_image'     => ! empty( $atts['story_show_image'] ),
					'image_position' => (string) $atts['story_image_position'],
					'media_fit'      => (string) $atts['story_media_fit'],
					'show_excerpt'   => ! empty( $atts['story_show_excerpt'] ),
					'excerpt_length' => (int) $atts['story_excerpt_length'],
					'show_category'  => ! empty( $atts['story_show_category'] ),
					'show_date'      => ! empty( $atts['story_show_date'] ),
					'show_readtime'  => ! empty( $atts['story_show_readtime'] ),
					'show_cta'       => ! empty( $atts['story_show_cta'] ),
					'cta_text'       => (string) $atts['story_cta_text'],
					'eager'          => 'story' === $eager,
				]
			);
			echo '</div>';

			if ( null !== $second ) {
				printf( '<div class="eex-hh__story-second eex-hh__story-second--%s">', esc_attr( $second_placement ) );
				TemplateLoader::part(
					'hero-secondary-story',
					[
						'story'         => $second,
						'heading_tag'   => $section_tag,
						'show_image'    => ! empty( $atts['story_show_image'] ),
						'show_category' => ! empty( $atts['story_show_category'] ),
						'show_date'     => ! empty( $atts['story_show_date'] ),
						'cta_text'      => (string) $atts['story_cta_text'],
					]
				);
				echo '</div>';
			}

			echo '</div>';
		};

		$event_column = static function () use ( $target, $more, $more_people, $more_presentation, $placement, $atts, $section_tag, $item_tag, $media, $cta, $drawer, $rsvp, $eager ): void {
			if ( null === $target && ( empty( $more ) || 'event-column' !== $placement ) ) {
				return;
			}

			echo '<div class="eex-hh__event">';

			if ( null !== $target ) {
				TemplateLoader::part(
					'hero-featured-event',
					[
						'target'         => $target,
						'cta'            => $cta,
						'media'          => $media,
						'drawer'         => $drawer['id'],
						'rsvp'           => $rsvp,
						'heading_tag'    => $section_tag,
						'eyebrow'        => (string) $atts['event_eyebrow'],
						'show_speakers'  => ! empty( $atts['event_show_speakers'] ),
						'show_countdown' => ! empty( $atts['event_show_countdown'] ),
						'eager'          => 'event' === $eager,
					]
				);
			}

			if ( ! empty( $more ) && 'event-column' === $placement ) {
				self::more_events_block( $more, $atts, $item_tag, 'column', $more_presentation, $more_people );
			}

			echo '</div>';
		};

		if ( $event_first ) {
			$event_column();
			$story_column();
		} else {
			$story_column();
			$event_column();
		}

		echo '</div>';

		if ( ! empty( $more ) && 'strip' === $placement ) {
			self::more_events_block( $more, $atts, $section_tag, 'strip', $more_presentation, $more_people );
		}

		if ( ! empty( $news ) ) {
			$news_title = trim( (string) $atts['news_title'] );
			if ( '' === $news_title ) {
				$news_title = __( 'Latest news', 'emailexpert-events' );
			}

			// The layout mode and the image treatment resolve once, here:
			// auto follows the item count and the chosen mode, and images
			// respect the on/off flag whatever the placement says.
			$news_layout = (string) $atts['news_layout'];
			if ( ! in_array( $news_layout, [ 'list', 'editorial', 'media' ], true ) ) {
				$news_layout = 'auto';
			}

			$news_image = (string) $atts['news_image_position'];
			if ( empty( $atts['news_show_image'] ) ) {
				$news_image = 'none';
			} elseif ( 'auto' === $news_image || ! in_array( $news_image, [ 'none', 'beside', 'above' ], true ) ) {
				$news_image = 'media' === $news_layout ? 'above' : ( 'list' === $news_layout ? 'none' : 'beside' );
			}

			$news_effective = 'auto' === $news_layout ? ( 'above' === $news_image ? 'media' : 'editorial' ) : $news_layout;

			// Desktop columns follow the item count: full-width rows never
			// pretend to be a four-column grid.
			$news_n = count( $news );
			if ( $news_n <= 4 ) {
				$news_cols = max( 2, $news_n );
			} elseif ( in_array( $news_n, [ 5, 6, 9 ], true ) ) {
				$news_cols = 3;
			} else {
				$news_cols = 4;
			}

			printf(
				'<section class="eex-hh__news eex-hh__news--%s" aria-label="%s">',
				esc_attr( $news_effective ),
				esc_attr( $news_title )
			);

			// One header row: the label left, the view-all link right.
			echo '<div class="eex-hh__news-head">';
			printf(
				'<%1$s class="eex-hh__label">%2$s</%1$s>',
				esc_attr( $section_tag ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
				esc_html( $news_title )
			);

			if ( '' !== (string) $atts['news_all_url'] ) {
				printf(
					'<a class="eex-hh__news-all eex-cta-quiet" href="%s" data-eex-action="news">%s<span class="eex-cta-arrow" aria-hidden="true">→</span></a>',
					esc_url( (string) $atts['news_all_url'] ),
					esc_html( '' !== (string) $atts['news_all_text'] ? (string) $atts['news_all_text'] : __( 'View all latest news', 'emailexpert-events' ) )
				);
			}
			echo '</div>';

			printf(
				'<ul class="eex-hh__news-list eex-hh__news-list--cols-%d" role="list">',
				(int) $news_cols
			);
			foreach ( $news as $item ) {
				echo '<li class="eex-hh__news-item">';
				TemplateLoader::part(
					'hero-news-item',
					[
						'story'          => $item,
						'heading_tag'    => $item_tag,
						'image_position' => $news_image,
						'image_size'     => (string) $atts['news_image_size'],
						'show_category'  => ! empty( $atts['news_show_category'] ),
						'show_date'      => ! empty( $atts['news_show_date'] ),
					]
				);
				echo '</li>';
			}
			echo '</ul>';

			echo '</section>';
		}

		echo '</div>';

		$html = (string) ob_get_clean();

		// The drawer rides outside the grid so its fixed positioning is
		// never trapped by a transformed ancestor.
		$html .= $drawer['html'];

		// One inline Event schema for the featured target (Lite mode only;
		// the lead article's own NewsArticle schema is never duplicated).
		if ( null !== $target ) {
			$html .= CompositionSchema::inline( $target );
		}

		return $html;
	}

	/**
	 * The compact More Events rows, in either placement and any presentation.
	 *
	 * @param array<int,array<string,mixed>> $more         Event rows.
	 * @param array<string,mixed>            $atts         Attributes.
	 * @param string                         $tag          Label heading tag.
	 * @param string                         $variant      'column' or 'strip'.
	 * @param string                         $presentation 'events', 'speakers' or 'people'.
	 * @param array<int,array<string,mixed>> $people       Person rows (people mode).
	 */
	private static function more_events_block( array $more, array $atts, string $tag, string $variant, string $presentation = 'events', array $people = [] ): void {
		$is_people = 'people' === $presentation && ! empty( $people );

		$title = trim( (string) $atts['more_events_title'] );
		if ( '' === $title ) {
			if ( $is_people ) {
				$title = __( 'Coming up', 'emailexpert-events' );
			} else {
				$title = 'strip' === $variant
					? __( 'More from emailexpert', 'emailexpert-events' )
					: __( 'More events', 'emailexpert-events' );
			}
		}

		// The arrangement: auto keeps the established behaviour (a wrapping
		// row in the strip, stacked rows in the event column).
		$layout = (string) $atts['more_events_layout'];
		if ( ! in_array( $layout, [ 'vertical', 'horizontal', 'grid' ], true ) ) {
			$layout = 'strip' === $variant ? 'horizontal' : 'vertical';
		}

		$speakers_per_row = min( 3, max( 1, (int) $atts['more_events_speakers'] ) );

		printf(
			'<div class="eex-hh__more eex-hh__more--%s eex-hh__more--lay-%s">',
			esc_attr( $variant ),
			esc_attr( $layout )
		);
		printf(
			'<%1$s class="eex-hh__label">%2$s</%1$s>',
			esc_attr( $tag ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
			esc_html( $title )
		);
		echo '<ul class="eex-hh__more-list" role="list">';

		if ( $is_people ) {
			foreach ( $people as $person ) {
				echo '<li>';
				TemplateLoader::part( 'hero-person-row', [ 'person' => $person ] );
				echo '</li>';
			}
		} else {
			foreach ( $more as $event ) {
				echo '<li>';
				TemplateLoader::part(
					'compact-event-row',
					[
						'event'         => $event,
						'show_speakers' => 'speakers' === $presentation,
						'speaker_limit' => $speakers_per_row,
						'whisper'       => (string) $atts['more_events_whisper'],
					]
				);
				echo '</li>';
			}
		}

		echo '</ul></div>';

		Diagnostics::note(
			sprintf(
				/* translators: %d: rows shown. */
				__( 'More Events: %d row(s) shown after deduplication.', 'emailexpert-events' ),
				$is_people ? count( $people ) : count( $more )
			)
		);
	}
}
