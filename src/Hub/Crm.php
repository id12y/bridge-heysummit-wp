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
	 * Update HINTS from the subscriber rows' own timestamps, oldest first.
	 * Hints only decide which contacts get looked at sooner — truth is
	 * the canonical projection hash, and the reconcile walk is the
	 * guarantee (tag REMOVALS, for one, leave no timestamp anywhere).
	 *
	 * @param string $since UTC 'Y-m-d H:i:s' floor (inclusive).
	 * @param int    $limit Maximum rows.
	 * @return array{rows:array<int,array{id:int,ts:string}>,truncated:bool}
	 */
	public function subscriber_hints( string $since, int $limit ): array {
		$table = $this->table( 'subscribers' );

		if ( '' === $table || $limit < 1 ) {
			return [
				'rows'      => [],
				'truncated' => false,
			];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only hint scan.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, updated_at FROM {$table} WHERE updated_at >= %s ORDER BY updated_at ASC, id ASC LIMIT %d", $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		return [
			'rows'      => array_map(
				static fn( array $row ): array => [
					'id' => (int) ( $row['id'] ?? 0 ),
					'ts' => (string) ( $row['updated_at'] ?? '' ),
				],
				$rows
			),
			'truncated' => count( $rows ) >= $limit,
		];
	}

	/**
	 * Update hints from the watched custom-field rows, oldest first.
	 *
	 * @param string $since UTC 'Y-m-d H:i:s' floor (inclusive).
	 * @param int    $limit Maximum rows per field.
	 * @return array{rows:array<int,array{id:int,ts:string}>,truncated:bool}
	 */
	public function field_hints( string $since, int $limit ): array {
		$table = $this->table( 'subscriber_custom_fields' );

		if ( '' === $table || $limit < 1 ) {
			return [
				'rows'      => [],
				'truncated' => false,
			];
		}

		global $wpdb;

		$hints     = [];
		$truncated = false;

		foreach ( $this->watched_fields() as $field_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only hint scan.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare( "SELECT subscriber_id, updated_at FROM {$table} WHERE field_id = %s AND updated_at >= %s ORDER BY updated_at ASC LIMIT %d", $field_id, $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
				ARRAY_A
			);

			$truncated = $truncated || count( $rows ) >= $limit;

			foreach ( $rows as $row ) {
				$hints[] = [
					'id' => (int) ( $row['subscriber_id'] ?? 0 ),
					'ts' => (string) ( $row['updated_at'] ?? '' ),
				];
			}
		}

		usort( $hints, static fn( $a, $b ) => strcmp( $a['ts'], $b['ts'] ) );

		return [
			'rows'      => $hints,
			'truncated' => $truncated,
		];
	}

	/**
	 * Update hints from tag ASSIGNMENTS, oldest first.
	 *
	 * @param string $since UTC 'Y-m-d H:i:s' floor (inclusive).
	 * @param int    $limit Maximum rows.
	 * @return array{rows:array<int,array{id:int,ts:string}>,truncated:bool}
	 */
	public function tag_hints( string $since, int $limit ): array {
		$table = $this->table( 'subscriber_tags' );

		if ( '' === $table || $limit < 1 ) {
			return [
				'rows'      => [],
				'truncated' => false,
			];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only hint scan.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT subscriber_id, created_at FROM {$table} WHERE created_at >= %s ORDER BY created_at ASC LIMIT %d", $since, $limit ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		return [
			'rows'      => array_map(
				static fn( array $row ): array => [
					'id' => (int) ( $row['subscriber_id'] ?? 0 ),
					'ts' => (string) ( $row['created_at'] ?? '' ),
				],
				$rows
			),
			'truncated' => count( $rows ) >= $limit,
		];
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
	 * Whether an address sits on a CRM suppression list. Delegates to the
	 * CRM's OWN checks — its hashing, soft-bounce thresholds, scope and
	 * expiry policies all live over there and are not reimplemented here.
	 * Null = unknown (the CRM's modern suppression engine could not
	 * answer); `false` is asserted only when it answered.
	 *
	 * @param string $email Email address.
	 */
	public function suppressed( string $email ): ?bool {
		if ( '' === $email ) {
			return null;
		}

		// Legacy v1 list — its static check applies the CRM's own
		// sub-threshold soft-bounce exclusion. A positive is decisive; a
		// negative alone is not enough to assert "not suppressed".
		if ( class_exists( 'EEN_Suppression_List' ) ) {
			try {
				if ( true === \EEN_Suppression_List::is_suppressed( $email ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// The modern engine: its check() applies the real hash scheme(s),
		// scope, expiry and policy rules in one place.
		if ( class_exists( 'EEN_Suppression_Service' ) ) {
			try {
				$decision = ( new \EEN_Suppression_Service() )->check( $email, [ 'scope_type' => 'global' ] );

				if ( is_array( $decision ) && array_key_exists( 'allowed', $decision ) ) {
					return false === $decision['allowed'];
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		return null;
	}

	/**
	 * Whether the contact holds at least one ACTIVE, un-snoozed
	 * subscription row — the CRM's live delivery membership (the
	 * subscribers.frequency column is a creation-time value that later
	 * per-frequency preference changes do not maintain). Null = the
	 * subscriptions table is unreadable.
	 *
	 * @param int $subscriber_id CRM subscriber id.
	 */
	public function has_active_subscription( int $subscriber_id ): ?bool {
		$table = $this->table( 'subscriptions' );

		if ( '' === $table ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only bounded probe (a handful of frequency rows per contact).
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT snoozed_until FROM {$table} WHERE subscriber_id = %d AND status = %s", $subscriber_id, 'active' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- registry table name.
			ARRAY_A
		);

		$now = gmdate( 'Y-m-d H:i:s' );

		foreach ( $rows as $row ) {
			$snoozed = (string) ( $row['snoozed_until'] ?? '' );

			if ( '' === $snoozed || $snoozed <= $now ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The slug of the CRM's "bought a ticket" marker tag. The CRM makes
	 * the tag name configurable (and disable-able): honour that instead
	 * of hard-coding the default. Null = the operator disabled the tag,
	 * so tag absence proves nothing.
	 */
	public function buyer_tag_slug(): ?string {
		$rules = get_option( 'een_tt_tag_rules', null );

		if ( is_array( $rules ) && array_key_exists( 'general_tag', $rules ) ) {
			$tag = trim( (string) $rules['general_tag'] );

			return '' !== $tag ? sanitize_title( $tag ) : null;
		}

		return 'ticket-buyer';
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
