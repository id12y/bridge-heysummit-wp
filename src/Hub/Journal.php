<?php
/**
 * The Hub change journal.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only. The auto-increment row id (exposed as `seq`) is the
 * /hub/changes cursor: strictly ordered, replay-safe (reading never
 * mutates anything) and immune to the clock problems of
 * updated-after-timestamp feeds — equal timestamps, clock skew and
 * concurrent writes cannot hide a row behind a cursor that has already
 * passed it. Rows hold no personal data (uuid, op, revision, timestamp),
 * so retention needs no GDPR pruning.
 */
class Journal {

	private const HEAD_OPTION = 'eex_hub_journal_head';

	/**
	 * Append one change.
	 *
	 * @param string $uuid     Contact UUID.
	 * @param string $op       'upsert' or 'delete'.
	 * @param int    $revision The contact revision this change produced.
	 * @return int The new sequence number.
	 */
	public function append( string $uuid, string $op, int $revision ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			Schema::journal_table(),
			[
				'uuid'        => $uuid,
				'object_type' => 'contact',
				'op'          => 'delete' === $op ? 'delete' : 'upsert',
				'revision'    => $revision,
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%d', '%s' ]
		);

		$seq = (int) $wpdb->insert_id;

		if ( $seq > (int) get_option( self::HEAD_OPTION, 0 ) ) {
			update_option( self::HEAD_OPTION, $seq, false );
		}

		return $seq;
	}

	/**
	 * One page of changes after a cursor position. Reading the same
	 * position always returns the same logical rows.
	 *
	 * @param int $after Exclusive sequence floor.
	 * @param int $limit Page size (already clamped by the caller).
	 * @return array<int,array<string,mixed>>
	 */
	public function page_after( int $after, int $limit ): array {
		global $wpdb;

		$table = Schema::journal_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		$rows = array_map(
			static fn( array $row ): array => [
				'seq'         => (int) ( $row['id'] ?? 0 ),
				'uuid'        => (string) ( $row['uuid'] ?? '' ),
				'object_type' => (string) ( $row['object_type'] ?? 'contact' ),
				'op'          => (string) ( $row['op'] ?? 'upsert' ),
				'revision'    => (int) ( $row['revision'] ?? 1 ),
				'created_at'  => (string) ( $row['created_at'] ?? '' ),
			],
			(array) $rows
		);

		usort( $rows, static fn( $a, $b ) => $a['seq'] <=> $b['seq'] );

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * The newest sequence number (0 = empty journal).
	 */
	public function head(): int {
		return (int) get_option( self::HEAD_OPTION, 0 );
	}
}
