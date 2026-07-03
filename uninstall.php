<?php
/**
 * Uninstall routine.
 *
 * Removes options, custom tables and scheduled events. Synced content (the
 * CPT posts) is left in place unless the operator enabled "on uninstall,
 * delete all data" in Settings → emailexpert Events → Display.
 *
 * @package Emailexpert\Events
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$eex_settings   = (array) get_option( 'eex_settings', [] );
$eex_delete_all = ! empty( $eex_settings['uninstall_delete'] );

// Scheduled events — wp_unschedule_hook clears queued jobs regardless of
// their args (wp_clear_scheduled_hook without args would miss them).
$eex_cron_hooks = [
	'eex_sync_cron',
	'eex_daily_maintenance',
	'eex_weekly_digest',
	'eex_sync_continue',
	'eex_async_sync',
	'eex_process_webhook',
	'eex_abandonment_check',
	'eex_relay_deliver',
	'eex_woo_push',
	'eex_accounts_push',
	'eex_accounts_backfill',
];

foreach ( $eex_cron_hooks as $eex_cron_hook ) {
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		wp_unschedule_hook( $eex_cron_hook );
	} else {
		wp_clear_scheduled_hook( $eex_cron_hook );
	}
}

// Custom tables.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eex_log" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}eex_attribution" );
// phpcs:enable

// Options (including per-connection discovery reports).
$eex_option_names = $wpdb->get_col(
	$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'eex_' ) . '%' )
);

foreach ( (array) $eex_option_names as $eex_option_name ) {
	delete_option( (string) $eex_option_name );
}

// Transients created by component caching use the eex_ prefix and are
// covered by the LIKE delete above (both _transient_eex_* variants).
$eex_transient_names = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_eex_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_eex_' ) . '%'
	)
);

foreach ( (array) $eex_transient_names as $eex_transient_name ) {
	delete_option( (string) $eex_transient_name );
}

// Content: only when explicitly enabled.
if ( $eex_delete_all ) {
	$eex_post_ids = get_posts(
		[
			'post_type'      => [ 'eex_event', 'eex_talk', 'eex_speaker', 'eex_sponsor' ],
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);

	foreach ( $eex_post_ids as $eex_post_id ) {
		wp_delete_post( (int) $eex_post_id, true );
	}

	foreach ( [ 'eex_event_series', 'eex_category', 'eex_sponsor_tier' ] as $eex_taxonomy ) {
		$eex_terms = get_terms(
			[
				'taxonomy'   => $eex_taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			]
		);

		if ( is_array( $eex_terms ) ) {
			foreach ( $eex_terms as $eex_term_id ) {
				wp_delete_term( (int) $eex_term_id, $eex_taxonomy );
			}
		}
	}
}
