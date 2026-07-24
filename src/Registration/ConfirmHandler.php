<?php
/**
 * The confirmation-link handler.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Registration;

use Emailexpert\Events\Data\Repositories;
use Emailexpert\Events\Data\Tickets;

defined( 'ABSPATH' ) || exit;

/**
 * ?eex_confirm=<raw token> on any front-end URL. Email links outlive
 * nonce lifetimes, so the token itself is the authentication (hash
 * looked up, single-use, expiring). A valid claim performs the real
 * registration and lands the visitor back on the page they started
 * from with a status flag the front-end JS turns into a banner. Invalid,
 * expired and already-used tokens are indistinguishable by design.
 */
final class ConfirmHandler {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'template_redirect', [ $this, 'maybe_confirm' ] );
	}

	/**
	 * Handle a confirmation click.
	 */
	public function maybe_confirm(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the single-use token IS the credential; email links outlive nonces.
		$token = isset( $_GET['eex_confirm'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['eex_confirm'] ) ) : '';

		if ( '' === $token ) {
			return;
		}

		$outcome = $this->confirm( $token );

		wp_safe_redirect( add_query_arg( 'eex_reg', rawurlencode( $outcome['status'] ), $outcome['url'] ) );
		exit;
	}

	/**
	 * Perform the confirmation (separated from the redirect for tests).
	 *
	 * @param string $token Raw token.
	 * @return array{status:string,url:string} done|failed|invalid + target.
	 */
	public function confirm( string $token ): array {
		$payload = PendingStore::claim( $token );

		if ( null === $payload ) {
			return [
				'status' => 'invalid',
				'url'    => home_url( '/' ),
			];
		}

		$return = wp_validate_redirect( (string) ( $payload['return_url'] ?? '' ), home_url( '/' ) );

		// The ticket must still be free at confirm time — 48 hours is long
		// enough for pricing to change, and the free-only guarantee holds
		// at the moment of the write, not the moment of the form.
		$ticket = null;
		foreach ( Tickets::for_display( (string) $payload['connection_id'], (string) $payload['event'], '' ) as $row ) {
			if ( (string) ( $payload['ticket'] ?? '' ) === (string) $row['id'] && empty( $row['is_paid'] ) ) {
				$ticket = $row;
				break;
			}
		}

		if ( null === $ticket ) {
			return [
				'status' => 'failed',
				'url'    => $return,
			];
		}

		$result = Registrar::register( $payload );

		if ( is_wp_error( $result ) ) {
			return [
				'status' => 'failed',
				'url'    => $return,
			];
		}

		// A session that landed on an EXISTING attendee's schedule gets the
		// transactional session-added email (HeySummit is silent there); a
		// fresh registration already receives HeySummit's own welcome.
		if ( 'already' === $result && '' !== (string) ( $payload['talk'] ?? '' ) ) {
			$talk = Repositories::current()->known_talk( (string) $payload['talk'] );

			if ( null !== $talk ) {
				Mailer::send_session_added( (string) $payload['email'], $talk );
			}
		}

		return [
			'status' => 'done',
			'url'    => $return,
		];
	}
}
