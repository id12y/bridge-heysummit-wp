<?php
/**
 * In-drawer free-ticket registration: guard rails and the write path.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Rest\RegisterController;
use Emailexpert\Events\Tests\TestCase;
use WP_REST_Request;

/**
 * @covers \Emailexpert\Events\Rest\RegisterController
 */
final class RegisterControllerTest extends TestCase {

	/**
	 * Captured POST requests: [ url, decoded body ].
	 *
	 * @var array<int,array{0:string,1:array<string,mixed>}>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		wp_insert_post(
			[
				'post_type'   => 'eex_event',
				'post_status' => 'publish',
				'post_title'  => 'Hub',
				'meta_input'  => [
					'_eex_heysummit_id'  => '101',
					'_eex_connection_id' => 'c1',
					'_eex_event_url'     => 'https://summit.example.com/',
				],
			]
		);
		update_option(
			'eex_connections',
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$posts = &$this->posts;
		$this->mock_http(
			static function ( $url, $args ) use ( &$posts ) {
				if ( 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
					$posts[] = [ (string) $url, (array) json_decode( (string) ( $args['body'] ?? '' ), true ) ];

					return self::json_response( [ 'id' => 9000001 ], 201 );
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'      => 9001,
									'title'   => 'All access',
									'is_paid' => 'true',
									'prices'  => '[{"id": 501, "title": "Standard", "price": "99"}]',
								],
								[
									'id'      => 9002,
									'title'   => 'Free pass',
									'is_paid' => 'false',
									'prices'  => '[{"id": 502, "title": "Guest", "price": "0.00"}]',
								],
							],
						]
					);
				}

				return null;
			}
		);
	}

	/**
	 * A valid registration request body.
	 *
	 * @param array<string,string> $over Overrides.
	 */
	private function request( array $over = [] ): WP_REST_Request {
		return new WP_REST_Request(
			array_merge(
				[
					'event'   => '101',
					'ticket'  => '9002',
					'price'   => '502',
					'name'    => 'Pat Visitor',
					'email'   => 'pat@example.org',
					'consent' => '1',
					'website' => '',
				],
				$over
			)
		);
	}

	public function test_free_ticket_registers_through_the_allowlisted_path(): void {
		$response = ( new RegisterController() )->create( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'registered', $response->get_data()['status'] );
		$this->assertSame( 'no-store', $response->headers['Cache-Control'] );

		$this->assertCount( 1, $this->posts );
		[ $url, $body ] = $this->posts[0];
		$this->assertStringContainsString( 'events/101/attendees/', $url, 'the one allowlisted create path' );
		$this->assertSame( 'pat@example.org', $body['email'] );
		$this->assertSame( 'Pat Visitor', $body['name'] );
		$this->assertSame( 502, $body['ticket_price_id'] );
	}

	public function test_honeypot_pretends_success_and_sends_nothing(): void {
		$response = ( new RegisterController() )->create( $this->request( [ 'website' => 'http://spam.example' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 0, $this->posts, 'nothing reaches the API' );
	}

	public function test_consent_is_required(): void {
		$response = ( new RegisterController() )->create( $this->request( [ 'consent' => '' ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->posts );
	}

	public function test_paid_tickets_are_refused(): void {
		$response = ( new RegisterController() )->create(
			$this->request(
				[
					'ticket' => '9001',
					'price'  => '501',
				]
			)
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertCount( 0, $this->posts, 'a paid ticket must never be granted through this route' );
	}

	public function test_a_foreign_price_id_is_replaced_with_the_tickets_own(): void {
		( new RegisterController() )->create( $this->request( [ 'price' => '501' ] ) );

		$this->assertCount( 1, $this->posts );
		$this->assertSame( 502, $this->posts[0][1]['ticket_price_id'], 'the paid ticket price cannot be smuggled onto a free ticket' );
	}

	public function test_unknown_event_is_refused(): void {
		$response = ( new RegisterController() )->create( $this->request( [ 'event' => '999' ] ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertCount( 0, $this->posts );
	}

	public function test_rate_limit_stops_the_sixth_attempt(): void {
		$controller = new RegisterController();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertSame( 200, $controller->create( $this->request() )->get_status() );
		}

		$this->assertSame( 429, $controller->create( $this->request() )->get_status() );
		$this->assertCount( 5, $this->posts );
	}

	public function test_bad_email_is_refused_with_a_plain_sentence(): void {
		$response = ( new RegisterController() )->create( $this->request( [ 'email' => 'not-an-email' ] ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertNotSame( '', (string) $response->get_data()['message'] );
		$this->assertCount( 0, $this->posts );
	}

	/**
	 * Seed a synced talk so known_talk() resolves it to its event.
	 *
	 * @param string $hs_id       Talk HeySummit ID.
	 * @param string $event_hs_id Owning event HeySummit ID.
	 */
	private function seed_talk( string $hs_id, string $event_hs_id ): void {
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Keynote ' . $hs_id,
				'meta_input'  => [
					'_eex_heysummit_id'    => $hs_id,
					'_eex_source_event_id' => $event_hs_id,
				],
			]
		);
	}

	public function test_a_clicked_session_is_added_to_the_new_attendee(): void {
		$this->seed_talk( '7001', '101' );

		$response = ( new RegisterController() )->create( $this->request( [ 'talk' => '7001' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'registered', $response->get_data()['status'] );

		// Two writes: the attendee create, then the schedule attach.
		$this->assertCount( 2, $this->posts );
		$this->assertStringContainsString( 'events/101/attendees/', $this->posts[0][0], 'create first' );
		$this->assertStringContainsString( 'events/101/attendees/9000001/talks/7001/', $this->posts[1][0], 'then attach the clicked session to the new attendee' );
	}

	public function test_an_unknown_session_is_never_attached(): void {
		// No talk seeded: a form-supplied talk ID is not trusted.
		( new RegisterController() )->create( $this->request( [ 'talk' => '9999' ] ) );

		$this->assertCount( 1, $this->posts, 'only the create — an unknown talk is silently not attached' );
	}

	public function test_a_talk_from_another_event_is_not_attached(): void {
		$this->seed_talk( '7002', '202' );

		( new RegisterController() )->create( $this->request( [ 'talk' => '7002' ] ) );

		$this->assertCount( 1, $this->posts, 'a talk belonging to a different event is rejected before any attach' );
	}

	public function test_a_non_numeric_talk_id_never_reaches_the_api_or_breaks_registration(): void {
		// The allowlisted attach path takes numeric segments only; a
		// non-numeric ID must degrade to "registered, session not attached" —
		// never a thrown write refusal after the registration succeeded.
		$this->seed_talk( 'weird-slug', '101' );

		$response = ( new RegisterController() )->create( $this->request( [ 'talk' => 'weird-slug' ] ) );

		$this->assertSame( 200, $response->get_status(), 'the visitor is registered regardless' );
		$this->assertSame( 'registered', $response->get_data()['status'] );
		$this->assertCount( 1, $this->posts, 'only the create — no attach attempt with a non-numeric id' );
	}

	public function test_a_returning_attendee_clicking_a_session_is_found_and_attached(): void {
		$this->seed_talk( '7001', '101' );

		// Replace the default mock: the create answers "already exists", the
		// email lookup finds the existing attendee, the attach is captured.
		remove_all_filters( 'pre_http_request' );
		$posts = &$this->posts;
		$this->mock_http(
			static function ( $url, $args ) use ( &$posts ) {
				$url = (string) $url;

				if ( 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
					if ( str_contains( $url, '/talks/' ) ) {
						$posts[] = [ $url, [] ];

						return self::json_response( [ 'status' => 'added' ], 200 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					}

					$posts[] = [ $url, (array) json_decode( (string) ( $args['body'] ?? '' ), true ) ];

					return self::json_response( [ 'detail' => 'Attendee already exists for this event.' ], 400 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( $url, 'attendees/' ) && str_contains( $url, 'email' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'    => 8123,
									'email' => 'pat@example.org',
								],
							],
						]
					);
				}

				if ( str_contains( $url, 'tickets/' ) ) {
					return self::json_response(
						[
							'results' => [
								[
									'id'      => 9002,
									'title'   => 'Free pass',
									'is_paid' => 'false',
									'prices'  => '[{"id": 502, "title": "Guest", "price": "0.00"}]',
								],
							],
						]
					);
				}

				return null;
			}
		);

		$response = ( new RegisterController() )->create( $this->request( [ 'talk' => '7001' ] ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'already', $response->get_data()['status'], 'a duplicate registration reads as success' );

		$this->assertCount( 2, $this->posts );
		$this->assertStringContainsString( 'events/101/attendees/', $this->posts[0][0], 'the create was attempted' );
		$this->assertStringContainsString( 'events/101/attendees/8123/talks/7001/', $this->posts[1][0], 'the clicked session is attached to the attendee found by email' );
	}
}
