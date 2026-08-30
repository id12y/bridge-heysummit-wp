<?php
/**
 * Contact identity: immutable UUIDs, revisions, tombstones, journal.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Hub\Cursor;
use Emailexpert\Events\Hub\Journal;
use Emailexpert\Events\Hub\Registry;
use Emailexpert\Events\Tests\HubTestCase;

/**
 * The registry mints one opaque UUID per contact — stable across email
 * and name changes, never the CRM's row id, never recycled — and flips
 * deletions to minimal tombstones the journal reports.
 *
 * @covers \Emailexpert\Events\Hub\Registry
 * @covers \Emailexpert\Events\Hub\Journal
 * @covers \Emailexpert\Events\Hub\Sweeper
 * @covers \Emailexpert\Events\Hub\Cursor
 */
final class HubIdentityTest extends HubTestCase {

	public function test_the_uuid_survives_an_email_and_name_change(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'old@example.org' ] );

		$this->sweep();

		$registry = new Registry();
		$before   = $registry->by_subscriber( $id );

		// The CRM row changes address and name entirely.
		global $wpdb;
		foreach ( $wpdb->tables['wp_een_subscribers'] as $i => $row ) {
			if ( (int) $row['id'] === $id ) {
				$wpdb->tables['wp_een_subscribers'][ $i ]['email']      = 'new@example.org';
				$wpdb->tables['wp_een_subscribers'][ $i ]['first_name'] = 'Renamed';
				$wpdb->tables['wp_een_subscribers'][ $i ]['updated_at'] = gmdate( 'Y-m-d H:i:s' );
			}
		}

		$this->sweep();

		$after = $registry->by_subscriber( $id );

		$this->assertSame( $before['uuid'], $after['uuid'], 'the uuid never changes with the email' );
		$this->assertGreaterThan( $before['revision'], $after['revision'], 'the change bumped the revision' );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $after['uuid'], 'an opaque uuid, not a row id' );
	}

	public function test_an_unchanged_contact_journals_nothing_twice(): void {
		eex_hub_seed_subscriber();

		$this->sweep();
		$head = ( new Journal() )->head();

		$this->sweep();
		$this->sweep();

		$this->assertSame( $head, ( new Journal() )->head(), 'idempotent sweeps append nothing' );
	}

	public function test_a_deleted_contact_becomes_a_minimal_tombstone(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'gone@example.org' ] );

		$this->sweep();
		$uuid = ( new Registry() )->by_subscriber( $id )['uuid'];

		eex_hub_purge_subscriber( $id );
		$this->sweep();

		$row = ( new Registry() )->by_uuid( $uuid );

		$this->assertSame( 'deleted', $row['state'] );
		$this->assertNull( $row['subscriber_id'], 'the CRM row id is cleared' );
		$this->assertSame( '', $row['projection_hash'] );
		$this->assertNotNull( $row['deleted_at'] );

		// Nothing personal survives in the raw table row.
		global $wpdb;
		$raw = null;
		foreach ( $wpdb->tables['wp_eex_hub_contacts'] as $stored ) {
			if ( $stored['uuid'] === $uuid ) {
				$raw = $stored;
			}
		}
		$this->assertStringNotContainsString( 'gone@example.org', (string) wp_json_encode( $raw ) );

		// The journal reports the deletion.
		$changes = ( new Journal() )->page_after( 0, 10 );
		$ops     = array_column( $changes, 'op' );
		$this->assertContains( 'delete', $ops );
	}

	public function test_the_purge_hook_captures_a_delete_without_waiting_for_the_sweep(): void {
		$id = eex_hub_seed_subscriber();

		$this->sweep();
		$uuid = ( new Registry() )->by_subscriber( $id )['uuid'];

		eex_hub_purge_subscriber( $id );
		\Emailexpert\Events\Hub\Sweeper::capture_delete( $id );

		$this->assertSame( 'deleted', ( new Registry() )->by_uuid( $uuid )['state'] );
	}

	public function test_journal_cursor_replay_is_deterministic_and_side_effect_free(): void {
		eex_hub_seed_subscriber( [ 'email' => 'a@example.org' ] );
		eex_hub_seed_subscriber( [ 'email' => 'b@example.org' ] );
		eex_hub_seed_subscriber( [ 'email' => 'c@example.org' ] );

		$this->sweep();

		$journal = new Journal();

		$first  = $journal->page_after( 0, 2 );
		$replay = $journal->page_after( 0, 2 );

		$this->assertSame( $first, $replay, 'the same cursor returns the same logical result' );
		$this->assertCount( 2, $first );

		$rest = $journal->page_after( $first[1]['seq'], 10 );
		$this->assertCount( 1, $rest );

		$seqs = array_merge( array_column( $first, 'seq' ), array_column( $rest, 'seq' ) );
		$this->assertSame( array_values( array_unique( $seqs ) ), $seqs, 'no duplicates across pages' );
	}

	public function test_tag_removal_is_caught_by_reconciliation_despite_leaving_no_timestamp(): void {
		$id = eex_hub_seed_subscriber();
		eex_hub_seed_tag( $id, 'community-opt-in' );

		$this->sweep();
		$registry = new Registry();
		$before   = $registry->by_subscriber( $id )['revision'];

		eex_hub_remove_tag( $id, 'community-opt-in' );
		$this->sweep();

		$this->assertGreaterThan( $before, $registry->by_subscriber( $id )['revision'], 'the reconcile pass saw the removal' );
	}

	public function test_cursors_are_opaque_signed_and_typed(): void {
		$cursor = Cursor::encode( 'changes', [ 'after' => 42 ] );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+$/', $cursor, 'opaque URL-safe encoding, no readable JSON' );
		$this->assertSame( [ 'after' => 42 ], Cursor::decode( $cursor, 'changes' ) );
		$this->assertNull( Cursor::decode( $cursor, 'contacts' ), 'a cursor never crosses endpoints' );
		$this->assertNull( Cursor::decode( substr( $cursor, 0, -2 ) . 'zz', 'changes' ), 'tampering invalidates it' );
		$this->assertNull( Cursor::decode( 'garbage', 'changes' ) );
	}
}
