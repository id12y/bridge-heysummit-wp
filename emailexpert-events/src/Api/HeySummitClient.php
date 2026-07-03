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
	 * Perform a GET request against a relative API path.
	 *
	 * Retries twice with backoff on 5xx responses and timeouts; never on 4xx.
	 *
	 * @param string              $path Relative path, e.g. 'events/'.
	 * @param array<string,mixed> $args Query arguments.
	 * @return array<string,mixed>|WP_Error Decoded body or error.
	 */
	public function get( string $path, array $args = [] ) {
		$url = self::BASE_URL . ltrim( $path, '/' );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url );
		}

		$attempts    = 0;
		$max_retries = 2;

		do {
			$started  = microtime( true );
			$response = wp_remote_get(
				$url,
				[
					'timeout' => 15,
					'headers' => [
						'Authorization' => 'Token ' . $this->api_key,
						'Accept'        => 'application/json',
					],
				]
			);
			$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

			$retryable = false;

			if ( is_wp_error( $response ) ) {
				$this->log_request( $path, 0, $duration, $response->get_error_message() );
				$retryable = true;
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$this->log_request( $path, $status, $duration );

				if ( $status >= 500 ) {
					$retryable = true;
				} elseif ( 401 === $status || 403 === $status ) {
					return new WP_Error(
						'eex_auth',
						__( 'HeySummit API key invalid or lacks access.', 'emailexpert-events' ),
						[ 'status' => $status ]
					);
				} elseif ( $status >= 400 ) {
					return new WP_Error(
						'eex_http',
						/* translators: %d: HTTP status code. */
						sprintf( __( 'HeySummit API returned HTTP %d.', 'emailexpert-events' ), $status ),
						[ 'status' => $status ]
					);
				} else {
					$body = json_decode( wp_remote_retrieve_body( $response ), true );

					if ( ! is_array( $body ) ) {
						return new WP_Error( 'eex_json', __( 'HeySummit API returned a response that is not valid JSON.', 'emailexpert-events' ) );
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

		return new WP_Error( 'eex_unreachable', __( 'HeySummit API unreachable after retries.', 'emailexpert-events' ) );
	}

	/**
	 * Fetch every page of a paginated collection.
	 *
	 * Follows DRF-style `next` links until exhausted, with a safety cap.
	 *
	 * @param string              $path Relative path.
	 * @param array<string,mixed> $args Query arguments for the first page.
	 * @return array<int,array<string,mixed>>|WP_Error All results, or error.
	 */
	public function get_all( string $path, array $args = [] ) {
		/**
		 * Filter the hard cap on pages followed per collection.
		 *
		 * @param int    $max_pages Maximum pages (default 50).
		 * @param string $path      The collection path.
		 */
		$max_pages = (int) apply_filters( 'eex_max_pages', 50, $path );

		$results = [];
		$page    = $this->get( $path, $args );
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

			$page = $this->get_absolute( $next );
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

			return new WP_Error( 'eex_unreachable', __( 'HeySummit API unreachable.', 'emailexpert-events' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$this->log_request( 'POST ' . $path, $status, $duration );

		if ( 401 === $status || 403 === $status ) {
			return new WP_Error( 'eex_auth', __( 'HeySummit API key invalid or lacks access.', 'emailexpert-events' ), [ 'status' => $status ] );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			return new WP_Error(
				'eex_http',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'HeySummit API returned HTTP %d.', 'emailexpert-events' ), $status ),
				[
					'status' => $status,
					'body'   => is_array( $decoded ) ? $decoded : [],
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
	 * @param string $url Absolute URL from a `next` field.
	 * @return array<string,mixed>|WP_Error
	 */
	private function get_absolute( string $url ) {
		if ( ! str_starts_with( $url, self::BASE_URL ) ) {
			// Never follow a pagination link off the API host.
			return new WP_Error( 'eex_pagination', __( 'Pagination link left the HeySummit API host; aborting.', 'emailexpert-events' ) );
		}

		return $this->get( substr( $url, strlen( self::BASE_URL ) ) );
	}

	/**
	 * Log one request outcome. Never includes the API key.
	 *
	 * @param string $path     Request path.
	 * @param int    $status   HTTP status (0 for transport error).
	 * @param int    $duration Milliseconds.
	 * @param string $error    Transport error message, if any.
	 */
	private function log_request( string $path, int $status, int $duration, string $error = '' ): void {
		$level = ( $status >= 400 || 0 === $status ) ? 'warning' : 'info';

		Logger::log(
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
