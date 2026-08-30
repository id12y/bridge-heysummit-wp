<?php
/**
 * The Hub change sweep.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the registry and journal true to the CRM without ever writing to
 * it. Three bounded, idempotent passes per run (cron every 5 minutes, or
 * driven from the CLI):
 *
 * 1. ENROLL — new CRM subscribers get a UUID and an initial journal
 *    upsert. This same pass IS the backfill: resumable (a stored floor
 *    id), batched, no long transactions, safe to re-run forever.
 * 2. UPDATE — contacts whose projection inputs carry a fresh timestamp
 *    are re-projected; only a changed canonical hash journals a new
 *    revision, so overlapping watermark windows cost nothing.
 * 3. RECONCILE — a cyclic walk over the registry that (a) tombstones
 *    contacts whose CRM row is gone (deletion detection that needs no
 *    hook) and (b) re-hashes live rows, catching changes that leave no
 *    timestamp anywhere (tag removals).
 *
 * CRM lifecycle hooks (purge, created, confirmed, unsubscribed) feed the
 * same idempotent primitives for immediacy; correctness never depends on
 * them. Timestamp comparison is only ever a HINT to look at a contact —
 * truth is the canonical projection hash, so clock skew, equal
 * timestamps and concurrent writes cannot lose a change.
 */
class Sweeper {

	private const STATE_OPTION = 'eex_hub_sweep_state';
	private const LOCK_KEY     = 'eex_hub_sweep_lock';

	/**
	 * Watermark overlap so second-resolution timestamps and in-flight
	 * writes at sweep time are re-examined on the next run.
	 */
	private const OVERLAP_SECONDS = 120;

	/**
	 * Cron entry point.
	 */
	public static function cron(): void {
		if ( ! Settings::enabled() || ! Schema::ready() ) {
			return;
		}

		( new self() )->run();
	}

	/**
	 * One bounded sweep.
	 *
	 * @param int $enroll_budget    Maximum new contacts to enrol.
	 * @param int $update_budget    Maximum hint rows per source.
	 * @param int $reconcile_budget Maximum registry rows to reconcile.
	 * @return array<string,mixed> Run statistics (plus 'locked' /
	 *                             'crm_unavailable' flags so callers can
	 *                             tell a no-op from an empty backlog).
	 */
	public function run( int $enroll_budget = 200, int $update_budget = 200, int $reconcile_budget = 50 ): array {
		$stats = [
			'enrolled'        => 0,
			'updated'         => 0,
			'deleted'         => 0,
			'reconciled'      => 0,
			'locked'          => false,
			'crm_unavailable' => false,
		];

		if ( false !== get_transient( self::LOCK_KEY ) ) {
			$stats['locked'] = true;

			return $stats;
		}
		set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$crm = new Crm();

			if ( ! $crm->available() ) {
				$stats['crm_unavailable'] = true;

				return $stats;
			}

			$registry = new Registry();
			$journal  = new Journal();
			$state    = $this->state();
			$started  = time();

			// 0. Retry contacts whose earlier enrolment read failed while
			// the CRM row existed — the floor has moved past them, so
			// without this list a transient read error would drop them.
			$retry = [];
			foreach ( array_slice( (array) $state['retry'], 0, 50 ) as $id ) {
				$id = (int) $id;

				if ( $id < 1 || ! $crm->exists( $id ) ) {
					continue;
				}

				if ( $this->project_one( $id, $crm, $registry, $journal ) ) {
					++$stats['enrolled'];
					continue;
				}

				$retry[] = $id;
			}
			$state['retry'] = array_merge( $retry, array_slice( (array) $state['retry'], 50 ) );

			// 1. Enroll new subscribers (and backfill, batch by batch).
			$ids = $crm->ids_after( (int) $state['last_enrolled_id'], $enroll_budget );
			foreach ( $ids as $id ) {
				if ( $this->project_one( $id, $crm, $registry, $journal ) ) {
					++$stats['enrolled'];
				} elseif ( $crm->exists( $id ) && null === $registry->by_subscriber( $id ) ) {
					$state['retry'][] = $id;
				}
				$state['last_enrolled_id'] = max( (int) $state['last_enrolled_id'], $id );
			}
			$state['retry'] = array_slice( array_values( array_unique( array_map( 'intval', (array) $state['retry'] ) ) ), 0, 200 );

			// 2. Re-project contacts hinted by fresh timestamps. Each
			// source keeps its own watermark, scans oldest-first, and —
			// when truncated at the budget — advances only to the last
			// timestamp actually processed, so a backlog drains instead
			// of freezing the watermark (or skipping past unseen rows).
			$watermarks = (array) $state['watermarks'];
			$touched    = [];

			foreach ( [ 'subscribers', 'fields', 'tags' ] as $source ) {
				$old   = (string) ( $watermarks[ $source ] ?? '1970-01-01 00:00:00' );
				$floor = strtotime( $old . ' UTC' );
				$since = gmdate( 'Y-m-d H:i:s', max( 0, ( false !== $floor ? $floor : 0 ) - self::OVERLAP_SECONDS ) );

				if ( 'subscribers' === $source ) {
					$hints = $crm->subscriber_hints( $since, $update_budget );
				} elseif ( 'fields' === $source ) {
					$hints = $crm->field_hints( $since, $update_budget );
				} else {
					$hints = $crm->tag_hints( $since, $update_budget );
				}

				$max_ts = $old;
				foreach ( $hints['rows'] as $hint ) {
					if ( $hint['id'] > 0 ) {
						$touched[ $hint['id'] ] = true;
					}
					if ( $hint['ts'] > $max_ts ) {
						$max_ts = $hint['ts'];
					}
				}

				if ( ! $hints['truncated'] ) {
					$watermarks[ $source ] = gmdate( 'Y-m-d H:i:s', $started );
				} elseif ( $max_ts > $old ) {
					$watermarks[ $source ] = $max_ts;
				} else {
					// A truncated batch made no timestamp progress (a
					// same-second burst larger than the budget): step
					// forward anyway — the reconcile walk is the
					// guarantee for anything the hints under-deliver.
					$watermarks[ $source ] = gmdate( 'Y-m-d H:i:s', $started );
				}
			}

			$state['watermarks'] = $watermarks;

			foreach ( array_keys( $touched ) as $id ) {
				if ( (int) $id <= (int) $state['last_enrolled_id'] && $this->project_one( (int) $id, $crm, $registry, $journal ) ) {
					++$stats['updated'];
				}
			}

			// 3. Reconcile: deletions and timestamp-less changes.
			$rows = $registry->page_after( (int) $state['reconcile_after'], $reconcile_budget );

			if ( [] === $rows && (int) $state['reconcile_after'] > 0 ) {
				// Wrap the cyclic walk and use this run's budget from the top.
				$state['reconcile_after'] = 0;
				$rows                     = $registry->page_after( 0, $reconcile_budget );
			}

			foreach ( $rows as $row ) {
				$state['reconcile_after'] = max( (int) $state['reconcile_after'], $row['id'] );
				++$stats['reconciled'];

				if ( 'active' !== $row['state'] || null === $row['subscriber_id'] ) {
					continue;
				}

				if ( ! $crm->exists( (int) $row['subscriber_id'] ) ) {
					if ( $this->tombstone( $row, $registry, $journal ) ) {
						++$stats['deleted'];
					}
					continue;
				}

				if ( $this->project_one( (int) $row['subscriber_id'], $crm, $registry, $journal ) ) {
					++$stats['updated'];
				}
			}

			$this->save_state( $state );
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $stats;
	}

	/**
	 * Journal a deletion, then flip the registry row to its tombstone.
	 * Journal-first: if the append fails, the row stays active and the
	 * next reconcile pass retries — a lost tombstone is never silent.
	 *
	 * @param array<string,mixed> $row      Active registry row.
	 * @param Registry            $registry Registry.
	 * @param Journal             $journal  Journal.
	 */
	private function tombstone( array $row, Registry $registry, Journal $journal ): bool {
		if ( 0 === $journal->append( $row['uuid'], 'delete', $row['revision'] + 1 ) ) {
			return false;
		}

		$registry->mark_deleted( $row['id'] );

		return true;
	}

	/**
	 * Immediate deletion capture from the CRM's purge hook. Best effort —
	 * the reconcile pass is the guarantee.
	 *
	 * @param mixed $subscriber_id Hook payload (the purged subscriber id).
	 */
	public static function capture_delete( $subscriber_id ): void {
		if ( ! Settings::enabled() || ! Schema::ready() || ! is_numeric( $subscriber_id ) ) {
			return;
		}

		try {
			$registry = new Registry();
			$row      = $registry->by_subscriber( (int) $subscriber_id );

			if ( null === $row || 'active' !== $row['state'] ) {
				return;
			}

			( new self() )->tombstone( $row, $registry, new Journal() );
		} catch ( \Throwable $e ) {
			unset( $e ); // Never let Hub bookkeeping disturb a CRM erasure.
		}
	}

	/**
	 * Immediate upsert capture from CRM lifecycle hooks. Accepts whatever
	 * payload shape the hook sends; anything unusable is left to the
	 * sweep. Best effort by design.
	 *
	 * @param mixed $subscriber Hook payload (an id, or an object with get_id()).
	 */
	public static function capture_touch( $subscriber ): void {
		if ( ! Settings::enabled() || ! Schema::ready() ) {
			return;
		}

		$id = 0;

		if ( is_numeric( $subscriber ) ) {
			$id = (int) $subscriber;
		} elseif ( is_object( $subscriber ) && method_exists( $subscriber, 'get_id' ) ) {
			try {
				$id = (int) $subscriber->get_id();
			} catch ( \Throwable $e ) {
				$id = 0;
			}
		}

		if ( $id < 1 ) {
			return;
		}

		try {
			( new self() )->project_one( $id, new Crm(), new Registry(), new Journal() );
		} catch ( \Throwable $e ) {
			unset( $e ); // Never let Hub bookkeeping disturb CRM writes.
		}
	}

	/**
	 * Project one contact and journal it when its canonical hash moved.
	 * Idempotent: an unchanged projection writes nothing. The hash also
	 * covers a fingerprint of the contact's stored ticket allocations, so
	 * a ticket change journals an upsert (the consumer's cue to refresh
	 * eligibility and the catalogue) even though tickets are served by
	 * their own endpoints.
	 *
	 * Write order is journal-first: if the append fails, the registry
	 * hash stays put and the next sweep retries — a change is never
	 * recorded as delivered without a journal row. (The reverse failure —
	 * an append whose bump then fails — yields a duplicate journal entry
	 * later, which an idempotent consumer absorbs.)
	 *
	 * @param int      $subscriber_id CRM subscriber id.
	 * @param Crm      $crm           CRM adapter.
	 * @param Registry $registry      Registry.
	 * @param Journal  $journal       Journal.
	 * @return bool Whether a change was journaled.
	 */
	public function project_one( int $subscriber_id, Crm $crm, Registry $registry, Journal $journal ): bool {
		$facts = Projection::facts( $crm, $subscriber_id );

		if ( null === $facts ) {
			return false; // Unreadable right now; reconcile decides later.
		}

		$fingerprint = array_map(
			static function ( array $entry ): array {
				unset( $entry['_field_updated_at'] );

				return $entry;
			},
			$crm->allocated_tickets( $subscriber_id )
		);

		$hash = Projection::hash( array_merge( $facts, [ '@tickets' => $fingerprint ] ) );
		$row  = $registry->by_subscriber( $subscriber_id );

		if ( null === $row ) {
			$row = $registry->enroll( $subscriber_id );

			if ( null === $row ) {
				return false;
			}
		}

		if ( 'active' !== $row['state'] || $row['projection_hash'] === $hash ) {
			return false;
		}

		$revision = $row['revision'] + 1;

		if ( 0 === $journal->append( $row['uuid'], 'upsert', $revision ) ) {
			return false;
		}

		$registry->bump( $row['id'], $hash, $revision );

		return true;
	}

	/**
	 * Sweep state with defaults.
	 *
	 * @return array<string,mixed>
	 */
	private function state(): array {
		return wp_parse_args(
			(array) get_option( self::STATE_OPTION, [] ),
			[
				'last_enrolled_id' => 0,
				'watermarks'       => [],
				'reconcile_after'  => 0,
				'retry'            => [],
			]
		);
	}

	/**
	 * Persist sweep state.
	 *
	 * @param array<string,mixed> $state State to save.
	 */
	private function save_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}
}
