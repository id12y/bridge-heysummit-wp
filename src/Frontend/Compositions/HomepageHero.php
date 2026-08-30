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

		// More Sessions presentation: every mode presents the same selected
		// rows. Rich and speaker treatments reuse the session data the page
		// already loads; absent information simplifies the row rather than
		// leaving placeholders.
		$more_presentation = (string) $atts['more_events_presentation'];
		$more_people       = [];
		$is_sessions       = 'upcoming_sessions' === (string) $atts['more_events_mode'];

		if ( ! empty( $more ) && 'auto' === $more_presentation ) {
			if ( $is_sessions ) {
				// Session rows carry enough context for the rich treatment:
				// horizontal across the wide strip, vertical in the column.
				$more_presentation = 'event-column' === $placement ? 'rich_vertical' : 'rich_horizontal';
			} else {
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

				$more_presentation = $has_portrait ? 'speakers' : 'events';
			}
		} elseif ( ! empty( $more ) && in_array( $more_presentation, [ 'speakers', 'rich_horizontal', 'rich_vertical' ], true ) && ! $is_sessions ) {
			// Event rows gain their soonest session's speakers for the
			// speaker and rich treatments.
			$more = EventSelector::attach_speakers( $more );
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

			// The interaction the existing registration system resolved
			// ('auto' becomes 'form' or 'panel' there) drives the same
			// existing drawer renderer the classic widgets use.
			$commerce['register_action'] = (string) ( $cta['register_action'] ?? $commerce['register_action'] ?? 'link' );

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

		// Ticket drawers rendered for rich More Sessions rows ride outside
		// the grid, exactly like the featured card's drawer.
		$more_extra = '';

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

		$event_column = static function () use ( $target, $more, $more_people, $more_presentation, $placement, $atts, $section_tag, $item_tag, $media, $cta, $drawer, $rsvp, $eager, &$more_extra ): void {
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
				$more_extra .= self::more_events_block( $more, $atts, $item_tag, 'column', $more_presentation, $more_people );
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
			$more_extra .= self::more_events_block( $more, $atts, $section_tag, 'strip', $more_presentation, $more_people );
		}

		if ( ! empty( $news ) ) {
			$news_title = trim( (string) $atts['news_title'] );
			if ( '' === $news_title ) {
				$news_title = __( 'Latest news', 'emailexpert-events' );
			}

			// The layout mode resolves once. Front Page (auto) and Newsroom
			// COMPOSE the selected stories through deterministic roles; the
			// grid and newswire modes keep their established flat treatment.
			// Selection decided which stories these are — the layout only
			// decides how they appear.
			$news_layout = (string) $atts['news_layout'];
			if ( ! in_array( $news_layout, [ 'list', 'editorial', 'media', 'newsroom' ], true ) ) {
				$news_layout = 'auto';
			}

			$news_composed = in_array( $news_layout, [ 'auto', 'newsroom' ], true );

			$news_image = (string) $atts['news_image_position'];
			if ( empty( $atts['news_show_image'] ) ) {
				$news_image = 'none';
			} elseif ( 'auto' === $news_image || ! in_array( $news_image, [ 'none', 'beside', 'above' ], true ) ) {
				$news_image = 'media' === $news_layout ? 'above' : ( 'list' === $news_layout ? 'none' : 'beside' );
			}

			$news_effective = $news_layout;
			if ( 'auto' === $news_layout ) {
				$news_effective = 'front';
			}

			// Desktop columns follow the item count in the flat modes:
			// full-width rows never pretend to be a four-column grid.
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

			// The All-news route: the configured destination, else the site's
			// own posts page when one is set — never a guessed URL, never a
			// broken link.
			$news_all_url = (string) $atts['news_all_url'];

			if ( '' === $news_all_url ) {
				$posts_page   = (int) get_option( 'page_for_posts' );
				$news_all_url = $posts_page > 0 ? (string) get_permalink( $posts_page ) : '';
			}

			if ( ! empty( $atts['news_all_show'] ) && '' !== $news_all_url ) {
				printf(
					'<a class="eex-hh__news-all eex-cta-quiet" href="%s" data-eex-action="news">%s<span class="eex-cta-arrow" aria-hidden="true">→</span></a>',
					esc_url( $news_all_url ),
					esc_html( '' !== (string) $atts['news_all_text'] ? (string) $atts['news_all_text'] : __( 'View all latest news', 'emailexpert-events' ) )
				);
			}
			echo '</div>';

			$news_item_args = static function ( array $item, array $extra = [] ) use ( $atts, $item_tag, $news_image ): array {
				return $extra + [
					'story'          => $item,
					'heading_tag'    => $item_tag,
					'image_position' => $news_image,
					'image_size'     => (string) $atts['news_image_size'],
					'show_category'  => ! empty( $atts['news_show_category'] ),
					'link_category'  => ! empty( $atts['news_link_categories'] ),
					'link_image'     => ! empty( $atts['news_link_images'] ),
					'show_date'      => ! empty( $atts['news_show_date'] ),
				];
			};

			if ( $news_composed ) {
				// Deterministic editorial roles from the selector's order:
				// as the count rises, later stories become lighter and
				// typography-led — never more equal cards.
				$roles    = self::news_roles( $news_n, $news_layout );
				$emphasis = (string) $atts['news_media_emphasis'];
				if ( ! in_array( $emphasis, [ 'restrained', 'strong' ], true ) ) {
					$emphasis = 'auto';
				}

				$grouped_items = [
					'lead'      => [],
					'secondary' => [],
					'standard'  => [],
					'brief'     => [],
				];
				$cursor        = 0;
				foreach ( [ 'lead', 'secondary', 'standard', 'brief' ] as $role ) {
					for ( $i = 0; $i < $roles[ $role ]; $i++ ) {
						if ( isset( $news[ $cursor ] ) ) {
							$grouped_items[ $role ][] = $news[ $cursor ];
							++$cursor;
						}
					}
				}

				$role_image = [
					'lead'      => 'none' === $news_image ? 'none' : 'above',
					'secondary' => 'none' === $news_image || 'restrained' === $emphasis ? 'none' : 'beside',
					'standard'  => 'none' === $news_image || 'restrained' === $emphasis ? 'none' : ( 'strong' === $emphasis ? 'above' : 'beside' ),
					'brief'     => 'none',
				];

				echo '<div class="eex-hh__news-top">';

				foreach ( $grouped_items['lead'] as $item ) {
					echo '<div class="eex-hh__news-leadwrap">';
					TemplateLoader::part(
						'hero-news-item',
						$news_item_args(
							$item,
							[
								'role'            => 'lead',
								'image_position'  => $role_image['lead'],
								'image_size'      => 'large',
								'show_standfirst' => ! empty( $atts['news_lead_standfirst'] ),
							]
						)
					);
					echo '</div>';
				}

				if ( ! empty( $grouped_items['secondary'] ) ) {
					echo '<ul class="eex-hh__news-rail" role="list">';
					foreach ( $grouped_items['secondary'] as $item ) {
						echo '<li class="eex-hh__news-item">';
						TemplateLoader::part(
							'hero-news-item',
							$news_item_args(
								$item,
								[
									'role'           => 'secondary',
									'image_position' => $role_image['secondary'],
									'image_size'     => 'medium',
								]
							)
						);
						echo '</li>';
					}
					echo '</ul>';
				}

				echo '</div>';

				if ( ! empty( $grouped_items['standard'] ) ) {
					printf(
						'<ul class="eex-hh__news-standard eex-hh__news-list--cols-%d" role="list">',
						absint( count( $grouped_items['standard'] ) >= 4 ? 4 : max( 2, count( $grouped_items['standard'] ) ) )
					);
					foreach ( $grouped_items['standard'] as $item ) {
						echo '<li class="eex-hh__news-item">';
						TemplateLoader::part(
							'hero-news-item',
							$news_item_args(
								$item,
								[
									'role'           => 'standard',
									'image_position' => $role_image['standard'],
									'image_size'     => 'compact',
								]
							)
						);
						echo '</li>';
					}
					echo '</ul>';
				}

				if ( ! empty( $grouped_items['brief'] ) ) {
					$dense_heading = trim( (string) $atts['news_dense_heading'] );
					if ( '' === $dense_heading ) {
						$dense_heading = __( 'Latest', 'emailexpert-events' );
					}

					printf( '<div class="eex-hh__news-briefs%s">', count( $grouped_items['brief'] ) >= 8 ? ' eex-hh__news-briefs--split' : '' );
					printf(
						'<%1$s class="eex-hh__label">%2$s</%1$s>',
						esc_attr( $item_tag ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- whitelisted tag.
						esc_html( $dense_heading )
					);
					echo '<ul class="eex-hh__news-brieflist" role="list">';
					foreach ( $grouped_items['brief'] as $item ) {
						echo '<li class="eex-hh__news-item">';
						TemplateLoader::part(
							'hero-news-item',
							$news_item_args(
								$item,
								[
									'role'           => 'brief',
									'image_position' => 'none',
								]
							)
						);
						echo '</li>';
					}
					echo '</ul></div>';
				}
			} else {
				printf(
					'<ul class="eex-hh__news-list eex-hh__news-list--cols-%d" role="list">',
					(int) $news_cols
				);
				foreach ( $news as $item ) {
					echo '<li class="eex-hh__news-item">';
					TemplateLoader::part( 'hero-news-item', $news_item_args( $item ) );
					echo '</li>';
				}
				echo '</ul>';
			}

			echo '</section>';
		}

		echo '</div>';

		$html = (string) ob_get_clean();

		// Drawers ride outside the grid so their fixed positioning is never
		// trapped by a transformed ancestor.
		$html .= $drawer['html'] . $more_extra;

		// One inline Event schema for the featured target (Lite mode only;
		// the lead article's own NewsArticle schema is never duplicated).
		if ( null !== $target ) {
			$html .= CompositionSchema::inline( $target );
		}

		return $html;
	}

	/**
	 * The deterministic editorial roles for a composed Latest News layout:
	 * how many of the already selected stories render as the lead, as
	 * secondary stories, as standard stories and as headline-led briefs.
	 * Pure arithmetic over the selector's order — selection decides WHICH
	 * stories appear, this only decides HOW; nothing is persisted and no
	 * story is ever dropped.
	 *
	 * Front Page: 1 lead; up to 3 secondaries; a standard row appears from
	 * nine stories (2/2/3/4 for 9–12, capped at 4); everything later is a
	 * brief. Newsroom trades secondaries for density: 2 secondaries, up to
	 * 4 standards, and a larger Latest list.
	 *
	 * @param int    $count  Selected story count.
	 * @param string $layout 'auto' (Front Page) or 'newsroom'.
	 * @return array{lead:int,secondary:int,standard:int,brief:int}
	 */
	private static function news_roles( int $count, string $layout ): array {
		$count = max( 0, $count );

		if ( 0 === $count ) {
			return [
				'lead'      => 0,
				'secondary' => 0,
				'standard'  => 0,
				'brief'     => 0,
			];
		}

		if ( 'newsroom' === $layout ) {
			$lead      = 1;
			$secondary = min( 2, $count - 1 );
			$standard  = min( 4, max( 0, $count - 3 ) );
			$brief     = $count - $lead - $secondary - $standard;

			return [
				'lead'      => $lead,
				'secondary' => $secondary,
				'standard'  => $standard,
				'brief'     => $brief,
			];
		}

		$lead = 1;

		if ( $count <= 4 ) {
			$secondary = $count - 1;
		} elseif ( $count <= 6 ) {
			$secondary = 2;
		} else {
			$secondary = 3;
		}

		$standard = 0;
		if ( $count >= 13 ) {
			$standard = 4;
		} elseif ( $count >= 9 ) {
			$standard = [
				9  => 2,
				10 => 2,
				11 => 3,
				12 => 4,
			][ $count ];
		}

		$brief = $count - $lead - $secondary - $standard;

		return [
			'lead'      => $lead,
			'secondary' => $secondary,
			'standard'  => $standard,
			'brief'     => $brief,
		];
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
	private static function more_events_block( array $more, array $atts, string $tag, string $variant, string $presentation = 'events', array $people = [] ): string {
		$is_people = 'people' === $presentation && ! empty( $people );
		$rich      = in_array( $presentation, [ 'rich_horizontal', 'rich_vertical' ], true );

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
		// row in the strip, stacked rows in the event column); the rich
		// presentations carry their own arrangement.
		$layout = (string) $atts['more_events_layout'];
		if ( $rich ) {
			$layout = 'rich_horizontal' === $presentation ? 'rich-h' : 'rich-v';
		} elseif ( ! in_array( $layout, [ 'vertical', 'horizontal', 'grid' ], true ) ) {
			$layout = 'strip' === $variant ? 'horizontal' : 'vertical';
		}

		$speakers_per_row = min( 3, max( 1, (int) $atts['more_events_speakers'] ) );

		// Rich rows carry one action each, resolved by the same registration
		// system every other surface uses: the shared RSVP form when its
		// free-ticket rules apply, the shared ticket panel when tickets
		// exist, the session page otherwise. One cached decision per owning
		// event — never one fetch per row.
		$row_ctas = [];
		$extra    = '';

		if ( $rich && ! $is_people ) {
			$interaction = (string) $atts['more_events_interaction'];
			$rsvp_cache  = [];
			$panel_cache = [];

			$commerce_keys = [ 'register_url', 'buy_on', 'coupon', 'currency', 'tickets', 'exclude' ];

			foreach ( $more as $index => $row ) {
				$event_ref = (string) ( $row['event_hs_id'] ?? '' );
				if ( '' === $event_ref ) {
					$event_ref = (string) ( $row['hs_id'] ?? '' ); // Event rows: the row is the event.
				}

				$details = (string) ( $row['url'] ?? '' );
				if ( '' === $details ) {
					$details = (string) ( $row['presentation']['details_url'] ?? '' );
				}

				$cta = null;

				if ( 'details' !== $interaction && '' !== $event_ref ) {
					$commerce = array_intersect_key( $atts, array_flip( $commerce_keys ) );

					if ( ! array_key_exists( $event_ref, $rsvp_cache ) ) {
						$rsvp_cache[ $event_ref ] = Components::rsvp_context(
							$commerce + [
								'event'           => $event_ref,
								'register_action' => 'form',
							]
						);
					}

					if ( ! empty( $rsvp_cache[ $event_ref ] ) ) {
						$cta = [
							'kind'  => 'rsvp',
							'label' => __( 'Register', 'emailexpert-events' ),
							'rsvp'  => $rsvp_cache[ $event_ref ],
							'url'   => $details,
						];
					} else {
						if ( ! array_key_exists( $event_ref, $panel_cache ) ) {
							$panel_cache[ $event_ref ] = Components::ticket_drawer(
								$commerce + [
									'event'           => $event_ref,
									'register_action' => 'panel',
								]
							);
						}

						if ( '' !== (string) $panel_cache[ $event_ref ]['id'] ) {
							$cta = [
								'kind'      => 'drawer',
								'label'     => __( 'Get tickets', 'emailexpert-events' ),
								'drawer_id' => (string) $panel_cache[ $event_ref ]['id'],
								'url'       => $details,
							];
						}
					}
				}

				if ( null === $cta && 'register' !== $interaction && '' !== $details ) {
					$cta = [
						'kind'  => 'link',
						'label' => __( 'View session', 'emailexpert-events' ),
						'url'   => $details,
					];
				}

				$row_ctas[ $index ] = $cta;
			}

			foreach ( $panel_cache as $panel ) {
				$extra .= (string) $panel['html'];
			}
		}

		ob_start();

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
			foreach ( $more as $index => $event ) {
				echo '<li>';
				TemplateLoader::part(
					'compact-event-row',
					[
						'event'         => $event,
						'show_speakers' => $rich || 'speakers' === $presentation,
						'speaker_limit' => $speakers_per_row,
						'whisper'       => (string) $atts['more_events_whisper'],
						'rich'          => $rich,
						'show_time'     => ! empty( $atts['more_show_time'] ),
						'show_event'    => ! empty( $atts['more_show_event'] ),
						'show_media'    => ! empty( $atts['more_show_media'] ),
						'cta'           => $row_ctas[ $index ] ?? null,
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

		echo ob_get_clean(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output escaped at build time.

		return $extra;
	}
}
