<?php
/**
 * Regression proofs for the adversarial-review findings.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Admin\ExportImport;
use Emailexpert\Events\Hub\Crm;
use Emailexpert\Events\Hub\Journal;
use Emailexpert\Events\Hub\Projection;
use Emailexpert\Events\Hub\Registry;
use Emailexpert\Events\Hub\RestController;
use Emailexpert\Events\Hub\Sweeper;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\HubTestCase;
use WP_REST_Request;

/**
 * Each test pins one confirmed review finding: suppression delegation to
 * the CRM's own checks (two distinct hash schemes), live subscription
 * state, watermark progress under truncation, journal-first ordering,
 * enrolment retry, the commit-grace window, ticket fingerprinting,
 * webhook timestamp/status handling, and the import allowlist.
 *
 * @covers \Emailexpert\Events\Hub\Crm
 * @covers \Emailexpert\Events\Hub\Sweeper
 * @covers \Emailexpert\Events\Hub\Journal
 */
final class HubReviewFixesTest extends HubTestCase {

	public function test_v2_suppression_matches_via_the_crm_service_hash(): void {
		global $wpdb;

		$service = new \EEN_Suppression_Service();
		$wpdb->insert(
			'wp_een_suppressions',
			[
				'email_hash' => $service->hash_email( 'complained@example.org' ),
				'scope_type' => 'global',
				'reason'     => 'complaint',
			]
		);

		$id = eex_hub_seed_subscriber( [ 'email' => 'complained@example.org' ] );

		$this->assertSame( 'suppressed', Projection::facts( new Crm(), $id )['state'], 'the service-side hash scheme is honoured' );
	}

	public function test_sub_threshold_soft_bounces_and_expired_suppressions_do_not_suppress(): void {
		global $wpdb;

		// One soft bounce: a tracking row the CRM still mails through.
		$wpdb->insert(
			'wp_een_suppression_list',
			[
				'email'             => 'softbounce@example.org',
				'reason'            => 'soft_bounce',
				'soft_bounce_count' => 1,
			]
		);
		$soft = eex_hub_seed_subscriber( [ 'email' => 'softbounce@example.org' ] );
		$this->assertSame( 'active', Projection::facts( new Crm(), $soft )['state'] );

		// An expired temporary v2 suppression no longer suppresses.
		$service = new \EEN_Suppression_Service();
		$wpdb->insert(
			'wp_een_suppressions',
			[
				'email_hash' => $service->hash_email( 'expired@example.org' ),
				'scope_type' => 'global',
				'reason'     => 'soft_bounce_ttl',
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			]
		);
		$expired = eex_hub_seed_subscriber( [ 'email' => 'expired@example.org' ] );
		$this->assertSame( 'active', Projection::facts( new Crm(), $expired )['state'] );
	}

	public function test_email_news_state_follows_the_live_subscriptions_table(): void {
		global $wpdb;
		$crm = new Crm();

		// Snoozed into the future: not subscribed right now.
		$snoozed = eex_hub_seed_subscriber( [ 'email' => 'snoozed@example.org' ] );
		foreach ( $wpdb->tables['wp_een_subscriptions'] as $i => $row ) {
			if ( (int) $row['subscriber_id'] === $snoozed ) {
				$wpdb->tables['wp_een_subscriptions'][ $i ]['snoozed_until'] = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			}
		}
		$this->assertSame( 'not_subscribed', Projection::facts( $crm, $snoozed )['email_news_state'] );

		// Subscription flipped inactive: the legacy frequency column no
		// longer decides.
		$inactive = eex_hub_seed_subscriber( [ 'email' => 'inactive@example.org' ] );
		foreach ( $wpdb->tables['wp_een_subscriptions'] as $i => $row ) {
			if ( (int) $row['subscriber_id'] === $inactive ) {
				$wpdb->tables['wp_een_subscriptions'][ $i ]['status'] = 'inactive';
			}
		}
		$this->assertSame( 'not_subscribed', Projection::facts( $crm, $inactive )['email_news_state'] );

		// Active and unsnoozed: subscribed.
		$live = eex_hub_seed_subscriber( [ 'email' => 'live@example.org' ] );
		$this->assertSame( 'subscribed', Projection::facts( $crm, $live )['email_news_state'] );
	}

	public function test_watermarks_advance_even_when_every_batch_is_truncated(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			eex_hub_seed_subscriber(
				[
					'email'      => 'wm' . $i . '@example.org',
					'updated_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS + $i ),
				]
			);
		}

		$sweeper = new Sweeper();
		$sweeper->run( 10, 2, 0 ); // Update budget far below the backlog.

		$first = (array) get_option( 'eex_hub_sweep_state', [] );
		$this->assertNotSame( '1970-01-01 00:00:00', (string) ( $first['watermarks']['subscribers'] ?? '1970-01-01 00:00:00' ), 'a truncated batch still moves the watermark' );

		$sweeper->run( 10, 2, 0 );
		$second = (array) get_option( 'eex_hub_sweep_state', [] );
		$this->assertGreaterThanOrEqual(
			(string) $first['watermarks']['subscribers'],
			(string) $second['watermarks']['subscribers'],
			'progress is monotonic'
		);
	}

	public function test_a_transient_read_failure_during_enrolment_is_retried_not_dropped(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'flaky@example.org' ] );

		$GLOBALS['een_test_fail_find'][ $id ] = true;
		$this->sweep();

		$registry = new Registry();
		$this->assertNull( $registry->by_subscriber( $id ), 'not enrolled while unreadable' );

		unset( $GLOBALS['een_test_fail_find'][ $id ] );
		$this->sweep();

		$row = $registry->by_subscriber( $id );
		$this->assertNotNull( $row, 'the retry list re-enrols it once readable — the floor having moved past it does not matter' );
		$this->assertSame( 'active', $row['state'] );
	}

	public function test_the_journal_grace_window_withholds_fresh_rows(): void {
		eex_hub_seed_subscriber();
		$this->sweep();

		$journal = new Journal();
		$this->assertNotEmpty( $journal->page_after( 0, 10 ), 'grace 0 (test default): visible immediately' );

		remove_all_filters( 'eex_hub_journal_grace_seconds' );
		$this->assertSame( [], $journal->page_after( 0, 10 ), 'default grace: rows younger than the window are withheld so a cursor cannot pass an uncommitted seq' );
	}

	public function test_a_ticket_allocation_change_journals_an_upsert(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'attendee@example.org' ] );
		$this->sweep();

		$head = ( new Journal() )->head();

		eex_hub_seed_custom_field(
			$id,
			'_tt_allocated_tickets',
			(string) wp_json_encode(
				[
					[
						'order_id'    => 'or_1',
						'ticket_id'   => 'it_1',
						'ticket_type' => 'GA',
						'box_office'  => '',
						'status'      => 'completed',
						'created_at'  => '1714567890',
					],
				]
			)
		);
		$this->sweep();

		$this->assertGreaterThan( $head, ( new Journal() )->head(), 'ticket changes surface in /hub/changes as contact upserts' );
	}

	public function test_webhook_style_entries_get_sane_timestamps_states_and_scopes(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'webhook@example.org' ] );
		eex_hub_seed_custom_field(
			$id,
			'_tt_allocated_tickets',
			(string) wp_json_encode(
				[
					[
						'order_id'    => 'or_77',
						'ticket_id'   => 'it_9',
						'ticket_type' => 'GA',
						'box_office'  => '', // The CRM webhook path records none.
						'status'      => 'completed', // TT ORDER status vocabulary.
						'created_at'  => '1714567890', // Raw epoch string.
					],
				]
			)
		);
		$this->sweep();

		$data   = ( new RestController() )->ticket_catalogue( new WP_REST_Request() )->get_data();
		$ticket = $data['tickets'][0];

		$this->assertSame( 'valid', $ticket['state'], 'order-status snapshots map into the state enum' );
		$this->assertSame( 'unrecorded', $ticket['box_office']['id'], 'missing box office is honest, not colliding under a fake scope' );
		$this->assertSame( '2024-05-01T12:51:30Z', $ticket['updated_at'], 'epoch-string timestamps convert correctly' );
	}

	public function test_the_buyer_tag_follows_the_crm_tag_rules_option(): void {
		$crm = new Crm();

		// Operator renamed the general tag in the CRM.
		update_option( 'een_tt_tag_rules', [ 'general_tag' => 'VIP Buyers' ] );
		$renamed = eex_hub_seed_subscriber( [ 'email' => 'vip@example.org' ] );
		eex_hub_seed_tag( $renamed, 'vip-buyers' );
		$this->assertTrue( Projection::ticket_facts( $crm, $renamed, $crm->tag_slugs( $renamed ) )['has_ticket'] );

		// Operator disabled the general tag: absence proves nothing.
		update_option( 'een_tt_tag_rules', [ 'general_tag' => '' ] );
		$unknown = eex_hub_seed_subscriber( [ 'email' => 'nobody@example.org' ] );
		$this->assertNull( Projection::ticket_facts( $crm, $unknown, $crm->tag_slugs( $unknown ) )['has_ticket'] );
	}

	public function test_a_settings_import_cannot_flip_the_hub_switch(): void {
		Options::update_settings( [ 'hub_enabled' => 0 ] );

		ExportImport::apply_snapshot(
			[
				'options' => [
					'eex_settings' => [
						'hub_enabled' => 1,
						'utm_medium'  => 'imported',
					],
				],
			]
		);

		$this->assertSame( 0, Options::setting( 'hub_enabled' ), 'the feature switch flips only via its explicit enable path' );
		$this->assertSame( 'imported', Options::setting( 'utm_medium' ), 'ordinary settings still import' );
	}

	public function test_a_tombstoned_row_cannot_be_resurrected_by_a_late_bump(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'race@example.org' ] );
		$this->sweep();

		$registry = new Registry();
		$row      = $registry->by_subscriber( $id );

		eex_hub_purge_subscriber( $id );
		$this->sweep(); // Tombstoned.

		$this->assertSame( 0, $registry->bump( $row['id'], 'stale-hash', $row['revision'] + 9 ), 'the guarded write refuses non-active rows' );
		$this->assertSame( 'deleted', $registry->by_uuid( $row['uuid'] )['state'] );
	}
}
