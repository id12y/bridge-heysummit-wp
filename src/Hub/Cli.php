<?php
/**
 * WP-CLI surface for the Hub API.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

use Emailexpert\Events\Options;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * `wp eex hub <subcommand>` — the operator surface for the feature
 * switch, credentials and the backfill. Registered even while the Hub is
 * disabled (that is how it gets enabled); everything else in the module
 * stays unloaded until the switch is on.
 *
 * Credential tokens are printed exactly once, at creation, and never
 * stored or logged in recoverable form.
 */
final class Cli {

	/**
	 * Hook up.
	 */
	public function register(): void {
		WP_CLI::add_command( 'eex hub', $this );
	}

	/**
	 * Show Hub status: switch, schema, CRM availability, backfill, journal.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub status
	 *
	 * @subcommand status
	 */
	public function status(): void {
		$crm      = new Crm();
		$enabled  = Settings::enabled();
		$ready    = Schema::ready();
		$counts   = $ready ? ( new Registry() )->counts() : [
			'total'   => 0,
			'active'  => 0,
			'deleted' => 0,
		];
		$total    = $crm->available() ? $crm->count_subscribers() : null;
		$journal  = $ready ? ( new Journal() )->head() : 0;
		$creds    = $ready ? ( new Credentials() )->all() : [];
		$active   = count( array_filter( $creds, static fn( $c ) => 'active' === $c['status'] ) );
		$next_run = wp_next_scheduled( Module::SWEEP_HOOK );

		WP_CLI::log( 'Feature switch:   ' . ( $enabled ? 'ENABLED' : 'disabled' ) );
		WP_CLI::log( 'Schema:           ' . ( $ready ? 'ready (v' . Schema::VERSION . ')' : 'not created' ) );
		WP_CLI::log( 'CRM plugin:       ' . ( $crm->available() ? 'available' : 'NOT AVAILABLE' ) );
		WP_CLI::log( 'Registry:         ' . $counts['total'] . ' contacts (' . $counts['active'] . ' active, ' . $counts['deleted'] . ' tombstones)' );
		WP_CLI::log( 'CRM contacts:     ' . ( null !== $total ? (string) $total : 'unknown' ) );
		WP_CLI::log( 'Backfill:         ' . ( null !== $total && $counts['total'] >= $total ? 'complete' : 'in progress / pending' ) );
		WP_CLI::log( 'Journal head:     ' . $journal );
		WP_CLI::log( 'Credentials:      ' . count( $creds ) . ' total, ' . $active . ' active' );
		WP_CLI::log( 'Sweep scheduled:  ' . ( $next_run ? gmdate( 'Y-m-d H:i:s\Z', $next_run ) : 'no' ) );
	}

	/**
	 * Enable the Hub API: create the Hub tables, schedule the sweep and
	 * flip the feature switch. Additive only — no CRM table is touched.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub enable
	 *
	 * @subcommand enable
	 */
	public function enable(): void {
		Schema::ensure();

		if ( ! Schema::ready() ) {
			WP_CLI::error( 'Could not create the Hub tables.' );
		}

		Options::update_settings( [ 'hub_enabled' => 1 ] );

		if ( ! wp_next_scheduled( Module::SWEEP_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, Module::SWEEP_SCHEDULE, Module::SWEEP_HOOK );
		}

		WP_CLI::success( 'Hub API enabled. Create a credential with `wp eex hub token-create --label=community-hub`, then run `wp eex hub backfill`.' );
	}

	/**
	 * Disable the Hub API immediately: flip the switch and stop the sweep.
	 * Tables and credentials are kept (re-enabling resumes where it left
	 * off); revoke credentials separately if this is a security response.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub disable
	 *
	 * @subcommand disable
	 */
	public function disable(): void {
		Options::update_settings( [ 'hub_enabled' => 0 ] );
		wp_unschedule_hook( Module::SWEEP_HOOK );

		WP_CLI::success( 'Hub API disabled. All Hub routes now return 404; the sweep is unscheduled.' );
	}

	/**
	 * Create a Hub credential. The token is printed ONCE — store it in the
	 * Community Hub's secret manager immediately; it cannot be recovered.
	 *
	 * ## OPTIONS
	 *
	 * [--label=<label>]
	 * : Operator-facing label. Default 'community-hub'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub token-create --label=community-hub
	 *
	 * @subcommand token-create
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function token_create( array $args, array $assoc_args ): void {
		$this->require_ready();

		$credential = ( new Credentials() )->create( (string) ( $assoc_args['label'] ?? 'community-hub' ) );

		WP_CLI::log( 'Credential id: ' . $credential['id'] );
		WP_CLI::log( 'Token (shown once, never stored): ' . $credential['token'] );
		WP_CLI::success( 'Send with header:  Authorization: Bearer <token>' );
	}

	/**
	 * Revoke a Hub credential immediately.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The credential id (see `wp eex hub token-list`).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub token-revoke 1
	 *
	 * @subcommand token-revoke
	 *
	 * @param array<int,string> $args Positional args.
	 */
	public function token_revoke( array $args ): void {
		$this->require_ready();

		$id = isset( $args[0] ) ? (int) $args[0] : 0;

		if ( $id < 1 ) {
			WP_CLI::error( 'Provide a credential id: wp eex hub token-revoke <id>' );
		}

		if ( ( new Credentials() )->revoke( $id ) ) {
			WP_CLI::success( 'Credential ' . $id . ' revoked. Requests using it are rejected from now.' );
		} else {
			WP_CLI::error( 'No active credential with id ' . $id . '.' );
		}
	}

	/**
	 * List Hub credentials (labels, hints and status — never tokens).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub token-list
	 *
	 * @subcommand token-list
	 */
	public function token_list(): void {
		$this->require_ready();

		$rows = ( new Credentials() )->all();

		if ( [] === $rows ) {
			WP_CLI::log( 'No credentials.' );

			return;
		}

		foreach ( $rows as $row ) {
			WP_CLI::log(
				sprintf(
					'#%d  %-20s  %s  %s  created %s  last used %s',
					$row['id'],
					$row['label'],
					$row['token_hint'],
					strtoupper( $row['status'] ),
					$row['created_at'],
					'' !== $row['last_used_at'] ? $row['last_used_at'] : 'never'
				)
			);
		}
	}

	/**
	 * Run the UUID backfill until every CRM contact is enrolled. Batched,
	 * resumable and idempotent: safe to interrupt and re-run, no long
	 * table locks, never inside a web request.
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<n>]
	 * : Contacts per batch (max 500). Default 200.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub backfill --batch=200
	 *
	 * @subcommand backfill
	 *
	 * @param array<int,string>    $args       Positional args.
	 * @param array<string,string> $assoc_args Named args.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		$this->require_ready();

		$crm = new Crm();

		if ( ! $crm->available() ) {
			WP_CLI::error( 'The CRM plugin (EmailExpert Newsletter) is not available.' );
		}

		$batch   = min( 500, max( 10, (int) ( $assoc_args['batch'] ?? 200 ) ) );
		$sweeper = new Sweeper();
		$total   = $crm->count_subscribers();
		$done    = 0;

		while ( true ) {
			$stats = $sweeper->run( $batch, 0, 0 );
			$done += $stats['enrolled'];

			if ( 0 === $stats['enrolled'] ) {
				break;
			}

			WP_CLI::log( 'Enrolled ' . $done . ' of ~' . $total . ' contacts…' );
		}

		$counts = ( new Registry() )->counts();
		WP_CLI::success( 'Backfill complete: ' . $counts['total'] . ' registry rows for ~' . $total . ' CRM contacts.' );
	}

	/**
	 * Run one sweep now (enrol + update + reconcile) and print what moved.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eex hub sweep
	 *
	 * @subcommand sweep
	 */
	public function sweep(): void {
		$this->require_ready();

		$stats = ( new Sweeper() )->run();

		WP_CLI::success(
			sprintf(
				'Sweep done: %d enrolled, %d updated, %d deleted, %d reconciled.',
				$stats['enrolled'],
				$stats['updated'],
				$stats['deleted'],
				$stats['reconciled']
			)
		);
	}

	/**
	 * Bail unless the Hub schema exists.
	 */
	private function require_ready(): void {
		if ( ! Schema::ready() ) {
			WP_CLI::error( 'The Hub is not set up yet. Run `wp eex hub enable` first.' );
		}
	}
}
