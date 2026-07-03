<?php
/**
 * Speaker resource mapper.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a raw HeySummit speaker record to the plugin's normalised shape.
 */
final class SpeakerMapper extends BaseMapper {

	/**
	 * Map one raw record.
	 *
	 * @param array<string,mixed> $raw Raw API record.
	 * @return array<string,mixed>|null Null when the record has no usable ID.
	 */
	public static function map( array $raw ): ?array {
		$hs_id = self::id_of( $raw, [ 'id' ] );

		if ( '' === $hs_id ) {
			return null;
		}

		$name = self::str( $raw, [ 'name', 'full_name' ] );
		if ( '' === $name ) {
			$name = trim( self::str( $raw, [ 'first_name' ] ) . ' ' . self::str( $raw, [ 'last_name' ] ) );
		}

		// Photo: candidate fields, string or {url: …}. First match wins (see docs/decisions.md D5).
		$photo_url = '';
		foreach ( [ 'headshot', 'avatar', 'photo_url', 'photo', 'avatar_url', 'image' ] as $field ) {
			if ( isset( $raw[ $field ] ) ) {
				$photo_url = self::url_of( $raw[ $field ] );
				if ( '' !== $photo_url ) {
					break;
				}
			}
		}

		$links = [];
		if ( isset( $raw['links'] ) && is_array( $raw['links'] ) ) {
			foreach ( $raw['links'] as $link ) {
				$url = self::url_of( $link );
				if ( '' !== $url ) {
					$links[] = $url;
				}
			}
		}
		foreach ( [ 'twitter', 'linkedin', 'website', 'facebook', 'instagram' ] as $network ) {
			if ( isset( $raw[ $network ] ) ) {
				$url = self::url_of( $raw[ $network ] );
				if ( '' !== $url && ! in_array( $url, $links, true ) ) {
					$links[] = $url;
				}
			}
		}

		$email = strtolower( self::str( $raw, [ 'email', 'email_address' ] ) );

		return [
			'hs_id'       => $hs_id,
			'name'        => $name,
			'headline'    => self::str( $raw, [ 'headline', 'company_title', 'expert_creds', 'title', 'job_title', 'position' ] ),
			'bio'         => self::str( $raw, [ 'bio', 'description', 'about' ] ),
			'company'     => self::str( $raw, [ 'company', 'organisation', 'organization' ] ),
			'email'       => $email,
			'photo_url'   => $photo_url,
			'links'       => $links,
			'event_hs_id' => self::id_of( $raw, [ 'event', 'event_id' ] ),
		];
	}
}
