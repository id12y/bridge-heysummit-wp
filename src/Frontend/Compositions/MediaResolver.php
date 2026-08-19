<?php
/**
 * Feature media resolution for the compositions.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Compositions;

use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One deliberate fallback chain for what a featured event or session shows:
 *
 *   Full  — presentation hero-media override, event hero override /
 *           featured image, synced or API event image, the presented
 *           session's artwork, speaker portraits, text only.
 *   Lite  — presentation hero-media override, the HeySummit feature_image,
 *           the presented session's artwork, speaker portraits, text only.
 *
 * Session artwork keeps the plugin's no-crop rule (object-fit: contain by
 * default — promo graphics carry text and sponsor strips to their edges);
 * event hero imagery may crop editorially. A missing image resolves to a
 * text-led layout, never a broken element.
 */
final class MediaResolver {

	/**
	 * Resolve the media for a feature target.
	 *
	 * @param array<string,mixed>      $target Feature target (event, session,
	 *                                         event presentation included).
	 * @param string                   $mode   auto|event|session|speakers|none.
	 * @return array{type:string,url:string,id:int,fit:string,speakers:array<int,array<string,mixed>>}
	 *               type is 'image', 'portraits' or 'none'.
	 */
	public static function resolve( array $target, string $mode ): array {
		$event        = (array) $target['event'];
		$session      = $target['session'] ?? null;
		$presentation = (array) ( $event['presentation'] ?? [] );

		$none = [
			'type'     => 'none',
			'url'      => '',
			'id'       => 0,
			'fit'      => 'cover',
			'speakers' => [],
		];

		if ( 'none' === $mode ) {
			return $none;
		}

		$override  = (string) ( $presentation['hero_media'] ?? '' );
		$portraits = self::portraits( null !== $session ? (array) ( $session['speakers'] ?? [] ) : [] );

		$event_image = self::event_image( $event, $override );

		$session_image = null !== $session ? [
			'type'     => 'image',
			'url'      => (string) ( $session['image'] ?? '' ),
			'id'       => 0,
			// The existing no-crop rule for session promotional artwork.
			'fit'      => 'contain',
			'speakers' => [],
		] : $none;

		$portrait_media = [
			'type'     => 'portraits',
			'url'      => '',
			'id'       => 0,
			'fit'      => 'cover',
			'speakers' => $portraits,
		];

		switch ( $mode ) {
			case 'event':
				return 'image' === $event_image['type'] ? $event_image : $none;

			case 'session':
				return '' !== $session_image['url'] ? $session_image : $none;

			case 'speakers':
				return ! empty( $portraits ) ? $portrait_media : $none;
		}

		// Auto. A presented session with usable portraits leads with them
		// (the approved mockup's treatment); the explicit hero-media
		// override always wins first.
		if ( '' !== $override && 'image' === $event_image['type'] && '' !== ( $event_image['url'] . $event_image['id'] ) ) {
			return $event_image;
		}

		if ( null !== $session ) {
			if ( ! empty( $portraits ) ) {
				return $portrait_media;
			}

			if ( '' !== $session_image['url'] ) {
				return $session_image;
			}
		}

		if ( 'image' === $event_image['type'] ) {
			return $event_image;
		}

		if ( '' !== $session_image['url'] ) {
			return $session_image;
		}

		if ( ! empty( $portraits ) ) {
			return $portrait_media;
		}

		return $none;
	}

	/**
	 * The event-level image, override first.
	 *
	 * @param array<string,mixed> $event    Event data array.
	 * @param string              $override Presentation hero-media (ID or URL).
	 * @return array<string,mixed> Media array (type 'none' when nothing usable).
	 */
	private static function event_image( array $event, string $override ): array {
		$image = [
			'type'     => 'image',
			'url'      => '',
			'id'       => 0,
			'fit'      => 'cover',
			'speakers' => [],
		];

		if ( ctype_digit( $override ) && (int) $override > 0 ) {
			$image['id'] = (int) $override;

			return $image;
		}

		if ( '' !== $override ) {
			$image['url'] = $override;

			return $image;
		}

		if ( ! Options::is_lite() && (int) ( $event['image_id'] ?? 0 ) > 0 ) {
			$image['id'] = (int) $event['image_id'];

			return $image;
		}

		if ( '' !== (string) ( $event['image'] ?? '' ) ) {
			$image['url'] = (string) $event['image'];

			return $image;
		}

		return [
			'type'     => 'none',
			'url'      => '',
			'id'       => 0,
			'fit'      => 'cover',
			'speakers' => [],
		];
	}

	/**
	 * Speakers with usable photos (portrait media needs at least one).
	 *
	 * @param array<int,array<string,mixed>> $speakers Session speakers.
	 * @return array<int,array<string,mixed>>
	 */
	private static function portraits( array $speakers ): array {
		return array_values(
			array_filter(
				$speakers,
				static function ( array $speaker ): bool {
					return (int) ( $speaker['photo_id'] ?? 0 ) > 0 || '' !== (string) ( $speaker['photo_url'] ?? '' );
				}
			)
		);
	}
}
