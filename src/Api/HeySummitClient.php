<?php
/**
 * HeySummit API client.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Api;

use Emailexpert\Events\Logging\Logger;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only client for the HeySummit v2 API.
 *
 * Hard rules: GET only, never a write or action endpoint; the API key is
 * never logged or returned in any response surface.
 */
class HeySummitClient {

	public const BASE_URL = 'https://app.heysummit.com/api/v2/';

	/**
	 * API key for this connection.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Connection ID, for log correlation.
	 *
	 * @var string
	 */
	private string $connection_id;

	/**
	 * Constructor.
	 *
	 * @param string $api_key       API key.
	 * @param string $connection_id Connection ID.
	 */
	public function __construct( string $api_key, string $connection_id = '' ) {
		$this->api_key       = $api_key;
		$this->connection_id = $connection_id;
	}

	/**
	 * Build a client for a stored connection.
	 *
	 * @param array<string,string> $connection Connection row.
	 */
	public static function for_connection( array $connection ): self {
		return new self( (string) ( $connection['api_key'] ?? '' ), (string) ( $connection['id'] ?? '' ) );
	}

	/**
	 * The connection this client belongs to (path-style memory is per
	 * connection).
	 */
	public function connection_id(): string {
		return $this->connection_id;
	}

	/**
	 * Perform a GET request against a relative API path.
	 *
	 * Retries twice with backoff on 5xx responses and timeouts; never on 4xx.
	 * Render-time callers (the Lite live repository) pass a short timeout
	 * and zero retries so a page never waits on the API.
	 *
	 * @param string              $path    Relative path, e.g. 'events/'.
	 * @param array<string,mixed> $args    Query arguments.
	 * @param array<string,int>   $options timeout (seconds), retries.
	 * @return array<string,mixed>|WP_Error Decoded body or error.
	 */
	public function get( string $path, array $args = [], array $options = [] ) {
		$url = self::BASE_URL . ltrim( $path, '/' );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url );
		}

		$timeout = max( 1, (int) ( $options['timeout'] ?? 15 ) );

		$attempts    = 0;
		$max_retries = max( 0, (int) ( $options['retries'] ?? 2 ) );

		do {
			$started  = microtime( true );
			$response = wp_remote_get(
				$url,
				[
					'timeout' => $timeout,
					'headers' => [
						'Authorization' => 'Token ' . $this->api_key,
						'Accept'        => 'application/json',
					],
				]
			);
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

			$retryable       = false;
			$transport_error = '';

			if ( is_wp_error( $response ) ) {
				$transport_error = $response->get_error_message();
				$this->log_request( $path, 0, $duration, $transport_error );
				$retryable = true;
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$body   = json_decode( wp_remote_retrieve_body( $response ), true );
				$detail = self::api_detail( $body );
				$log_id = $this->log_request( $path, $status, $duration, $detail );

				if ( $status >= 500 ) {
					$retryable = true;
				} elseif ( 401 === $status || 403 === $status ) {
					return new WP_Error(
						'eex_auth',
						sprintf(
							/* translators: 1: endpoint path, 2: HTTP status, 3: API error detail (may be empty). */
							__( 'HeySummit API key invalid or lacks access (GET %1$s → HTTP %2$d%3$s). Check the key and that the account is on the Business plan.', 'emailexpert-events' ),
							$path,
							$status,
							'' !== $detail ? ' — ' . $detail : ''
						),
						self::error_data( $status, $path, $detail, $log_id )
					);
				} elseif ( $status >= 400 ) {
					return new WP_Error(
						'eex_http',
						sprintf(
							/* translators: 1: HTTP status, 2: endpoint path, 3: API error detail (may be empty), 4: log reference (may be empty). */
							__( 'HeySummit API returned HTTP %1$d for GET %2$s%3$s%4$s', 'emailexpert-events' ),
							$status,
							$path,
							'' !== $detail ? ' — ' . $detail : '.',
							self::log_ref( $log_id )
						),
						self::error_data( $status, $path, $detail, $log_id )
					);
				} else {
					if ( ! is_array( $body ) ) {
						return new WP_Error(
							'eex_json',
							sprintf(
								/* translators: %s: endpoint path. */
								__( 'HeySummit API returned a response that is not valid JSON (GET %s).', 'emailexpert-events' ),
								$path
							),
							self::error_data( $status, $path, '', $log_id )
						);
					}

					return $body;
				}
			}

			++$attempts;

			if ( $retryable && $attempts <= $max_retries ) {
				/**
				 * Filter the retry backoff in seconds. Tests set this to 0.
				 *
				 * @param int $delay   Seconds to sleep before retrying.
				 * @param int $attempt Attempt number just failed (1-based).
				 */
				$delay = (int) apply_filters( 'eex_http_retry_delay', $attempts, $attempts );
				if ( $delay > 0 ) {
					sleep( $delay );
				}
			}
		} while ( $retryable && $attempts <= $max_retries );

		return new WP_Error(
			'eex_unreachable',
			sprintf(
				/* translators: 1: endpoint path, 2: attempt count, 3: last transport error or HTTP 5xx note. */
				__( 'HeySummit API unreachable (GET %1$s, %2$d attempt(s)%3$s).', 'emailexpert-events' ),
				$path,
				$attempts,
				'' !== $transport_error ? '; last error: ' . $transport_error : '; the API answered HTTP 5xx'
			),
			[
				'endpoint' => $path,
				'attempts' => $attempts,
				'error'    => $transport_error,
			]
		);
	}

	/**
	 * A short human-readable detail string from an API error body. DRF puts
	 * the reason under detail/message/error or field => [messages]; the
	 * first one found is truncated and tag-stripped so it is safe to show
	 * an administrator and store in a note.
	 *
	 * @param mixed $body Decoded response body.
	 */
	public static function api_detail( $body ): string {
		if ( ! is_array( $body ) ) {
			return '';
		}

		foreach ( [ 'detail', 'message', 'error', 'non_field_errors' ] as $key ) {
			if ( isset( $body[ $key ] ) ) {
				$value = $body[ $key ];
				$value = is_array( $value ) ? reset( $value ) : $value;

				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return self::truncate_detail( (string) $value );
				}
			}
		}

		// Field-level validation errors: "field: first message".
		foreach ( $body as $field => $value ) {
			if ( is_string( $field ) && is_array( $value ) && isset( $value[0] ) && is_scalar( $value[0] ) ) {
				return self::truncate_detail( $field . ': ' . (string) $value[0] );
			}
		}

		return '';
	}

	/**
	 * Sanitise and bound a detail string.
	 *
	 * @param string $detail Raw detail.
	 */
	private static function truncate_detail( string $detail ): string {
		$detail = trim( wp_strip_all_tags( $detail ) );

		return strlen( $detail ) > 200 ? substr( $detail, 0, 197 ) . '…' : $detail;
	}

	/**
	 * Structured data attached to every client error, so callers can build
	 * their own messages without re-parsing ours.
	 *
	 * @param int    $status HTTP status.
	 * @param string $path   Endpoint path.
	 * @param string $detail API detail string.
	 * @param int    $log_id Log row ID (0 when logging went to the ring buffer).
	 * @return array<string,mixed>
	 */
	private static function error_data( int $status, string $path, string $detail, int $log_id ): array {
		return [
			'status'   => $status,
			'endpoint' => $path,
			'detail'   => $detail,
			'log_id'   => $log_id,
		];
	}

	/**
	 * A log pointer suffix for admin-facing messages.
	 *
	 * @param int $log_id Log row ID.
	 */
	private static function log_ref( int $log_id ): string {
		return $log_id > 0 ? sprintf( ' (sync log #%d)', $log_id ) : '';
	}

	/**
	 * Fetch every page of a paginated collection.
	 *
	 * Follows DRF-style `next` links until exhausted, with a safety cap.
	 *
	 * @param string              $path    Relative path.
	 * @param array<string,mixed> $args    Query arguments for the first page.
	 * @param array<string,mixed> $options Request options (timeout, retries)
	 *                                     plus an optional max_pages override
	 *                                     for latency-sensitive callers.
	 * @return array<int,array<string,mixed>>|WP_Error All results, or error.
	 */
	public function get_all( string $path, array $args = [], array $options = [] ) {
		/**
		 * Filter the hard cap on pages followed per collection.
		 *
		 * @param int    $max_pages Maximum pages (default 50).
		 * @param string $path      The collection path.
		 */
		$max_pages = (int) apply_filters( 'eex_max_pages', (int) ( $options['max_pages'] ?? 50 ), $path );

		$results = [];
		$page    = $this->get( $path, $args, $options );
		$pages   = 1;

		while ( true ) {
			if ( is_wp_error( $page ) ) {
				return $page;
			}

			if ( isset( $page['results'] ) && is_array( $page['results'] ) ) {
				$results = array_merge( $results, $page['results'] );
			} elseif ( array_is_list( $page ) ) {
				// Tolerate a plain list response.
				$results = array_merge( $results, $page );
			} else {
				// A single object; return it as a one-item list.
				$results[] = $page;
			}

			$next = isset( $page['next'] ) && is_string( $page['next'] ) ? $page['next'] : '';

			if ( '' === $next || $pages >= $max_pages ) {
				if ( '' !== $next && $pages >= $max_pages ) {
					Logger::warning(
						Logger::CONTEXT_API,
						sprintf( 'Pagination cap of %d pages reached for %s; results truncated.', $max_pages, $path ),
						[ 'connection' => $this->connection_id ]
					);
				}
				break;
			}

			$page = $this->get_absolute( $next, $options );
			++$pages;
		}

		return $results;
	}

	/**
	 * Test the connection by fetching the events collection.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function test() {
		return $this->get( 'events/' );
	}

	/**
	 * Perform a POST against an allowlisted write endpoint.
	 *
	 * Writes exist solely for the WooCommerce bridge (attendee create,
	 * external ticket sale import). Any path outside
	 * WriteEndpoints::ALLOWLIST throws — this is the enforcement point for
	 * the amended hard rule, not a convention. No transport-level retries:
	 * a duplicated attendee-create is worse than a failed one, so the push
	 * job owns retrying with its local dedupe record.
	 *
	 * @param string              $path Relative path, e.g. 'attendees/'.
	 * @param array<string,mixed> $body JSON body.
	 * @return array<string,mixed>|WP_Error Decoded response body or error.
	 *
	 * @throws \InvalidArgumentException When the endpoint is not allowlisted.
	 */
	public function post( string $path, array $body ) {
		if ( ! WriteEndpoints::allowed( $path ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Refusing to write to non-allowlisted HeySummit endpoint "%s".', esc_html( $path ) )
			);
		}

		$url = self::BASE_URL . ltrim( $path, '/' );

		$started  = microtime( true );
		$response = wp_remote_post(
			$url,
			[
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Token ' . $this->api_key,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->log_request( 'POST ' . $path, 0, $duration, $response->get_error_message() );

			return new WP_Error(
				'eex_unreachable',
				sprintf(
					/* translators: 1: endpoint path, 2: transport error. */
					__( 'HeySummit API unreachable (POST %1$s; %2$s).', 'emailexpert-events' ),
					$path,
					$response->get_error_message()
				),
				[
					'endpoint' => $path,
					'error'    => $response->get_error_message(),
				]
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$detail  = self::api_detail( $decoded );
		$log_id  = $this->log_request( 'POST ' . $path, $status, $duration, $detail );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error(
				'eex_auth',
				sprintf(
					/* translators: 1: endpoint path, 2: HTTP status, 3: API error detail (may be empty). */
					__( 'HeySummit API key invalid or lacks access (POST %1$s → HTTP %2$d%3$s).', 'emailexpert-events' ),
					$path,
					$status,
					'' !== $detail ? ' — ' . $detail : ''
				),
				[
					'status'   => $status,
					'endpoint' => $path,
					'detail'   => $detail,
					'log_id'   => $log_id,
				]
			);
		}

		if ( $status >= 400 ) {
			return new WP_Error(
				'eex_http',
				sprintf(
					/* translators: 1: HTTP status, 2: endpoint path, 3: API error detail (may be empty), 4: log reference (may be empty). */
					__( 'HeySummit API returned HTTP %1$d for POST %2$s%3$s%4$s', 'emailexpert-events' ),
					$status,
					$path,
					'' !== $detail ? ' — ' . $detail : '.',
					$log_id > 0 ? sprintf( ' (sync log #%d)', $log_id ) : ''
				),
				[
					'status'   => $status,
					'endpoint' => $path,
					'detail'   => $detail,
					'log_id'   => $log_id,
					'body'     => is_array( $decoded ) ? $decoded : [],
				]
			);
		}

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * Perform an OPTIONS request (safe, non-mutating). DRF describes the
	 * POST body schema under actions.POST, which the discovery diagnostic
	 * uses to verify the write shapes without creating anything.
	 *
	 * @param string $path Relative path.
	 * @return array<string,mixed>|WP_Error
	 */
	public function options_request( string $path ) {
		$url = self::BASE_URL . ltrim( $path, '/' );

		$started  = microtime( true );
		$response = wp_remote_request(
			$url,
			[
				'method'  => 'OPTIONS',
				'timeout' => 15,
				'headers' => [
					'Authorization' => 'Token ' . $this->api_key,
					'Accept'        => 'application/json',
				],
			]
		);
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$this->log_request( 'OPTIONS ' . $path, 0, $duration, $response->get_error_message() );

			return new WP_Error( 'eex_unreachable', __( 'HeySummit API unreachable.', 'emailexpert-events' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$this->log_request( 'OPTIONS ' . $path, $status, $duration );

		if ( $status >= 400 ) {
			return new WP_Error(
				'eex_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'HeySummit API returned HTTP %d.', 'emailexpert-events' ), $status ),
				[ 'status' => $status ]
			);
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : [];
	}

	/**
	 * GET an absolute `next` URL, provided it stays on the API host.
	 *
	 * @param string              $url     Absolute URL from a `next` field.
	 * @param array<string,mixed> $options Request options (timeout, retries).
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_absolute( string $url, array $options = [] ) {
		if ( ! str_starts_with( $url, self::BASE_URL ) ) {
			// Never follow a pagination link off the API host.
			return new WP_Error( 'eex_pagination', __( 'Pagination link left the HeySummit API host; aborting.', 'emailexpert-events' ) );
		}

		return $this->get( substr( $url, strlen( self::BASE_URL ) ), [], $options );
	}

	/**
	 * Log one request outcome. Never includes the API key.
	 *
	 * @param string $path     Request path.
	 * @param int    $status   HTTP status (0 for transport error).
	 * @param int    $duration Milliseconds.
	 * @param string $error    Transport error or API detail, if any.
	 * @return int Log row ID (0 in Lite's ring buffer).
	 */
	private function log_request( string $path, int $status, int $duration, string $error = '' ): int {
		$level = ( $status >= 400 || 0 === $status ) ? 'warning' : 'info';

		return Logger::log(
			Logger::CONTEXT_API,
			$level,
			sprintf( 'GET %s -> %s (%dms)', $path, $status ?: 'transport error', $duration ),
			array_filter(
				[
					'connection' => $this->connection_id,
					'endpoint'   => $path,
					'status'     => $status,
					'duration'   => $duration,
					'error'      => $error,
				]
			)
		);
	}
}
