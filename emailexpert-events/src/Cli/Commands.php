<?php
/**
 * WP-CLI commands.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Cli;

use Emailexpert\Events\Api\Discovery;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Options;
use Emailexpert\Events\PostTypes\PostTypes;
use Emailexpert\Events\Sync\Health;
use Emailexpert\Events\Sync\SyncEngine;
use Emailexpert\Events\Webhooks\Processor;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the `wp eex` command surface: sync, status, orphans, discover,
 * webhooks:replay.
 */
final class Commands {

	/**
	 * Hook up.
	 */
	public function register(): void {
		WP_CLI::add_command( 'eex sync', [ $this, 'sync' ] );
		WP_CLI::add_command( 'eex status', [ $this, 'status' ] );
		WP_CLI::add_command( 'eex orphans', [ $this, 'orphans' ] );
		WP_CLI::add_command( 'eex discover', [ $this, 'discover' ] );
		WP_CLI::add_command( 'eex webhooks:replay', [ $this, 'replay' ] );
		WP_CLI::add_command( 'eex woo:push', [ $this, 'woo_push' ] );
	}

	/**
	 * Manually push a WooCommerce order to HeySummit.
	 *
	 * ## OPTIONS
	 *
	 * <order_id>
	 * : The WooCommerce order ID.
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function woo_push( array $args ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$order_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $order_id <= 0 ) {
			WP_CLI::error( 'Provide an order ID: wp eex woo:push <order_id>' );
		}

		$results = ( new \Emailexpert\Events\WooCommerce\Pusher() )->push_order_now( $order_id );

		if ( empty( $results ) ) {
			WP_CLI::warning( 'No mapped line items on that order.' );

			return;
		}

		foreach ( $results as $item_id => $result ) {
			WP_CLI::log( sprintf( 'Item %d: %s — %s', $item_id, $result['status'], $result['message'] ) );
		}

		WP_CLI::success( 'Done.' );
	}

	/**
	 * Run a sync.
	 *
	 * ## OPTIONS
	 *
	 * [--event=<id>]
	 * : Sync only this HeySummit event ID.
	 *
	 * [--force]
	 * : Ignore sync hashes and write every record.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function sync( array $args, array $assoc_args ): void {
		$force = isset( $assoc_args['force'] );
		$keys  = SyncEngine::enabled_event_keys();

		if ( isset( $assoc_args['event'] ) ) {
			$event = (string) $assoc_args['event'];
			$keys  = array_values( array_filter( $keys, static fn( string $key ): bool => str_ends_with( $key, '|' . $event ) ) );

			if ( empty( $keys ) ) {
				WP_CLI::error( sprintf( 'Event %s is not enabled for sync.', $event ) );
			}
		}

		if ( empty( $keys ) ) {
			WP_CLI::warning( 'No events are enabled for sync.' );

			return;
		}

		// No time budget in CLI: run everything inline.
		add_filter( 'eex_sync_time_budget', static fn(): int => PHP_INT_MAX );

		$engine = new SyncEngine();
		$engine->run_keys( $keys, $force );

		WP_CLI::success( sprintf( 'Sync completed for %d event(s). See the sync log for detail.', count( $keys ) ) );
	}

	/**
	 * Report sync health.
	 */
	public function status(): void {
		$status = Health::status();

		WP_CLI::log( sprintf( 'Enabled events: %d', $status['enabled_count'] ) );
		WP_CLI::log( sprintf( 'Next cron run:  %s%s', $status['next_cron'] ?: 'not scheduled', $status['cron_overdue'] ? ' (OVERDUE)' : '' ) );
		WP_CLI::log( sprintf( 'Last webhook:   %s', $status['last_webhook'] ?: 'never' ) );

		foreach ( $status['last_sync'] as $key => $when ) {
			WP_CLI::log( sprintf( 'Last sync %s: %s', (string) $key, (string) $when ) );
		}

		$failing = false;
		foreach ( $status['failures'] as $connection_id => $entry ) {
			$failing = true;
			WP_CLI::warning( sprintf( 'Connection %s: %d consecutive failures (last %s).', (string) $connection_id, (int) ( $entry['count'] ?? 0 ), (string) ( $entry['last'] ?? '?' ) ) );
		}

		if ( ! $failing ) {
			WP_CLI::success( 'No failing connections.' );
		}
	}

	/**
	 * List orphaned posts.
	 *
	 * ## OPTIONS
	 *
	 * [--list]
	 * : List each orphaned post.
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function orphans( array $args, array $assoc_args ): void {
		$rows = [];

		foreach ( PostTypes::SYNCED as $post_type ) {
			$ids = get_posts(
				[
					'post_type'      => $post_type,
					'post_status'    => 'any',
					'meta_key'       => '_eex_orphaned', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- CLI report.
					'meta_value'     => 1, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				]
			);

			foreach ( $ids as $post_id ) {
				$rows[] = [
					'ID'           => (int) $post_id,
					'type'         => $post_type,
					'title'        => get_the_title( (int) $post_id ),
					'heysummit_id' => (string) get_post_meta( (int) $post_id, '_eex_heysummit_id', true ),
				];
			}
		}

		WP_CLI::log( sprintf( '%d orphaned post(s).', count( $rows ) ) );

		if ( isset( $assoc_args['list'] ) && ! empty( $rows ) ) {
			WP_CLI\Utils\format_items( 'table', $rows, [ 'ID', 'type', 'title', 'heysummit_id' ] );
		}
	}

	/**
	 * Run the API shape discovery diagnostic for every connection.
	 */
	public function discover(): void {
		$connections = Options::connections();

		if ( empty( $connections ) ) {
			WP_CLI::error( 'No connections configured.' );
		}

		foreach ( $connections as $connection ) {
			if ( '' === (string) ( $connection['api_key'] ?? '' ) ) {
				WP_CLI::warning( sprintf( 'Connection %s has no API key; skipped.', (string) ( $connection['id'] ?? '?' ) ) );
				continue;
			}

			WP_CLI::log( sprintf( '== Connection %s ==', (string) ( $connection['label'] ?? $connection['id'] ) ) );
			$report = Discovery::run( HeySummitClient::for_connection( $connection ), (string) $connection['id'] );

			foreach ( $report as $resource_slug => $row ) {
				if ( ! empty( $row['error'] ) ) {
					WP_CLI::warning( sprintf( '%s: %s', (string) $resource_slug, (string) $row['error'] ) );
					continue;
				}
				if ( ! empty( $row['empty'] ) ) {
					WP_CLI::log( sprintf( '%s: no records to sample.', (string) $resource_slug ) );
					continue;
				}

				WP_CLI::log(
					sprintf(
						'%s: %d fields found; missing: %s; unmapped: %s; type mismatches: %s',
						(string) $resource_slug,
						count( (array) $row['found'] ),
						implode( ',', (array) $row['missing'] ) ?: 'none',
						implode( ',', (array) $row['unmapped'] ) ?: 'none',
						implode( ',', array_keys( (array) $row['type_mismatch'] ) ) ?: 'none'
					)
				);
			}
		}

		WP_CLI::success( 'Discovery complete. Full detail is on the settings page diagnostics panel.' );
	}

	/**
	 * Re-process a captured webhook payload from the log.
	 *
	 * ## OPTIONS
	 *
	 * <log_id>
	 * : The sync log row ID of a captured payload (context webhook, flagged capture).
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function replay( array $args ): void {
		$log_id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $log_id <= 0 ) {
			WP_CLI::error( 'Provide a log row ID: wp eex webhooks:replay <log_id>' );
		}

		$row = Logger::get( $log_id );

		if ( null === $row ) {
			WP_CLI::error( sprintf( 'Log row %d not found.', $log_id ) );
		}

		$data    = json_decode( (string) ( $row['data'] ?? '' ), true );
		$payload = is_array( $data ) ? ( $data['payload'] ?? null ) : null;

		if ( ! is_array( $payload ) ) {
			WP_CLI::error( 'Log row carries no captured payload. Enable capture mode, trigger one self-registration, and use that row.' );
		}

		$result = ( new Processor() )->process_payload( $payload, true );

		WP_CLI::log( 'Parsed action:   ' . (string) ( $result['action'] ?? 'unrecognised' ) );
		WP_CLI::log( 'Attendee HS ID:  ' . (string) ( $result['attendee_hs_id'] ?? '' ) );
		WP_CLI::log( 'Event HS ID:     ' . (string) ( $result['event_hs_id'] ?? '' ) );
		WP_CLI::log( 'Handled:         ' . ( ! empty( $result['handled'] ) ? 'yes' : 'no' ) );

		if ( ! empty( $result['notes'] ) ) {
			foreach ( (array) $result['notes'] as $note ) {
				WP_CLI::log( 'Note: ' . (string) $note );
			}
		}

		WP_CLI::success( 'Replay complete.' );
	}
}
