<?php
/**
 * Canonical event identity.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Selection;

defined( 'ABSPATH' ) || exit;

/**
 * One stable identity per event, used wherever the compositions deduplicate
 * (the featured event must never reappear in More Events) or store per-event
 * presentation settings. Never an array position or an offset.
 *
 * Preferred form: connection ID + HeySummit event ID — stable across both
 * repositories and across syncs. Where one side is unavailable the fallbacks
 * stay deterministic: a bare HeySummit ID (single-connection sites predating
 * connection IDs), then the local post ID (a manual event post that was never
 * synced), then the title as a last resort so two genuinely different events
 * can still be told apart.
 */
final class EventIdentity {

	/**
	 * The canonical identity of an event data array.
	 *
	 * @param array<string,mixed> $event Event data (see Data\Repository).
	 */
	public static function of( array $event ): string {
		$hs_id = (string) ( $event['hs_id'] ?? '' );
		$conn  = (string) ( $event['connection'] ?? '' );

		if ( '' !== $hs_id ) {
			return '' !== $conn ? $conn . '|' . $hs_id : 'hs|' . $hs_id;
		}

		$post_id = (int) ( $event['id'] ?? 0 );

		if ( $post_id > 0 ) {
			return 'post|' . $post_id;
		}

		return 'title|' . sanitize_title( (string) ( $event['title'] ?? '' ) );
	}

	/**
	 * Whether two event data arrays are the same event.
	 *
	 * A bare-HeySummit-ID identity matches a connection-scoped one carrying
	 * the same event ID: the two forms describe the same record when a site
	 * gains connection IDs between saves.
	 *
	 * @param array<string,mixed> $a One event.
	 * @param array<string,mixed> $b Another event.
	 */
	public static function same( array $a, array $b ): bool {
		$id_a = self::of( $a );
		$id_b = self::of( $b );

		if ( $id_a === $id_b ) {
			return true;
		}

		$hs_a = (string) ( $a['hs_id'] ?? '' );
		$hs_b = (string) ( $b['hs_id'] ?? '' );

		return '' !== $hs_a && $hs_a === $hs_b;
	}
}
