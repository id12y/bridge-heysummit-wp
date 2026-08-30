<?php
/**
 * Hub route authentication.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use WP_Error;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * The permission_callback for EVERY Hub route, capabilities and health
 * included — default deny. Order: feature switch → schema ready → HTTPS →
 * bearer token (headers only, never query parameters) → optional IP
 * allowlist (extra protection, never the only control) → per-credential
 * rate limit. Failures return generic errors that reveal nothing about
 * which check failed beyond the HTTP status.
 */
class Auth {

	/**
	 * The credential authenticated for the current request (for logging
	 * by id — never the token).
	 *
	 * @var array<string,mixed>|null
	 */
	private static ?array $credential = null;

	/**
	 * Permission callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public function permit( WP_REST_Request $request ) {
		self::$credential = null;

		if ( ! Settings::enabled() || ! Schema::ready() ) {
			return new WP_Error( 'eex_hub_unavailable', 'Not available.', [ 'status' => 404 ] );
		}

		/**
		 * HTTPS is required; the filter exists for local development
		 * environments only.
		 *
		 * @param bool $require Whether to require HTTPS.
		 */
		if ( apply_filters( 'eex_hub_require_https', true ) && ! is_ssl() ) {
			return new WP_Error( 'eex_hub_https_required', 'HTTPS required.', [ 'status' => 403 ] );
		}

		$token = $this->bearer_token( $request );

		if ( '' === $token ) {
			return new WP_Error( 'eex_hub_unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
		}

		$credential = ( new Credentials() )->verify( $token );

		if ( null === $credential ) {
			return new WP_Error( 'eex_hub_unauthorized', 'Unauthorized.', [ 'status' => 401 ] );
		}

		if ( ! $this->ip_allowed() ) {
			return new WP_Error( 'eex_hub_forbidden', 'Forbidden.', [ 'status' => 403 ] );
		}

		if ( ! $this->within_rate_limit( (int) $credential['id'] ) ) {
			return new WP_Error( 'eex_hub_rate_limited', 'Too many requests.', [ 'status' => 429 ] );
		}

		self::$credential = $credential;

		return true;
	}

	/**
	 * The credential that authenticated the current request, if any.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function credential(): ?array {
		return self::$credential;
	}

	/**
	 * Extract the token from headers ONLY: `Authorization: Bearer <token>`
	 * or `X-EEX-Hub-Token: <token>` (for hosts that strip Authorization).
	 * Query parameters are never read.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	private function bearer_token( WP_REST_Request $request ): string {
		$header = trim( (string) $request->get_header( 'authorization' ) );

		if ( '' !== $header && 0 === stripos( $header, 'Bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		return trim( (string) $request->get_header( 'x-eex-hub-token' ) );
	}

	/**
	 * Optional IP allowlist — additional protection on top of the token,
	 * never a substitute. Empty list = no restriction.
	 */
	private function ip_allowed(): bool {
		$allowlist = (array) Settings::get( 'ip_allowlist' );
		$allowlist = array_values( array_filter( array_map( 'trim', array_map( 'strval', $allowlist ) ) ) );

		if ( [] === $allowlist ) {
			return true;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return '' !== $ip && in_array( $ip, $allowlist, true );
	}

	/**
	 * Fixed-window per-credential rate limit. Deliberately coarse and
	 * best-effort: the read-increment-write is not atomic and a window
	 * boundary admits up to twice the cap — it is an abuse brake for a
	 * single server-side consumer, never a security boundary (the token
	 * is).
	 *
	 * @param int $credential_id Credential id.
	 */
	private function within_rate_limit( int $credential_id ): bool {
		$limit  = max( 1, (int) Settings::get( 'rate_limit' ) );
		$window = max( 60, (int) Settings::get( 'rate_window' ) );

		$key   = 'eex_hub_rl_' . $credential_id . '_' . (int) floor( time() / $window );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, 2 * $window );

		return true;
	}
}
