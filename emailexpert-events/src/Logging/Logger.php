<?php
/**
 * Sync log writer.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Logging;

defined( 'ABSPATH' ) || exit;

/**
 * Writes structured entries to the eex_log table. Email addresses inside the
 * data payload are redacted before storage: the local part is replaced with a
 * SHA-256 hash prefix so entries can still be correlated with the attribution
 * table without holding raw addresses.
 */
final class Logger {

	public const CONTEXT_SYNC    = 'sync';
	public const CONTEXT_WEBHOOK = 'webhook';
	public const CONTEXT_API     = 'api';

	/**
	 * Write a log entry.
	 *
	 * @param string              $context One of sync|webhook|api.
	 * @param string              $level   One of info|warning|error.
	 * @param string              $message Human-readable message.
	 * @param array<string,mixed> $data    Structured payload; emails redacted.
	 * @return int Inserted row ID, 0 on failure.
	 */
	public static function log( string $context, string $level, string $message, array $data = [] ): int {
		global $wpdb;

		$data = self::redact( $data );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->insert(
			$wpdb->prefix . 'eex_log',
			[
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'context'    => substr( $context, 0, 20 ),
				'level'      => substr( $level, 0, 10 ),
				'message'    => $message,
				'data'       => empty( $data ) ? null : wp_json_encode( $data ),
			],
			[ '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Convenience wrappers.
	 *
	 * @param string              $context Context.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data    Payload.
	 * @return int
	 */
	public static function info( string $context, string $message, array $data = [] ): int {
		return self::log( $context, 'info', $message, $data );
	}

	/**
	 * Log a warning.
	 *
	 * @param string              $context Context.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data    Payload.
	 * @return int
	 */
	public static function warning( string $context, string $message, array $data = [] ): int {
		return self::log( $context, 'warning', $message, $data );
	}

	/**
	 * Log an error.
	 *
	 * @param string              $context Context.
	 * @param string              $message Message.
	 * @param array<string,mixed> $data    Payload.
	 * @return int
	 */
	public static function error( string $context, string $message, array $data = [] ): int {
		return self::log( $context, 'error', $message, $data );
	}

	/**
	 * Fetch one log row by ID.
	 *
	 * @param int $id Row ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}eex_log WHERE id = %d", $id ),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Recursively redact email addresses in a payload.
	 *
	 * @param mixed $value Payload branch.
	 * @return mixed
	 */
	public static function redact( $value ) {
		if ( is_array( $value ) ) {
			return array_map( [ self::class, 'redact' ], $value );
		}

		if ( is_string( $value ) ) {
			return preg_replace_callback(
				'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
				static function ( array $m ): string {
					[ $local, $domain ] = explode( '@', $m[0], 2 );
					unset( $local );
					$hash = substr( hash( 'sha256', strtolower( $m[0] ) ), 0, 12 );

					return $hash . '@' . $domain;
				},
				$value
			);
		}

		return $value;
	}

	/**
	 * Delete entries older than the retention window (30 days).
	 */
	public static function prune(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$wpdb->prefix}eex_log WHERE created_at < %s", $cutoff )
		);
	}
}
