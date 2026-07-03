<?php
/**
 * Per-rule backfill of existing users.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Logging\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Never automatic: a manual dry run (exact count plus a sample) precedes an
 * explicit confirmation, then queued batches run with progress in the sync
 * log. Every batch re-runs the identical Engine::run_rule gate chain, so
 * suppression, consent and idempotency hold and the dry-run count matches
 * what the confirmed run pushes. State persists in an option, making an
 * interrupted backfill resumable.
 */
class Backfill {

	private const STATE      = 'eex_backfill_state';
	private const BATCH_SIZE = 20;

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Constructor.
	 */
	public function __construct( ?Engine $engine = null ) {
		$this->engine = $engine ?? new Engine();
	}

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_accounts_backfill', [ $this, 'run_batch' ] );
	}

	/**
	 * Dry run: the exact users a confirmed run would push.
	 *
	 * @param string $rule_id Rule ID.
	 * @return array{count:int,sample:array<int,string>,user_ids:int[]}
	 */
	public function dry_run( string $rule_id ): array {
		$rule = Rules::get( $rule_id );

		$matched = [];

		if ( null !== $rule && ! empty( $rule['enabled'] ) ) {
			foreach ( get_users( [ 'fields' => 'ID' ] ) as $user_id ) {
				$user_id = (int) ( is_object( $user_id ) ? $user_id->ID : $user_id );
				$context = $this->engine->backfill_context( $user_id, $rule );

				if ( null === $context ) {
					continue;
				}

				if ( $this->engine->run_rule( $user_id, $rule, (string) $rule['trigger'], $context, true ) ) {
					$matched[] = $user_id;
				}
			}
		}

		$sample = [];
		foreach ( array_slice( $matched, 0, 10 ) as $user_id ) {
			$user     = get_userdata( $user_id );
			$sample[] = $user ? (string) $user->user_login : (string) $user_id;
		}

		return [
			'count'    => count( $matched ),
			'sample'   => $sample,
			'user_ids' => $matched,
		];
	}

	/**
	 * Confirm: store the matched set and queue the first batch.
	 *
	 * @param string $rule_id Rule ID.
	 * @return int Users queued.
	 */
	public function confirm( string $rule_id ): int {
		$dry = $this->dry_run( $rule_id );

		if ( 0 === $dry['count'] ) {
			return 0;
		}

		$state             = (array) get_option( self::STATE, [] );
		$state[ $rule_id ] = [
			'user_ids'   => $dry['user_ids'],
			'position'   => 0,
			'total'      => $dry['count'],
			'started_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
		];
		update_option( self::STATE, $state, false );

		Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Backfill confirmed for rule %s: %d user(s) queued in batches of %d.', $rule_id, $dry['count'], self::BATCH_SIZE ) );

		wp_schedule_single_event( time() - 1, 'eex_accounts_backfill', [ $rule_id ] );
		spawn_cron();

		return $dry['count'];
	}

	/**
	 * Stored progress for a rule (for the UI and resume).
	 *
	 * @param string $rule_id Rule ID.
	 * @return array<string,mixed>|null
	 */
	public static function progress( string $rule_id ): ?array {
		$state = (array) get_option( self::STATE, [] );

		return isset( $state[ $rule_id ] ) && is_array( $state[ $rule_id ] ) ? $state[ $rule_id ] : null;
	}

	/**
	 * Resume an interrupted backfill by re-queueing its next batch.
	 *
	 * @param string $rule_id Rule ID.
	 */
	public function resume( string $rule_id ): bool {
		if ( null === self::progress( $rule_id ) ) {
			return false;
		}

		wp_schedule_single_event( time() - 1, 'eex_accounts_backfill', [ $rule_id ] );
		spawn_cron();

		return true;
	}

	/**
	 * Process one batch, then reschedule until done. Every user re-runs the
	 * full gate chain (suppression/consent/idempotency may have changed
	 * since confirmation).
	 *
	 * @param string $rule_id Rule ID.
	 */
	public function run_batch( $rule_id = '' ): void {
		$rule_id = (string) $rule_id;
		$rule    = Rules::get( $rule_id );
		$state   = (array) get_option( self::STATE, [] );
		$job     = $state[ $rule_id ] ?? null;

		if ( null === $rule || ! is_array( $job ) ) {
			return;
		}

		$user_ids = array_map( 'intval', (array) $job['user_ids'] );
		$position = (int) $job['position'];
		$batch    = array_slice( $user_ids, $position, self::BATCH_SIZE );

		foreach ( $batch as $user_id ) {
			$context = $this->engine->backfill_context( $user_id, $rule );

			if ( null !== $context ) {
				$this->engine->run_rule( $user_id, $rule, (string) $rule['trigger'], $context );
			}
		}

		$position += count( $batch );

		if ( $position >= count( $user_ids ) ) {
			unset( $state[ $rule_id ] );
			update_option( self::STATE, $state, false );
			Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Backfill for rule %s complete: %d user(s) processed.', $rule_id, count( $user_ids ) ) );

			return;
		}

		$state[ $rule_id ]['position'] = $position;
		update_option( self::STATE, $state, false );

		Logger::info( Logger::CONTEXT_SYNC, sprintf( 'Backfill for rule %s: %d of %d processed.', $rule_id, $position, count( $user_ids ) ) );

		wp_schedule_single_event( time() + 5, 'eex_accounts_backfill', [ $rule_id ] );
		spawn_cron();
	}
}
