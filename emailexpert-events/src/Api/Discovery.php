<?php
/**
 * Runtime API shape discovery.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches a sample record from each known resource, records the actual field
 * names and types (never values that could contain personal data), and
 * compares them against the shapes the mappers expect. The result is stored
 * per connection for the diagnostics panel and logged flagged `discovery`.
 */
final class Discovery {

	/**
	 * Run discovery against a connection.
	 *
	 * @param HeySummitClient $client        Client.
	 * @param string          $connection_id Connection ID.
	 * @return array<string,array<string,mixed>> Report keyed by resource.
	 */
	public static function run( HeySummitClient $client, string $connection_id ): array {
		$report = [];

		foreach ( array_keys( Shapes::RESOURCES ) as $resource ) {
			$report[ $resource ] = self::inspect_resource( $client, $resource );
		}

		update_option( 'eex_discovery_' . sanitize_key( $connection_id ), $report, false );

		$has_missing = array_filter( $report, static fn( $r ) => ! empty( $r['missing'] ) || ! empty( $r['type_mismatch'] ) );

		Logger::log(
			Logger::CONTEXT_API,
			$has_missing ? 'warning' : 'info',
			$has_missing
				? 'discovery: shape mismatches found; see diagnostics panel'
				: 'discovery: all expected fields present',
			[
				'flag'       => 'discovery',
				'connection' => $connection_id,
				'report'     => $report,
			]
		);

		return $report;
	}

	/**
	 * Retrieve the stored report for a connection.
	 *
	 * @param string $connection_id Connection ID.
	 * @return array<string,array<string,mixed>>
	 */
	public static function stored_report( string $connection_id ): array {
		return (array) get_option( 'eex_discovery_' . sanitize_key( $connection_id ), [] );
	}

	/**
	 * Inspect one resource.
	 *
	 * @param HeySummitClient $client        Client.
	 * @param string          $resource_slug Resource slug (path segment).
	 * @return array<string,mixed>
	 */
	private static function inspect_resource( HeySummitClient $client, string $resource_slug ): array {
		$expected = Shapes::RESOURCES[ $resource_slug ];
		$response = $client->get( $resource_slug . '/' );

		if ( is_wp_error( $response ) ) {
			return [
				'error'         => $response->get_error_message(),
				'found'         => [],
				'missing'       => [],
				'unmapped'      => [],
				'type_mismatch' => [],
			];
		}

		$sample = null;
		if ( isset( $response['results'] ) && is_array( $response['results'] ) && ! empty( $response['results'] ) ) {
			$sample = $response['results'][0];
		} elseif ( array_is_list( $response ) && ! empty( $response ) ) {
			$sample = $response[0];
		}

		if ( ! is_array( $sample ) ) {
			return [
				'error'         => '',
				'empty'         => true,
				'found'         => [],
				'missing'       => [],
				'unmapped'      => [],
				'type_mismatch' => [],
			];
		}

		$found         = [];
		$type_mismatch = [];

		foreach ( $sample as $field => $value ) {
			$found[ (string) $field ] = Shapes::describe_type( $value );
		}

		$missing = [];
		foreach ( $expected as $field => $meta ) {
			if ( ! array_key_exists( $field, $sample ) ) {
				if ( empty( $meta['optional'] ) ) {
					$missing[] = $field;
				}
				continue;
			}

			if ( null !== $sample[ $field ] && ! Shapes::matches_type( $sample[ $field ], $meta['type'] ) ) {
				$type_mismatch[ $field ] = [
					'expected' => $meta['type'],
					'found'    => Shapes::describe_type( $sample[ $field ] ),
				];
			}
		}

		$unmapped = array_values( array_diff( array_keys( $found ), array_keys( $expected ) ) );

		return [
			'error'         => '',
			'found'         => $found,
			'missing'       => $missing,
			'unmapped'      => $unmapped,
			'type_mismatch' => $type_mismatch,
		];
	}
}
