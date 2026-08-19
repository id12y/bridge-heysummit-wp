<?php
/**
 * Inline Event schema for the compositions (Lite mode).
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Frontend\Compositions;

use Emailexpert\Events\Frontend\SchemaGenerator;
use Emailexpert\Events\Options;

defined( 'ABSPATH' ) || exit;

/**
 * One inline Event JSON-LD block per composition, for the featured target
 * only, and only in Lite mode (Full-mode event pages carry their own schema
 * and the Yoast/Rank Math graph integration; duplicating it from a
 * composition would be the duplication the rules forbid). Postponed and
 * cancelled states map to their supported schema.org eventStatus values,
 * and offers are dropped whenever registration is not genuinely open.
 */
final class CompositionSchema {

	/**
	 * The inline schema script for a feature target, or ''.
	 *
	 * @param array<string,mixed> $target Feature target (event, session, lifecycle).
	 */
	public static function inline( array $target ): string {
		if ( ! Options::is_lite()
			|| ! (bool) Options::setting( 'schema_enabled' )
			|| ! (bool) Options::setting( 'schema_event' ) ) {
			return '';
		}

		$session = $target['session'] ?? null;

		$schema = null !== $session
			? SchemaGenerator::inline_event_from_talk( (array) $session )
			: SchemaGenerator::inline_event_from_event( (array) $target['event'] );

		if ( empty( $schema ) ) {
			return '';
		}

		$status = (string) ( $target['lifecycle']['status'] ?? '' );

		if ( 'cancelled' === $status ) {
			$schema['eventStatus'] = 'https://schema.org/EventCancelled';
			unset( $schema['offers'] );
		} elseif ( 'postponed' === $status ) {
			$schema['eventStatus'] = 'https://schema.org/EventPostponed';
			unset( $schema['offers'] );
		}

		if ( empty( $target['lifecycle']['registration_open'] ) ) {
			unset( $schema['offers'] );
		}

		return '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES ) . '</script>';
	}
}
