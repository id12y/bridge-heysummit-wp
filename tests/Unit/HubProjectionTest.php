<?php
/**
 * Projection semantics: enumerations and tri-state facts.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Hub\Crm;
use Emailexpert\Events\Hub\Projection;
use Emailexpert\Events\Tests\HubTestCase;

/**
 * false, null, unknown and not-applicable are distinct answers, and the
 * enumerations map the CRM's states exactly.
 *
 * @covers \Emailexpert\Events\Hub\Projection
 * @covers \Emailexpert\Events\Hub\Crm
 */
final class HubProjectionTest extends HubTestCase {

	public function test_email_news_state_enumeration(): void {
		$cases = [
			[ [ 'status' => 'pending' ], 'pending_confirmation' ],
			[ [ 'status' => 'unsubscribed' ], 'unsubscribed' ],
			[ [ 'status' => 'contact' ], 'not_subscribed' ],
			[
				[
					'status'    => 'confirmed',
					'frequency' => 'weekly',
				],
				'subscribed',
			],
			[
				[
					'status'    => 'confirmed',
					'frequency' => 'none',
				],
				'not_subscribed',
			],
			[
				[
					'status'          => 'confirmed',
					'news_digest_off' => 1,
				],
				'not_subscribed',
			],
			[ [ 'status' => 'something-new' ], 'unknown' ],
		];

		$crm = new Crm();

		foreach ( $cases as $i => [ $overrides, $expected ] ) {
			$id    = eex_hub_seed_subscriber( array_merge( [ 'email' => 'case' . $i . '@example.org' ], $overrides ) );
			$facts = Projection::facts( $crm, $id );

			$this->assertSame( $expected, $facts['email_news_state'], 'case ' . $i );
		}
	}

	public function test_an_unsubscribed_or_suppressed_contact_reads_suppressed(): void {
		$crm = new Crm();

		$unsub = eex_hub_seed_subscriber(
			[
				'email'  => 'unsub@example.org',
				'status' => 'unsubscribed',
			]
		);
		$this->assertSame( 'suppressed', Projection::facts( $crm, $unsub )['state'] );

		// A confirmed contact on the CRM suppression list is suppressed too.
		global $wpdb;
		$wpdb->insert( 'wp_een_suppression_list', [ 'email' => 'listed@example.org' ] );

		$listed = eex_hub_seed_subscriber( [ 'email' => 'listed@example.org' ] );
		$this->assertSame( 'suppressed', Projection::facts( $crm, $listed )['state'] );

		$clean = eex_hub_seed_subscriber( [ 'email' => 'clean@example.org' ] );
		$this->assertSame( 'active', Projection::facts( $crm, $clean )['state'] );
	}

	public function test_community_facts_come_from_markers_and_a_site_filter_can_override(): void {
		$crm = new Crm();

		$id = eex_hub_seed_subscriber( [ 'email' => 'markers@example.org' ] );
		eex_hub_seed_tag( $id, 'community-eligible' );
		eex_hub_seed_custom_field( $id, '_membership_tier', 'founding-member' );

		$community = Projection::community( $crm, $id, $crm->tag_slugs( $id ) );

		$this->assertTrue( $community['eligible'] );
		$this->assertFalse( $community['opt_in'], 'marker absent with readable tags = false, not null' );
		$this->assertSame( 'founding-member', $community['membership_tier'] );

		// Unreadable tags = unknown, never a silent false.
		$blind = Projection::community( $crm, $id, null );
		$this->assertNull( $blind['eligible'] );
		$this->assertNull( $blind['opt_in'] );

		// The CRM-side seam can answer authoritatively.
		add_filter(
			'eex_hub_community_facts',
			static function ( array $facts ): array {
				$facts['admitted'] = true;

				return $facts;
			}
		);

		$this->assertTrue( Projection::community( $crm, $id, [] )['admitted'] );
	}

	public function test_the_projection_hash_is_canonical(): void {
		$a = [
			'email' => 'x@example.org',
			'community' => [
				'opt_in'   => true,
				'eligible' => false,
			],
		];
		$b = [
			'community' => [
				'eligible' => false,
				'opt_in'   => true,
			],
			'email' => 'x@example.org',
		];

		$this->assertSame( Projection::hash( $a ), Projection::hash( $b ), 'key order never changes the hash' );
		$this->assertNotSame( Projection::hash( $a ), Projection::hash( array_merge( $a, [ 'email' => 'y@example.org' ] ) ) );
	}

	public function test_ticket_facts_distinguish_false_from_unknown(): void {
		$crm = new Crm();

		$with_tag = eex_hub_seed_subscriber( [ 'email' => 'buyer@example.org' ] );
		eex_hub_seed_tag( $with_tag, 'ticket-buyer' );
		$facts = Projection::ticket_facts( $crm, $with_tag, $crm->tag_slugs( $with_tag ) );
		$this->assertTrue( $facts['has_ticket'] );

		$without = eex_hub_seed_subscriber( [ 'email' => 'no-ticket@example.org' ] );
		$facts   = Projection::ticket_facts( $crm, $without, $crm->tag_slugs( $without ) );
		$this->assertFalse( $facts['has_ticket'], 'readable tags without the marker = false' );

		$facts = Projection::ticket_facts( $crm, $without, null );
		$this->assertNull( $facts['has_ticket'], 'unreadable tags = unknown' );
	}
}
