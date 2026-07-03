<?php
/**
 * Time-scoped imports: scope filter and its engine + dry-run integration.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Options;
use Emailexpert\Events\Sync\DryRun;
use Emailexpert\Events\Sync\ScopeFilter;
use Emailexpert\Events\Sync\SyncEngine;
use Emailexpert\Events\Sync\Upserter;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Sync\ScopeFilter
 * @covers \Emailexpert\Events\Sync\DryRun
 */
final class ScopeFilterTest extends TestCase {

	private const NOW = 1_800_000_000;

	/**
	 * A mapped talk at an offset from NOW.
	 */
	private static function talk( int $id, int $offset_days ): array {
		return [
			'hs_id'           => (string) $id,
			'title'           => 'Talk ' . $id,
			'starts_at'       => gmdate( 'Y-m-d\TH:i:s\Z', self::NOW + $offset_days * DAY_IN_SECONDS ),
			'category_hs_ids' => [],
		];
	}

	public function test_defaults_keep_everything(): void {
		$talks = [ self::talk( 1, -10 ), self::talk( 2, 5 ) ];

		$this->assertCount( 2, ScopeFilter::apply( $talks, [], self::NOW ) );
	}

	public function test_future_none_drops_upcoming_only(): void {
		$talks = [ self::talk( 1, -10 ), self::talk( 2, 5 ), self::talk( 3, 10 ) ];

		$kept = ScopeFilter::apply( $talks, [ 'future_mode' => 'none' ], self::NOW );

		$this->assertSame( [ '1' ], array_column( $kept, 'hs_id' ) );
	}

	public function test_past_none_drops_past_only(): void {
		$talks = [ self::talk( 1, -10 ), self::talk( 2, 5 ) ];

		$kept = ScopeFilter::apply( $talks, [ 'past_mode' => 'none' ], self::NOW );

		$this->assertSame( [ '2' ], array_column( $kept, 'hs_id' ) );
	}

	public function test_most_recent_n_keeps_exactly_n_newest_past(): void {
		$talks = [ self::talk( 1, -40 ), self::talk( 2, -30 ), self::talk( 3, -20 ), self::talk( 4, -10 ), self::talk( 5, -5 ), self::talk( 6, 3 ) ];

		$kept = ScopeFilter::apply(
			$talks,
			[
				'past_mode' => 'recent',
				'past_n'    => 5,
			],
			self::NOW
		);
		$this->assertCount( 6, $kept, 'five past plus the future one' );

		$kept = ScopeFilter::apply(
			$talks,
			[
				'past_mode' => 'recent',
				'past_n'    => 2,
			],
			self::NOW
		);

		$past_kept = array_filter( $kept, static fn( array $t ): bool => strtotime( $t['starts_at'] ) < self::NOW );
		$this->assertCount( 2, $past_kept, 'exactly N past sessions' );
		$this->assertSame( [ '5', '4' ], array_values( array_column( $past_kept, 'hs_id' ) ), 'the newest N' );
	}

	public function test_rolling_recent_window_shifts_with_time(): void {
		$talks = [ self::talk( 1, -30 ), self::talk( 2, -20 ), self::talk( 3, -10 ) ];
		$config = [
			'past_mode' => 'recent',
			'past_n'    => 2,
		];

		$kept_now = ScopeFilter::apply( $talks, $config, self::NOW );
		$this->assertSame( [ '3', '2' ], array_column( $kept_now, 'hs_id' ) );

		// A month later, with a newer talk in the collection, the window moves.
		$talks[]    = self::talk( 4, 1 ); // Was future, now past after 31 days.
		$kept_later = ScopeFilter::apply( $talks, $config, self::NOW + 31 * DAY_IN_SECONDS );
		$this->assertSame( [ '4', '3' ], array_column( $kept_later, 'hs_id' ), 'rolling: the most recent 2 stays the most recent 2' );
	}

	public function test_since_date_bounds_past(): void {
		$talks = [ self::talk( 1, -40 ), self::talk( 2, -10 ), self::talk( 3, 5 ) ];

		$kept = ScopeFilter::apply(
			$talks,
			[
				'past_mode'  => 'since',
				'past_since' => gmdate( 'Y-m-d', self::NOW - 20 * DAY_IN_SECONDS ),
			],
			self::NOW
		);

		$this->assertSame( [ '3', '2' ], array_column( $kept, 'hs_id' ) );
	}

	public function test_untimed_talks_count_as_upcoming(): void {
		$talks = [
			[
				'hs_id'           => '9',
				'title'           => 'Untimed',
				'starts_at'       => '',
				'category_hs_ids' => [],
			],
		];

		$this->assertCount( 1, ScopeFilter::apply( $talks, [ 'past_mode' => 'none' ], self::NOW ) );
		$this->assertCount( 0, ScopeFilter::apply( $talks, [ 'future_mode' => 'none' ], self::NOW ) );
	}

	public function test_category_filter_composes_with_time_scope(): void {
		$talk_a                    = self::talk( 1, -5 );
		$talk_a['category_hs_ids'] = [ '31' ];
		$talk_b                    = self::talk( 2, -3 );
		$talk_b['category_hs_ids'] = [ '32' ];

		$kept = ScopeFilter::apply(
			[ $talk_a, $talk_b ],
			[
				'cat_filter_mode' => 'exclude',
				'cat_filter'      => [ '32' ],
				'past_mode'       => 'recent',
				'past_n'          => 5,
			],
			self::NOW
		);

		$this->assertSame( [ '1' ], array_column( $kept, 'hs_id' ) );
	}

	/**
	 * Acceptance v2 #3: dry-run counts exactly match what the confirmed
	 * import creates; "most recent 5" imports exactly 5; reducing the scope
	 * later orphan-drafts the surplus.
	 */
	public function test_dry_run_counts_match_sync_and_scope_reduction_orphans_surplus(): void {
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$talks = [];
		for ( $i = 1; $i <= 8; $i++ ) {
			$talks[] = [
				'id'        => 9000 + $i,
				'title'     => 'Past ' . $i,
				'event'     => 101,
				'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() - $i * DAY_IN_SECONDS ),
			];
		}
		$talks[] = [
			'id'        => 9100,
			'title'     => 'Future one',
			'event'     => 101,
			'starts_at' => gmdate( 'Y-m-d\TH:i:s\Z', time() + DAY_IN_SECONDS ),
		];

		$this->mock_http( function ( $url ) use ( $talks ) {
			if ( str_contains( $url, 'events/101/' ) ) {
				return self::json_response( [ 'id' => 101, 'title' => 'Hub' ] );
			}
			if ( str_contains( $url, 'talks/' ) ) {
				return self::json_response( [ 'results' => $talks ] );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$config = [
			'enabled'   => 1,
			'talks'     => 1,
			'speakers'  => 0,
			'past_mode' => 'recent',
			'past_n'    => 5,
		];

		// Dry run first.
		$connection = Options::connection( 'c1' );
		$counts     = DryRun::preview( $connection, '101', $config );
		$this->assertSame( 6, $counts['sessions'] );
		$this->assertSame( 5, $counts['past'], 'most recent 5 previews exactly 5' );
		$this->assertSame( 1, $counts['upcoming'] );

		// Confirmed import creates exactly the previewed number.
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => $config ] );
		( new SyncEngine() )->sync_event( 'c1', '101' );

		$created = get_posts(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
			]
		);
		$this->assertCount( $counts['sessions'], $created, 'dry-run count matches the import' );

		// Reducing the scope orphan-drafts the surplus on the next run.
		$config['past_n'] = 2;
		update_option( Options::SYNCED_EVENTS, [ 'c1|101' => $config ] );
		( new SyncEngine() )->sync_event( 'c1', '101' );

		$published = get_posts(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
			]
		);
		$this->assertCount( 3, $published, '2 past + 1 future' );

		$surplus_id = Upserter::find_by_hs_id( 'eex_talk', '9003' );
		$this->assertSame( 'draft', get_post_status( $surplus_id ) );
		$this->assertSame( 1, get_post_meta( $surplus_id, '_eex_orphaned', true ) );
	}
}
