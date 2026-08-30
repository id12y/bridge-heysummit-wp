<?php
/**
 * The Hub REST surface: routes, projections, pagination, catalogue.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Hub\Auth;
use Emailexpert\Events\Hub\RestController;
use Emailexpert\Events\Tests\HubTestCase;
use WP_REST_Request;

/**
 * Every route is GET-only behind the auth callback; responses expose the
 * fixed allowlisted projection and nothing else — no secrets, no raw
 * records, no field selection.
 *
 * @covers \Emailexpert\Events\Hub\RestController
 * @covers \Emailexpert\Events\Hub\Projection
 */
final class HubRestTest extends HubTestCase {

	/**
	 * The exact top-level keys of an active contact projection.
	 *
	 * @var array<int,string>
	 */
	private const PROJECTION_KEYS = [
		'contact_uuid',
		'revision',
		'updated_at',
		'email',
		'first_name',
		'last_name',
		'display_name',
		'state',
		'email_news_state',
		'sources',
		'community',
	];

	public function test_every_route_is_get_only_and_permission_guarded(): void {
		( new RestController() )->register_routes();

		$routes = $GLOBALS['eex_test_rest_routes'] ?? [];
		$hub    = array_filter( $routes, static fn( $key ) => str_starts_with( $key, 'emailexpert-crm/v1/hub' ), ARRAY_FILTER_USE_KEY );

		$this->assertCount( 6, $hub );

		foreach ( $hub as $route => $args ) {
			$this->assertSame( 'GET', $args['methods'], $route . ' must be GET-only' );
			$this->assertIsArray( $args['permission_callback'], $route . ' must not use __return_true' );
			$this->assertInstanceOf( Auth::class, $args['permission_callback'][0] );
			$this->assertSame( 'permit', $args['permission_callback'][1] );
		}
	}

	public function test_capabilities_reports_health_but_never_secrets(): void {
		update_option(
			'een_ticket_tailor_box_offices',
			[
				[
					'label'   => 'Main Box Office',
					'api_key' => 'sk_live_SUPERSECRET123',
				],
			]
		);

		$response = ( new RestController() )->capabilities( new WP_REST_Request() );
		$data     = $response->get_data();
		$raw      = (string) wp_json_encode( $data );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1.0.0', $data['contract_version'] );
		$this->assertSame( [ 'contacts', 'changes', 'ticket-catalogue', 'eligibility' ], $data['resources'] );
		$this->assertTrue( $data['features']['contact_uuid'] );
		$this->assertFalse( $data['features']['ticket_validity'], 'validity honestly reported unsupported' );
		$this->assertSame( 'ok', $data['health'] );
		$this->assertSame(
			[
				'id'    => 'main-box-office',
				'label' => 'Main Box Office',
			],
			$data['ticket_tailor']['box_offices'][0]
		);

		$this->assertStringNotContainsString( 'SUPERSECRET', $raw, 'API keys never leave the server' );
		$this->assertStringNotContainsString( 'api_key', $raw );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $data['server_time_utc'] );
	}

	public function test_contacts_returns_only_the_allowlisted_projection(): void {
		$id = eex_hub_seed_subscriber(
			[
				'email'      => 'pat@example.org',
				'first_name' => 'Pat',
				'last_name'  => 'Example',
			]
		);
		eex_hub_seed_custom_field( $id, '_source', 'subscribe,ticket_tailor' );
		eex_hub_seed_custom_field( $id, '_internal_notes', 'NEVER-EXPOSED' );
		$this->sweep();

		$response = ( new RestController() )->contacts( new WP_REST_Request() );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['contacts'] );

		$contact = $data['contacts'][0];
		$this->assertSame( self::PROJECTION_KEYS, array_keys( $contact ), 'exactly the allowlist, nothing else' );
		$this->assertSame( 'pat@example.org', $contact['email'] );
		$this->assertSame( 'Pat Example', $contact['display_name'] );
		$this->assertSame( 'active', $contact['state'] );
		$this->assertSame( 'subscribed', $contact['email_news_state'] );
		$this->assertSame( [ 'subscribe', 'ticket_tailor' ], $contact['sources'] );
		$this->assertSame( [ 'eligible', 'opt_in', 'invited', 'admitted', 'membership_tier' ], array_keys( $contact['community'] ) );

		$this->assertStringNotContainsString( 'NEVER-EXPOSED', (string) wp_json_encode( $data ) );

		// Contact data is never cacheable.
		$this->assertSame( 'private, no-store', $response->headers['Cache-Control'] );
		$this->assertNotEmpty( $response->headers['X-EEX-Request-Id'] );
	}

	public function test_snapshot_pagination_is_deterministic_and_bounded(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			eex_hub_seed_subscriber( [ 'email' => 'p' . $i . '@example.org' ] );
		}
		$this->sweep();

		$controller = new RestController();

		$page_one = $controller->contacts( new WP_REST_Request( [ 'limit' => 2 ] ) )->get_data();
		$this->assertCount( 2, $page_one['contacts'] );
		$this->assertTrue( $page_one['has_more'] );

		// Replaying the same (empty) cursor gives the same page.
		$replay = $controller->contacts( new WP_REST_Request( [ 'limit' => 2 ] ) )->get_data();
		$this->assertSame(
			array_column( $page_one['contacts'], 'contact_uuid' ),
			array_column( $replay['contacts'], 'contact_uuid' )
		);

		$page_two = $controller->contacts(
			new WP_REST_Request(
				[
					'limit'  => 2,
					'cursor' => $page_one['next_cursor'],
				]
			)
		)->get_data();

		$this->assertCount( 1, $page_two['contacts'] );

		$all = array_merge(
			array_column( $page_one['contacts'], 'contact_uuid' ),
			array_column( $page_two['contacts'], 'contact_uuid' )
		);
		$this->assertSame( array_values( array_unique( $all ) ), $all, 'no duplicates, no gaps' );

		// An oversized limit clamps to the hard maximum.
		$this->assertSame( 100, \Emailexpert\Events\Hub\Settings::page_size( 99999 ) );

		// A forged cursor is rejected, not guessed at.
		$bad = $controller->contacts( new WP_REST_Request( [ 'cursor' => 'forged' ] ) );
		$this->assertSame( 400, $bad->get_status() );
	}

	public function test_single_contact_lookup_and_tombstone_shape(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'erase-me@example.org' ] );
		$this->sweep();

		$controller = new RestController();
		$registry   = new \Emailexpert\Events\Hub\Registry();
		$uuid       = $registry->by_subscriber( $id )['uuid'];

		$found = $controller->contact( new WP_REST_Request( [ 'contact_uuid' => $uuid ] ) );
		$this->assertSame( 200, $found->get_status() );
		$this->assertSame( $uuid, $found->get_data()['contact_uuid'] );

		$missing = $controller->contact( new WP_REST_Request( [ 'contact_uuid' => '11111111-2222-4333-8444-555555555555' ] ) );
		$this->assertSame( 404, $missing->get_status() );

		// After erasure: a minimal tombstone, nothing personal.
		eex_hub_purge_subscriber( $id );
		$this->sweep();

		$tombstone = $controller->contact( new WP_REST_Request( [ 'contact_uuid' => $uuid ] ) )->get_data();

		$this->assertSame( [ 'contact_uuid', 'revision', 'updated_at', 'state', 'deleted_at' ], array_keys( $tombstone ) );
		$this->assertSame( 'deleted', $tombstone['state'] );
		$this->assertStringNotContainsString( 'erase-me', (string) wp_json_encode( $tombstone ) );
	}

	public function test_changes_feed_carries_upserts_and_tombstones(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'change@example.org' ] );
		$this->sweep();

		eex_hub_purge_subscriber( $id );
		$this->sweep();

		$controller = new RestController();
		$data       = $controller->changes( new WP_REST_Request() )->get_data();

		$this->assertCount( 2, $data['changes'] );

		$upsert = $data['changes'][0];
		$this->assertSame( 'upsert', $upsert['op'] );
		$this->assertSame( 1, $upsert['change_id'] );
		$this->assertSame( 'contact', $upsert['object_type'] );
		$this->assertNull( $upsert['tombstone'] );

		$delete = $data['changes'][1];
		$this->assertSame( 'delete', $delete['op'] );
		$this->assertGreaterThan( $upsert['revision'], $delete['revision'], 'revisions are monotonic per contact' );
		$this->assertNull( $delete['projection'] );
		$this->assertNotNull( $delete['tombstone']['deleted_at'] );

		// Replay: the same cursor yields the same changes.
		$replay = $controller->changes( new WP_REST_Request() )->get_data();
		$this->assertSame(
			array_column( $data['changes'], 'change_id' ),
			array_column( $replay['changes'], 'change_id' )
		);

		// Resume past the first change.
		$rest = $controller->changes( new WP_REST_Request( [ 'cursor' => $this->cursor_after( $upsert['change_id'] ) ] ) )->get_data();
		$this->assertCount( 1, $rest['changes'] );
		$this->assertSame( 'delete', $rest['changes'][0]['op'] );
	}

	public function test_ticket_catalogue_scopes_identities_by_box_office(): void {
		$one = eex_hub_seed_subscriber( [ 'email' => 'attendee1@example.org' ] );
		$two = eex_hub_seed_subscriber( [ 'email' => 'attendee2@example.org' ] );

		$entry = static fn( string $box ): string => (string) wp_json_encode(
			[
				[
					'order_id'      => 'or_900',
					'ticket_id'     => 'it_1',
					'event_summary' => 'Email Expert Live',
					'ticket_type'   => 'General Admission',
					'buyer_name'    => 'Someone Else',
					'buyer_email'   => 'buyer@example.org',
					'box_office'    => $box,
					'status'        => 'valid',
					'created_at'    => '2026-08-01T10:00:00Z',
				],
			]
		);

		eex_hub_seed_custom_field( $one, '_tt_allocated_tickets', $entry( 'Main Box Office' ) );
		eex_hub_seed_custom_field( $two, '_tt_allocated_tickets', $entry( 'Second Account' ) );
		$this->sweep();

		$data = ( new RestController() )->ticket_catalogue( new WP_REST_Request() )->get_data();

		$this->assertCount( 2, $data['tickets'] );

		$ids = array_column( $data['tickets'], 'id' );
		$this->assertSame( array_values( array_unique( $ids ) ), $ids, 'identical external ids in different box offices never collide' );
		$this->assertContains( 'bo:main-box-office:order:or_900:ticket:it_1', $ids );
		$this->assertContains( 'bo:second-account:order:or_900:ticket:it_1', $ids );

		$ticket = $data['tickets'][0];
		$this->assertSame( 'valid', $ticket['state'] );
		$this->assertSame( 'Email Expert Live', $ticket['event']['label'] );
		$this->assertSame( 'General Admission', $ticket['ticket_type']['label'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/', $ticket['contact_uuid'] );

		// No payment, billing or buyer-identity data leaks through.
		$raw = (string) wp_json_encode( $data );
		$this->assertStringNotContainsString( 'buyer@example.org', $raw );
		$this->assertStringNotContainsString( 'buyer_name', $raw );

		// Deleted contacts drop out of the catalogue.
		eex_hub_purge_subscriber( $one );
		$this->sweep();
		$after = ( new RestController() )->ticket_catalogue( new WP_REST_Request() )->get_data();
		$this->assertCount( 1, $after['tickets'] );
	}

	public function test_eligibility_facts_are_separate_and_never_inferred_from_tickets(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'member@example.org' ] );
		eex_hub_seed_tag( $id, 'ticket-buyer' );
		eex_hub_seed_tag( $id, 'community-opt-in' );
		$this->sweep();

		$uuid = ( new \Emailexpert\Events\Hub\Registry() )->by_subscriber( $id )['uuid'];
		$data = ( new RestController() )->eligibility( new WP_REST_Request( [ 'contact_uuid' => $uuid ] ) )->get_data();

		$this->assertTrue( $data['facts']['has_ticket'] );
		$this->assertNull( $data['facts']['ticket_currently_valid'], 'validity is honestly unknown' );
		$this->assertTrue( $data['facts']['community_opt_in'] );
		$this->assertFalse( $data['facts']['community_eligible'], 'a ticket does NOT imply eligibility: the marker is absent, so the answer is false' );
		$this->assertFalse( $data['facts']['community_admitted'] );
		$this->assertNull( $data['membership_tier'] );

		$bad = ( new RestController() )->eligibility( new WP_REST_Request( [ 'contact_uuid' => 'not-a-uuid' ] ) );
		$this->assertSame( 404, $bad->get_status() );
	}

	public function test_a_vanished_crm_row_degrades_to_unknown_facts_not_an_error(): void {
		$id = eex_hub_seed_subscriber( [ 'email' => 'blink@example.org' ] );
		$this->sweep();

		// The CRM row disappears before the next sweep runs.
		eex_hub_purge_subscriber( $id );

		$data = ( new RestController() )->contacts( new WP_REST_Request() )->get_data();

		$contact = $data['contacts'][0];
		$this->assertSame( 'active', $contact['state'] );
		$this->assertNull( $contact['email'] );
		$this->assertSame( 'unknown', $contact['email_news_state'] );
		$this->assertNull( $contact['community']['opt_in'] );
	}

	/**
	 * A changes cursor pointing after a sequence number.
	 */
	private function cursor_after( int $seq ): string {
		return \Emailexpert\Events\Hub\Cursor::encode( 'changes', [ 'after' => $seq ] );
	}
}
