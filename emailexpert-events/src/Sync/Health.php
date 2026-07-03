<?php
/**
 * Sync health monitoring.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

use Emailexpert\Events\Admin\Notices;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * A silently dead sync is the most likely long-term failure mode, so this is
 * loud: consecutive-failure tracking (notice after 3, email after 6), a Site
 * Health test, and status data shared with `wp eex status`.
 */
final class Health {

	private const FAILURES = 'eex_sync_failures';

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', [ $this, 'register_test' ] );
	}

	/**
	 * Record a successful run for a connection.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public static function record_success( string $connection_id ): void {
		$failures = (array) get_option( self::FAILURES, [] );

		if ( isset( $failures[ $connection_id ] ) ) {
			unset( $failures[ $connection_id ] );
			update_option( self::FAILURES, $failures, false );
		}

		Notices::remove( 'sync_failing_' . $connection_id );
	}

	/**
	 * Record a failed run for a connection; escalate at 3 and 6 consecutive
	 * failures.
	 *
	 * @param string $connection_id Connection ID.
	 */
	public static function record_failure( string $connection_id ): void {
		$failures = (array) get_option( self::FAILURES, [] );

		$entry          = (array) ( $failures[ $connection_id ] ?? [
			'count'   => 0,
			'emailed' => 0,
		] );
		$entry['count'] = (int) $entry['count'] + 1;
		$entry['last']  = gmdate( 'Y-m-d\TH:i:s\Z' );

		if ( $entry['count'] >= 3 ) {
			Notices::add(
				'sync_failing_' . $connection_id,
				sprintf(
					/* translators: 1: connection ID, 2: consecutive failure count. */
					__( 'HeySummit sync has failed %2$d times in a row for connection %1$s. Check the sync log.', 'emailexpert-events' ),
					$connection_id,
					$entry['count']
				),
				'error'
			);
		}

		if ( $entry['count'] >= 6 && empty( $entry['emailed'] ) && (bool) Options::setting( 'health_email' ) ) {
			wp_mail(
				get_bloginfo( 'admin_email' ),
				__( 'emailexpert Events: HeySummit sync is failing', 'emailexpert-events' ),
				sprintf(
					/* translators: 1: connection ID, 2: failure count, 3: admin URL. */
					__( "The HeySummit sync for connection %1\$s has failed %2\$d consecutive times.\n\nReview the sync log: %3\$s", 'emailexpert-events' ),
					$connection_id,
					$entry['count'],
					admin_url( 'options-general.php?page=emailexpert-events-log' )
				)
			);
			$entry['emailed'] = 1;
		}

		$failures[ $connection_id ] = $entry;
		update_option( self::FAILURES, $failures, false );
	}

	/**
	 * Status snapshot shared by Site Health and the CLI.
	 *
	 * @return array<string,mixed>
	 */
	public static function status(): array {
		$next_cron = wp_next_scheduled( 'eex_sync_cron' );

		return [
			'last_sync'     => (array) get_option( 'eex_last_sync', [] ),
			'failures'      => (array) get_option( self::FAILURES, [] ),
			'last_webhook'  => (string) get_option( 'eex_last_webhook_at', '' ),
			'next_cron'     => $next_cron ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $next_cron ) : '',
			'cron_overdue'  => (bool) ( $next_cron && $next_cron < time() - HOUR_IN_SECONDS ),
			'enabled_count' => count( SyncEngine::enabled_event_keys() ),
		];
	}

	/**
	 * Register the Site Health test.
	 *
	 * @param array<string,mixed> $tests Existing tests.
	 * @return array<string,mixed>
	 */
	public function register_test( array $tests ): array {
		$tests['direct']['eex_sync_health'] = [
			'label' => __( 'HeySummit sync health', 'emailexpert-events' ),
			'test'  => [ $this, 'run_test' ],
		];

		return $tests;
	}

	/**
	 * The Site Health test callback.
	 *
	 * @return array<string,mixed>
	 */
	public function run_test(): array {
		$status = self::status();
		$issues = [];

		foreach ( $status['failures'] as $connection_id => $entry ) {
			if ( (int) ( $entry['count'] ?? 0 ) >= 3 ) {
				$issues[] = sprintf(
					/* translators: 1: connection ID, 2: failure count. */
					__( 'Connection %1$s has %2$d consecutive failed sync runs.', 'emailexpert-events' ),
					(string) $connection_id,
					(int) $entry['count']
				);
			}
		}

		if ( $status['cron_overdue'] ) {
			$issues[] = __( 'The sync cron event is overdue; WP-Cron may not be firing.', 'emailexpert-events' );
		}

		if ( $status['enabled_count'] > 0 && empty( $status['last_sync'] ) ) {
			$issues[] = __( 'Events are enabled for sync but no run has completed yet.', 'emailexpert-events' );
		}

		$description = '<p>' . esc_html__( 'Last successful sync per event:', 'emailexpert-events' ) . '</p><ul>';
		foreach ( $status['last_sync'] as $key => $when ) {
			$description .= '<li><code>' . esc_html( (string) $key ) . '</code> — ' . esc_html( (string) $when ) . '</li>';
		}
		$description .= '</ul>';

		if ( '' !== $status['last_webhook'] ) {
			$description .= '<p>' . sprintf(
				/* translators: %s: timestamp. */
				esc_html__( 'Last webhook received: %s', 'emailexpert-events' ),
				esc_html( $status['last_webhook'] )
			) . '</p>';
		}

		if ( empty( $issues ) ) {
			return [
				'label'       => __( 'HeySummit sync is healthy', 'emailexpert-events' ),
				'status'      => 'good',
				'badge'       => [
					'label' => __( 'emailexpert Events', 'emailexpert-events' ),
					'color' => 'blue',
				],
				'description' => $description,
				'test'        => 'eex_sync_health',
			];
		}

		return [
			'label'       => __( 'HeySummit sync needs attention', 'emailexpert-events' ),
			'status'      => 'critical',
			'badge'       => [
				'label' => __( 'emailexpert Events', 'emailexpert-events' ),
				'color' => 'red',
			],
			'description' => '<p>' . implode( '</p><p>', array_map( 'esc_html', $issues ) ) . '</p>' . $description,
			'test'        => 'eex_sync_health',
		];
	}
}
