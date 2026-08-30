<?php
/**
 * Community Hub API module.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * The additive integration layer for the Community Hub: a versioned,
 * read-only, default-deny REST contract over the sibling CRM plugin's
 * approved facts, with its own identity registry, change journal and
 * revocable service credentials — and zero writes to CRM or Ticket
 * Tailor data anywhere.
 *
 * Gated exactly like the accounts module: unless the `hub_enabled`
 * switch in the one autoloaded settings option is on, nothing here loads
 * and no route exists. The WP-CLI surface (Hub\Cli) is registered from
 * Cli\Commands regardless, because `wp eex hub enable` is how the switch
 * gets turned on.
 */
final class Module {

	public const SWEEP_HOOK     = 'eex_hub_sweep';
	public const SWEEP_SCHEDULE = 'eex_hub_five_minutes';

	/**
	 * Register the enabled module.
	 */
	public static function register(): void {
		( new RestController() )->register();

		add_filter( 'cron_schedules', [ self::class, 'add_schedule' ] ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- 300s, above the platform minimum for background reconciliation.
		add_action( self::SWEEP_HOOK, [ Sweeper::class, 'cron' ] );

		// Self-heal the schedule (mirrors the plugin's other recurring
		// hooks); cheap single-option read.
		if ( ! wp_next_scheduled( self::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SWEEP_SCHEDULE, self::SWEEP_HOOK );
		}

		// Immediacy taps on the CRM's lifecycle hooks. Best effort and
		// try/caught inside — correctness always rests on the sweep, and
		// Hub bookkeeping must never disturb a CRM write or erasure.
		add_action( 'een_after_purge_subscriber', [ Sweeper::class, 'capture_delete' ], 10, 1 );
		add_action( 'een_subscriber_created', [ Sweeper::class, 'capture_touch' ], 10, 1 );
		add_action( 'een_subscriber_confirmed', [ Sweeper::class, 'capture_touch' ], 10, 1 );
		add_action( 'een_subscriber_unsubscribed', [ Sweeper::class, 'capture_touch' ], 10, 1 );
		add_action( 'een_subscriber_resubscribed', [ Sweeper::class, 'capture_touch' ], 10, 1 );
	}

	/**
	 * The five-minute sweep interval.
	 *
	 * @param array<string,array<string,mixed>> $schedules Cron schedules.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SWEEP_SCHEDULE ] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => 'Every five minutes (emailexpert Events Hub sweep)',
		];

		return $schedules;
	}
}
