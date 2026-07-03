<?php
/**
 * Speaker photo sideloading.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Sideloads speaker photos once into the media library, re-downloading only
 * when the source URL changes, with the speaker's name as alt text.
 */
final class MediaSideloader {

	/**
	 * Sideload a speaker photo and set it as the featured image.
	 *
	 * @param int    $post_id Speaker post ID.
	 * @param string $url     Source URL.
	 * @param string $name    Speaker name (alt text).
	 * @return int Attachment ID, 0 on failure or skip.
	 */
	public static function sideload_speaker_photo( int $post_id, string $url, string $name ): int {
		$existing_url = (string) get_post_meta( $post_id, '_eex_photo_source_url', true );
		$existing_id  = (int) get_post_meta( $post_id, '_eex_photo_attachment_id', true );

		if ( $existing_url === $url && $existing_id > 0 && null !== get_post( $existing_id ) ) {
			return $existing_id;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, $post_id, $name, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			Logger::warning(
				Logger::CONTEXT_SYNC,
				sprintf( 'Photo sideload failed for speaker post %d.', $post_id ),
				[ 'error' => $attachment_id->get_error_message() ]
			);

			return 0;
		}

		$attachment_id = (int) $attachment_id;

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $name );
		update_post_meta( $post_id, '_eex_photo_attachment_id', $attachment_id );
		update_post_meta( $post_id, '_eex_photo_source_url', $url );
		set_post_thumbnail( $post_id, $attachment_id );

		return $attachment_id;
	}
}
