<?php
/**
 * Per-event presentation settings (promotion, status, media, CTA overrides).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Data;

use Emailexpert\Events\Frontend\Selection\EventIdentity;
use Emailexpert\Events\Options;
use Emailexpert\Events\Support\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * One mode-neutral accessor for the small set of deliberate, deterministic
 * fields that steer the Homepage Editorial Hero and the Event Landing Page:
 * eligibility flags, a promotion level with an optional window, a public
 * status override with its notice, and optional hero-media / intro / CTA
 * overrides. Selection and rendering read through here and never care
 * whether a value came from event post meta (Full) or the settings option
 * (Lite). Deliberately not a rules engine: these fields are enough.
 */
final class EventPresentation {

	/**
	 * Post meta key holding the presentation array in Full mode.
	 */
	public const META_KEY = '_eex_presentation';

	/**
	 * Settings key holding presentation rows in Lite mode, keyed by the
	 * configured "connection|event" pair.
	 */
	public const SETTING_KEY = 'lite_presentation';

	/**
	 * Promotion levels, in descending editorial priority.
	 */
	public const LEVELS = [ 'flagship', 'featured', 'normal', 'none' ];

	/**
	 * Public status overrides.
	 */
	public const STATUSES = [ 'auto', 'scheduled', 'postponed', 'cancelled' ];

	/**
	 * The neutral defaults: everything eligible, nothing overridden.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'feature_eligible' => 1,
			'more_eligible'    => 1,
			'level'            => 'normal',
			'promo_start'      => '',
			'promo_end'        => '',
			'status'           => 'auto',
			'status_message'   => '',
			'intro'            => '',
			'hero_media'       => '',
			'cta_label'        => '',
			'cta_url'          => '',
		];
	}

	/**
	 * The presentation values for one event data array, in either mode.
	 *
	 * @param array<string,mixed> $event Event data (see Data\Repository).
	 * @return array<string,mixed> Sanitised values merged over defaults.
	 */
	public static function for_event( array $event ): array {
		$post_id = (int) ( $event['id'] ?? 0 );

		if ( $post_id > 0 && ! Options::is_lite() ) {
			$stored = get_post_meta( $post_id, self::META_KEY, true );

			return self::sanitise( is_array( $stored ) ? $stored : [] );
		}

		$rows = (array) Options::setting( self::SETTING_KEY );
		$key  = (string) ( $event['connection'] ?? '' ) . '|' . (string) ( $event['hs_id'] ?? '' );

		$stored = $rows[ $key ] ?? null;

		return self::sanitise( is_array( $stored ) ? $stored : [] );
	}

	/**
	 * Save presentation values for a Full-mode event post and flush the
	 * display cache so the change shows immediately.
	 *
	 * @param int                 $post_id Event post ID.
	 * @param array<string,mixed> $values  Raw values.
	 */
	public static function save_full( int $post_id, array $values ): void {
		$clean = self::sanitise( $values );

		if ( self::defaults() === $clean ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, $clean );
		}

		\Emailexpert\Events\Frontend\Cache::flush();
	}

	/**
	 * Save presentation values for a Lite-mode configured event and flush
	 * the display cache.
	 *
	 * @param string              $key    Configured "connection|event" key.
	 * @param array<string,mixed> $values Raw values.
	 */
	public static function save_lite( string $key, array $values ): void {
		$rows  = array_filter( (array) Options::setting( self::SETTING_KEY ), 'is_array' );
		$clean = self::sanitise( $values );

		if ( self::defaults() === $clean ) {
			unset( $rows[ $key ] );
		} else {
			$rows[ $key ] = $clean;
		}

		// Bounded: rows only exist for configured events, and stale keys are
		// dropped whenever an event stops being configured.
		$configured = array_map( 'strval', (array) Options::setting( 'lite_events' ) );
		$rows       = array_intersect_key( $rows, array_flip( array_merge( $configured, [ $key ] ) ) );

		Options::update_settings( [ self::SETTING_KEY => $rows ] );

		\Emailexpert\Events\Frontend\Cache::flush();
	}

	/**
	 * Coerce raw values into the whitelisted shape.
	 *
	 * @param array<string,mixed> $values Raw values.
	 * @return array<string,mixed>
	 */
	public static function sanitise( array $values ): array {
		$defaults = self::defaults();
		$clean    = [];

		$clean['feature_eligible'] = isset( $values['feature_eligible'] ) ? (int) (bool) $values['feature_eligible'] : 1;
		$clean['more_eligible']    = isset( $values['more_eligible'] ) ? (int) (bool) $values['more_eligible'] : 1;

		$level          = (string) ( $values['level'] ?? 'normal' );
		$clean['level'] = in_array( $level, self::LEVELS, true ) ? $level : 'normal';

		$clean['promo_start'] = self::sanitise_datetime( (string) ( $values['promo_start'] ?? '' ) );
		$clean['promo_end']   = self::sanitise_datetime( (string) ( $values['promo_end'] ?? '' ) );

		$status          = (string) ( $values['status'] ?? 'auto' );
		$clean['status'] = in_array( $status, self::STATUSES, true ) ? $status : 'auto';

		$clean['status_message'] = sanitize_text_field( (string) ( $values['status_message'] ?? '' ) );
		$clean['intro']          = trim( wp_kses_post( (string) ( $values['intro'] ?? '' ) ) );

		// An attachment ID or an absolute URL; anything else is dropped.
		$media               = trim( (string) ( $values['hero_media'] ?? '' ) );
		$clean['hero_media'] = ( ctype_digit( $media ) || '' === $media ) ? $media : esc_url_raw( $media );

		$clean['cta_label'] = sanitize_text_field( (string) ( $values['cta_label'] ?? '' ) );
		$clean['cta_url']   = esc_url_raw( (string) ( $values['cta_url'] ?? '' ) );

		return array_merge( $defaults, $clean );
	}

	/**
	 * The effective promotion level at a moment: the stored level inside its
	 * window, 'normal' outside it. A missing boundary leaves that side open.
	 *
	 * @param array<string,mixed> $presentation Sanitised presentation values.
	 * @param int                 $now          Unix timestamp (0 = Clock::now()).
	 */
	public static function effective_level( array $presentation, int $now = 0 ): string {
		$level = (string) ( $presentation['level'] ?? 'normal' );

		if ( 'none' === $level ) {
			return 'none'; // "Do not promote" has no window: it always holds.
		}

		$now   = $now > 0 ? $now : Clock::now();
		$start = self::timestamp( (string) ( $presentation['promo_start'] ?? '' ) );
		$end   = self::timestamp( (string) ( $presentation['promo_end'] ?? '' ) );

		if ( ( $start > 0 && $now < $start ) || ( $end > 0 && $now >= $end ) ) {
			return 'normal';
		}

		return $level;
	}

	/**
	 * Promotion-window boundaries still ahead of a moment (cache TTLs).
	 *
	 * @param array<string,mixed> $presentation Sanitised presentation values.
	 * @param int                 $now          Unix timestamp (0 = Clock::now()).
	 * @return int[] Future Unix timestamps.
	 */
	public static function future_boundaries( array $presentation, int $now = 0 ): array {
		$now = $now > 0 ? $now : Clock::now();
		$out = [];

		foreach ( [ 'promo_start', 'promo_end' ] as $field ) {
			$ts = self::timestamp( (string) ( $presentation[ $field ] ?? '' ) );

			if ( $ts > $now ) {
				$out[] = $ts;
			}
		}

		return $out;
	}

	/**
	 * Parse a stored datetime to a Unix timestamp (0 = unset/unparseable).
	 *
	 * @param string $datetime Stored value.
	 */
	public static function timestamp( string $datetime ): int {
		if ( '' === $datetime ) {
			return 0;
		}

		$ts = strtotime( $datetime );

		return false === $ts ? 0 : $ts;
	}

	/**
	 * Normalise a submitted datetime: accepts datetime-local input
	 * ("2026-11-17T10:30") or anything strtotime can read; stored as UTC ISO
	 * 8601 so selection never parses display strings.
	 *
	 * Bare datetime-local values are interpreted in the site timezone — the
	 * operator types wall-clock time, and the site zone is the only wall
	 * clock the admin screen can honestly claim.
	 *
	 * @param string $raw Submitted value.
	 */
	public static function sanitise_datetime( string $raw ): string {
		$raw = trim( sanitize_text_field( $raw ) );

		if ( '' === $raw ) {
			return '';
		}

		// A trailing Z or explicit offset is already absolute.
		if ( preg_match( '/(Z|[+-]\d{2}:?\d{2})$/i', $raw ) ) {
			$ts = strtotime( $raw );

			return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
		}

		try {
			$local = new \DateTimeImmutable( $raw, wp_timezone() );
		} catch ( \Exception $e ) {
			unset( $e );

			return '';
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', $local->getTimestamp() );
	}
}
