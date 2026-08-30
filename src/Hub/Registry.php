<?php
/**
 * The Hub contact identity registry.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the immutable contact_uuid. A UUID is minted once per CRM
 * subscriber, never changes when the email or name changes, never leaks
 * the CRM's row id, and is never recycled: deletion flips the row to a
 * minimal tombstone (uuid, revision, deleted_at — the subscriber id and
 * projection hash are cleared) instead of removing it, so the Hub can
 * observe the deletion while nothing personal survives erasure.
 */
class Registry {

	/**
	 * Enrol a CRM subscriber, minting its UUID. Idempotent: an existing
	 * row is returned untouched.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<string,mixed>|null The registry row.
	 */
	public function enroll( int $subscriber_id ): ?array {
		$existing = $this->by_subscriber( $subscriber_id );

		if ( null !== $existing ) {
			return $existing;
		}

		global $wpdb;

		$now = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			Schema::contacts_table(),
			[
				'subscriber_id'   => $subscriber_id,
				'uuid'            => wp_generate_uuid4(),
				'revision'        => 1,
				'projection_hash' => '',
				'state'           => 'active',
				'created_at'      => $now,
				'updated_at'      => $now,
			],
			[ '%d', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);

		return $this->by_subscriber( $subscriber_id );
	}

	/**
	 * The registry row for a CRM subscriber.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<string,mixed>|null
	 */
	public function by_subscriber( int $subscriber_id ): ?array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE subscriber_id = %d", $subscriber_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		return null !== $row ? $this->normalise( $row ) : null;
	}

	/**
	 * The registry row for a contact UUID.
	 *
	 * @param string $uuid Contact UUID.
	 * @return array<string,mixed>|null
	 */
	public function by_uuid( string $uuid ): ?array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		return null !== $row ? $this->normalise( $row ) : null;
	}

	/**
	 * One deterministic snapshot page, ordered by registry id (an insertion
	 * order that never changes), tombstones included.
	 *
	 * @param int $after Exclusive registry-id floor.
	 * @param int $limit Page size (already clamped by the caller).
	 * @return array<int,array<string,mixed>>
	 */
	public function page_after( int $after, int $limit ): array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		$rows = array_map( [ $this, 'normalise' ], (array) $rows );
		usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] );

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * Record a projection change: bump the revision, store the new hash.
	 *
	 * @param int    $registry_id Registry row id.
	 * @param string $hash        New canonical projection hash.
	 * @return int The new revision (0 when the row is gone).
	 */
	public function bump( int $registry_id, string $hash ): int {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $registry_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		if ( null === $row ) {
			return 0;
		}

		$revision = (int) $row['revision'] + 1;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update(
			$table,
			[
				'revision'        => $revision,
				'projection_hash' => $hash,
				'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $registry_id ],
			[ '%d', '%s', '%s' ],
			[ '%d' ]
		);

		return $revision;
	}

	/**
	 * Flip a row to its minimal tombstone. Idempotent.
	 *
	 * @param int $registry_id Registry row id.
	 * @return int The tombstone revision (0 when the row is gone or already deleted).
	 */
	public function mark_deleted( int $registry_id ): int {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $registry_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		if ( null === $row || 'deleted' === $row['state'] ) {
			return 0;
		}

		$revision = (int) $row['revision'] + 1;
		$now      = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update(
			$table,
			[
				'subscriber_id'   => null,
				'revision'        => $revision,
				'projection_hash' => '',
				'state'           => 'deleted',
				'updated_at'      => $now,
				'deleted_at'      => $now,
			],
			[ 'id' => $registry_id ],
			[ '%d', '%d', '%s', '%s', '%s', '%s' ],
			[ '%d' ]
		);

		return $revision;
	}

	/**
	 * Row counts for capabilities/backfill reporting.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;

		$table = Schema::contacts_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no user input.
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$deleted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE state = 'deleted'" );
		// phpcs:enable

		return [
			'total'   => $total,
			'active'  => $total - $deleted,
			'deleted' => $deleted,
		];
	}

	/**
	 * Typed registry row.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array<string,mixed>
	 */
	private function normalise( array $row ): array {
		return [
			'id'              => (int) ( $row['id'] ?? 0 ),
			'subscriber_id'   => isset( $row['subscriber_id'] ) && null !== $row['subscriber_id'] && '' !== $row['subscriber_id'] ? (int) $row['subscriber_id'] : null,
			'uuid'            => (string) ( $row['uuid'] ?? '' ),
			'revision'        => (int) ( $row['revision'] ?? 1 ),
			'projection_hash' => (string) ( $row['projection_hash'] ?? '' ),
			'state'           => (string) ( $row['state'] ?? 'active' ),
			'created_at'      => (string) ( $row['created_at'] ?? '' ),
			'updated_at'      => (string) ( $row['updated_at'] ?? '' ),
			'deleted_at'      => isset( $row['deleted_at'] ) && '' !== (string) $row['deleted_at'] ? (string) $row['deleted_at'] : null,
		];
	}
}
