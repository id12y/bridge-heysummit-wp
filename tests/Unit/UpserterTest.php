<?php
/**
 * Upsert idempotency, hash skipping and sync-mode protection.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Mappers\TalkMapper;
use Emailexpert\Events\Sync\Upserter;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Sync\Upserter
 */
final class UpserterTest extends TestCase {

	/**
	 * A mapped talk used across tests.
	 *
	 * @return array<string,mixed>
	 */
	private static function mapped_talk(): array {
		return TalkMapper::map(
			[
				'id'        => 9001,
				'title'     => 'Deliverability in 2026',
				'starts_at' => '2026-08-01T15:00:00Z',
				'event'     => 101,
			]
		);
	}

	public function test_same_payload_twice_produces_one_post_and_one_write(): void {
		$first = Upserter::upsert( 'eex_talk', self::mapped_talk(), [ 'connection_id' => 'c1' ] );
		$this->assertSame( 'created', $first['action'] );
		$writes_after_create = \EEX_Test_State::$post_write_count;

		$second = Upserter::upsert( 'eex_talk', self::mapped_talk(), [ 'connection_id' => 'c1' ] );

		$this->assertSame( 'skipped_hash', $second['action'] );
		$this->assertSame( $first['id'], $second['id'] );
		$this->assertSame( $writes_after_create, \EEX_Test_State::$post_write_count, 'second run must perform zero post writes' );
		$this->assertCount( 1, get_posts( [ 'post_type' => 'eex_talk', 'post_status' => 'any' ] ) );
	}

	public function test_force_ignores_hash(): void {
		Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );
		$result = Upserter::upsert( 'eex_talk', self::mapped_talk(), [ 'force' => true ] );

		$this->assertSame( 'updated', $result['action'] );
	}

	public function test_changed_payload_updates_and_restores_title(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );

		// Editor changes the title in WP.
		wp_update_post(
			[
				'ID'         => $created['id'],
				'post_title' => 'Editor changed this',
			]
		);

		// Re-sync with a changed payload restores the HeySummit title.
		$mapped          = self::mapped_talk();
		$mapped['title'] = 'Deliverability in 2026 (updated)';
		$result          = Upserter::upsert( 'eex_talk', $mapped, [] );

		$this->assertSame( 'updated', $result['action'] );
		$this->assertSame( 'Deliverability in 2026 (updated)', get_post( $created['id'] )->post_title );
	}

	public function test_manual_meta_survives_update(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );
		update_post_meta( $created['id'], '_eex_replay_url', 'https://manual.example/replay' );

		$mapped               = self::mapped_talk();
		$mapped['replay_url'] = 'https://synced.example/replay';
		Upserter::upsert( 'eex_talk', $mapped, [] );

		$this->assertSame( 'https://manual.example/replay', get_post_meta( $created['id'], '_eex_replay_url', true ) );
		$this->assertSame( 'https://synced.example/replay', get_post_meta( $created['id'], '_eex_replay_url_synced', true ) );
	}

	public function test_detached_post_is_never_written(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );
		update_post_meta( $created['id'], '_eex_sync_mode', 'detached' );
		wp_update_post(
			[
				'ID'         => $created['id'],
				'post_title' => 'Hand-edited title',
			]
		);
		$writes = \EEX_Test_State::$post_write_count;

		$mapped          = self::mapped_talk();
		$mapped['title'] = 'API title that must not land';
		$result          = Upserter::upsert( 'eex_talk', $mapped, [ 'force' => true ] );

		$this->assertSame( 'skipped_mode', $result['action'] );
		$this->assertSame( $writes, \EEX_Test_State::$post_write_count );
		$this->assertSame( 'Hand-edited title', get_post( $created['id'] )->post_title );
	}

	public function test_excluded_post_stays_draft_through_forced_syncs(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );
		update_post_meta( $created['id'], '_eex_sync_mode', 'excluded' );
		wp_update_post(
			[
				'ID'          => $created['id'],
				'post_status' => 'draft',
			]
		);

		for ( $i = 0; $i < 3; $i++ ) {
			$result = Upserter::upsert( 'eex_talk', self::mapped_talk(), [ 'force' => true ] );
			$this->assertSame( 'skipped_mode', $result['action'] );
		}

		$this->assertSame( 'draft', get_post_status( $created['id'] ) );
	}

	public function test_new_posts_respect_pending_import_status_and_updates_never_change_status(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [ 'import_status' => 'pending' ] );

		$this->assertSame( 'pending', get_post_status( $created['id'] ) );

		// Content update flows in without publishing the post.
		$mapped          = self::mapped_talk();
		$mapped['title'] = 'Changed';
		Upserter::upsert( 'eex_talk', $mapped, [ 'import_status' => 'pending' ] );
		$this->assertSame( 'pending', get_post_status( $created['id'] ) );

		// Editor approves; later syncs keep it published.
		wp_update_post(
			[
				'ID'          => $created['id'],
				'post_status' => 'publish',
			]
		);
		$mapped['title'] = 'Changed again';
		Upserter::upsert( 'eex_talk', $mapped, [ 'import_status' => 'pending' ] );
		$this->assertSame( 'publish', get_post_status( $created['id'] ) );
	}

	public function test_upsert_clears_orphan_flag(): void {
		$created = Upserter::upsert( 'eex_talk', self::mapped_talk(), [] );
		update_post_meta( $created['id'], '_eex_orphaned', 1 );

		$mapped          = self::mapped_talk();
		$mapped['title'] = 'Back again';
		Upserter::upsert( 'eex_talk', $mapped, [] );

		$this->assertSame( 0, get_post_meta( $created['id'], '_eex_orphaned', true ) );
	}

	public function test_speaker_email_stored_only_as_hash(): void {
		$result = Upserter::upsert(
			'eex_speaker',
			[
				'hs_id' => '501',
				'name'  => 'Jane Sender',
				'email' => 'jane@inbox.example',
				'links' => [],
			],
			[]
		);

		$all_meta = get_post_meta( $result['id'] );
		$this->assertStringNotContainsString( 'jane@inbox.example', json_encode( $all_meta ) ); // phpcs:ignore
		$this->assertSame( hash( 'sha256', 'jane@inbox.example' ), $all_meta['_eex_email_hash'] );
	}
}
