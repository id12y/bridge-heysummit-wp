<?php
/**
 * Form submission push queue.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The pending form pushes, keyed by a dedupe hash of email + event +
 * mapping, so a double-submitted form (or a re-fired plugin hook) never
 * produces two attendees. Entries hold the submission data until delivery
 * and are deleted on success — the queue is a buffer, not a ledger. A hard
 * size cap keeps a runaway form from growing an autoloaded-adjacent option
 * without bound; hitting it is logged loudly rather than silently dropped.
 */
final class Queue {

	public const OPTION = 'eex_forms_queue';

	public const MAX_ENTRIES = 500;

	/**
	 * All entries, keyed by ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		return array_filter( (array) get_option( self::OPTION, [] ), 'is_array' );
	}

	/**
	 * One entry.
	 *
	 * @param string $id Entry ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $id ): ?array {
		$entries = self::all();

		return isset( $entries[ $id ] ) ? $entries[ $id ] : null;
	}

	/**
	 * The dedupe key for a submission.
	 *
	 * @param string $email      Email address.
	 * @param string $event      HeySummit event ID.
	 * @param string $mapping_id Mapping ID.
	 */
	public static function key_for( string $email, string $event, string $mapping_id ): string {
		return hash( 'sha256', strtolower( trim( $email ) ) . '|' . $event . '|' . $mapping_id );
	}

	/**
	 * Add an entry. False when a duplicate already queued/failed, or the
	 * queue is full.
	 *
	 * @param string              $id    Entry ID (key_for()).
	 * @param array<string,mixed> $entry Entry data.
	 */
	public static function add( string $id, array $entry ): bool {
		$entries = self::all();

		if ( isset( $entries[ $id ] ) ) {
			return false; // The existing entry (pending, retrying or failed) owns this submission.
		}

		if ( count( $entries ) >= self::MAX_ENTRIES ) {
			Logger::error(
				Logger::CONTEXT_API,
				sprintf( 'Forms queue is full (%d entries) — a submission was NOT queued. Clear failed pushes on the Bridges screen.', self::MAX_ENTRIES )
			);

			return false;
		}

		$entries[ $id ] = $entry;
		update_option( self::OPTION, $entries, false );

		return true;
	}

	/**
	 * Update an entry in place.
	 *
	 * @param string              $id    Entry ID.
	 * @param array<string,mixed> $entry New data.
	 */
	public static function update( string $id, array $entry ): void {
		$entries = self::all();

		if ( isset( $entries[ $id ] ) ) {
			$entries[ $id ] = $entry;
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * Delete an entry.
	 *
	 * @param string $id Entry ID.
	 */
	public static function delete( string $id ): void {
		$entries = self::all();

		if ( isset( $entries[ $id ] ) ) {
			unset( $entries[ $id ] );
			update_option( self::OPTION, $entries, false );
		}
	}

	/**
	 * Entries with a given status.
	 *
	 * @param string $status pending|failed.
	 * @return array<string,array<string,mixed>>
	 */
	public static function with_status( string $status ): array {
		return array_filter(
			self::all(),
			static fn( array $entry ): bool => (string) ( $entry['status'] ?? '' ) === $status
		);
	}
}
