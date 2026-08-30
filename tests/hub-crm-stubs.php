<?php
/**
 * Minimal stubs of the sibling CRM plugin's (EmailExpert Newsletter, EEN_)
 * public classes, backed by the fake wpdb — enough to unit test the Hub
 * module's read-only adapter without the real plugin. Guarded like every
 * other stub so the suite can also run beside the real thing.
 *
 * @package Emailexpert\Events\Tests
 */

// phpcs:ignoreFile -- test infrastructure, mirrors the CRM plugin's public API.

if ( ! class_exists( 'EEN_Table_Registry' ) ) {
	class EEN_Table_Registry {
		public static function get_subscribers_table(): string {
			return 'wp_een_subscribers';
		}

		public static function get_subscriber_custom_fields_table(): string {
			return 'wp_een_subscriber_custom_fields';
		}

		public static function get_tags_table(): string {
			return 'wp_een_tags';
		}

		public static function get_subscriber_tags_table(): string {
			return 'wp_een_subscriber_tags';
		}

		public static function get_suppression_list_table(): string {
			return 'wp_een_suppression_list';
		}

		public static function get_suppressions_table(): string {
			return 'wp_een_suppressions';
		}

		public static function get_subscriptions_table(): string {
			return 'wp_een_subscriptions';
		}
	}
}

if ( ! class_exists( 'EEN_Suppression_List' ) ) {
	/**
	 * Mirrors the CRM's static v1 check, including its sub-threshold
	 * soft-bounce exclusion (rows below the threshold are tracking, not
	 * suppression).
	 */
	class EEN_Suppression_List {
		public static function is_suppressed( string $email ): bool {
			global $wpdb;

			$normalized = strtolower( trim( $email ) );

			foreach ( $wpdb->tables['wp_een_suppression_list'] ?? [] as $row ) {
				if ( ( $row['email'] ?? '' ) !== $normalized ) {
					continue;
				}

				if ( 'soft_bounce' === ( $row['reason'] ?? '' ) && (int) ( $row['soft_bounce_count'] ?? 0 ) < 5 ) {
					continue; // Tracking record, not a suppression yet.
				}

				return true;
			}

			return false;
		}
	}
}

if ( ! class_exists( 'EEN_Suppression_Service' ) ) {
	/**
	 * Mirrors the CRM's v2 engine: its OWN peppered hash (deliberately
	 * different from EEN_Encryption::hash_email, as in the real plugin)
	 * and expiry-aware global-scope lookups, answering with a Decision
	 * array.
	 */
	class EEN_Suppression_Service {
		public function hash_email( string $email_norm ): string {
			return hash( 'sha256', 'een-suppress-pepper|' . $email_norm );
		}

		public function check( string $email, array $context = [] ): array {
			global $wpdb;

			$hash = $this->hash_email( strtolower( trim( $email ) ) );
			$now  = gmdate( 'Y-m-d H:i:s' );

			foreach ( $wpdb->tables['wp_een_suppressions'] ?? [] as $row ) {
				if ( ( $row['email_hash'] ?? '' ) !== $hash || 'global' !== ( $row['scope_type'] ?? 'global' ) ) {
					continue;
				}

				$expires = (string) ( $row['expires_at'] ?? '' );
				if ( '' !== $expires && $expires <= $now ) {
					continue; // Expired temporary suppression.
				}

				return [
					'allowed'     => false,
					'reason_code' => (string) ( $row['reason'] ?? 'manual' ),
				];
			}

			return [
				'allowed'     => true,
				'reason_code' => '',
			];
		}
	}
}

if ( ! class_exists( 'EEN_Subscriber' ) ) {
	class EEN_Subscriber {
		private array $row;

		public function __construct( array $row ) {
			$this->row = $row;
		}

		public function get_id(): int {
			return (int) ( $this->row['id'] ?? 0 );
		}

		public function get_email(): string {
			return (string) ( $this->row['email'] ?? '' );
		}

		public function get_first_name(): string {
			return (string) ( $this->row['first_name'] ?? '' );
		}

		public function get_last_name(): string {
			return (string) ( $this->row['last_name'] ?? '' );
		}

		public function get_status(): string {
			return (string) ( $this->row['status'] ?? '' );
		}

		public function get_frequency(): string {
			return (string) ( $this->row['frequency'] ?? 'weekly' );
		}

		public function get_news_digest_off(): bool {
			return (bool) ( $this->row['news_digest_off'] ?? false );
		}

		public function get_updated_at(): ?string {
			return isset( $this->row['updated_at'] ) ? (string) $this->row['updated_at'] : null;
		}
	}
}

if ( ! class_exists( 'EEN_Subscriber_Repository' ) ) {
	class EEN_Subscriber_Repository {
		public function __construct( $encryption = null ) {}

		public function find_by_id( int $id ): ?EEN_Subscriber {
			if ( ! empty( $GLOBALS['een_test_fail_find'][ $id ] ) ) {
				throw new RuntimeException( 'simulated transient CRM read failure' );
			}

			global $wpdb;

			foreach ( $wpdb->tables['wp_een_subscribers'] ?? [] as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					return new EEN_Subscriber( $row );
				}
			}

			return null;
		}
	}
}

if ( ! class_exists( 'EEN_Encryption' ) ) {
	class EEN_Encryption {
		public function hash_email( string $email ): string {
			return hash( 'sha256', 'een-test|' . strtolower( trim( $email ) ) );
		}
	}
}

/**
 * Seed one CRM subscriber row into the fake wpdb. Returns its id. As in
 * the real CRM, a confirmed subscriber with a delivery frequency also
 * holds an ACTIVE row in the live subscriptions table (frequency 'none'
 * seeds no row).
 */
function eex_hub_seed_subscriber( array $overrides = [] ): int {
	global $wpdb;

	$row = array_merge(
		[
			'email'           => 'person@example.org',
			'first_name'      => 'Pat',
			'last_name'       => 'Example',
			'status'          => 'confirmed',
			'frequency'       => 'weekly',
			'news_digest_off' => 0,
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
		],
		$overrides
	);

	$wpdb->insert( 'wp_een_subscribers', $row );
	$id = (int) $wpdb->insert_id;

	if ( 'confirmed' === $row['status'] && 'none' !== $row['frequency'] ) {
		eex_hub_seed_subscription( $id, (string) $row['frequency'] );
	}

	return $id;
}

/**
 * Seed one live subscription row (the CRM's per-frequency delivery
 * membership).
 */
function eex_hub_seed_subscription( int $subscriber_id, string $frequency = 'weekly', string $status = 'active', string $snoozed_until = '' ): void {
	global $wpdb;

	$wpdb->insert(
		'wp_een_subscriptions',
		[
			'subscriber_id' => $subscriber_id,
			'frequency'     => $frequency,
			'status'        => $status,
			'snoozed_until' => $snoozed_until,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => gmdate( 'Y-m-d H:i:s' ),
		]
	);
}

/**
 * Seed a custom-field row for a subscriber.
 */
function eex_hub_seed_custom_field( int $subscriber_id, string $field_id, string $value, string $updated_at = '' ): void {
	global $wpdb;

	$wpdb->insert(
		'wp_een_subscriber_custom_fields',
		[
			'subscriber_id' => $subscriber_id,
			'field_id'      => $field_id,
			'field_value'   => $value,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
			'updated_at'    => '' !== $updated_at ? $updated_at : gmdate( 'Y-m-d H:i:s' ),
		]
	);
}

/**
 * Seed a tag (by slug) and attach it to a subscriber.
 */
function eex_hub_seed_tag( int $subscriber_id, string $slug ): void {
	global $wpdb;

	$tag_id = null;
	foreach ( $wpdb->tables['wp_een_tags'] ?? [] as $row ) {
		if ( ( $row['slug'] ?? '' ) === $slug ) {
			$tag_id = (int) $row['id'];
			break;
		}
	}

	if ( null === $tag_id ) {
		$wpdb->insert(
			'wp_een_tags',
			[
				'name' => $slug,
				'slug' => $slug,
			]
		);
		$tag_id = (int) $wpdb->insert_id;
	}

	$wpdb->insert(
		'wp_een_subscriber_tags',
		[
			'subscriber_id' => $subscriber_id,
			'tag_id'        => $tag_id,
			'created_at'    => gmdate( 'Y-m-d H:i:s' ),
		]
	);
}

/**
 * Remove a tag assignment (simulates a CRM tag removal, which leaves no
 * timestamp anywhere — the reconcile pass must catch it).
 */
function eex_hub_remove_tag( int $subscriber_id, string $slug ): void {
	global $wpdb;

	$tag_id = null;
	foreach ( $wpdb->tables['wp_een_tags'] ?? [] as $row ) {
		if ( ( $row['slug'] ?? '' ) === $slug ) {
			$tag_id = (int) $row['id'];
			break;
		}
	}

	if ( null === $tag_id ) {
		return;
	}

	$wpdb->tables['wp_een_subscriber_tags'] = array_values(
		array_filter(
			$wpdb->tables['wp_een_subscriber_tags'] ?? [],
			static fn( $row ) => ! ( (int) ( $row['subscriber_id'] ?? 0 ) === $subscriber_id && (int) ( $row['tag_id'] ?? 0 ) === $tag_id )
		)
	);
}

/**
 * Hard-delete a subscriber row (simulates the CRM's GDPR purge).
 */
function eex_hub_purge_subscriber( int $subscriber_id ): void {
	global $wpdb;

	$wpdb->tables['wp_een_subscribers'] = array_values(
		array_filter(
			$wpdb->tables['wp_een_subscribers'] ?? [],
			static fn( $row ) => (int) ( $row['id'] ?? 0 ) !== $subscriber_id
		)
	);
}
