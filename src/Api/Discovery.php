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

		// Write shape for the attendee-create endpoint: DRF describes the
		// POST body under actions.POST on an OPTIONS request — safe, nothing
		// is created. The allowlist entries are patterns needing a real
		// event ID; the ticket attach endpoint additionally needs an
		// attendee ID, so only create is sampled (its body is spec-verified:
		// email, name, ticket_price_id, questions).
		$sample_event = self::sample_event_id( $client );

		if ( '' !== $sample_event ) {
			$report['write:attendees'] = self::inspect_write_shape( $client, 'events/' . rawurlencode( $sample_event ) . '/attendees/' );
		}

		// Stamp the report: a stale report from an older build reads like a
		// live failure (and has sent operators chasing their API key).
		$report['_meta'] = [
			'version' => EEX_VERSION,
			'ran_at'  => gmdate( 'Y-m-d H:i' ) . ' UTC',
		];

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
	 * Inspect a write endpoint's POST schema via OPTIONS.
	 *
	 * @param HeySummitClient $client     Client.
	 * @param string          $write_path Allowlisted write path.
	 * @return array<string,mixed>
	 */
	private static function inspect_write_shape( HeySummitClient $client, string $write_path ): array {
		$response = $client->options_request( $write_path );

		if ( is_wp_error( $response ) ) {
			return [
				'error'         => $response->get_error_message(),
				'found'         => [],
				'missing'       => [],
				'unmapped'      => [],
				'type_mismatch' => [],
			];
		}

		$post_schema = $response['actions']['POST'] ?? null;

		if ( ! is_array( $post_schema ) ) {
			return [
				'error'         => '',
				'empty'         => true,
				'found'         => [],
				'missing'       => [ 'actions.POST (endpoint may not accept POST or OPTIONS is not descriptive)' ],
				'unmapped'      => [],
				'type_mismatch' => [],
			];
		}

		$found = [];
		foreach ( $post_schema as $field => $meta ) {
			$found[ (string) $field ] = is_array( $meta ) ? (string) ( $meta['type'] ?? 'unknown' ) : 'unknown';
		}

		return [
			'error'         => '',
			'found'         => $found,
			'missing'       => [],
			'unmapped'      => [],
			'type_mismatch' => [],
		];
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

		// Some accounts refuse top-level collection routes (403) but serve
		// the same resources nested under an event (DRF hyperlinked style,
		// verified live). Sample the nested route before reporting an error.
		if ( is_wp_error( $response ) && in_array( $resource_slug, [ 'talks', 'speakers', 'categories', 'tickets', 'attendees', 'sponsors' ], true ) ) {
			$event_id = self::sample_event_id( $client );

			if ( '' !== $event_id ) {
				$nested = $client->get( 'events/' . rawurlencode( $event_id ) . '/' . $resource_slug . '/' );

				if ( ! is_wp_error( $nested ) ) {
					$report         = self::compare_sample( $nested, $expected );
					$report['note'] = sprintf( 'Top-level %1$s/ refused (%2$s — normal for this account shape, not a key problem); served nested under events/<id>/%1$s/ instead, which is the route the plugin uses on this connection.', $resource_slug, self::short_error( $response ) );
					return $report;
				}

				return [
					'error'         => sprintf( 'Both routes failed: top-level %1$s/ (%2$s) and events/%3$s/%1$s/ (%4$s). If other resources work, this is a route/permission quirk on the account, not a bad key.', $resource_slug, $response->get_error_message(), $event_id, $nested->get_error_message() ),
					'found'         => [],
					'missing'       => [],
					'unmapped'      => [],
					'type_mismatch' => [],
				];
			}
		}

		if ( is_wp_error( $response ) ) {
			return [
				'error'         => $response->get_error_message(),
				'found'         => [],
				'missing'       => [],
				'unmapped'      => [],
				'type_mismatch' => [],
			];
		}

		return self::compare_sample( $response, $expected );
	}

	/**
	 * The first event ID visible on the connection (for nested sampling).
	 *
	 * @param HeySummitClient $client Client.
	 */
	private static function sample_event_id( HeySummitClient $client ): string {
		$events = $client->get( 'events/' );

		if ( is_wp_error( $events ) ) {
			return '';
		}

		$fallback = '';

		foreach ( (array) ( $events['results'] ?? [] ) as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['id'] ) || ! is_scalar( $event['id'] ) ) {
				continue;
			}

			if ( '' === $fallback ) {
				$fallback = (string) $event['id'];
			}

			// Prefer an event that has sessions, so the talks and speakers
			// samples have records to inspect instead of "no records".
			if ( ! empty( $event['first_talk_at'] ) ) {
				return (string) $event['id'];
			}
		}

		return $fallback;
	}

	/**
	 * An error reduced to its HTTP status for inline notes (the full message
	 * speculates about causes, which reads wrong inside a reassurance).
	 *
	 * @param \WP_Error $error Error.
	 */
	private static function short_error( \WP_Error $error ): string {
		if ( preg_match( '/HTTP \\d{3}/', (string) $error->get_error_message(), $m ) ) {
			return $m[0];
		}

		return $error->get_error_message();
	}

	/**
	 * Compare a collection response's first record against expected fields.
	 *
	 * @param array<string,mixed>               $response Decoded response.
	 * @param array<string,array<string,mixed>> $expected Expected fields.
	 * @return array<string,mixed>
	 */
	private static function compare_sample( array $response, array $expected ): array {

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
