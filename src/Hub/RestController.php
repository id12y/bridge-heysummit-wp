<?php
/**
 * The Community Hub read-only API.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use Emailexpert\Events\Logging\Logger;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET-only routes under the versioned namespace `emailexpert-crm/v1`,
 * every one behind the same default-deny permission callback (Auth). The
 * namespace is deliberately plugin-agnostic: the contract belongs to the
 * CRM domain, and the implementation can move into the CRM plugin later
 * without changing a single Hub-side URL.
 *
 * Responses carry `Cache-Control: private, no-store` and a request id;
 * errors are generic (code + short message, no internals, no stack
 * traces, no configuration). Every route is side-effect free: reads
 * never mutate contact, ticket or journal state.
 */
final class RestController {

	public const REST_NAMESPACE   = 'emailexpert-crm/v1';
	public const CONTRACT_VERSION = '1.0.0';

	private const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register all Hub routes (GET only; other methods get WordPress's
	 * standard method rejection).
	 */
	public function register_routes(): void {
		$auth = new Auth();

		$read = static fn( array $extra = [] ): array => array_merge(
			[
				'methods'             => 'GET',
				'permission_callback' => [ $auth, 'permit' ],
			],
			$extra
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/capabilities',
			$read( [ 'callback' => [ $this, 'capabilities' ] ] )
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/contacts',
			$read(
				[
					'callback' => [ $this, 'contacts' ],
					'args'     => [
						'limit'  => [ 'type' => 'integer' ],
						'cursor' => [ 'type' => 'string' ],
					],
				]
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/contacts/(?P<contact_uuid>[0-9a-fA-F-]{36})',
			$read(
				[
					'callback' => [ $this, 'contact' ],
					'args'     => [
						'contact_uuid' => [ 'type' => 'string' ],
					],
				]
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/changes',
			$read(
				[
					'callback' => [ $this, 'changes' ],
					'args'     => [
						'limit'  => [ 'type' => 'integer' ],
						'cursor' => [ 'type' => 'string' ],
					],
				]
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/ticket-catalogue',
			$read(
				[
					'callback' => [ $this, 'ticket_catalogue' ],
					'args'     => [
						'limit'  => [ 'type' => 'integer' ],
						'cursor' => [ 'type' => 'string' ],
					],
				]
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/hub/eligibility',
			$read(
				[
					'callback' => [ $this, 'eligibility' ],
					'args'     => [
						'contact_uuid' => [ 'type' => 'string' ],
					],
				]
			)
		);
	}

	/**
	 * GET /hub/capabilities — safe operational facts only. Never secrets,
	 * keys, paths or configuration.
	 */
	public function capabilities(): WP_REST_Response {
		return $this->handle(
			'capabilities',
			function (): array {
				$crm      = new Crm();
				$registry = new Registry();
				$journal  = new Journal();

				$counts    = $registry->counts();
				$crm_total = $crm->available() ? $crm->count_subscribers() : null;

				return [
					'contract_version' => self::CONTRACT_VERSION,
					'plugin'           => [
						'name'    => 'emailexpert-events',
						'version' => defined( 'EEX_VERSION' ) ? EEX_VERSION : '',
					],
					'resources'        => [ 'contacts', 'changes', 'ticket-catalogue', 'eligibility' ],
					'features'         => [
						'contact_uuid'            => true,
						'snapshot'                => true,
						'changes'                 => true,
						'deletions_as_tombstones' => true,
						// Honest: the CRM stores ticket-status snapshots and
						// never reconciles cancellations/refunds, so current
						// validity is not answerable from durable data.
						'ticket_validity'         => false,
					],
					'ticket_tailor'    => [
						'available'   => [] !== $crm->box_offices(),
						'box_offices' => $crm->box_offices(),
					],
					'crm'              => [ 'available' => $crm->available() ],
					'backfill'         => [
						'enrolled'        => $counts['total'],
						'estimated_total' => $crm_total,
						'complete'        => null !== $crm_total && $counts['total'] >= $crm_total,
					],
					'journal_head'     => $journal->head(),
					'server_time_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
					'health'           => $crm->available() ? 'ok' : 'degraded',
				];
			}
		);
	}

	/**
	 * GET /hub/contacts — the paginated snapshot, tombstones included,
	 * ordered by an insertion sequence that never reorders. Records
	 * created during a snapshot appear in later pages or in /hub/changes
	 * from the returned journal_head — nothing is silently missed.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function contacts( WP_REST_Request $request ): WP_REST_Response {
		return $this->handle(
			'contacts',
			function () use ( $request ) {
				$after = 0;

				$cursor = (string) ( $request['cursor'] ?? '' );
				if ( '' !== $cursor ) {
					$position = Cursor::decode( $cursor, 'contacts' );

					if ( null === $position ) {
						return $this->bad_cursor();
					}

					$after = (int) ( $position['after'] ?? 0 );
				}

				$limit    = Settings::page_size( $request['limit'] ?? null );
				$registry = new Registry();
				$crm      = new Crm();

				$rows  = $registry->page_after( $after, $limit );
				$items = array_map( fn( array $row ): array => $this->serialize_contact( $row, $crm ), $rows );

				$last = [] !== $rows ? (int) end( $rows )['id'] : $after;

				return [
					'contacts'     => $items,
					'next_cursor'  => Cursor::encode( 'contacts', [ 'after' => $last ] ),
					'has_more'     => count( $rows ) === $limit,
					'journal_head' => ( new Journal() )->head(),
				];
			}
		);
	}

	/**
	 * GET /hub/contacts/{contact_uuid} — one contact (or its tombstone).
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function contact( WP_REST_Request $request ): WP_REST_Response {
		return $this->handle(
			'contact',
			function () use ( $request ) {
				$row = $this->row_for_uuid( (string) ( $request['contact_uuid'] ?? '' ) );

				if ( null === $row ) {
					return $this->not_found();
				}

				return $this->serialize_contact( $row, new Crm() );
			}
		);
	}

	/**
	 * GET /hub/changes — the incremental feed. Cursors are journal
	 * positions: replaying one returns the same logical result and
	 * mutates nothing.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function changes( WP_REST_Request $request ): WP_REST_Response {
		return $this->handle(
			'changes',
			function () use ( $request ) {
				$after = 0;

				$cursor = (string) ( $request['cursor'] ?? '' );
				if ( '' !== $cursor ) {
					$position = Cursor::decode( $cursor, 'changes' );

					if ( null === $position ) {
						return $this->bad_cursor();
					}

					$after = (int) ( $position['after'] ?? 0 );
				}

				$limit    = Settings::page_size( $request['limit'] ?? null );
				$journal  = new Journal();
				$registry = new Registry();
				$crm      = new Crm();

				$rows    = $journal->page_after( $after, $limit );
				$changes = [];

				foreach ( $rows as $row ) {
					$change = [
						'change_id'    => $row['seq'],
						'contact_uuid' => $row['uuid'],
						'object_type'  => $row['object_type'],
						'op'           => $row['op'],
						'revision'     => $row['revision'],
						'changed_at'   => $this->iso( $row['created_at'] ),
						'projection'   => null,
						'tombstone'    => null,
					];

					$registry_row = $registry->by_uuid( $row['uuid'] );

					if ( 'delete' === $row['op'] ) {
						$change['tombstone'] = [
							'deleted_at' => null !== $registry_row ? $this->iso( (string) $registry_row['deleted_at'] ) : null,
						];
					} elseif ( null !== $registry_row ) {
						// The CURRENT approved projection (it may be newer
						// than this change; consumers key on revision).
						$change['projection'] = $this->serialize_contact( $registry_row, $crm );
					}

					$changes[] = $change;
				}

				$last = [] !== $rows ? (int) end( $rows )['seq'] : $after;

				return [
					'changes'      => $changes,
					'next_cursor'  => Cursor::encode( 'changes', [ 'after' => $last ] ),
					'has_more'     => count( $rows ) === $limit,
					'journal_head' => $journal->head(),
				];
			}
		);
	}

	/**
	 * GET /hub/ticket-catalogue — the durable Ticket Tailor facts the CRM
	 * holds, every identity scoped by its box office so identical external
	 * ids from different accounts can never collide.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function ticket_catalogue( WP_REST_Request $request ): WP_REST_Response {
		return $this->handle(
			'ticket-catalogue',
			function () use ( $request ) {
				$after  = 0;
				$offset = 0;

				$cursor = (string) ( $request['cursor'] ?? '' );
				if ( '' !== $cursor ) {
					$position = Cursor::decode( $cursor, 'tickets' );

					if ( null === $position ) {
						return $this->bad_cursor();
					}

					$after  = (int) ( $position['after'] ?? 0 );
					$offset = max( 0, (int) ( $position['idx'] ?? 0 ) );
				}

				$limit    = Settings::page_size( $request['limit'] ?? null );
				$registry = new Registry();
				$crm      = new Crm();

				$items       = [];
				$filled      = 0;
				$scan_budget = 500; // Hard bound on registry rows examined per request.
				$exhausted   = false;

				while ( $filled < $limit && $scan_budget > 0 ) {
					$rows = $registry->page_after( $after, min( 50, $scan_budget ) );

					if ( [] === $rows ) {
						$exhausted = true;
						break;
					}

					foreach ( $rows as $row ) {
						--$scan_budget;

						$tickets = [];
						if ( 'active' === $row['state'] && null !== $row['subscriber_id'] ) {
							$tickets = $crm->allocated_tickets( (int) $row['subscriber_id'] );
						}

						foreach ( array_slice( $tickets, $offset, null, true ) as $index => $entry ) {
							if ( $filled >= $limit ) {
								// Mid-contact stop: resume inside this row at
								// the first entry not yet emitted.
								return $this->ticket_page( $items, $after, (int) $index, false );
							}

							$items[] = $this->serialize_ticket( $entry, $row );
							++$filled;
						}

						$offset = 0;
						$after  = (int) $row['id'];

						if ( $scan_budget < 1 ) {
							break;
						}
					}
				}

				return $this->ticket_page( $items, $after, 0, $exhausted );
			}
		);
	}

	/**
	 * GET /hub/eligibility?contact_uuid= — the separate eligibility facts.
	 * Ticket facts and community facts never feed each other: a booking
	 * is not membership.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public function eligibility( WP_REST_Request $request ): WP_REST_Response {
		return $this->handle(
			'eligibility',
			function () use ( $request ) {
				$row = $this->row_for_uuid( (string) ( $request['contact_uuid'] ?? '' ) );

				if ( null === $row ) {
					return $this->not_found();
				}

				$facts = [
					'has_ticket'             => null,
					'ticket_currently_valid' => null,
					'community_eligible'     => null,
					'community_opt_in'       => null,
					'community_invited'      => null,
					'community_admitted'     => null,
				];
				$tier  = null;
				$state = $row['state'];

				if ( 'active' === $row['state'] && null !== $row['subscriber_id'] ) {
					$crm  = new Crm();
					$id   = (int) $row['subscriber_id'];
					$tags = $crm->tag_slugs( $id );

					$ticket     = Projection::ticket_facts( $crm, $id, $tags );
					$community  = Projection::community( $crm, $id, $tags );
					$projection = Projection::facts( $crm, $id );

					if ( null !== $projection ) {
						$state = $projection['state']; // active|suppressed, live.
					}

					$facts = [
						'has_ticket'             => $ticket['has_ticket'],
						'ticket_currently_valid' => $ticket['ticket_currently_valid'],
						'community_eligible'     => $community['eligible'],
						'community_opt_in'       => $community['opt_in'],
						'community_invited'      => $community['invited'],
						'community_admitted'     => $community['admitted'],
					];
					$tier  = $community['membership_tier'];
				}

				return [
					'contact_uuid'    => $row['uuid'],
					'state'           => $state,
					'facts'           => $facts,
					'membership_tier' => $tier,
					'as_of'           => gmdate( 'Y-m-d\TH:i:s\Z' ),
				];
			}
		);
	}

	/**
	 * The full approved projection for a registry row (or its tombstone).
	 *
	 * @param array<string,mixed> $row Registry row.
	 * @param Crm                 $crm CRM adapter.
	 * @return array<string,mixed>
	 */
	private function serialize_contact( array $row, Crm $crm ): array {
		if ( 'deleted' === $row['state'] ) {
			return [
				'contact_uuid' => $row['uuid'],
				'revision'     => $row['revision'],
				'updated_at'   => $this->iso( (string) ( $row['deleted_at'] ?? $row['updated_at'] ) ),
				'state'        => 'deleted',
				'deleted_at'   => $this->iso( (string) ( $row['deleted_at'] ?? $row['updated_at'] ) ),
			];
		}

		$facts = null !== $row['subscriber_id'] ? Projection::facts( $crm, (int) $row['subscriber_id'] ) : null;

		if ( null === $facts ) {
			// The CRM row is unreadable right now (plugin inactive, or the
			// contact vanished mid-page); every fact reads as unknown and
			// the changes feed corrects it after the next sweep.
			$facts = [
				'email'            => null,
				'first_name'       => null,
				'last_name'        => null,
				'display_name'     => null,
				'state'            => 'active',
				'email_news_state' => 'unknown',
				'sources'          => [],
				'community'        => [
					'eligible'        => null,
					'opt_in'          => null,
					'invited'         => null,
					'admitted'        => null,
					'membership_tier' => null,
				],
			];
		}

		return array_merge(
			[
				'contact_uuid' => $row['uuid'],
				'revision'     => $row['revision'],
				'updated_at'   => $this->iso( (string) $row['updated_at'] ),
			],
			$facts
		);
	}

	/**
	 * One catalogue item from a stored allocation entry, identity scoped
	 * by box office. No payment or billing fields exist in the source
	 * data, and none are ever added here.
	 *
	 * @param array<string,mixed> $entry Stored allocation entry.
	 * @param array<string,mixed> $row   Registry row of the contact.
	 * @return array<string,mixed>
	 */
	private function serialize_ticket( array $entry, array $row ): array {
		$box_label = (string) ( $entry['box_office'] ?? '' );
		$box_id    = '' !== $box_label ? sanitize_title( $box_label ) : 'unknown';
		$order_id  = (string) ( $entry['order_id'] ?? '' );
		$ticket_id = (string) ( $entry['ticket_id'] ?? '' );
		$raw_state = strtolower( trim( (string) ( $entry['status'] ?? '' ) ) );

		$map = [
			'valid'       => 'valid',
			'cancelled'   => 'cancelled',
			'canceled'    => 'cancelled',
			'voided'      => 'cancelled',
			'refunded'    => 'refunded',
			'transferred' => 'transferred',
		];

		$updated = (string) ( $entry['created_at'] ?? '' );
		if ( '' === $updated ) {
			$updated = (string) ( $entry['_field_updated_at'] ?? '' );
		}

		return [
			'id'           => 'bo:' . $box_id . ':order:' . $order_id . ':ticket:' . ( '' !== $ticket_id ? $ticket_id : 'unknown' ),
			'box_office'   => [
				'id'    => $box_id,
				'label' => $box_label,
			],
			'event'        => [
				'id'    => null,
				'label' => '' !== (string) ( $entry['event_summary'] ?? '' ) ? (string) $entry['event_summary'] : null,
			],
			'ticket_type'  => [
				'id'    => null,
				'label' => '' !== (string) ( $entry['ticket_type'] ?? '' ) ? (string) $entry['ticket_type'] : null,
			],
			'order_id'     => '' !== $order_id ? $order_id : null,
			'ticket_ref'   => '' !== $ticket_id ? $ticket_id : null,
			'state'        => $map[ $raw_state ] ?? ( '' === $raw_state ? 'unknown' : 'other' ),
			'state_raw'    => '' !== $raw_state ? $raw_state : null,
			'contact_uuid' => $row['uuid'],
			'updated_at'   => '' !== $updated ? $this->iso_flexible( $updated ) : null,
			'revision'     => $row['revision'],
		];
	}

	/**
	 * Assemble a ticket-catalogue page.
	 *
	 * @param array<int,array<string,mixed>> $items     Page items.
	 * @param int                            $after     Last fully consumed registry id.
	 * @param int                            $idx       Offset within the next row.
	 * @param bool                           $exhausted Whether the registry was fully scanned.
	 * @return array<string,mixed>
	 */
	private function ticket_page( array $items, int $after, int $idx, bool $exhausted ): array {
		return [
			'tickets'     => $items,
			'next_cursor' => Cursor::encode(
				'tickets',
				[
					'after' => $after,
					'idx'   => $idx,
				]
			),
			'has_more'    => ! $exhausted,
		];
	}

	/**
	 * A registry row for a validated UUID parameter.
	 *
	 * @param string $uuid Raw parameter.
	 * @return array<string,mixed>|null
	 */
	private function row_for_uuid( string $uuid ): ?array {
		if ( 1 !== preg_match( self::UUID_PATTERN, $uuid ) ) {
			return null;
		}

		return ( new Registry() )->by_uuid( strtolower( $uuid ) );
	}

	/**
	 * Run a handler with uniform headers, generic errors and an
	 * access-log line (request id, route, outcome, timing — never tokens,
	 * never personal data).
	 *
	 * @param string   $route Short route name for the log.
	 * @param callable $build Returns the body array, optionally with a
	 *                        '__status' key for non-200 outcomes.
	 */
	private function handle( string $route, callable $build ): WP_REST_Response {
		$started    = microtime( true );
		$request_id = bin2hex( random_bytes( 8 ) );

		try {
			$result = $build();
			$status = 200;

			if ( isset( $result['__status'] ) ) {
				$status = (int) $result['__status'];
				unset( $result['__status'] );
			}
		} catch ( \Throwable $e ) {
			// Internal diagnostics keep the class only — no message, no
			// trace, nothing user-supplied.
			Logger::info(
				Logger::CONTEXT_HUB,
				'Hub request failed',
				[
					'request_id' => $request_id,
					'route'      => $route,
					'error'      => get_class( $e ),
				]
			);

			$result = [
				'code'    => 'eex_hub_error',
				'message' => 'Internal error.',
			];
			$status = 500;
		}

		/**
		 * Whether to write one access-log line per Hub request.
		 *
		 * @param bool $log Default true.
		 */
		if ( apply_filters( 'eex_hub_log_requests', true ) ) {
			$credential = Auth::credential();

			Logger::info(
				Logger::CONTEXT_HUB,
				'Hub request',
				[
					'request_id' => $request_id,
					'route'      => $route,
					'status'     => $status,
					'ms'         => (int) round( ( microtime( true ) - $started ) * 1000 ),
					'credential' => null !== $credential ? (int) $credential['id'] : 0,
				]
			);
		}

		$response = new WP_REST_Response( $result, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		$response->header( 'X-Content-Type-Options', 'nosniff' );
		$response->header( 'X-EEX-Request-Id', $request_id );

		return $response;
	}

	/**
	 * Generic invalid-cursor error body.
	 *
	 * @return array<string,mixed>
	 */
	private function bad_cursor(): array {
		return [
			'code'     => 'eex_hub_bad_cursor',
			'message'  => 'Invalid cursor.',
			'__status' => 400,
		];
	}

	/**
	 * Generic not-found error body.
	 *
	 * @return array<string,mixed>
	 */
	private function not_found(): array {
		return [
			'code'     => 'eex_hub_not_found',
			'message'  => 'Not found.',
			'__status' => 404,
		];
	}

	/**
	 * UTC MySQL datetime → ISO 8601 Z.
	 *
	 * @param string $mysql 'Y-m-d H:i:s' UTC.
	 */
	private function iso( string $mysql ): ?string {
		if ( '' === $mysql ) {
			return null;
		}

		$time = strtotime( $mysql . ' UTC' );

		return false !== $time ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;
	}

	/**
	 * Best-effort ISO 8601 Z from mixed stored formats (the CRM stores
	 * allocation created_at as ISO already, field rows as MySQL UTC).
	 *
	 * @param string $stored Stored timestamp.
	 */
	private function iso_flexible( string $stored ): ?string {
		if ( str_contains( $stored, 'T' ) ) {
			$time = strtotime( $stored );
		} else {
			$time = strtotime( $stored . ' UTC' );
		}

		return false !== $time ? gmdate( 'Y-m-d\TH:i:s\Z', $time ) : null;
	}
}
