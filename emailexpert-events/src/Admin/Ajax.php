<?php
/**
 * Admin AJAX endpoints.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Api\Discovery;
use Emailexpert\Events\Api\HeySummitClient;
use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Nonce- and capability-protected AJAX actions used by the settings screen.
 */
final class Ajax {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'wp_ajax_eex_test_connection', [ $this, 'test_connection' ] );
		add_action( 'wp_ajax_eex_load_events', [ $this, 'load_events' ] );
		add_action( 'wp_ajax_eex_load_categories', [ $this, 'load_categories' ] );
		add_action( 'wp_ajax_eex_sync_now', [ $this, 'sync_now' ] );
		add_action( 'wp_ajax_eex_regenerate_secret', [ $this, 'regenerate_secret' ] );
	}

	/**
	 * Common guard for every action.
	 */
	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'emailexpert-events' ) ], 403 );
		}

		check_ajax_referer( 'eex_admin', 'nonce' );
	}

	/**
	 * Resolve the connection named in the request.
	 *
	 * @return array<string,string>
	 */
	private function requested_connection(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$connection_id = isset( $_POST['connection'] ) ? sanitize_key( wp_unslash( $_POST['connection'] ) ) : '';
		$connection    = Options::connection( $connection_id );

		if ( null === $connection || '' === (string) ( $connection['api_key'] ?? '' ) ) {
			wp_send_json_error( [ 'message' => __( 'Connection not found or has no API key saved.', 'emailexpert-events' ) ], 400 );
		}

		return $connection;
	}

	/**
	 * Test a connection, and on success run the discovery diagnostic.
	 */
	public function test_connection(): void {
		$this->guard();
		$connection = $this->requested_connection();

		$client = HeySummitClient::for_connection( $connection );
		$result = $client->test();

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$count  = isset( $result['count'] ) ? (int) $result['count'] : count( (array) ( $result['results'] ?? [] ) );
		$report = Discovery::run( $client, $connection['id'] );

		$mismatches = 0;
		foreach ( $report as $resource_report ) {
			$mismatches += count( (array) ( $resource_report['missing'] ?? [] ) ) + count( (array) ( $resource_report['type_mismatch'] ?? [] ) );
		}

		Notices::remove( 'auth_' . $connection['id'] );

		wp_send_json_success(
			[
				'message'    => sprintf(
					/* translators: 1: number of events, 2: number of shape mismatches. */
					__( 'Connection succeeded. %1$d event(s) visible. Discovery ran: %2$d shape mismatch(es); see the diagnostics panel.', 'emailexpert-events' ),
					$count,
					$mismatches
				),
				'mismatches' => $mismatches,
			]
		);
	}

	/**
	 * Fetch and cache the list of events available on a connection.
	 */
	public function load_events(): void {
		$this->guard();
		$connection = $this->requested_connection();

		$client = HeySummitClient::for_connection( $connection );
		$events = $client->get_all( 'events/' );

		if ( is_wp_error( $events ) ) {
			wp_send_json_error( [ 'message' => $events->get_error_message() ] );
		}

		$available = (array) get_option( 'eex_available_events', [] );
		$list      = [];

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) || ! isset( $event['id'] ) ) {
				continue;
			}
			$list[] = [
				'id'    => (string) $event['id'],
				'title' => sanitize_text_field( (string) ( $event['title'] ?? $event['id'] ) ),
			];
		}

		$available[ $connection['id'] ] = $list;
		update_option( 'eex_available_events', $available, false );

		wp_send_json_success( [ 'events' => $list ] );
	}

	/**
	 * Fetch and cache the categories of one event, for the category filter UI.
	 */
	public function load_categories(): void {
		$this->guard();
		$connection = $this->requested_connection();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$event_id = isset( $_POST['event'] ) ? sanitize_text_field( wp_unslash( $_POST['event'] ) ) : '';

		if ( '' === $event_id ) {
			wp_send_json_error( [ 'message' => __( 'Missing event ID.', 'emailexpert-events' ) ], 400 );
		}

		$client     = HeySummitClient::for_connection( $connection );
		$categories = $client->get_all( 'categories/', [ 'event' => $event_id ] );

		if ( is_wp_error( $categories ) ) {
			wp_send_json_error( [ 'message' => $categories->get_error_message() ] );
		}

		$list = [];
		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['id'] ) ) {
				continue;
			}
			$list[] = [
				'id'    => (string) $category['id'],
				'title' => sanitize_text_field( (string) ( $category['title'] ?? $category['name'] ?? $category['id'] ) ),
			];
		}

		$available = (array) get_option( 'eex_available_categories', [] );

		$available[ $connection['id'] . '|' . $event_id ] = $list;
		update_option( 'eex_available_categories', $available, false );

		wp_send_json_success( [ 'categories' => $list ] );
	}

	/**
	 * Kick off an immediate sync via an async event, not inline.
	 */
	public function sync_now(): void {
		$this->guard();

		Scheduler::dispatch_async_run( true );

		wp_send_json_success( [ 'message' => __( 'Sync queued. Watch the sync log for progress.', 'emailexpert-events' ) ] );
	}

	/**
	 * Regenerate the webhook secret.
	 */
	public function regenerate_secret(): void {
		$this->guard();

		$secret = wp_generate_password( 40, false, false );
		update_option( Options::SECRET, $secret, false );

		wp_send_json_success(
			[
				'message' => __( 'Webhook secret regenerated. Update the URL in HeySummit.', 'emailexpert-events' ),
				'url'     => rest_url( 'eex/v1/heysummit/' . $secret ),
			]
		);
	}
}
