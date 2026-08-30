<?php
/**
 * Opaque, tamper-evident pagination cursors.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use Emailexpert\Events\Support\Crypto;

defined( 'ABSPATH' ) || exit;

/**
 * Cursors are positions in server-owned monotonic sequences (registry id,
 * journal seq), MAC-signed and base64url-encoded so clients can neither
 * read nor forge them, and typed per endpoint so a contacts cursor can
 * never replay against the changes feed. Decoding never touches state:
 * cursor replay is free of side effects by construction.
 */
final class Cursor {

	/**
	 * Encode a cursor.
	 *
	 * @param string              $type Endpoint type tag ('contacts', 'changes', 'tickets').
	 * @param array<string,mixed> $data Position payload (small scalars only).
	 */
	public static function encode( string $type, array $data ): string {
		$payload = (string) wp_json_encode( [ $type, $data ] );
		$mac     = substr( Crypto::mac( $payload, 'hub_cursor' ), 0, 32 );

		return rtrim( strtr( base64_encode( $payload . '|' . $mac ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque transport encoding of a signed cursor.
	}

	/**
	 * Decode and verify a cursor. Null on any mismatch — callers reject
	 * the request rather than guessing a position.
	 *
	 * @param string $cursor Raw cursor parameter.
	 * @param string $type   Expected endpoint type tag.
	 * @return array<string,mixed>|null
	 */
	public static function decode( string $cursor, string $type ): ?array {
		if ( '' === $cursor || strlen( $cursor ) > 400 ) {
			return null;
		}

		$raw = base64_decode( strtr( $cursor, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding.

		if ( false === $raw ) {
			return null;
		}

		$split = strrpos( $raw, '|' );

		if ( false === $split ) {
			return null;
		}

		$payload = substr( $raw, 0, $split );
		$mac     = substr( $raw, $split + 1 );

		if ( ! hash_equals( substr( Crypto::mac( $payload, 'hub_cursor' ), 0, 32 ), $mac ) ) {
			return null;
		}

		$decoded = json_decode( $payload, true );

		if ( ! is_array( $decoded ) || 2 !== count( $decoded ) || ( $decoded[0] ?? '' ) !== $type || ! is_array( $decoded[1] ) ) {
			return null;
		}

		return $decoded[1];
	}
}
