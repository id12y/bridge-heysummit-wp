<?php
/**
 * Runtime ticket-assignment method resolution.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Api\Discovery;

defined( 'ABSPATH' ) || exit;

/**
 * The documented attendee-create call does not clearly take a ticket
 * parameter; historical shapes suggest tickets attach separately. The
 * discovery diagnostic records both write endpoints' OPTIONS schemas
 * (write:attendees, write:external-ticket-sales); this class turns that
 * into the method used at push time:
 *
 * - `create_param`: the attendee-create POST schema exposes a ticket field,
 *   so the ticket rides in the create body.
 * - `ticket_import`: assign via the (allowlisted) external-ticket-sale
 *   import with zero amounts — the documented off-platform flow.
 * - `unsupported`: neither endpoint can assign a ticket; the attendee is
 *   still registered and a warning names the intended ticket in the log
 *   and the diagnostics panel.
 *
 * See docs/api-notes.md (v3 section) and docs/decisions.md D31.
 */
final class TicketAssignment {

	public const CREATE_PARAM  = 'create_param';
	public const TICKET_IMPORT = 'ticket_import';
	public const UNSUPPORTED   = 'unsupported';

	/**
	 * The assignment method for a connection.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public static function method( string $connection_id ): string {
		/**
		 * Override the resolved ticket-assignment method (for live
		 * correction without code changes).
		 *
		 * @param string $method        '' to resolve from discovery.
		 * @param string $connection_id Connection.
		 */
		$override = (string) apply_filters( 'eex_ticket_assignment_method', '', $connection_id );
		if ( in_array( $override, [ self::CREATE_PARAM, self::TICKET_IMPORT, self::UNSUPPORTED ], true ) ) {
			return $override;
		}

		$report = Discovery::stored_report( $connection_id );

		$attendees_fields = array_keys( (array) ( $report['write:attendees']['found'] ?? [] ) );
		foreach ( [ 'ticket', 'ticket_id', 'tickets' ] as $candidate ) {
			if ( in_array( $candidate, $attendees_fields, true ) ) {
				return self::CREATE_PARAM;
			}
		}

		$sales = (array) ( $report['write:external-ticket-sales'] ?? [] );
		if ( ! empty( $sales['found'] ) ) {
			return self::TICKET_IMPORT;
		}

		// Both write shapes present in the report but neither usable for a
		// ticket: genuinely unsupported.
		if ( isset( $report['write:attendees'], $report['write:external-ticket-sales'] ) ) {
			return self::UNSUPPORTED;
		}

		// No discovery data yet: the ticket-import endpoint is the
		// documented off-platform flow and is allowlisted — the conservative
		// working default until discovery says otherwise.
		return self::TICKET_IMPORT;
	}

	/**
	 * The create-body field name when the method is create_param.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public static function create_param_field( string $connection_id ): string {
		$fields = array_keys( (array) ( Discovery::stored_report( $connection_id )['write:attendees']['found'] ?? [] ) );

		foreach ( [ 'ticket', 'ticket_id', 'tickets' ] as $candidate ) {
			if ( in_array( $candidate, $fields, true ) ) {
				return $candidate;
			}
		}

		return 'ticket';
	}
}
