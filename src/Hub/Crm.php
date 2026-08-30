<?php
/**
 * Read-only adapter over the sibling CRM plugin.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * The one place Hub code touches CRM data. Everything is READ-ONLY and
 * guarded: the EmailExpert Newsletter plugin (EEN_) may be inactive, an
 * older version, or mid-upgrade — every accessor then degrades to null /
 * empty instead of fataling, and the Hub reports the facts as unknown.
 * Email decryption goes through the CRM's own repository classes; this
 * adapter never reimplements its cryptography.
 *
 * SQL here reads the CRM's tables directly only for non-personal columns
 * (ids, timestamps, tag slugs, plaintext custom-field values) with
 * prepared, bounded, single-table queries.
 */
class Crm {

	/**
	 * Per-request tag map cache (id => slug).
	 *
	 * @var array<int,string>|null
	 */
	private ?array $tag_map = null;

	/**
	 * Whether the CRM plugin's classes are present.
	 */
	public function available(): bool {
		return class_exists( 'EEN_Table_Registry' ) && class_exists( 'EEN_Subscriber_Repository' );
	}

	/**
	 * Total CRM contacts (capabilities/backfill reporting).
	 */
	public function count_subscribers(): int {
		$table = $this->table( 'subscribers' );

		if ( '' === $table ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- read-only count on the CRM's table; name from its registry.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Subscriber ids above a floor, ascending — the enrolment feed.
	 *
	 * @param int $after Exclusive floor id.
	 * @param int $limit Maximum ids.
	 * @return array<int,int>
	 */
	public function ids_after( int $after, int $limit ): array {
		$table = $this->table( 'subscribers' );

		if ( '' === $table || $limit < 1 ) {
			return [];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only id scan on the CRM's table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from the CRM's registry.
				$after,
				$limit
			),
			ARRAY_A
		);

		$ids = array_map( static fn( $row ) => (int) $row['id'], (array) $rows );
		sort( $ids );

		return array_slice( $ids, 0, $limit );
	}

	/**
	 * Subscriber ids whose projection inputs changed since a watermark:
	 * the subscriber row itself, an allowlisted custom field, or a tag
	 * assignment. Tag REMOVALS leave no timestamp anywhere in the CRM —
	 * the sweeper's reconciliation walk catches those.
	 *
	 * @param string $since UTC 'Y-m-d H:i:s' watermark (inclusive).
	 * @param int    $limit Maximum ids.
	 * @return array<int,int>
	 */
	public function ids_updated_since( string $since, int $limit ): array {
		if ( $limit < 1 ) {
			return [];
		}

		global $wpdb;

		$ids = [];

		$subscribers = $this->table( 'subscribers' );
		if ( '' !== $subscribers ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only id scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT id FROM {$subscribers} WHERE updated_at >= %s ORDER BY updated_at ASC LIMIT %d", $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$ids[ (int) $row['id'] ] = true;
			}
		}

		$fields = $this->table( 'subscriber_custom_fields' );
		if ( '' !== $fields ) {
			foreach ( $this->watched_fields() as $field_id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only id scan.
				$rows = $wpdb->get_results(
					$wpdb->prepare( "SELECT subscriber_id FROM {$fields} WHERE field_id = %s AND updated_at >= %s LIMIT %d", $field_id, $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
					ARRAY_A
				);
				foreach ( (array) $rows as $row ) {
					$ids[ (int) $row['subscriber_id'] ] = true;
				}
			}
		}

		$pivot = $this->table( 'subscriber_tags' );
		if ( '' !== $pivot ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only id scan.
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT subscriber_id FROM {$pivot} WHERE created_at >= %s LIMIT %d", $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$ids[ (int) $row['subscriber_id'] ] = true;
			}
		}

		$list = array_map( 'intval', array_keys( $ids ) );
		sort( $list );

		return array_slice( $list, 0, $limit );
	}

	/**
	 * Whether a subscriber row still exists.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 */
	public function exists( int $subscriber_id ): bool {
		$table = $this->table( 'subscribers' );

		if ( '' === $table ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only existence probe.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $subscriber_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		return null !== $row;
	}

	/**
	 * The allowlisted contact columns, email decrypted by the CRM's own
	 * repository. Null when the CRM is unavailable or the row is gone.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<string,mixed>|null
	 */
	public function contact( int $subscriber_id ): ?array {
		if ( ! $this->available() ) {
			return null;
		}

		try {
			$repository = new \EEN_Subscriber_Repository();
			$subscriber = $repository->find_by_id( $subscriber_id );

			if ( null === $subscriber ) {
				return null;
			}

			return [
				'email'           => (string) $subscriber->get_email(),
				'first_name'      => (string) $subscriber->get_first_name(),
				'last_name'       => (string) $subscriber->get_last_name(),
				'status'          => (string) $subscriber->get_status(),
				'frequency'       => (string) $subscriber->get_frequency(),
				'news_digest_off' => (bool) $subscriber->get_news_digest_off(),
				'updated_at'      => (string) ( $subscriber->get_updated_at() ?? '' ),
			];
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * One plaintext custom-field row (value + updated_at), or null.
	 *
	 * @param int    $subscriber_id CRM subscriber id.
	 * @param string $field_id      Field id, e.g. '_source'.
	 * @return array<string,string>|null
	 */
	public function custom_field_row( int $subscriber_id, string $field_id ): ?array {
		$table = $this->table( 'subscriber_custom_fields' );

		if ( '' === $table ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only plaintext custom-field fetch.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT field_value, updated_at FROM {$table} WHERE subscriber_id = %d AND field_id = %s", $subscriber_id, $field_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		if ( null === $row ) {
			return null;
		}

		return [
			'value'      => (string) ( $row['field_value'] ?? '' ),
			'updated_at' => (string) ( $row['updated_at'] ?? '' ),
		];
	}

	/**
	 * A contact's tag slugs. Null when tags are unreadable (so callers can
	 * distinguish "no tags" from "unknown").
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<int,string>|null
	 */
	public function tag_slugs( int $subscriber_id ): ?array {
		$pivot = $this->table( 'subscriber_tags' );
		$map   = $this->tag_map();

		if ( '' === $pivot || null === $map ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only pivot fetch.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT tag_id FROM {$pivot} WHERE subscriber_id = %d", $subscriber_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		$slugs = [];
		foreach ( (array) $rows as $row ) {
			$slug = $map[ (int) ( $row['tag_id'] ?? 0 ) ] ?? '';
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		sort( $slugs );

		return $slugs;
	}

	/**
	 * The durable Ticket Tailor allocation entries for a contact, exactly
	 * as the CRM stores them (order/ticket ids, labels, box-office label,
	 * status snapshot). Empty array when none; entries are already free of
	 * payment data.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<int,array<string,mixed>>
	 */
	public function allocated_tickets( int $subscriber_id ): array {
		$row = $this->custom_field_row( $subscriber_id, '_tt_allocated_tickets' );

		if ( null === $row || '' === $row['value'] ) {
			return [];
		}

		$entries = json_decode( $row['value'], true );

		if ( ! is_array( $entries ) ) {
			return [];
		}

		$tickets = [];
		foreach ( $entries as $entry ) {
			if ( is_array( $entry ) ) {
				$entry['_field_updated_at'] = $row['updated_at'];
				$tickets[]                  = $entry;
			}
		}

		return $tickets;
	}

	/**
	 * Whether an address sits on a CRM suppression list. Null = unknown
	 * (CRM unavailable or its suppression internals unreadable).
	 *
	 * @param string $email Email address.
	 */
	public function suppressed( string $email ): ?bool {
		if ( '' === $email ) {
			return null;
		}

		global $wpdb;

		$known = false;

		$v1 = $this->table( 'suppression_list' );
		if ( '' !== $v1 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only suppression probe.
			$row = $wpdb->get_row(
				$wpdb->prepare( "SELECT id FROM {$v1} WHERE email = %s", strtolower( trim( $email ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
				ARRAY_A
			);

			if ( null !== $row ) {
				return true;
			}

			$known = true;
		}

		$v2 = $this->table( 'suppressions' );
		if ( '' !== $v2 && class_exists( 'EEN_Encryption' ) ) {
			try {
				$encryption = new \EEN_Encryption();
				$hash       = (string) $encryption->hash_email( $email );

				if ( '' !== $hash ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only suppression probe.
					$row = $wpdb->get_row(
						$wpdb->prepare( "SELECT id FROM {$v2} WHERE email_hash = %s AND scope_type = %s", $hash, 'global' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
						ARRAY_A
					);

					if ( null !== $row ) {
						return true;
					}

					$known = true;
				}
			} catch ( \Throwable $e ) {
				// Fall through: the v1 answer (if any) stands.
				unset( $e );
			}
		}

		return $known ? false : null;
	}

	/**
	 * Configured Ticket Tailor box offices as safe labels only — API keys
	 * are stripped before anything leaves this method.
	 *
	 * @return array<int,array<string,string>>
	 */
	public function box_offices(): array {
		$offices = get_option( 'een_ticket_tailor_box_offices', [] );

		if ( ! is_array( $offices ) ) {
			return [];
		}

		$safe = [];
		foreach ( $offices as $office ) {
			$label = is_array( $office ) ? (string) ( $office['label'] ?? '' ) : '';

			if ( '' !== $label ) {
				$safe[] = [
					'id'    => sanitize_title( $label ),
					'label' => $label,
				];
			}
		}

		return $safe;
	}

	/**
	 * Custom-field ids that feed the Hub projection.
	 *
	 * @return array<int,string>
	 */
	private function watched_fields(): array {
		return array_values(
			array_unique(
				[
					'_source',
					'_tt_allocated_tickets',
					(string) Settings::get( 'tier_field' ),
				]
			)
		);
	}

	/**
	 * The id => slug map of all CRM tags (small table, cached per request).
	 *
	 * @return array<int,string>|null
	 */
	private function tag_map(): ?array {
		if ( null !== $this->tag_map ) {
			return $this->tag_map;
		}

		$table = $this->table( 'tags' );

		if ( '' === $table ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only fetch of the small tag catalogue.
		$rows = $wpdb->get_results( "SELECT id, slug FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name, no user input.

		$map = [];
		foreach ( (array) $rows as $row ) {
			$map[ (int) ( $row['id'] ?? 0 ) ] = (string) ( $row['slug'] ?? '' );
		}

		$this->tag_map = $map;

		return $map;
	}

	/**
	 * A CRM table name via the CRM's own registry — '' when unavailable,
	 * so every caller degrades instead of guessing at schema.
	 *
	 * @param string $which Registry suffix, e.g. 'subscribers'.
	 */
	private function table( string $which ): string {
		if ( ! class_exists( 'EEN_Table_Registry' ) ) {
			return '';
		}

		$method = 'get_' . $which . '_table';

		if ( ! method_exists( 'EEN_Table_Registry', $method ) ) {
			return '';
		}

		try {
			return (string) \EEN_Table_Registry::$method();
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
