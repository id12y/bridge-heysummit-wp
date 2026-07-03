<?php
/**
 * Import scope filtering (categories + time).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which mapped talks are in scope for an event's configuration:
 * the category include/exclude filter plus the time scope (future all/none;
 * past all/none/most-recent-N/since-date). Scope is evaluated on every run,
 * so "most recent 20" is rolling. Out-of-scope talks are treated exactly
 * like category-excluded ones: not created, orphan-drafted if previously
 * synced. Shared by the sync engine and the wizard's dry run so preview
 * counts always match what an import creates.
 */
final class ScopeFilter {

	/**
	 * Apply category and time scope to a set of mapped talks.
	 *
	 * @param array<int,array<string,mixed>> $mapped_talks Mapped talks.
	 * @param array<string,mixed>            $config       Event config.
	 * @param int|null                       $now          Timestamp override for tests.
	 * @return array<int,array<string,mixed>> In-scope talks.
	 */
	public static function apply( array $mapped_talks, array $config, ?int $now = null ): array {
		$now = $now ?? time();

		$in_scope = array_values( array_filter( $mapped_talks, static fn( array $talk ): bool => self::passes_category( $talk, $config ) ) );

		$future_mode = (string) ( $config['future_mode'] ?? 'all' );
		$past_mode   = (string) ( $config['past_mode'] ?? 'all' );

		$future = [];
		$past   = [];

		foreach ( $in_scope as $talk ) {
			$start = strtotime( (string) ( $talk['starts_at'] ?? '' ) );

			// Untimed talks count as upcoming (docs/decisions.md D18).
			if ( false === $start || $start >= $now ) {
				$future[] = $talk;
			} else {
				$past[] = $talk;
			}
		}

		if ( 'none' === $future_mode ) {
			$future = [];
		}

		switch ( $past_mode ) {
			case 'none':
				$past = [];
				break;

			case 'recent':
				$n = max( 0, (int) ( $config['past_n'] ?? 0 ) );
				usort(
					$past,
					static fn( array $a, array $b ): int =>
						strtotime( (string) $b['starts_at'] ) <=> strtotime( (string) $a['starts_at'] )
				);
				$past = array_slice( $past, 0, $n );
				break;

			case 'since':
				$since = strtotime( (string) ( $config['past_since'] ?? '' ) );
				if ( false !== $since ) {
					$past = array_values(
						array_filter(
							$past,
							static fn( array $talk ): bool => strtotime( (string) $talk['starts_at'] ) >= $since
						)
					);
				}
				break;
		}

		return array_merge( $future, $past );
	}

	/**
	 * The per-event category include/exclude filter.
	 *
	 * @param array<string,mixed> $mapped Mapped talk.
	 * @param array<string,mixed> $config Event config.
	 */
	public static function passes_category( array $mapped, array $config ): bool {
		$mode   = (string) ( $config['cat_filter_mode'] ?? '' );
		$filter = array_map( 'strval', (array) ( $config['cat_filter'] ?? [] ) );

		if ( '' === $mode || empty( $filter ) ) {
			return true;
		}

		$talk_categories = array_map( 'strval', (array) ( $mapped['category_hs_ids'] ?? [] ) );
		$intersects      = ! empty( array_intersect( $talk_categories, $filter ) );

		return 'include' === $mode ? $intersects : ! $intersects;
	}
}
