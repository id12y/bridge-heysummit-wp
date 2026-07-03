<?php
/**
 * Attribution table access.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * The system of record for registration attribution. Emails are stored only
 * as SHA-256 hashes of the lowercased address; the log is for debugging.
 */
final class Attribution {

	/**
	 * Insert an attribution row from a mapped attendee.
	 *
	 * @param array<string,mixed> $attendee    Mapped attendee.
	 * @param string              $event_hs_id HeySummit event ID.
	 * @param string              $status      started|completed.
	 * @return int Row ID.
	 */
	public static function insert( array $attendee, string $event_hs_id, string $status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'eex_attribution',
			[
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'event_hs_id'     => substr( $event_hs_id, 0, 64 ),
				'attendee_hs_id'  => substr( (string) ( $attendee['hs_id'] ?? '' ), 0, 64 ),
				'email_hash'      => (string) ( $attendee['email_hash'] ?? '' ),
				'status'          => in_array( $status, [ 'started', 'completed' ], true ) ? $status : 'started',
				'utm_source'      => substr( (string) ( $attendee['utm_source'] ?? '' ), 0, 191 ),
				'utm_medium'      => substr( (string) ( $attendee['utm_medium'] ?? '' ), 0, 191 ),
				'utm_campaign'    => substr( (string) ( $attendee['utm_campaign'] ?? '' ), 0, 191 ),
				'referer_domain'  => substr( (string) ( $attendee['referer_domain'] ?? '' ), 0, 191 ),
				'affiliate_email' => substr( (string) ( $attendee['affiliate_email'] ?? '' ), 0, 191 ),
				'ticket_name'     => substr( (string) ( $attendee['ticket_name'] ?? '' ), 0, 191 ),
				'amount_gross'    => substr( (string) ( $attendee['amount_gross'] ?? '' ), 0, 32 ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Whether a completed row exists for an email hash (and event).
	 *
	 * @param string $email_hash  Email hash.
	 * @param string $event_hs_id Event ID ('' = any event).
	 */
	public static function has_completed( string $email_hash, string $event_hs_id = '' ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		if ( '' !== $event_hs_id ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}eex_attribution WHERE email_hash = %s AND event_hs_id = %s AND status = 'completed'",
					$email_hash,
					$event_hs_id
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}eex_attribution WHERE email_hash = %s AND status = 'completed'",
					$email_hash
				)
			);
		}
		// phpcs:enable

		return (int) $count > 0;
	}

	/**
	 * Rows matching an email hash (privacy export).
	 *
	 * @param string $email_hash Email hash.
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows_for_hash( string $email_hash ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eex_attribution WHERE email_hash = %s", $email_hash ),
			ARRAY_A
		);
	}

	/**
	 * Delete rows matching an email hash (privacy erasure).
	 *
	 * @param string $email_hash Email hash.
	 * @return int Rows removed.
	 */
	public static function erase_hash( string $email_hash ): int {
		global $wpdb;

		$before = count( self::rows_for_hash( $email_hash ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}eex_attribution WHERE email_hash = %s", $email_hash )
		);

		return $before;
	}
}
