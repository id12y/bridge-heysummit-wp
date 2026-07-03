<?php
/**
 * Dashboard glance widget.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Query;
use Emailexpert\Events\Frontend\TimeFormat;
use Emailexpert\Events\Install\Tables;
use Emailexpert\Events\Sync\Health;

defined( 'ABSPATH' ) || exit;

/**
 * wp-admin dashboard widget: the next three sessions, registrations in the
 * last 7 days with a spark of sources, last sync time and health, and quick
 * links to Sync now and the log.
 */
final class Dashboard {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
	}

	/**
	 * Register the widget for admins.
	 */
	public function add_widget(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			'eex_dashboard',
			__( 'emailexpert Events', 'emailexpert-events' ),
			[ $this, 'render' ]
		);
	}

	/**
	 * Render the widget.
	 */
	public function render(): void {
		$next = Query::upcoming_talks( [ 'limit' => 3 ] );

		echo '<h3>' . esc_html__( 'Next sessions', 'emailexpert-events' ) . '</h3>';
		if ( empty( $next ) ) {
			echo '<p class="description">' . esc_html__( 'No upcoming sessions.', 'emailexpert-events' ) . '</p>';
		} else {
			echo '<ul>';
			foreach ( $next as $talk_id ) {
				$data = Components::talk_data( $talk_id );
				printf(
					'<li><a href="%s">%s</a> — %s</li>',
					esc_url( (string) $data['permalink'] ),
					esc_html( (string) $data['title'] ),
					TimeFormat::render( (string) $data['starts_at'], (string) $data['timezone'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in helper.
				);
			}
			echo '</ul>';
		}

		echo '<h3>' . esc_html__( 'Registrations, last 7 days', 'emailexpert-events' ) . '</h3>';
		$by_source = $this->registrations_by_source();
		if ( empty( $by_source ) ) {
			echo '<p class="description">' . esc_html__( 'No registrations recorded.', 'emailexpert-events' ) . '</p>';
		} else {
			$total = array_sum( $by_source );
			printf( '<p><strong>%d</strong></p><ul>', (int) $total );
			foreach ( $by_source as $source => $count ) {
				printf(
					'<li>%s: %d <span aria-hidden="true">%s</span></li>',
					esc_html( (string) $source ),
					(int) $count,
					esc_html( str_repeat( '▮', max( 1, (int) round( 10 * $count / max( 1, $total ) ) ) ) )
				);
			}
			echo '</ul>';
		}

		$status = Health::status();
		echo '<h3>' . esc_html__( 'Sync', 'emailexpert-events' ) . '</h3>';
		$last = ! empty( $status['last_sync'] ) ? max( array_map( 'strval', $status['last_sync'] ) ) : '';
		printf(
			'<p>%s %s</p>',
			esc_html__( 'Last successful sync:', 'emailexpert-events' ),
			esc_html( $last ?: __( 'never', 'emailexpert-events' ) )
		);
		if ( ! empty( $status['failures'] ) ) {
			echo '<p><strong>' . esc_html__( 'Attention: a connection has consecutive failures.', 'emailexpert-events' ) . '</strong></p>';
		}

		printf(
			'<p><a class="button" href="%s">%s</a> <a href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=emailexpert-events' ) ),
			esc_html__( 'Settings and Sync now', 'emailexpert-events' ),
			esc_url( admin_url( 'options-general.php?page=emailexpert-events-log' ) ),
			esc_html__( 'Sync log', 'emailexpert-events' )
		);
	}

	/**
	 * Completed registrations by source over the last 7 days, one query.
	 *
	 * @return array<string,int>
	 */
	private function registrations_by_source(): array {
		if ( ! Tables::exists( 'attribution' ) ) {
			return [];
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, admin widget.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}eex_attribution WHERE status = 'completed' AND created_at >= %s",
				gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
			),
			ARRAY_A
		);

		$by_source = [];
		foreach ( $rows as $row ) {
			$source               = (string) ( $row['utm_source'] ?: '(none)' );
			$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
		}
		arsort( $by_source );

		return $by_source;
	}
}
