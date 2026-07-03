<?php
/**
 * Component data repository contract.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

defined( 'ABSPATH' ) || exit;

/**
 * Everything the display components know about events, sessions, speakers,
 * categories and sponsors comes through this interface, so the render
 * callbacks are a single code path over two sources: the synced local
 * database (Full mode) or the HeySummit API via the live cache (Lite mode).
 *
 * All methods return plain data arrays:
 *
 * Talk: id (post ID in Full, HeySummit ID in Lite), hs_id, title, permalink
 * (the card link target: local single in Full, HeySummit URL in Lite),
 * description, starts_at, ends_at (ISO 8601 UTC), talk_url, replay_url,
 * event_url (UTM-tagged), raw_event_url (untagged, for calendar entries),
 * event_hs_id, event_post_id (0 in Lite), timezone, ics_ref,
 * speakers (list of { id, name, url }), categories (list of objects with
 * slug and name properties).
 *
 * Event: id (post ID, 0 in Lite), hs_id, title, url (card link target),
 * event_url (UTM-tagged HeySummit URL), first_talk_at, last_talk_at,
 * timezone, open (bool), evergreen (bool), venue, reg_count,
 * series (list of objects with slug and name).
 *
 * Speaker: id, name, url (card link target), headline, company,
 * photo_id (attachment, Full), photo_url (remote, Lite).
 *
 * Sponsor: id, name, url, logo_id (attachment, Full), logo_url (remote,
 * Lite), blurb, tier_name, tier_order.
 *
 * Category: slug, name, url.
 */
interface Repository {

	/**
	 * Upcoming talks, soonest first.
	 *
	 * @param array<string,mixed> $atts event, category, limit, offset, q, ids.
	 * @return array<int,array<string,mixed>> Talk data arrays.
	 */
	public function upcoming_talks( array $atts ): array;

	/**
	 * Past talks, newest first.
	 *
	 * @param array<string,mixed> $atts event, category, limit, offset, q.
	 * @return array<int,array<string,mixed>> Talk data arrays.
	 */
	public function past_talks( array $atts ): array;

	/**
	 * Count of past talks matching the attributes (for pagination).
	 *
	 * @param array<string,mixed> $atts event, category, q.
	 */
	public function past_talks_total( array $atts ): int;

	/**
	 * Upcoming events, soonest first, evergreen last.
	 *
	 * @param array<string,mixed> $atts limit, series.
	 * @return array<int,array<string,mixed>> Event data arrays.
	 */
	public function upcoming_events( array $atts ): array;

	/**
	 * Past events, newest first.
	 *
	 * @param array<string,mixed> $atts limit, series.
	 * @return array<int,array<string,mixed>> Event data arrays.
	 */
	public function past_events( array $atts ): array;

	/**
	 * One event summary by reference (HeySummit ID, post ID or slug; '' means
	 * the sole configured event when exactly one exists).
	 *
	 * @param string $ref Event reference.
	 * @return array<string,mixed>|null Event data array.
	 */
	public function event_summary( string $ref ): ?array;

	/**
	 * One talk by reference (HeySummit ID or post ID).
	 *
	 * @param string $ref Talk reference.
	 * @return array<string,mixed>|null Talk data array.
	 */
	public function talk( string $ref ): ?array;

	/**
	 * Speakers with at least one talk matching the filters, alphabetical.
	 *
	 * @param array<string,mixed> $atts event, category, limit.
	 * @return array<int,array<string,mixed>> Speaker data arrays.
	 */
	public function speakers( array $atts ): array;

	/**
	 * Categories relevant to the filters.
	 *
	 * @param array<string,mixed> $atts event.
	 * @return array<int,array<string,mixed>> Category data arrays.
	 */
	public function categories( array $atts ): array;

	/**
	 * Sponsors, flat; the component groups by tier.
	 *
	 * @param array<string,mixed> $atts event.
	 * @return array<int,array<string,mixed>> Sponsor data arrays.
	 */
	public function sponsors( array $atts ): array;
}
