<?php
/**
 * Dynamic tags exposing synced data to Elementor Pro Theme Builder.
 *
 * Every tag returns empty (never placeholder text) when data is missing,
 * consistent with the schema policy. This file deliberately holds the whole
 * tag family: the classes are tiny, Elementor-Pro-only, and loaded solely
 * from the Elementor module.
 *
 * @package Emailexpert\Events
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- cohesive tag family, Elementor-only.

namespace Emailexpert\Events\Elementor\DynamicTags;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\TimeFormat;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\PostTypes\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Registry of tag classes.
 */
final class Tags {

	/**
	 * All tag class names to register.
	 *
	 * @return string[]
	 */
	public static function classes(): array {
		return [
			SessionStartTag::class,
			SessionEndTag::class,
			SessionCategoriesTag::class,
			SessionLiveStatusTag::class,
			RegisterUrlTag::class,
			ReplayUrlTag::class,
			EventUrlTag::class,
			EventRegCountTag::class,
			VenueTag::class,
			SpeakerNameTag::class,
			SpeakerHeadlineTag::class,
			SpeakerCompanyTag::class,
			SpeakerPhotoTag::class,
			SpeakerLinkTag::class,
		];
	}
}

/**
 * Shared context helpers for the tag family.
 */
trait TagContext {

	/**
	 * Talk data for the current post, or null off talk singles.
	 *
	 * @return array<string,mixed>|null
	 */
	protected function current_talk(): ?array {
		$post = get_post();

		if ( ! $post || PostTypes::TALK !== $post->post_type ) {
			return null;
		}

		return Components::talk_data( (int) $post->ID );
	}

	/**
	 * The relevant event post ID for the current context.
	 */
	protected function current_event_id(): int {
		$post = get_post();

		if ( ! $post ) {
			return 0;
		}

		if ( PostTypes::EVENT === $post->post_type ) {
			return (int) $post->ID;
		}

		if ( PostTypes::TALK === $post->post_type ) {
			return Components::event_post_for_hs_id( (string) get_post_meta( (int) $post->ID, '_eex_source_event_id', true ) );
		}

		return 0;
	}

	/**
	 * The current speaker post ID, or 0.
	 */
	protected function current_speaker_id(): int {
		$post = get_post();

		return ( $post && PostTypes::SPEAKER === $post->post_type ) ? (int) $post->ID : 0;
	}
}

/**
 * Base text tag.
 */
abstract class BaseTextTag extends \Elementor\Core\DynamicTags\Tag {

	use TagContext;

	/**
	 * Tag group.
	 *
	 * @return string[]
	 */
	public function get_group(): array {
		return [ 'emailexpert-events' ];
	}

	/**
	 * Tag categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
	}

	/**
	 * Output the value; empty when data is missing.
	 */
	public function render(): void {
		echo esc_html( $this->value() );
	}

	/**
	 * The tag's value.
	 */
	abstract protected function value(): string;
}

/**
 * Base URL tag.
 */
abstract class BaseUrlTag extends \Elementor\Core\DynamicTags\Data_Tag {

	use TagContext;

	/**
	 * Tag group.
	 *
	 * @return string[]
	 */
	public function get_group(): array {
		return [ 'emailexpert-events' ];
	}

	/**
	 * Tag categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
	}

	/**
	 * The URL value.
	 *
	 * @param array<string,mixed> $options Options.
	 * @return string
	 */
	public function get_value( array $options = [] ) {
		return esc_url_raw( $this->url() );
	}

	/**
	 * The tag's URL.
	 */
	abstract protected function url(): string;
}

/**
 * Session start time, formatted, site or event timezone.
 */
class SessionStartTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-session-start';
	}

	public function get_title(): string {
		return __( 'Session start', 'emailexpert-events' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'format',
			[
				'label'   => __( 'PHP date format', 'emailexpert-events' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => '',
			]
		);
		$this->add_control(
			'zone',
			[
				'label'   => __( 'Timezone', 'emailexpert-events' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => [
					'event' => __( 'Event timezone', 'emailexpert-events' ),
					'site'  => __( 'Site timezone', 'emailexpert-events' ),
				],
				'default' => 'event',
			]
		);
	}

	protected function value(): string {
		$talk = $this->current_talk();
		$key  = 'eex-session-start' === $this->get_name() ? 'starts_at' : 'ends_at';

		if ( null === $talk || '' === (string) $talk[ $key ] ) {
			return '';
		}

		$timestamp = strtotime( (string) $talk[ $key ] );
		if ( false === $timestamp ) {
			return '';
		}

		$settings = (array) $this->get_settings();
		$format   = (string) ( $settings['format'] ?? '' );
		if ( '' === $format ) {
			$format = (string) get_option( 'date_format', 'j F Y' ) . ' ' . (string) get_option( 'time_format', 'H:i' );
		}

		$tz = 'site' === ( $settings['zone'] ?? 'event' )
			? wp_timezone()
			: TimeFormat::timezone( (string) $talk['timezone'] );

		return ( new \DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $tz )->format( $format );
	}
}

/**
 * Session end time.
 */
class SessionEndTag extends SessionStartTag {

	public function get_name(): string {
		return 'eex-session-end';
	}

	public function get_title(): string {
		return __( 'Session end', 'emailexpert-events' );
	}
}

/**
 * Session category list.
 */
class SessionCategoriesTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-session-categories';
	}

	public function get_title(): string {
		return __( 'Session categories', 'emailexpert-events' );
	}

	protected function value(): string {
		$post = get_post();

		if ( ! $post || PostTypes::TALK !== $post->post_type ) {
			return '';
		}

		$terms = get_the_terms( (int) $post->ID, Taxonomies::CATEGORY );

		if ( ! is_array( $terms ) ) {
			return '';
		}

		return implode( ', ', array_map( static fn( $term ): string => (string) $term->name, $terms ) );
	}
}

/**
 * Live status: 'live', 'soon', 'upcoming' or 'past'. Empty without times.
 * Note: server-derived; on cached pages prefer the JS session states.
 */
class SessionLiveStatusTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-session-live-status';
	}

	public function get_title(): string {
		return __( 'Session live status', 'emailexpert-events' );
	}

	protected function value(): string {
		$talk = $this->current_talk();

		if ( null === $talk || '' === (string) $talk['starts_at'] ) {
			return '';
		}

		$start = (int) strtotime( (string) $talk['starts_at'] );
		$end   = (int) ( strtotime( (string) $talk['ends_at'] ) ?: $start + HOUR_IN_SECONDS );
		$now   = time();

		if ( $now >= $start && $now <= $end ) {
			return 'live';
		}
		if ( $now < $start ) {
			return ( $start - $now <= HOUR_IN_SECONDS ) ? 'soon' : 'upcoming';
		}

		return 'past';
	}
}

/**
 * Register URL (event URL, falling back to the talk URL).
 */
class RegisterUrlTag extends BaseUrlTag {

	public function get_name(): string {
		return 'eex-register-url';
	}

	public function get_title(): string {
		return __( 'Register URL', 'emailexpert-events' );
	}

	protected function url(): string {
		$talk = $this->current_talk();

		if ( null !== $talk ) {
			return (string) ( $talk['event_url'] ?: $talk['talk_url'] );
		}

		$event_id = $this->current_event_id();

		return $event_id > 0 ? (string) get_post_meta( $event_id, '_eex_event_url', true ) : '';
	}
}

/**
 * Replay URL (manual wins over synced).
 */
class ReplayUrlTag extends BaseUrlTag {

	public function get_name(): string {
		return 'eex-replay-url';
	}

	public function get_title(): string {
		return __( 'Replay URL', 'emailexpert-events' );
	}

	protected function url(): string {
		$talk = $this->current_talk();

		return null !== $talk ? (string) $talk['replay_url'] : '';
	}
}

/**
 * Event URL.
 */
class EventUrlTag extends BaseUrlTag {

	public function get_name(): string {
		return 'eex-event-url';
	}

	public function get_title(): string {
		return __( 'Event URL', 'emailexpert-events' );
	}

	protected function url(): string {
		$event_id = $this->current_event_id();

		return $event_id > 0 ? (string) get_post_meta( $event_id, '_eex_event_url', true ) : '';
	}
}

/**
 * Event registration count.
 */
class EventRegCountTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-event-reg-count';
	}

	public function get_title(): string {
		return __( 'Event registration count', 'emailexpert-events' );
	}

	protected function value(): string {
		$event_id = $this->current_event_id();

		if ( 0 === $event_id ) {
			return '';
		}

		$count = (int) get_post_meta( $event_id, '_eex_registration_count', true );

		return $count > 0 ? (string) $count : '';
	}
}

/**
 * Venue field (selectable part).
 */
class VenueTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-venue';
	}

	public function get_title(): string {
		return __( 'Venue field', 'emailexpert-events' );
	}

	protected function register_controls(): void {
		$this->add_control(
			'part',
			[
				'label'   => __( 'Field', 'emailexpert-events' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => [
					'name'     => __( 'Venue name', 'emailexpert-events' ),
					'street'   => __( 'Street', 'emailexpert-events' ),
					'locality' => __( 'Town or city', 'emailexpert-events' ),
					'postcode' => __( 'Postcode', 'emailexpert-events' ),
					'country'  => __( 'Country', 'emailexpert-events' ),
				],
				'default' => 'name',
			]
		);
	}

	protected function value(): string {
		$event_id = $this->current_event_id();

		if ( 0 === $event_id ) {
			return '';
		}

		$part = (string) ( $this->get_settings( 'part' ) ?: 'name' );

		return (string) get_post_meta( $event_id, '_eex_venue_' . $part, true );
	}
}

/**
 * Speaker name.
 */
class SpeakerNameTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-speaker-name';
	}

	public function get_title(): string {
		return __( 'Speaker name', 'emailexpert-events' );
	}

	protected function value(): string {
		$speaker_id = $this->current_speaker_id();

		return $speaker_id > 0 ? get_the_title( $speaker_id ) : '';
	}
}

/**
 * Speaker headline.
 */
class SpeakerHeadlineTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-speaker-headline';
	}

	public function get_title(): string {
		return __( 'Speaker headline', 'emailexpert-events' );
	}

	protected function value(): string {
		$speaker_id = $this->current_speaker_id();

		return $speaker_id > 0 ? (string) get_post_meta( $speaker_id, '_eex_headline', true ) : '';
	}
}

/**
 * Speaker company.
 */
class SpeakerCompanyTag extends BaseTextTag {

	public function get_name(): string {
		return 'eex-speaker-company';
	}

	public function get_title(): string {
		return __( 'Speaker company', 'emailexpert-events' );
	}

	protected function value(): string {
		$speaker_id = $this->current_speaker_id();

		return $speaker_id > 0 ? (string) get_post_meta( $speaker_id, '_eex_company', true ) : '';
	}
}

/**
 * Speaker photo (image tag).
 */
class SpeakerPhotoTag extends \Elementor\Core\DynamicTags\Data_Tag {

	use TagContext;

	public function get_name(): string {
		return 'eex-speaker-photo';
	}

	public function get_title(): string {
		return __( 'Speaker photo', 'emailexpert-events' );
	}

	/**
	 * Tag group.
	 *
	 * @return string[]
	 */
	public function get_group(): array {
		return [ 'emailexpert-events' ];
	}

	/**
	 * Tag categories.
	 *
	 * @return string[]
	 */
	public function get_categories(): array {
		return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY ];
	}

	/**
	 * Image value: id + url, or empty array when missing.
	 *
	 * @param array<string,mixed> $options Options.
	 * @return array<string,mixed>
	 */
	public function get_value( array $options = [] ) {
		$speaker_id = $this->current_speaker_id();

		if ( 0 === $speaker_id ) {
			return [];
		}

		$photo_id = (int) get_post_meta( $speaker_id, '_eex_photo_attachment_id', true );

		if ( 0 === $photo_id ) {
			return [];
		}

		$url = function_exists( 'wp_get_attachment_url' ) ? wp_get_attachment_url( $photo_id ) : '';

		return $url ? [
			'id'  => $photo_id,
			'url' => (string) $url,
		] : [];
	}
}

/**
 * Speaker link (first profile link).
 */
class SpeakerLinkTag extends BaseUrlTag {

	public function get_name(): string {
		return 'eex-speaker-link';
	}

	public function get_title(): string {
		return __( 'Speaker link', 'emailexpert-events' );
	}

	protected function url(): string {
		$speaker_id = $this->current_speaker_id();

		if ( 0 === $speaker_id ) {
			return '';
		}

		$links = array_values( array_filter( array_map( 'strval', (array) get_post_meta( $speaker_id, '_eex_links', true ) ) ) );

		return $links[0] ?? '';
	}
}
