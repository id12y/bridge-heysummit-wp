<?php
/**
 * Pending (unconfirmed) registrations.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Registration;

use Emailexpert\Events\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * A registration awaiting email confirmation. Nothing is sent to
 * HeySummit until the mailbox owner clicks: the submission is held here,
 * keyed by the HASH of the confirmation token (raw token exists only in
 * the email), payload encrypted at rest, self-expiring after 48 hours.
 * Claims are single-use — the record is deleted before it is acted on.
 */
final class PendingStore {

	public const TTL = 2 * DAY_IN_SECONDS;

	private const COOLDOWN = 10 * MINUTE_IN_SECONDS;

	/**
	 * Store a pending registration and mint its confirmation token.
	 *
	 * @param array<string,mixed> $payload Registration payload (name, email,
	 *                                     event, ticket, price, talk,
	 *                                     marketing, consent, return_url).
	 * @return string RAW token for the confirmation email ('' on failure).
	 */
	public static function create( array $payload ): string {
		$payload['created'] = time();

		$sealed = Crypto::encrypt( (string) wp_json_encode( $payload ) );

		if ( '' === $sealed ) {
			return '';
		}

		$token = Crypto::new_token();

		set_transient( 'eex_pendreg_' . Crypto::hash_token( $token ), $sealed, self::TTL );

		return $token;
	}

	/**
	 * Claim a pending registration by its raw token. Single-use: the
	 * record is removed before the payload is returned, so a re-click can
	 * never replay the action.
	 *
	 * @param string $token Raw token from the confirmation link.
	 * @return array<string,mixed>|null Payload, or null (invalid, expired,
	 *                                  tampered or already used — callers
	 *                                  must not distinguish these).
	 */
	public static function claim( string $token ): ?array {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return null;
		}

		$key    = 'eex_pendreg_' . Crypto::hash_token( $token );
		$sealed = get_transient( $key );

		if ( ! is_string( $sealed ) || '' === $sealed ) {
			return null;
		}

		delete_transient( $key );

		$payload = json_decode( Crypto::decrypt( $sealed ), true );

		return is_array( $payload ) ? $payload : null;
	}

	/**
	 * Take the per-address send slot (one confirmation email per address
	 * per window). add_option is an INSERT — exactly one concurrent
	 * request wins; stale slots expire by timestamp.
	 *
	 * @param string $email Address about to be mailed.
	 * @return bool Whether this caller may send.
	 */
	public static function take_send_slot( string $email ): bool {
		$name = 'eex_regcd_' . Crypto::hash_email( $email );

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		$taken = (int) get_option( $name, 0 );

		if ( $taken > 0 && $taken < time() - self::COOLDOWN ) {
			update_option( $name, time(), false );

			return true;
		}

		return false;
	}
}
