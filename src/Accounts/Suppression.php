<?php
/**
 * Push suppression list.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Email + event pairs that must never be pushed to HeySummit, populated by
 * profile opt-outs, GDPR erasure requests and manual entries. Checked
 * before every push — rule, backfill, retry or manual — and suppressed
 * users are never re-added. Emails are stored as SHA-256 hashes plus the
 * domain (for admin recognisability); the raw address is never kept.
 */
final class Suppression {

	private const OPTION = 'eex_suppression';

	public const ALL_EVENTS = '*';

	/**
	 * All entries.
	 *
	 * @return array<int,array<string,string>> email_hash, domain, event, reason, added_at.
	 */
	public static function entries(): array {
		return array_values( array_filter( (array) get_option( self::OPTION, [] ), 'is_array' ) );
	}

	/**
	 * Whether an email is suppressed for an event.
	 *
	 * @param string $email       Email address.
	 * @param string $event_hs_id HeySummit event ID ('' matches all-event entries only).
	 */
	public static function is_suppressed( string $email, string $event_hs_id = '' ): bool {
		$hash = self::hash( $email );

		if ( '' === $hash ) {
			return false;
		}

		foreach ( self::entries() as $entry ) {
			if ( ( $entry['email_hash'] ?? '' ) !== $hash ) {
				continue;
			}

			$scope = (string) ( $entry['event'] ?? self::ALL_EVENTS );
			if ( self::ALL_EVENTS === $scope || ( '' !== $event_hs_id && $scope === $event_hs_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add an entry (idempotent).
	 *
	 * @param string $email       Email address.
	 * @param string $event_hs_id Event scope (ALL_EVENTS for every event).
	 * @param string $reason      opt_out|erasure|manual.
	 */
	public static function add( string $email, string $event_hs_id = self::ALL_EVENTS, string $reason = 'manual' ): void {
		$hash = self::hash( $email );

		if ( '' === $hash ) {
			return;
		}

		$event_hs_id = '' !== $event_hs_id ? $event_hs_id : self::ALL_EVENTS;
		$entries     = self::entries();

		foreach ( $entries as $entry ) {
			if ( ( $entry['email_hash'] ?? '' ) === $hash && ( $entry['event'] ?? '' ) === $event_hs_id ) {
				return; // Already present.
			}
		}

		$entries[] = [
			'email_hash' => $hash,
			'domain'     => strtolower( (string) substr( (string) strrchr( $email, '@' ), 1 ) ),
			'event'      => $event_hs_id,
			'reason'     => $reason,
			'added_at'   => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];

		update_option( self::OPTION, $entries, false );

		Logger::info(
			Logger::CONTEXT_SYNC,
			sprintf( 'Suppression entry added (%s, scope %s).', $reason, $event_hs_id ),
			[ 'email' => $email ] // The logger redacts this to its hash prefix.
		);
	}

	/**
	 * Remove one entry by hash + event (manual admin action).
	 *
	 * @param string $email_hash Stored hash.
	 * @param string $event      Stored event scope.
	 */
	public static function remove( string $email_hash, string $event ): void {
		$entries = array_values(
			array_filter(
				self::entries(),
				static fn( array $entry ): bool => ( $entry['email_hash'] ?? '' ) !== $email_hash || ( $entry['event'] ?? '' ) !== $event
			)
		);

		update_option( self::OPTION, $entries, false );
	}

	/**
	 * The canonical email hash (same construction as the attribution table).
	 *
	 * @param string $email Email address.
	 */
	public static function hash( string $email ): string {
		$email = strtolower( trim( $email ) );

		return '' === $email ? '' : hash( 'sha256', $email );
	}
}
