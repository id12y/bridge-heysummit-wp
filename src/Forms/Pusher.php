<?php
/**
 * Form submission → HeySummit push jobs.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Forms;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Options;
use Emailexpert\Events\Webhooks\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Same posture as the Woo and accounts bridges: queued async pushes,
 * suppression re-checked at delivery (an opt-out between queueing and
 * delivery must win), "attendee already exists" treated as success, three
 * retries with backoff, terminal failures flagged with an admin notice and
 * retryable from the Bridges screen. Uses only the allowlisted
 * attendee-create endpoint; the ticket price and question answers ride in
 * the create body.
 */
class Pusher {

	private const BACKOFF = [ 300, 900, 2700 ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_forms_push', [ $this, 'run_job' ], 10, 2 );
	}

	/**
	 * Queued job entry point.
	 *
	 * @param string $entry_id Queue entry ID.
	 * @param int    $attempt  Attempt number (1-based).
	 */
	public function run_job( $entry_id = '', $attempt = 1 ): void {
		$this->push( (string) $entry_id, (int) $attempt );
	}

	/**
	 * Push one queued submission.
	 *
	 * @param string $entry_id Queue entry ID.
	 * @param int    $attempt  Attempt number.
	 * @return array{status:string,message:string}
	 */
	public function push( string $entry_id, int $attempt = 1 ): array {
		$entry = Queue::get( $entry_id );

		if ( null === $entry ) {
			return [
				'status'  => 'done',
				'message' => __( 'Nothing queued under that ID (already pushed?).', 'emailexpert-events' ),
			];
		}

		$mapping = Mappings::get( (string) ( $entry['mapping'] ?? '' ) );

		if ( null === $mapping ) {
			// The mapping was deleted while the job waited: without it there
			// is no event or consent context, so the entry cannot be pushed.
			Queue::delete( $entry_id );
			Logger::info( Logger::CONTEXT_API, sprintf( 'Form push dropped: mapping %s no longer exists.', (string) ( $entry['mapping'] ?? '?' ) ) );

			return [
				'status'  => 'skipped',
				'message' => __( 'The mapping no longer exists; not pushed.', 'emailexpert-events' ),
			];
		}

		$email = (string) ( $entry['email'] ?? '' );
		$event = (string) ( $mapping['event'] ?? '' );

		// Suppression is re-checked at delivery: an opt-out or erasure
		// between queueing and delivery must win.
		if ( class_exists( '\Emailexpert\Events\Accounts\Suppression' )
			&& \Emailexpert\Events\Accounts\Suppression::is_suppressed( $email, $event ) ) {
			Queue::delete( $entry_id );
			Logger::info( Logger::CONTEXT_API, sprintf( 'Form push cancelled for mapping %s: suppressed before delivery.', (string) $mapping['id'] ) );

			return [
				'status'  => 'suppressed',
				'message' => __( 'Suppressed; not pushed.', 'emailexpert-events' ),
			];
		}

		$connection = Options::connection( (string) ( $mapping['connection'] ?? '' ) );

		if ( null === $connection || '' === (string) ( $connection['api_key'] ?? '' ) ) {
			return $this->fail( $entry_id, $entry, $mapping, $attempt, __( 'Connection has no API key.', 'emailexpert-events' ) );
		}

		$request = AttendeeRequestBuilder::build(
			[
				'name'            => (string) ( $entry['name'] ?? '' ),
				'email'           => $email,
				'event_hs_id'     => $event,
				'ticket_price_id' => (string) ( $mapping['ticket'] ?? '' ),
				'questions'       => (array) ( $entry['questions'] ?? [] ),
				'order_reference' => 'form:' . (string) $mapping['id'],
			]
		);

		$client   = HeySummitClient::for_connection( $connection );
		$response = $client->post( (string) $request['path'], (array) $request['body'] );

		$already_existed = false;
		$attendee_hs_id  = '';

		if ( is_wp_error( $response ) ) {
			if ( ! \Emailexpert\Events\Accounts\Pusher::is_already_exists_error( $response ) ) {
				return $this->fail( $entry_id, $entry, $mapping, $attempt, $response->get_error_message() );
			}

			// A duplicate attendee is success: the person is registered.
			$already_existed = true;
		} else {
			$attendee_hs_id = (string) ( $response['id'] ?? '' );
		}

		// Success: the entry (and with it the stored address) is gone.
		Queue::delete( $entry_id );

		// Attribution: form registrations join the weekly numbers; the
		// attendee-ID dedupe prevents webhook double-counting.
		if ( '' !== $attendee_hs_id ) {
			Attribution::insert(
				[
					'hs_id'       => $attendee_hs_id,
					'email_hash'  => hash( 'sha256', strtolower( $email ) ),
					'utm_source'  => 'form',
					'utm_medium'  => (string) ( $mapping['source'] ?? '' ),
					'ticket_name' => (string) ( $mapping['ticket'] ?? '' ),
				],
				$event,
				'completed'
			);
		}

		Logger::info(
			Logger::CONTEXT_API,
			sprintf(
				'Form submission pushed to HeySummit event %s via mapping %s%s.',
				$event,
				(string) $mapping['id'],
				$already_existed ? ' (attendee already existed)' : ''
			)
		);

		/**
		 * A form submission was pushed to HeySummit.
		 *
		 * @param string $mapping_id     Mapping ID.
		 * @param string $event_hs_id    Event.
		 * @param string $attendee_hs_id Attendee ID ('' when pre-existing).
		 */
		do_action( 'eex_forms_pushed', (string) $mapping['id'], $event, $attendee_hs_id );

		return [
			'status'  => 'done',
			'message' => $already_existed
				? __( 'Attendee already existed; nothing to do.', 'emailexpert-events' )
				: sprintf( /* translators: %s: attendee ID. */ __( 'Pushed; attendee %s.', 'emailexpert-events' ), $attendee_hs_id ?: '?' ),
		];
	}

	/**
	 * Re-schedule every failed entry (the Bridges screen button).
	 *
	 * @return int How many were re-queued.
	 */
	public function retry_failed(): int {
		$count = 0;

		foreach ( Queue::with_status( 'failed' ) as $id => $entry ) {
			$entry['status']   = 'pending';
			$entry['attempts'] = 0;
			Queue::update( (string) $id, $entry );

			wp_schedule_single_event( time() - 1, 'eex_forms_push', [ (string) $id, 1 ] );
			++$count;
		}

		if ( $count > 0 && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		return $count;
	}

	/**
	 * Record a failed attempt: retry with backoff up to 3 attempts, then
	 * flag the queue entry and raise an admin notice.
	 *
	 * @param string              $entry_id Entry ID.
	 * @param array<string,mixed> $entry    Entry data.
	 * @param array<string,mixed> $mapping  Mapping row.
	 * @param int                 $attempt  Attempt just failed.
	 * @param string              $message  Failure message.
	 * @return array{status:string,message:string}
	 */
	private function fail( string $entry_id, array $entry, array $mapping, int $attempt, string $message ): array {
		Logger::error(
			Logger::CONTEXT_API,
			sprintf( 'Form push attempt %d failed for mapping %s / event %s: %s', $attempt, (string) $mapping['id'], (string) $mapping['event'], $message )
		);

		$entry['attempts']   = $attempt;
		$entry['last_error'] = $message;

		if ( $attempt < 3 ) {
			$entry['status'] = 'pending';
			Queue::update( $entry_id, $entry );

			$delay = self::BACKOFF[ $attempt - 1 ] ?? 900;
			wp_schedule_single_event( time() + $delay, 'eex_forms_push', [ $entry_id, $attempt + 1 ] );

			return [
				'status'  => 'retrying',
				'message' => $message,
			];
		}

		$entry['status'] = 'failed';
		Queue::update( $entry_id, $entry );

		\Emailexpert\Events\Admin\Notices::add(
			'forms_push_failed',
			sprintf(
				/* translators: 1: mapping label or ID, 2: the failure reason from the API client. */
				__( 'A form submission failed to push to HeySummit after 3 attempts (mapping %1$s): %2$s — retry from Settings → EEX Bridges; the log has the full request trail.', 'emailexpert-events' ),
				(string) ( $mapping['label'] ?: $mapping['id'] ),
				$message
			),
			'error'
		);

		return [
			'status'  => 'failed',
			'message' => $message,
		];
	}
}
