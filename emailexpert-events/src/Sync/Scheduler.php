<?php
/**
 * Cron scheduling for the sync engine.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the eex_sync_cron recurring event, the async "Sync now" dispatch and
 * the continuation event used when a run exceeds its time budget. When
 * Action Scheduler is available (for example via WooCommerce), per-event jobs
 * are queued through it so one slow event cannot exhaust a run.
 */
final class Scheduler {

	/**
	 * Available frequencies: key => [label, interval seconds].
	 */
	public const FREQUENCIES = [
		'15min'      => [
			'label'    => 'Every 15 minutes',
			'interval' => 900,
		],
		'hourly'     => [
			'label'    => 'Hourly',
			'interval' => 3600,
		],
		'twicedaily' => [
			'label'    => 'Twice daily',
			'interval' => 43200,
		],
		'daily'      => [
			'label'    => 'Daily',
			'interval' => 86400,
		],
	];

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'cron_schedules', [ $this, 'add_schedules' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 15 minutes is a deliberate choice.
		add_action( 'eex_sync_cron', [ $this, 'run_scheduled' ] );
		add_action( 'eex_async_sync', [ $this, 'run_async' ] );
		add_action( 'eex_sync_continue', [ $this, 'run_continuation' ], 10, 2 );
		add_action( 'eex_sync_event_job', [ $this, 'run_event_job' ], 10, 3 );
	}

	/**
	 * Register the custom 15-minute interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Existing schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedules( array $schedules ): array {
		$schedules['eex_15min'] = [
			'interval' => 900,
			'display'  => __( 'Every 15 minutes (emailexpert Events)', 'emailexpert-events' ),
		];

		return $schedules;
	}

	/**
	 * (Re)schedule the recurring sync at a frequency.
	 *
	 * @param string $frequency Key from FREQUENCIES.
	 */
	public static function schedule( string $frequency ): void {
		$recurrence = match ( $frequency ) {
			'15min'      => 'eex_15min',
			'twicedaily' => 'twicedaily',
			'daily'      => 'daily',
			default      => 'hourly',
		};

		wp_clear_scheduled_hook( 'eex_sync_cron' );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, $recurrence, 'eex_sync_cron' );
	}

	/**
	 * Cron on demand: schedule the recurring sync only while at least one
	 * event is enabled; unschedule when the last one is disabled.
	 *
	 * @param string $frequency Frequency key ('' = keep the configured one).
	 */
	public static function sync_schedule_state( string $frequency = '' ): void {
		$frequency = '' !== $frequency ? $frequency : (string) \Emailexpert\Events\Options::setting( 'frequency' );

		if ( empty( SyncEngine::enabled_event_keys() ) ) {
			wp_clear_scheduled_hook( 'eex_sync_cron' );

			return;
		}

		self::schedule( $frequency );
	}

	/**
	 * Queue an immediate full run without blocking the current request.
	 *
	 * @param bool $force Ignore sync hashes.
	 */
	public static function dispatch_async_run( bool $force = false ): void {
		wp_schedule_single_event( time() - 1, 'eex_async_sync', [ $force ? 1 : 0 ] );
		spawn_cron();
	}

	/**
	 * Whether Action Scheduler is available on this site.
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	/**
	 * Recurring cron entry point.
	 */
	public function run_scheduled(): void {
		$this->fan_out( false );
	}

	/**
	 * "Sync now" entry point.
	 *
	 * @param int $force 1 to ignore hashes.
	 */
	public function run_async( $force = 0 ): void {
		$this->fan_out( (bool) $force );
	}

	/**
	 * Continuation entry point for time-budgeted runs.
	 *
	 * @param array $remaining Remaining event keys.
	 * @param int   $force     1 to ignore hashes.
	 */
	public function run_continuation( $remaining = [], $force = 0 ): void {
		$engine = new SyncEngine();
		$engine->run_keys( (array) $remaining, (bool) $force );
	}

	/**
	 * Action Scheduler per-event job.
	 *
	 * @param string $connection_id Connection ID.
	 * @param string $event_id      HeySummit event ID.
	 * @param int    $force         1 to ignore hashes.
	 */
	public function run_event_job( $connection_id = '', $event_id = '', $force = 0 ): void {
		$engine = new SyncEngine();
		$engine->run_keys( [ (string) $connection_id . '|' . (string) $event_id ], (bool) $force );
	}

	/**
	 * Fan a run out to per-event jobs (Action Scheduler) or run inline with a
	 * time budget and continuation.
	 *
	 * @param bool $force Ignore sync hashes.
	 */
	private function fan_out( bool $force ): void {
		$keys = SyncEngine::enabled_event_keys();

		if ( empty( $keys ) ) {
			return;
		}

		if ( self::has_action_scheduler() ) {
			foreach ( $keys as $key ) {
				[ $connection_id, $event_id ] = array_pad( explode( '|', $key, 2 ), 2, '' );
				as_enqueue_async_action( 'eex_sync_event_job', [ $connection_id, $event_id, $force ? 1 : 0 ], 'emailexpert-events' );
			}

			return;
		}

		$engine = new SyncEngine();
		$engine->run_keys( $keys, $force );
	}
}
