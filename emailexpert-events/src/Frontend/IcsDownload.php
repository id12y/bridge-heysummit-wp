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
	 * Serve the download when requested. In Full the reference is a talk
	 * post ID; in Lite it is the HeySummit talk ID, resolved through the
	 * live repository (cached, budgeted — same rules as any render).
	 */
	public function maybe_serve(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public read-only download.
		$talk_id = isset( $_GET['eex_ics'] ) ? (int) $_GET['eex_ics'] : 0;

		if ( $talk_id <= 0 ) {
			return;
		}

		if ( \Emailexpert\Events\Options::is_lite() ) {
			$this->serve_live( (string) $talk_id );

			return;
		}

		$post = get_post( $talk_id );

		if ( ! $post || PostTypes::TALK !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}

		$ics = Ics::calendar( [ $talk_id ] );

		$this->send( $ics, (string) ( $post->post_name ?: 'session' ) );
	}

	/**
	 * Lite variant: build the VEVENT from live talk data. Rate limited, and
	 * resolved via known_talk() only — the reference is visitor-controlled,
	 * so it must never trigger a per-ID API fetch or cache write.
	 *
	 * @param string $ref HeySummit talk ID.
	 */
	private function serve_live( string $ref ): void {
		if ( ! \Emailexpert\Events\RateLimiter::allow( 'ics', 60 ) ) {
			return; // Fall through to the normal page.
		}

		$data = \Emailexpert\Events\Data\Repositories::current()->known_talk( $ref );

		if ( null === $data || '' === (string) ( $data['starts_at'] ?? '' ) ) {
			return; // Unknown session: fall through to the normal page.
		}

		$this->send( Ics::calendar_from_data( [ $data ] ), sanitize_title( (string) $data['title'] ) ?: 'session' );
	}

	/**
	 * Send a calendar document and exit.
	 *
	 * @param string $ics      Calendar document.
	 * @param string $filename Base filename.
	 */
	private function send( string $ics, string $filename ): void {
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '.ics"' );

		echo $ics; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/calendar document, escaped per RFC 5545.
		exit;
	}
}
