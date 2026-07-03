<?php
/**
 * Personal data exporter and eraser.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers with Tools → Export/Erase Personal Data. Given a requester's
 * email, the same hashing (SHA-256 of the lowercased address) locates
 * matching attribution rows, and the 12-character hash prefix used by the
 * log redactor locates matching log entries.
 */
final class Privacy {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporter' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_eraser' ] );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array<string,mixed> $exporters Exporters.
	 * @return array<string,mixed>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['emailexpert-events'] = [
			'exporter_friendly_name' => __( 'emailexpert Events registration attribution', 'emailexpert-events' ),
			'callback'               => [ $this, 'export' ],
		];

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array<string,mixed> $erasers Erasers.
	 * @return array<string,mixed>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['emailexpert-events'] = [
			'eraser_friendly_name' => __( 'emailexpert Events registration attribution', 'emailexpert-events' ),
			'callback'             => [ $this, 'erase' ],
		];

		return $erasers;
	}

	/**
	 * Export attribution rows for an email address.
	 *
	 * @param string $email_address Requester email.
	 * @param int    $page          Page (single page; the data set is small).
	 * @return array<string,mixed>
	 */
	public function export( string $email_address, int $page = 1 ): array {
		$hash = self::hash( $email_address );
		$data = [];

		foreach ( Attribution::rows_for_hash( $hash ) as $row ) {
			$items = [];
			foreach ( [ 'created_at', 'event_hs_id', 'status', 'utm_source', 'utm_medium', 'utm_campaign', 'referer_domain', 'ticket_name', 'amount_gross' ] as $field ) {
				if ( '' !== (string) ( $row[ $field ] ?? '' ) ) {
					$items[] = [
						'name'  => $field,
						'value' => (string) $row[ $field ],
					];
				}
			}

			$data[] = [
				'group_id'    => 'eex_attribution',
				'group_label' => __( 'Event registration attribution', 'emailexpert-events' ),
				'item_id'     => 'eex-attribution-' . (int) ( $row['id'] ?? 0 ),
				'data'        => $items,
			];
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	/**
	 * Erase attribution rows and matching log entries for an email address.
	 *
	 * @param string $email_address Requester email.
	 * @param int    $page          Page.
	 * @return array<string,mixed>
	 */
	public function erase( string $email_address, int $page = 1 ): array {
		global $wpdb;

		$hash    = self::hash( $email_address );
		$removed = Attribution::erase_hash( $hash );

		// Erasure suppresses future pushes for this address across all
		// events, so no rule, backfill or retry can ever re-add them.
		\Emailexpert\Events\Accounts\Suppression::add( $email_address, \Emailexpert\Events\Accounts\Suppression::ALL_EVENTS, 'erasure' );

		// Log entries carry the address redacted to a 12-character hash
		// prefix (see Logger::redact); delete rows containing it.
		$prefix = substr( $hash, 0, 12 );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}eex_log WHERE data LIKE %s",
				'%' . $wpdb->esc_like( $prefix ) . '%'
			)
		);

		$messages = [
			__( 'The address has been added to the plugin\'s suppression list, so it will never be re-registered automatically.', 'emailexpert-events' ),
			__( 'Removing the attendee from HeySummit itself is a manual step: delete the registration in the HeySummit dashboard.', 'emailexpert-events' ),
		];
		if ( $removed > 0 ) {
			array_unshift(
				$messages,
				sprintf( /* translators: %d: number of rows. */ __( '%d attribution record(s) removed.', 'emailexpert-events' ), $removed )
			);
		}

		return [
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => $messages,
			'done'           => true,
		];
	}

	/**
	 * The canonical email hash.
	 *
	 * @param string $email_address Email.
	 */
	public static function hash( string $email_address ): string {
		return hash( 'sha256', strtolower( trim( $email_address ) ) );
	}
}
