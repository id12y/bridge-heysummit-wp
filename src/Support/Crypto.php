<?php
/**
 * Minimal crypto for short-lived PII at rest.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Support;

defined( 'ABSPATH' ) || exit;

/**
 * The pending-registration store holds a name and email for up to 48
 * hours; they are encrypted at rest so a database dump does not leak
 * them (the sibling newsletter plugin's doctrine, adopted). Keys derive
 * from the site's auth salts — nothing new to configure, and a leaked
 * database without wp-config stays sealed. Sodium (bundled with every
 * supported PHP) does the work; decryption FAILS CLOSED: any tamper or
 * key change yields '', never partial plaintext.
 *
 * Token and email hashes are deterministic HMACs on separate keys, so
 * lookups never require storing raw values (raw token goes into the
 * email only; raw email never lands in options or logs).
 */
final class Crypto {

	/**
	 * Encrypt a string (sodium secretbox, random nonce).
	 *
	 * @param string $plain Plaintext.
	 * @return string Versioned base64 blob ('' on failure).
	 */
	public static function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return '';
		}

		try {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe transport encoding, not obfuscation.
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Decrypt a blob produced by encrypt(). Fails closed.
	 *
	 * @param string $blob Versioned blob.
	 * @return string Plaintext, or '' when invalid/tampered.
	 */
	public static function decrypt( string $blob ): string {
		if ( ! str_starts_with( $blob, 's1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
			return '';
		}

		$raw = base64_decode( substr( $blob, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport decoding.

		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Throwable $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * A new random token (64 hex chars) — email the raw value, store only
	 * its hash.
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Deterministic token hash (HMAC-SHA256, its own derived key).
	 *
	 * @param string $token Raw token.
	 */
	public static function hash_token( string $token ): string {
		return hash_hmac( 'sha256', $token, self::derived( 'token' ) );
	}

	/**
	 * Deterministic case-insensitive email hash — lets cooldowns and
	 * receipts reference an address without storing it.
	 *
	 * @param string $email Email address.
	 */
	public static function hash_email( string $email ): string {
		return hash_hmac( 'sha256', strtolower( trim( $email ) ), self::derived( 'email' ) );
	}

	/**
	 * The secretbox key (32 bytes) from the auth salt.
	 */
	private static function key(): string {
		return hash( 'sha256', self::derived( 'box' ), true );
	}

	/**
	 * A purpose-separated key string from the site salts.
	 *
	 * @param string $purpose Key namespace.
	 */
	private static function derived( string $purpose ): string {
		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : (string) ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'eex' );

		return hash( 'sha256', $salt . '|eex|' . $purpose );
	}
}
