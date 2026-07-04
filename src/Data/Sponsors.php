<?php
/**
 * Shared HeySummit sponsor fetching.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Api\PathStyles;
use Emailexpert\Events\Options;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * The sponsors endpoint arrived long after the rest of the API (the wall
 * was manual-only until then), so everything here is deliberately
 * defensive: field names are guessed across the API's known spellings,
 * unknown shapes degrade to skipped rows, and a failed or missing endpoint
 * yields an empty list — the wall then falls back to the operator's manual
 * rows exactly as before.
 */
final class Sponsors {

	/**
	 * Raw sponsor rows for one event, cached for 15 minutes.
	 *
	 * Tries the top-level route then the nested one, remembering which
	 * worked (the same account-shape split as tickets).
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public static function raw( string $connection_id, string $event_id ) {
		if ( '' === $connection_id || '' === $event_id ) {
			return new WP_Error( 'eex_sponsors_args', __( 'Choose a connection and event first.', 'emailexpert-events' ) );
		}

		$key    = 'eex_sponsors_' . md5( $connection_id . '|' . $event_id );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$connection = Options::connection( $connection_id );
		if ( null === $connection ) {
			return new WP_Error( 'eex_sponsors_connection', __( 'Connection not found or has no API key saved.', 'emailexpert-events' ) );
		}

		$client   = HeySummitClient::for_connection( $connection );
		$sponsors = $client->get_all( 'sponsors/', [ 'event' => $event_id ] );

		if ( is_wp_error( $sponsors ) ) {
			$nested = $client->get_all( 'events/' . rawurlencode( $event_id ) . '/sponsors/' );

			if ( ! is_wp_error( $nested ) ) {
				PathStyles::remember( $client->connection_id(), 'sponsors', 'nested' );
				$sponsors = $nested;
			}
		}

		if ( is_wp_error( $sponsors ) ) {
			return $sponsors;
		}

		$sponsors = array_values(
			array_filter(
				$sponsors,
				static fn( $sponsor ): bool => is_array( $sponsor ) && isset( $sponsor['id'] )
			)
		);

		set_transient( $key, $sponsors, 15 * MINUTE_IN_SECONDS );

		return $sponsors;
	}

	/**
	 * Display-shaped sponsor rows for the wall (the same shape the manual
	 * rows produce). Empty on any failure.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_display( string $connection_id, string $event_id ): array {
		$sponsors = self::raw( $connection_id, $event_id );

		if ( is_wp_error( $sponsors ) ) {
			return [];
		}

		$out = [];

		foreach ( $sponsors as $sponsor ) {
			if ( isset( $sponsor['is_active'] ) && false === $sponsor['is_active'] ) {
				continue;
			}

			$name = self::first_string( $sponsor, [ 'name', 'title', 'company_name' ] );
			if ( '' === $name ) {
				continue;
			}

			$tier = self::tier_of( $sponsor );

			$out[] = [
				'id'         => (int) $sponsor['id'],
				'name'       => $name,
				'url'        => self::first_url( $sponsor, [ 'url', 'website', 'website_url', 'link' ] ),
				'logo_id'    => 0,
				'logo_url'   => self::first_url( $sponsor, [ 'logo', 'logo_url', 'image', 'logo_white' ] ),
				'blurb'      => self::first_string( $sponsor, [ 'description', 'blurb', 'about' ] ),
				'tier_name'  => '' !== $tier['name'] ? $tier['name'] : __( 'Partner', 'emailexpert-events' ),
				'tier_order' => $tier['order'],
			];
		}

		return $out;
	}

	/**
	 * The first non-empty string among candidate keys.
	 *
	 * @param array<string,mixed> $row  Raw row.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function first_string( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) && '' !== trim( (string) $row[ $key ] ) ) {
				return sanitize_text_field( (string) $row[ $key ] );
			}
		}

		return '';
	}

	/**
	 * The first http(s) URL among candidate keys.
	 *
	 * @param array<string,mixed> $row  Raw row.
	 * @param array<int,string>   $keys Candidate keys.
	 */
	private static function first_url( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = isset( $row[ $key ] ) && is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';

			if ( '' !== $value && preg_match( '#^https?://#i', $value ) ) {
				return esc_url_raw( $value );
			}
		}

		return '';
	}

	/**
	 * The sponsorship tier, tolerating a plain string, a nested object, or
	 * nothing at all.
	 *
	 * @param array<string,mixed> $row Raw row.
	 * @return array{name:string,order:int}
	 */
	private static function tier_of( array $row ): array {
		foreach ( [ 'tier', 'tier_name', 'level', 'sponsorship_level' ] as $key ) {
			$value = $row[ $key ] ?? null;

			if ( is_array( $value ) ) {
				return [
					'name'  => self::first_string( $value, [ 'title', 'name' ] ),
					'order' => max( 0, (int) ( $value['order'] ?? $value['position'] ?? 99 ) ),
				];
			}

			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return [
					'name'  => sanitize_text_field( (string) $value ),
					'order' => max( 0, (int) ( $row['tier_order'] ?? $row['order'] ?? $row['position'] ?? 99 ) ),
				];
			}
		}

		return [
			'name'  => '',
			'order' => max( 0, (int) ( $row['tier_order'] ?? $row['order'] ?? $row['position'] ?? 99 ) ),
		];
	}
}
