<?php
/**
 * Account registration push jobs.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Mappers\TicketSaleRequestBuilder;
use Emailexpert\Events\Options;
use Emailexpert\Events\Registrations;
use Emailexpert\Events\Webhooks\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Same posture as the Woo bridge: queued async pushes, suppression and
 * consent re-checked at push time, "attendee already exists" treated as
 * success, 3 retries with backoff, terminal failures flagged with an admin
 * notice and a per-user manual push. Uses only the two allowlisted write
 * endpoints; ticket assignment follows the discovery finding.
 */
class Pusher {

	private const BACKOFF = [ 300, 900, 2700 ];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_accounts_push', [ $this, 'run_job' ], 10, 4 );
	}

	/**
	 * Queued job entry point.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id Event.
	 * @param string $rule_id     Rule that justified the push.
	 * @param int    $attempt     Attempt number (1-based).
	 */
	public function run_job( $user_id = 0, $event_hs_id = '', $rule_id = '', $attempt = 1 ): void {
		$this->push( (int) $user_id, (string) $event_hs_id, (string) $rule_id, (int) $attempt );
	}

	/**
	 * Push one user to one event.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id Event.
	 * @param string $rule_id     Rule ID ('' for manual pushes re-running a recorded rule).
	 * @param int    $attempt     Attempt number.
	 * @return array{status:string,message:string}
	 */
	public function push( int $user_id, string $event_hs_id, string $rule_id, int $attempt = 1 ): array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return [
				'status'  => 'error',
				'message' => __( 'User not found.', 'emailexpert-events' ),
			];
		}

		$record = Registrations::get( $user_id, $event_hs_id ) ?? [];

		if ( Registrations::STATUS_DONE === (string) ( $record['status'] ?? '' ) ) {
			return [
				'status'  => 'done',
				'message' => __( 'Already registered.', 'emailexpert-events' ),
			];
		}

		$rule = Rules::get( '' !== $rule_id ? $rule_id : (string) ( $record['rule'] ?? '' ) );

		// Suppression and consent are re-checked at push time: an opt-out or
		// erasure between queueing and delivery must win.
		if ( Suppression::is_suppressed( (string) $user->user_email, $event_hs_id )
			|| '' !== (string) get_user_meta( $user_id, Consent::OPT_OUT_META_KEY, true ) ) {
			Registrations::mark_failed( $user_id, $event_hs_id, 'suppressed before delivery' );
			Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Account push cancelled for user %d: suppressed before delivery.', $user_id ) );

			return [
				'status'  => 'suppressed',
				'message' => __( 'Suppressed; not pushed.', 'emailexpert-events' ),
			];
		}

		if ( null !== $rule ) {
			$consent = Consent::satisfied( $user_id, (string) $rule['consent_source'] );
			if ( ! $consent['ok'] ) {
				Registrations::mark_failed( $user_id, $event_hs_id, 'consent no longer satisfied' );
				Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Account push cancelled for user %d: consent no longer satisfied.', $user_id ) );

				return [
					'status'  => 'no_consent',
					'message' => __( 'Consent not satisfied; not pushed.', 'emailexpert-events' ),
				];
			}
		}

		$connection_id = null !== $rule ? (string) $rule['connection'] : (string) ( $record['connection'] ?? '' );
		$connection    = Options::connection( $connection_id );

		if ( null === $connection || '' === (string) ( $connection['api_key'] ?? '' ) ) {
			return $this->fail( $user_id, $event_hs_id, $rule_id, $attempt, __( 'Connection has no API key.', 'emailexpert-events' ) );
		}

		$client   = HeySummitClient::for_connection( $connection );
		$ticket   = null !== $rule ? (string) $rule['ticket'] : '';
		$method   = TicketAssignment::method( $connection_id );
		$purchase = [
			'name'            => (string) ( $user->display_name ?: $user->user_login ),
			'email'           => (string) $user->user_email,
			'event_hs_id'     => $event_hs_id,
			'order_reference' => 'account:' . $user_id,
		];

		$request = AttendeeRequestBuilder::build( $purchase );

		// Ticket in the create body when discovery showed the field exists.
		if ( '' !== $ticket && TicketAssignment::CREATE_PARAM === $method ) {
			$request['body'][ TicketAssignment::create_param_field( $connection_id ) ] = $ticket;
		}

		$response       = $client->post( (string) $request['path'], (array) $request['body'] );
		$already_exists = false;

		if ( is_wp_error( $response ) ) {
			if ( self::is_already_exists_error( $response ) ) {
				// An existing attendee is success, never an error.
				$already_exists = true;
				$response       = [];
			} else {
				return $this->fail( $user_id, $event_hs_id, $rule_id, $attempt, $response->get_error_message() );
			}
		}

		$attendee_hs_id = (string) ( $response['id'] ?? '' );

		// Ticket via the import endpoint when that is the discovered method
		// (zero amounts: a free assignment, not a sale). Skipped for
		// already-existing attendees: their ticket state is unknown and a
		// duplicate import is worse than none.
		if ( '' !== $ticket && ! $already_exists && TicketAssignment::TICKET_IMPORT === $method ) {
			$sale = TicketSaleRequestBuilder::build(
				[
					'attendee_hs_id'  => $attendee_hs_id,
					'ticket_hs_id'    => $ticket,
					'amount_gross'    => '0.00',
					'amount_net'      => '0.00',
					'currency'        => '',
					'order_reference' => 'account:' . $user_id,
				]
			);

			$sale_response = $client->post( (string) $sale['path'], (array) $sale['body'] );

			if ( is_wp_error( $sale_response ) ) {
				return $this->fail( $user_id, $event_hs_id, $rule_id, $attempt, $sale_response->get_error_message() );
			}
		}

		if ( '' !== $ticket && TicketAssignment::UNSUPPORTED === $method ) {
			Logger::warning(
				Logger::CONTEXT_API,
				sprintf( 'Attendee registered for event %s but ticket "%s" could not be assigned via the API (discovery shows no assignment path). Assign it manually in HeySummit.', $event_hs_id, $ticket ),
				[ 'flag' => 'discovery', 'connection' => $connection_id ] // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			);
		}

		// Success: the ledger records rule, trigger and consent source.
		Registrations::record(
			$user_id,
			$event_hs_id,
			[
				'status'         => Registrations::STATUS_DONE,
				'attendee_hs_id' => $attendee_hs_id,
				'rule'           => '' !== $rule_id ? $rule_id : (string) ( $record['rule'] ?? '' ),
				'ticket'         => $ticket,
				'note'           => $already_exists ? 'Attendee already existed in HeySummit.' : '',
			]
		);
		delete_user_meta( $user_id, '_eex_hs_push_failed' );

		// Attribution: account registrations join the weekly numbers and the
		// digest; the attendee-ID dedupe prevents webhook double-counting.
		Attribution::insert(
			[
				'hs_id'       => $attendee_hs_id,
				'email_hash'  => Suppression::hash( (string) $user->user_email ),
				'utm_source'  => 'account-rule',
				'ticket_name' => $ticket,
			],
			$event_hs_id,
			'completed'
		);

		Logger::info(
			Logger::CONTEXT_SYNC,
			sprintf(
				'User %d registered with HeySummit event %s via rule %s%s.',
				$user_id,
				$event_hs_id,
				'' !== $rule_id ? $rule_id : (string) ( $record['rule'] ?? '?' ),
				$already_exists ? ' (attendee already existed)' : ''
			)
		);

		/**
		 * An account rule registered a user with HeySummit.
		 *
		 * @param int    $user_id        User ID.
		 * @param string $event_hs_id    Event.
		 * @param string $attendee_hs_id Attendee ID ('' when pre-existing).
		 */
		do_action( 'eex_account_pushed', $user_id, $event_hs_id, $attendee_hs_id );

		return [
			'status'  => 'done',
			'message' => $already_exists
				? __( 'Attendee already existed; recorded as registered.', 'emailexpert-events' )
				: sprintf( /* translators: %s: attendee ID. */ __( 'Registered; attendee %s.', 'emailexpert-events' ), $attendee_hs_id ?: '?' ),
		];
	}

	/**
	 * Whether an API error means "this attendee already exists".
	 *
	 * @param \WP_Error $error Client error.
	 */
	public static function is_already_exists_error( $error ): bool {
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );

		if ( 409 === $status ) {
			return true;
		}

		if ( 400 !== $status ) {
			return false;
		}

		$body = strtolower( (string) wp_json_encode( $data['body'] ?? [] ) );

		return str_contains( $body, 'already' ) || str_contains( $body, 'exist' ) || str_contains( $body, 'unique' ) || str_contains( $body, 'duplicate' );
	}

	/**
	 * Record a failed attempt: retry with backoff up to 3 attempts, then
	 * flag the user.
	 *
	 * @param int    $user_id     User ID.
	 * @param string $event_hs_id Event.
	 * @param string $rule_id     Rule ID.
	 * @param int    $attempt     Attempt just failed.
	 * @param string $message     Failure message.
	 * @return array{status:string,message:string}
	 */
	private function fail( int $user_id, string $event_hs_id, string $rule_id, int $attempt, string $message ): array {
		Logger::error(
			Logger::CONTEXT_SYNC,
			sprintf( 'Account push attempt %d failed for user %d / event %s: %s', $attempt, $user_id, $event_hs_id, $message )
		);

		if ( $attempt < 3 ) {
			$delay = self::BACKOFF[ $attempt - 1 ] ?? 900;
			wp_schedule_single_event( time() + $delay, 'eex_accounts_push', [ $user_id, $event_hs_id, $rule_id, $attempt + 1 ] );

			return [
				'status'  => 'retrying',
				'message' => $message,
			];
		}

		Registrations::mark_failed( $user_id, $event_hs_id, $message );
		update_user_meta( $user_id, '_eex_hs_push_failed', $message );

		\Emailexpert\Events\Admin\Notices::add(
			'accounts_push_failed',
			sprintf(
				/* translators: 1: user ID, 2: HeySummit event ID, 3: the failure reason from the API client. */
				__( 'An account registration failed to push to HeySummit after 3 attempts (user %1$d, event %2$s): %3$s — retry from the Users screen row action; the sync log has the full request trail.', 'emailexpert-events' ),
				$user_id,
				$event_hs_id,
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
