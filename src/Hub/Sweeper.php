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
	 * @param int $update_budget    Maximum touched contacts to re-project.
	 * @param int $reconcile_budget Maximum registry rows to reconcile.
	 * @return array<string,int> Run statistics.
	 */
	public function run( int $enroll_budget = 200, int $update_budget = 200, int $reconcile_budget = 50 ): array {
		$stats = [
			'enrolled'   => 0,
			'updated'    => 0,
			'deleted'    => 0,
			'reconciled' => 0,
		];

		if ( false !== get_transient( self::LOCK_KEY ) ) {
			return $stats;
		}
		set_transient( self::LOCK_KEY, 1, 2 * MINUTE_IN_SECONDS );

		try {
			$crm = new Crm();

			if ( ! $crm->available() ) {
				return $stats;
			}

			$registry = new Registry();
			$journal  = new Journal();
			$state    = $this->state();
			$started  = time();

			// 1. Enroll new subscribers (and backfill, batch by batch).
			$ids = $crm->ids_after( (int) $state['last_enrolled_id'], $enroll_budget );
			foreach ( $ids as $id ) {
				if ( $this->project_one( $id, $crm, $registry, $journal ) ) {
					++$stats['enrolled'];
				}
				$state['last_enrolled_id'] = max( (int) $state['last_enrolled_id'], $id );
			}

			// 2. Re-project contacts with fresh timestamps.
			$since   = gmdate( 'Y-m-d H:i:s', max( 0, $this->watermark_time( $state ) - self::OVERLAP_SECONDS ) );
			$touched = $crm->ids_updated_since( $since, $update_budget );
			foreach ( $touched as $id ) {
				if ( $id <= (int) $state['last_enrolled_id'] && $this->project_one( $id, $crm, $registry, $journal ) ) {
					++$stats['updated'];
				}
			}

			// Advance the watermark only when the touched set was not
			// truncated at the budget — otherwise re-scan from the same
			// point next run (idempotent, so re-reads are free).
			if ( count( $touched ) < $update_budget ) {
				$state['watermark'] = gmdate( 'Y-m-d H:i:s', $started );
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
					$revision = $registry->mark_deleted( $row['id'] );
					if ( $revision > 0 ) {
						$journal->append( $row['uuid'], 'delete', $revision );
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

			$revision = $registry->mark_deleted( $row['id'] );

			if ( $revision > 0 ) {
				( new Journal() )->append( $row['uuid'], 'delete', $revision );
			}
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
	 * Idempotent: an unchanged projection writes nothing.
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

		$hash = Projection::hash( $facts );
		$row  = $registry->by_subscriber( $subscriber_id );

		if ( null === $row ) {
			$row = $registry->enroll( $subscriber_id );

			if ( null === $row ) {
				return false;
			}

			$revision = $registry->bump( $row['id'], $hash );
			$journal->append( $row['uuid'], 'upsert', max( 1, $revision ) );

			return true;
		}

		if ( 'active' !== $row['state'] || $row['projection_hash'] === $hash ) {
			return false;
		}

		$revision = $registry->bump( $row['id'], $hash );

		if ( $revision > 0 ) {
			$journal->append( $row['uuid'], 'upsert', $revision );
		}

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
				'watermark'        => '1970-01-01 00:00:00',
				'reconcile_after'  => 0,
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

	/**
	 * The watermark as a Unix timestamp.
	 *
	 * @param array<string,mixed> $state Sweep state.
	 */
	private function watermark_time( array $state ): int {
		$time = strtotime( (string) $state['watermark'] . ' UTC' );

		return false !== $time ? $time : 0;
	}
}
