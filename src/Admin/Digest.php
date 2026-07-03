<?php
/**
 * Weekly digest email.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Admin;

use Emailexpert\Events\Frontend\Components;
use Emailexpert\Events\Frontend\Query;
use Emailexpert\Events\Install\Tables;
use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Optional (off by default) Monday email to the admin: new registrations by
 * source, sessions published, upcoming sessions, sync health. Plain text,
 * one query pass, unsubscribable via the settings toggle.
 */
final class Digest {

	/**
	 * Hook up.
	 */
	public function register(): void {
		add_action( 'eex_weekly_digest', [ $this, 'send' ] );
	}

	/**
	 * Schedule or unschedule to match the toggle (called on settings save).
	 */
	public static function sync_schedule_state(): void {
		$scheduled = wp_next_scheduled( 'eex_weekly_digest' );

		if ( ! (bool) Options::setting( 'digest_enabled' ) ) {
			if ( $scheduled ) {
				wp_clear_scheduled_hook( 'eex_weekly_digest' );
			}

			return;
		}

		if ( ! $scheduled ) {
			$next_monday = strtotime( 'next monday 08:00 UTC' );
			wp_schedule_event( $next_monday ?: time() + WEEK_IN_SECONDS, 'weekly', 'eex_weekly_digest' );
		}
	}

	/**
	 * Compose and send the digest.
	 */
	public function send(): void {
		if ( ! (bool) Options::setting( 'digest_enabled' ) ) {
			return;
		}

		wp_mail(
			get_bloginfo( 'admin_email' ),
			sprintf(
				/* translators: %s: site name. */
				__( '%s: your weekly events digest', 'emailexpert-events' ),
				get_bloginfo( 'name' )
			),
			self::compose()
		);
	}

	/**
	 * The plain-text body.
	 */
	public static function compose(): string {
		$lines   = [];
		$lines[] = __( 'Your weekly emailexpert Events digest.', 'emailexpert-events' );
		$lines[] = '';

		// Registrations by source, one query pass.
		$lines[]   = strtoupper( __( 'Registrations, last 7 days', 'emailexpert-events' ) );
		$by_source = [];
		if ( Tables::exists( 'attribution' ) ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, weekly email.
			$rows = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}eex_attribution WHERE status = 'completed' AND created_at >= %s",
					gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS )
				),
				ARRAY_A
			);
			foreach ( $rows as $row ) {
				$source               = (string) ( $row['utm_source'] ?: '(none)' );
				$by_source[ $source ] = ( $by_source[ $source ] ?? 0 ) + 1;
			}
			arsort( $by_source );
		}
		if ( empty( $by_source ) ) {
			$lines[] = __( 'None recorded.', 'emailexpert-events' );
		} else {
			$lines[] = sprintf( /* translators: %d: total registrations. */ __( 'Total: %d', 'emailexpert-events' ), array_sum( $by_source ) );
			foreach ( $by_source as $source => $count ) {
				$lines[] = sprintf( '- %s: %d', $source, $count );
			}
		}
		$lines[] = '';

		// Sessions published in the last 7 days.
		$week_ago  = time() - 7 * DAY_IN_SECONDS;
		$published = 0;
		foreach ( get_posts(
			[
				'post_type'      => 'eex_talk',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			]
		) as $post ) {
			$synced = strtotime( (string) get_post_meta( (int) $post->ID, '_eex_last_synced', true ) );
			$since  = strtotime( (string) ( $post->post_date_gmt ?? '' ) );
			if ( ( false !== $synced && $synced >= $week_ago ) || ( false !== $since && $since >= $week_ago ) ) {
				++$published;
			}
		}
		$lines[] = strtoupper( __( 'Sessions', 'emailexpert-events' ) );
		$lines[] = sprintf( /* translators: %d: count. */ __( 'Published or updated this week: %d', 'emailexpert-events' ), $published );

		// Upcoming sessions.
		$upcoming = Query::upcoming_talks( [ 'limit' => 5 ] );
		if ( ! empty( $upcoming ) ) {
			$lines[] = '';
			$lines[] = strtoupper( __( 'Upcoming sessions', 'emailexpert-events' ) );
			foreach ( $upcoming as $talk_id ) {
				$data    = Components::talk_data( $talk_id );
				$lines[] = sprintf( '- %s (%s)', (string) $data['title'], (string) $data['starts_at'] );
			}
		}

		// Sync health.
		$status  = Health::status();
		$lines[] = '';
		$lines[] = strtoupper( __( 'Sync health', 'emailexpert-events' ) );
		$lines[] = empty( $status['failures'] )
			? __( 'Healthy: no failing connections.', 'emailexpert-events' )
			: sprintf( /* translators: %d: failing connection count. */ __( 'ATTENTION: %d connection(s) failing. Check the sync log.', 'emailexpert-events' ), count( $status['failures'] ) );

		$lines[] = '';
		$lines[] = sprintf(
			/* translators: %s: settings URL. */
			__( 'Turn this digest off in Settings → emailexpert Events. %s', 'emailexpert-events' ),
			admin_url( 'options-general.php?page=emailexpert-events' )
		);

		return implode( "\n", $lines );
	}
}
