<?php
/**
 * Outbound webhook relay.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Webhooks;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Forwards each processed, verified webhook action as a JSON POST to
 * configured URLs (per-URL shared-secret header, 3 retries with backoff,
 * deliveries logged), so registrations flow to n8n, Make or an ESP without
 * PHP. The relayed attendee carries the email hash, never the raw address.
 */
class Relay {

	private const BACKOFF = [ 60, 300, 900 ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_checkout_complete', fn( $attendee, $event_post_id ) => $this->dispatch( 'checkout_complete', (array) $attendee, (int) $event_post_id ), 10, 2 );
		add_action( 'eex_registration_started', fn( $attendee, $event_post_id ) => $this->dispatch( 'registration_started', (array) $attendee, (int) $event_post_id ), 10, 2 );
		add_action( 'eex_talk_signup', fn( $attendee, $talk_hs_id, $event_post_id ) => $this->dispatch( 'talk_added', (array) $attendee, (int) $event_post_id, (string) $talk_hs_id ), 10, 3 );
		add_action( 'eex_relay_deliver', [ $this, 'deliver' ], 10, 3 );
	}

	/**
	 * Configured relay targets.
	 *
	 * @return array<int,array{url:string,secret:string,actions:array<int,string>}>
	 */
	public static function targets(): array {
		$targets = [];

		foreach ( (array) get_option( 'eex_relay_urls', [] ) as $row ) {
			if ( ! is_array( $row ) || '' === (string) ( $row['url'] ?? '' ) ) {
				continue;
			}

			$targets[] = [
				'url'     => (string) $row['url'],
				'secret'  => (string) ( $row['secret'] ?? '' ),
				'actions' => array_values( array_map( 'strval', (array) ( $row['actions'] ?? [] ) ) ),
			];
		}

		return $targets;
	}

	/**
	 * Queue a delivery per configured URL subscribed to the action.
	 *
	 * @param string              $action        Action key.
	 * @param array<string,mixed> $attendee      Mapped attendee (hashed email only).
	 * @param int                 $event_post_id Event post ID.
	 * @param string              $talk_hs_id    Talk ID for talk_added.
	 */
	public function dispatch( string $action, array $attendee, int $event_post_id, string $talk_hs_id = '' ): void {
		$targets = self::targets();

		if ( empty( $targets ) ) {
			return;
		}

		// Never relay a raw email address.
		unset( $attendee['email'] );

		$payload = [
			'action'     => $action,
			'attendee'   => $attendee,
			'event'      => [
				'post_id'      => $event_post_id,
				'heysummit_id' => $event_post_id > 0 ? (string) get_post_meta( $event_post_id, '_eex_heysummit_id', true ) : '',
				'title'        => $event_post_id > 0 ? get_the_title( $event_post_id ) : '',
			],
			'talk_hs_id' => $talk_hs_id,
			'site'       => home_url( '/' ),
			'sent_at'    => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];

		foreach ( $targets as $index => $target ) {
			if ( ! in_array( $action, $target['actions'], true ) ) {
				continue;
			}

			wp_schedule_single_event( time() - 1, 'eex_relay_deliver', [ $index, $payload, 1 ] );
		}

		spawn_cron();
	}

	/**
	 * Deliver one payload to one target; retry with backoff on failure.
	 *
	 * @param int   $target_index Index into targets().
	 * @param array $payload      Payload.
	 * @param int   $attempt      Attempt number (1-based).
	 * @return bool Delivered.
	 */
	public function deliver( $target_index = 0, $payload = [], $attempt = 1 ): bool {
		$targets = self::targets();
		$target  = $targets[ (int) $target_index ] ?? null;

		if ( null === $target ) {
			return false; // Target removed since queueing.
		}

		$result = self::send( $target, (array) $payload );

		Logger::log(
			Logger::CONTEXT_WEBHOOK,
			$result['ok'] ? 'info' : 'warning',
			sprintf(
				'Relay %s to %s: %s (attempt %d).',
				(string) ( $payload['action'] ?? '?' ),
				(string) wp_parse_url( $target['url'], PHP_URL_HOST ),
				$result['ok'] ? 'delivered' : $result['message'],
				(int) $attempt
			)
		);

		if ( ! $result['ok'] && (int) $attempt < 3 ) {
			$delay = self::BACKOFF[ (int) $attempt - 1 ] ?? 300;
			wp_schedule_single_event( time() + $delay, 'eex_relay_deliver', [ (int) $target_index, $payload, (int) $attempt + 1 ] );
		}

		return (bool) $result['ok'];
	}

	/**
	 * Send one payload (shared by deliveries and the test button).
	 *
	 * @param array{url:string,secret:string} $target  Target.
	 * @param array<string,mixed>             $payload Payload.
	 * @return array{ok:bool,message:string}
	 */
	public static function send( array $target, array $payload ): array {
		$headers = [ 'Content-Type' => 'application/json' ];

		if ( '' !== (string) ( $target['secret'] ?? '' ) ) {
			$headers['X-Eex-Secret'] = (string) $target['secret'];
		}

		$response = wp_remote_post(
			(string) $target['url'],
			[
				'timeout' => 10,
				'headers' => $headers,
				'body'    => wp_json_encode( $payload ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return [
				'ok'      => false,
				'message' => $response->get_error_message(),
			];
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		return [
			'ok'      => $status >= 200 && $status < 300,
			'message' => 'HTTP ' . $status,
		];
	}
}
