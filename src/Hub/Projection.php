<?php
/**
 * The approved Hub contact projection.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Hub;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the ONLY view of a contact the Hub ever sees: a fixed, explicit
 * allowlist — no field selection, no raw records. Facts are tri-state
 * throughout: true/false mean the CRM answered; null means the fact is
 * unavailable or not applicable, never a silent default.
 *
 * Community facts come exclusively from operator-controlled markers in
 * the CRM (tag slugs and a custom field configured in Settings). A ticket
 * purchase NEVER implies community membership, opt-in or eligibility —
 * there is deliberately no inference path from ticket facts to community
 * facts.
 */
final class Projection {

	/**
	 * The CRM-derived facts for one contact — everything except the
	 * identity wrapper (uuid/revision) the registry adds. Null when the
	 * contact row is unreadable.
	 *
	 * @param Crm $crm           CRM adapter.
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<string,mixed>|null
	 */
	public static function facts( Crm $crm, int $subscriber_id ): ?array {
		$contact = $crm->contact( $subscriber_id );

		if ( null === $contact ) {
			return null;
		}

		$tags       = $crm->tag_slugs( $subscriber_id );
		$suppressed = 'unsubscribed' === $contact['status'] ? true : $crm->suppressed( $contact['email'] );

		$first   = trim( $contact['first_name'] );
		$last    = trim( $contact['last_name'] );
		$display = trim( $first . ' ' . $last );

		return [
			'email'            => '' !== $contact['email'] ? $contact['email'] : null,
			'first_name'       => '' !== $first ? $first : null,
			'last_name'        => '' !== $last ? $last : null,
			'display_name'     => '' !== $display ? $display : null,
			'state'            => true === $suppressed ? 'suppressed' : 'active',
			'email_news_state' => self::email_news_state( $contact, $crm->has_active_subscription( $subscriber_id ) ),
			'sources'          => self::sources( $crm, $subscriber_id ),
			'community'        => self::community( $crm, $subscriber_id, $tags ),
		];
	}

	/**
	 * A canonical hash of the facts, for change detection. Key-sorted and
	 * JSON-encoded so semantically identical projections always collide.
	 *
	 * @param array<string,mixed> $facts Projection facts.
	 */
	public static function hash( array $facts ): string {
		self::deep_ksort( $facts );

		return hash( 'sha256', (string) wp_json_encode( $facts ) );
	}

	/**
	 * The email-news preference as one explicit enum. The CRM's LIVE
	 * per-frequency subscription rows decide 'subscribed' when readable;
	 * the legacy frequency column is only the fallback (it is written at
	 * creation and not maintained by later preference changes).
	 *
	 * @param array<string,mixed> $contact Allowlisted contact columns.
	 * @param bool|null           $live    Live subscription membership, or
	 *                                     null when unreadable.
	 */
	public static function email_news_state( array $contact, ?bool $live = null ): string {
		switch ( $contact['status'] ) {
			case 'pending':
				return 'pending_confirmation';
			case 'unsubscribed':
				return 'unsubscribed';
			case 'contact':
				return 'not_subscribed';
			case 'confirmed':
				if ( true === $contact['news_digest_off'] ) {
					return 'not_subscribed';
				}

				if ( null !== $live ) {
					return $live ? 'subscribed' : 'not_subscribed';
				}

				return 'none' === $contact['frequency'] ? 'not_subscribed' : 'subscribed';
			default:
				return 'unknown';
		}
	}

	/**
	 * The community facts, read only from operator-set CRM markers.
	 *
	 * @param Crm                     $crm           CRM adapter.
	 * @param int                     $subscriber_id CRM subscriber id.
	 * @param array<int,string>|null  $tags          The contact's tag slugs, or null when unreadable.
	 * @return array<string,mixed>
	 */
	public static function community( Crm $crm, int $subscriber_id, ?array $tags ): array {
		$facts = [
			'eligible'        => self::tag_fact( $tags, (string) Settings::get( 'tag_eligible' ) ),
			'opt_in'          => self::tag_fact( $tags, (string) Settings::get( 'tag_opt_in' ) ),
			'invited'         => self::tag_fact( $tags, (string) Settings::get( 'tag_invited' ) ),
			'admitted'        => self::tag_fact( $tags, (string) Settings::get( 'tag_admitted' ) ),
			'membership_tier' => self::membership_tier( $crm, $subscriber_id ),
		];

		/**
		 * Lets the CRM (or site code) answer the community facts
		 * authoritatively once it grows first-class fields for them. Must
		 * return the same keys; values true/false/null (tier: ?string).
		 *
		 * @param array<string,mixed> $facts         Marker-derived facts.
		 * @param int                 $subscriber_id CRM subscriber id.
		 */
		$filtered = apply_filters( 'eex_hub_community_facts', $facts, $subscriber_id );

		return is_array( $filtered ) ? array_merge( $facts, array_intersect_key( $filtered, $facts ) ) : $facts;
	}

	/**
	 * The ticket facts for the eligibility endpoint. `ticket_currently_valid`
	 * is honestly null: the CRM stores status snapshots at receipt time and
	 * never reconciles cancellations or refunds, so "still valid" cannot be
	 * answered from durable data (capabilities reports this too).
	 *
	 * @param Crm                    $crm           CRM adapter.
	 * @param int                    $subscriber_id CRM subscriber id.
	 * @param array<int,string>|null $tags          The contact's tag slugs, or null when unreadable.
	 * @return array<string,mixed>
	 */
	public static function ticket_facts( Crm $crm, int $subscriber_id, ?array $tags ): array {
		$allocated = $crm->allocated_tickets( $subscriber_id );
		$buyer_tag = $crm->buyer_tag_slug();

		if ( [] !== $allocated ) {
			$has_ticket = true;
		} elseif ( null === $tags || null === $buyer_tag ) {
			// Tags unreadable, or the CRM's buyer tag is disabled — the
			// marker's absence proves nothing either way.
			$has_ticket = null;
		} else {
			$has_ticket = in_array( $buyer_tag, $tags, true );
		}

		return [
			'has_ticket'             => $has_ticket,
			'ticket_currently_valid' => null,
		];
	}

	/**
	 * The contact's provenance sources (multi-value, from the CRM's
	 * '_source' field). Empty array = none recorded.
	 *
	 * @param Crm $crm           CRM adapter.
	 * @param int $subscriber_id CRM subscriber id.
	 * @return array<int,string>
	 */
	public static function sources( Crm $crm, int $subscriber_id ): array {
		$row = $crm->custom_field_row( $subscriber_id, '_source' );

		if ( null === $row || '' === $row['value'] ) {
			return [];
		}

		$sources = array_filter( array_map( 'trim', explode( ',', $row['value'] ) ) );
		sort( $sources );

		return array_values( array_unique( $sources ) );
	}

	/**
	 * One tri-state tag-backed fact.
	 *
	 * @param array<int,string>|null $tags Tag slugs, or null when unreadable.
	 * @param string                 $slug Marker slug.
	 */
	private static function tag_fact( ?array $tags, string $slug ): ?bool {
		if ( null === $tags || '' === $slug ) {
			return null;
		}

		return in_array( $slug, $tags, true );
	}

	/**
	 * The membership tier from the configured CRM custom field.
	 *
	 * @param Crm $crm           CRM adapter.
	 * @param int $subscriber_id CRM subscriber id.
	 */
	private static function membership_tier( Crm $crm, int $subscriber_id ): ?string {
		$field = (string) Settings::get( 'tier_field' );

		if ( '' === $field ) {
			return null;
		}

		$row = $crm->custom_field_row( $subscriber_id, $field );

		if ( null === $row || '' === trim( $row['value'] ) ) {
			return null;
		}

		return trim( $row['value'] );
	}

	/**
	 * Recursive key sort for canonical hashing.
	 *
	 * @param array<string,mixed> $data Array to sort in place.
	 */
	private static function deep_ksort( array &$data ): void {
		ksort( $data );

		foreach ( $data as &$value ) {
			if ( is_array( $value ) && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
				self::deep_ksort( $value );
			}
		}
	}
}
