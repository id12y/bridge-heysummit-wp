<?php
/**
 * The shared HeySummit registration ledger.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events;

defined( 'ABSPATH' ) || exit;

/**
 * The generalisation of the v2 WooCommerce push record: one canonical
 * answer to "has this WordPress user been registered with HeySummit for
 * this event, and why". Stored as user meta (`_eex_hs_registrations`,
 * event ID => record). The Woo order-item record remains the per-item job
 * lock; this ledger is the cross-path idempotency store that the accounts
 * rules, the Woo bridge and manual pushes all read and write, so a user is
 * registered at most once per event regardless of which paths fire.
 *
 * Every record stores which rule, trigger and consent source justified the
 * push (or the order that did).
 */
final class Registrations {

	private const META_KEY = '_eex_hs_registrations';

	public const STATUS_PENDING = 'pending';
	public const STATUS_DONE    = 'done';
	public const STATUS_FAILED  = 'failed';

	/**
	 * The record for a user + event, or null.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id HeySummit event ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $user_id, string $event_hs_id ): ?array {
		$all = self::all( $user_id );

		return $all[ $event_hs_id ] ?? null;
	}

	/**
	 * All records for a user, event ID keyed.
	 *
	 * @param int $user_id User ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function all( int $user_id ): array {
		$all = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $all ) ? $all : [];
	}

	/**
	 * Whether a user is registered (done) or a push is already owned
	 * (pending) for an event. Both block further pushes: the record is the
	 * lock, exactly like the v2 order-item record.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id HeySummit event ID.
	 */
	public static function is_registered_or_pending( int $user_id, string $event_hs_id ): bool {
		$record = self::get( $user_id, $event_hs_id );

		return null !== $record && in_array( (string) ( $record['status'] ?? '' ), [ self::STATUS_DONE, self::STATUS_PENDING ], true );
	}

	/**
	 * Write (merge) a record.
	 *
	 * @param int                 $user_id     User ID.
	 * @param string              $event_hs_id HeySummit event ID.
	 * @param array<string,mixed> $data        status, attendee_hs_id, rule,
	 *                                         trigger, consent, source, note.
	 */
	public static function record( int $user_id, string $event_hs_id, array $data ): void {
		if ( $user_id <= 0 || '' === $event_hs_id ) {
			return;
		}

		$all = self::all( $user_id );

		$all[ $event_hs_id ] = array_merge(
			(array) ( $all[ $event_hs_id ] ?? [] ),
			$data,
			[ 'updated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ) ]
		);

		update_user_meta( $user_id, self::META_KEY, $all );
	}

	/**
	 * Release a pending lock after a terminal failure so a manual push can
	 * try again.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id HeySummit event ID.
	 * @param string $error       Failure message.
	 */
	public static function mark_failed( int $user_id, string $event_hs_id, string $error ): void {
		self::record(
			$user_id,
			$event_hs_id,
			[
				'status'     => self::STATUS_FAILED,
				'last_error' => $error,
			]
		);
	}

	/**
	 * Record a WooCommerce-originated registration for the purchaser's WP
	 * account, when one exists. Called by the Woo pusher on success so the
	 * accounts rules dedupe against purchases without any direct coupling.
	 *
	 * @param string $billing_email  Billing email.
	 * @param string $event_hs_id    HeySummit event ID.
	 * @param string $attendee_hs_id Created attendee ID.
	 * @param int    $order_id       Order ID.
	 */
	public static function record_woo_purchase( string $billing_email, string $event_hs_id, string $attendee_hs_id, int $order_id ): void {
		$user = function_exists( 'get_user_by' ) ? get_user_by( 'email', $billing_email ) : false;

		if ( ! $user ) {
			return; // Guest checkout: the order-item record and attribution row still hold the history.
		}

		self::record(
			(int) $user->ID,
			$event_hs_id,
			[
				'status'         => self::STATUS_DONE,
				'attendee_hs_id' => $attendee_hs_id,
				'source'         => 'woocommerce',
				'trigger'        => 'product_purchase',
				'consent'        => 'checkout_checkbox',
				'rule'           => 'order:' . $order_id,
			]
		);
	}
}
