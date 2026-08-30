<?php
/**
 * Hub service credentials.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use Emailexpert\Events\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Dedicated, revocable bearer tokens for the Hub — never a WordPress
 * login, never an administrator credential, and never able to reach
 * anything but the Hub routes (WordPress grants them no user, no
 * capabilities, no cookie session).
 *
 * The raw token is shown exactly once at creation and stored ONLY as a
 * purpose-keyed HMAC hash; the database never holds a usable secret.
 * Rotation = create a new token, switch the Hub, revoke the old one.
 * Revocation is immediate (a status flag checked on every request).
 */
class Credentials {

	/**
	 * Recognisable prefix so leaked tokens are findable by secret
	 * scanners; carries no entropy of its own.
	 */
	public const PREFIX = 'eexhub_';

	/**
	 * Create a credential. The returned raw token is the ONLY copy that
	 * will ever exist — it is not stored and cannot be recovered.
	 *
	 * @param string $label Operator-facing label.
	 * @return array<string,mixed> { id, label, token }
	 */
	public function create( string $label ): array {
		global $wpdb;

		$token = self::PREFIX . Crypto::new_token();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			Schema::credentials_table(),
			[
				'label'      => substr( sanitize_text_field( $label ), 0, 100 ),
				'token_hash' => Crypto::hash_token( $token ),
				'token_hint' => substr( $token, -4 ),
				'status'     => 'active',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		return [
			'id'    => (int) $wpdb->insert_id,
			'label' => $label,
			'token' => $token,
		];
	}

	/**
	 * Verify a presented token. Lookup is by deterministic hash (indexed,
	 * so timing reveals nothing about other rows) with a constant-time
	 * comparison on the stored hash as belt and braces.
	 *
	 * @param string $token Raw token from the Authorization header.
	 * @return array<string,mixed>|null The credential row, or null.
	 */
	public function verify( string $token ): ?array {
		if ( '' === $token || strlen( $token ) > 200 ) {
			return null;
		}

		global $wpdb;

		$table = Schema::credentials_table();
		$hash  = Crypto::hash_token( $token );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token_hash = %s", $hash ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		if ( null === $row || 'active' !== (string) ( $row['status'] ?? '' ) ) {
			return null;
		}

		if ( ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			return null;
		}

		$this->touch( (int) $row['id'], (string) ( $row['last_used_at'] ?? '' ) );

		return [
			'id'    => (int) $row['id'],
			'label' => (string) ( $row['label'] ?? '' ),
		];
	}

	/**
	 * Revoke a credential immediately.
	 *
	 * @param int $id Credential id.
	 * @return bool Whether an active credential was revoked.
	 */
	public function revoke( int $id ): bool {
		global $wpdb;

		$table = Schema::credentials_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table.
			ARRAY_A
		);

		if ( null === $row || 'active' !== (string) ( $row['status'] ?? '' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update(
			$table,
			[
				'status'     => 'revoked',
				'revoked_at' => gmdate( 'Y-m-d H:i:s' ),
			],
			[ 'id' => $id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);

		return true;
	}

	/**
	 * All credentials, safe fields only — hashes never leave this class.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		global $wpdb;

		$table = Schema::credentials_table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- own table, no user input.
		$rows = $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );

		return array_map(
			static fn( array $row ): array => [
				'id'           => (int) ( $row['id'] ?? 0 ),
				'label'        => (string) ( $row['label'] ?? '' ),
				'token_hint'   => '…' . (string) ( $row['token_hint'] ?? '' ),
				'status'       => (string) ( $row['status'] ?? '' ),
				'created_at'   => (string) ( $row['created_at'] ?? '' ),
				'last_used_at' => (string) ( $row['last_used_at'] ?? '' ),
				'revoked_at'   => (string) ( $row['revoked_at'] ?? '' ),
			],
			(array) $rows
		);
	}

	/**
	 * Stamp last_used_at, at most once a minute per credential.
	 *
	 * @param int    $id        Credential id.
	 * @param string $last_used Stored last_used_at.
	 */
	private function touch( int $id, string $last_used ): void {
		if ( '' !== $last_used && strtotime( $last_used . ' UTC' ) > time() - MINUTE_IN_SECONDS ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update(
			Schema::credentials_table(),
			[ 'last_used_at' => gmdate( 'Y-m-d H:i:s' ) ],
			[ 'id' => $id ],
			[ '%s' ],
			[ '%d' ]
		);
	}
}
