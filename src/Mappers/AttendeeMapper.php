<?php
/**
 * Attendee resource mapper.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Mappers;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a raw HeySummit attendee record (from the API or a webhook payload)
 * to the plugin's normalised shape.
 */
final class AttendeeMapper extends BaseMapper {

	/**
	 * Map one raw record.
	 *
	 * @param array<string,mixed> $raw Raw API record.
	 * @return array<string,mixed>|null Null when nothing identifies the attendee.
	 */
	public static function map( array $raw ): ?array {
		$hs_id = self::id_of( $raw, [ 'id', 'attendee_id' ] );
		$email = strtolower( self::str( $raw, [ 'email', 'email_address' ] ) );

		if ( '' === $hs_id && '' === $email ) {
			return null;
		}

		$ticket_name  = '';
		$amount_gross = '';
		if ( isset( $raw['tickets'] ) && is_array( $raw['tickets'] ) && isset( $raw['tickets'][0] ) && is_array( $raw['tickets'][0] ) ) {
			$ticket       = $raw['tickets'][0];
			$ticket_name  = self::str( $ticket, [ 'title', 'name', 'ticket_name' ] );
			$amount_gross = self::str( $ticket, [ 'amount_gross', 'amount', 'price', 'total' ] );
		}
		if ( '' === $ticket_name ) {
			$ticket_name = self::str( $raw, [ 'ticket_name', 'ticket' ] );
		}
		if ( '' === $amount_gross ) {
			$amount_gross = self::str( $raw, [ 'amount_gross', 'amount' ] );
		}

		$referer_domain = '';
		$referer        = self::str( $raw, [ 'http_referer', 'http_referer_domain', 'referrer', 'http_referrer', 'referer', 'referer_ref' ] );
		if ( '' !== $referer ) {
			$referer_domain = strtolower( (string) wp_parse_url( $referer, PHP_URL_HOST ) );
		}

		return [
			'hs_id'               => $hs_id,
			'email'               => $email,
			'email_hash'          => '' !== $email ? hash( 'sha256', $email ) : '',
			'name'                => self::str( $raw, [ 'name', 'full_name' ] ),
			'registration_status' => self::str( $raw, [ 'registration_status', 'status' ] ),
			'event_hs_id'         => self::id_of( $raw, [ 'event_id', 'event' ] ),
			'created_at'          => self::datetime( $raw, [ 'created_at', 'registered_at' ] ),
			'utm_source'          => self::str( $raw, [ 'utm_source' ] ),
			'utm_medium'          => self::str( $raw, [ 'utm_medium' ] ),
			'utm_campaign'        => self::str( $raw, [ 'utm_campaign' ] ),
			'referer_domain'      => $referer_domain,
			'affiliate_email'     => strtolower( self::str( $raw, [ 'affiliate_email' ] ) ),
			'ticket_name'         => $ticket_name,
			'amount_gross'        => $amount_gross,
			'talk_hs_ids'         => self::id_list( $raw['talks'] ?? null ),
		];
	}
}
