<?php
/**
 * Per-session .ics download endpoint.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend;

use Emailexpert\Events\PostTypes\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Serves ?eex_ics=<talk_id> as a downloadable single-event calendar.
 */
final class IcsDownload {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_serve' ] );
	}

	/**
	 * Serve the download when requested.
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only download.
		$talk_id = isset( $_GET['eex_ics'] ) ? (int) $_GET['eex_ics'] : 0;

		if ( $talk_id <= 0 ) {
			return;
		}

		$post = get_post( $talk_id );

		if ( ! $post || PostTypes::TALK !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		$ics = Ics::calendar( [ $talk_id ] );

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $post->post_name ?: 'session' ) . '.ics"' );

		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar document, escaped per RFC 5545.
		exit;
	}
}
