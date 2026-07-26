<?php
/**
 * The confirmed opt-in registration flow.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Options;
use Emailexpert\Events\Registration\ConfirmHandler;
use Emailexpert\Events\Rest\RegisterController;
use Emailexpert\Events\Support\Crypto;
use Emailexpert\Events\Tests\TestCase;
use WP_REST_Request;

/**
 * Unknown addresses are held and confirmed by email; verified identities
 * register instantly; every anonymous branch answers identically; PII in
 * the pending store is encrypted; tokens are hashed and single-use.
 */
final class ConfirmedOptInTest extends TestCase {

	/**
	 * Captured HeySummit POSTs.
	 *
	 * @var array<int,array{0:string,1:array}>
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
		wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => 'publish',
				'post_title'  => 'Keynote 7001',
				'meta_input'  => [
					'_eex_heysummit_id'    => '7001',
					'_eex_source_event_id' => '101',
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

					return self::json_response( [ 'id' => 9000001 ], 201 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( (string) $url, 'tickets/' ) ) {
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
	}

	/**
	 * A registration request (standard confirmation mode by default).
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
					'talk'    => '7001',
					'name'    => 'Pat Visitor',
					'email'   => 'pat@example.org',
					'consent' => '1',
					'return'  => 'https://example.test/events/',
					'website' => '',
				],
				$over
			)
		);
	}

	/**
	 * The raw confirmation token from the last captured email.
	 */
	private function token_from_mail(): string {
		$last = end( \EEX_Test_State::$mail );
		preg_match( '/eex_confirm=([0-9a-f]{64})/', (string) ( $last['message'] ?? '' ), $m );

		return (string) ( $m[1] ?? '' );
	}

	public function test_crypto_roundtrip_and_fail_closed(): void {
		$blob = Crypto::encrypt( 'pat@example.org' );

		$this->assertNotSame( '', $blob );
		$this->assertStringNotContainsString( 'pat@example.org', $blob, 'ciphertext never contains plaintext' );
		$this->assertSame( 'pat@example.org', Crypto::decrypt( $blob ) );

		// One flipped character: nothing comes back.
		$tampered = substr( $blob, 0, -2 ) . ( '=' === substr( $blob, -1 ) ? 'a=' : 'ab' );
		$this->assertSame( '', Crypto::decrypt( $tampered ), 'tamper fails closed' );
	}

	public function test_an_unknown_address_is_held_and_asked_to_confirm(): void {
		$response = ( new RegisterController() )->create( $this->request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'submitted', $response->get_data()['status'], 'the neutral anonymous answer' );

		// Nothing reached HeySummit.
		$this->assertCount( 0, $this->posts, 'no attendee exists until the mailbox owner confirms' );

		// One confirmation email went out, carrying the link and the exact
		// consent wording (the receipt in the visitor's own inbox).
		$this->assertCount( 1, \EEX_Test_State::$mail );
		$mail = \EEX_Test_State::$mail[0];
		$this->assertSame( 'pat@example.org', $mail['to'] );
		$this->assertStringContainsString( 'Confirm your registration', (string) $mail['subject'] );
		$this->assertMatchesRegularExpression( '/eex_confirm=[0-9a-f]{64}/', (string) $mail['message'] );
		$this->assertStringContainsString( 'creates my free account', (string) $mail['message'], 'the disclosure wording travels in the email' );

		// The pending record holds no plaintext PII and no raw token.
		$token   = $this->token_from_mail();
		$pending = array_filter( \EEX_Test_State::$transients, fn( $k ) => str_starts_with( (string) $k, 'eex_pendreg_' ), ARRAY_FILTER_USE_KEY );
		$this->assertCount( 1, $pending );
		$stored = (string) reset( $pending );
		$this->assertStringNotContainsString( 'pat@example.org', $stored, 'email is encrypted at rest' );
		$this->assertStringNotContainsString( 'Pat Visitor', $stored, 'name is encrypted at rest' );
		$this->assertArrayNotHasKey( 'eex_pendreg_' . $token, \EEX_Test_State::$transients, 'the store is keyed by the token HASH, not the raw token' );
	}

	public function test_the_confirmation_click_completes_the_registration_once(): void {
		( new RegisterController() )->create( $this->request() );
		$token = $this->token_from_mail();
		$this->assertNotSame( '', $token );

		$outcome = ( new ConfirmHandler() )->confirm( $token );

		$this->assertSame( 'done', $outcome['status'] );
		$this->assertStringContainsString( 'https://example.test/events/', $outcome['url'], 'lands back on the originating page' );

		// The real write happened: create, then the schedule attach.
		$this->assertCount( 2, $this->posts );
		$this->assertStringContainsString( 'events/101/attendees/', $this->posts[0][0] );
		$this->assertStringContainsString( 'events/101/attendees/9000001/talks/7001/', $this->posts[1][0] );

		// A consent receipt exists — wording and flags, no raw email.
		$receipts = get_option( 'eex_consent_receipts', [] );
		$this->assertCount( 1, $receipts );
		$this->assertStringContainsString( 'creates my free account', (string) $receipts[0]['consent']['disclosure'] );
		$this->assertStringNotContainsString( 'pat@example.org', (string) wp_json_encode( $receipts[0] ) );

		// Single-use: the same link again is indistinguishable from invalid.
		$again = ( new ConfirmHandler() )->confirm( $token );
		$this->assertSame( 'invalid', $again['status'] );
		$this->assertCount( 2, $this->posts, 'no replay' );
	}

	public function test_a_garbage_token_is_invalid_without_any_api_call(): void {
		$outcome = ( new ConfirmHandler() )->confirm( str_repeat( 'a', 64 ) );

		$this->assertSame( 'invalid', $outcome['status'] );
		$this->assertCount( 0, $this->posts );
	}

	public function test_the_send_slot_stops_repeat_confirmation_emails(): void {
		( new RegisterController() )->create( $this->request() );
		$response = ( new RegisterController() )->create( $this->request() );

		$this->assertSame( 'submitted', $response->get_data()['status'], 'same neutral answer' );
		$this->assertCount( 1, \EEX_Test_State::$mail, 'one confirmation email per address per window' );
	}

	public function test_all_anonymous_branches_answer_identically(): void {
		$unknown  = ( new RegisterController() )->create( $this->request() );
		$honeypot = ( new RegisterController() )->create( $this->request( [ 'website' => 'spam' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( $unknown->get_status(), $honeypot->get_status() );
		$this->assertSame( $unknown->get_data(), $honeypot->get_data(), 'held and honeypot are indistinguishable' );
	}

	public function test_a_logged_in_member_registering_their_own_address_is_instant(): void {
		eex_test_create_user( 'pat', 'pat@example.org' );

		$response = ( new RegisterController() )->create( $this->request() );

		$this->assertSame( 'registered', $response->get_data()['status'], 'verified identity: the real status' );
		$this->assertCount( 2, $this->posts, 'create + attach happened immediately' );
		$this->assertCount( 0, \EEX_Test_State::$mail, 'no confirmation email needed' );
	}

	public function test_a_logged_in_member_typing_someone_elses_address_still_confirms(): void {
		eex_test_create_user( 'pat', 'pat@example.org' );

		$response = ( new RegisterController() )->create( $this->request( [ 'email' => 'other@example.org' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 'submitted', $response->get_data()['status'] );
		$this->assertCount( 0, $this->posts, 'a claim about another person is not a verified identity' );
		$this->assertCount( 1, \EEX_Test_State::$mail, 'the mailbox owner decides' );
	}

	public function test_standard_mode_trusts_the_crm_verified_filter_strict_does_not(): void {
		add_filter( 'eex_email_is_verified', static fn(): bool => true );

		$response = ( new RegisterController() )->create( $this->request() );
		$this->assertSame( 'submitted', $response->get_data()['status'], 'anonymous callers still get the neutral body' );
		$this->assertCount( 2, $this->posts, 'but the registration happened instantly (create + attach)' );
		$this->assertCount( 0, \EEX_Test_State::$mail, 'no confirmation round-trip for a confirmed contact' );

		// Strict mode ignores the CRM answer.
		Options::update_settings( [ 'reg_confirm_mode' => 'strict' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->posts = [];
		\EEX_Test_State::$mail = [];
		delete_option( 'eex_regcd_' . Crypto::hash_email( 'pat2@example.org' ) );

		$strict = ( new RegisterController() )->create( $this->request( [ 'email' => 'pat2@example.org' ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertSame( 'submitted', $strict->get_data()['status'] );
		$this->assertCount( 0, $this->posts, 'strict: even CRM-confirmed addresses go through the email' );
		$this->assertCount( 1, \EEX_Test_State::$mail );
	}

	public function test_the_ticket_must_still_be_free_at_confirm_time(): void {
		( new RegisterController() )->create( $this->request() );
		$token = $this->token_from_mail();

		// The ticket turns paid during the 48-hour window.
		remove_all_filters( 'pre_http_request' );
		\Emailexpert\Events\Data\LiveCache::flush();
		\Emailexpert\Events\Data\LiveCache::reset_request_state();
		$this->mock_http(
			static function ( $url ) {
				if ( str_contains( (string) $url, 'tickets/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 9002, 'title' => 'Free pass', 'is_paid' => 'true', 'prices' => '[{"id": 502, "title": "Guest", "price": "49"}]' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				return null;
			}
		);

		$outcome = ( new ConfirmHandler() )->confirm( $token );

		$this->assertSame( 'failed', $outcome['status'] );
		$this->assertCount( 0, $this->posts, 'the free-only guarantee holds at write time' );
	}

	public function test_a_session_added_to_an_existing_attendee_sends_the_ics_email(): void {
		Options::update_settings( [ 'reg_confirm_mode' => 'off' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_post_meta( 2, '_eex_talk_start', gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) );

		$posts = &$this->posts;
		remove_all_filters( 'pre_http_request' );
		$this->mock_http(
			static function ( $url, $args ) use ( &$posts ) {
				$url = (string) $url;

				if ( 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
					if ( str_contains( $url, '/talks/' ) ) {
						$posts[] = [ $url, [] ];

						return self::json_response( [ 'status' => 'added' ], 200 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					}

					$posts[] = [ $url, [] ];

					return self::json_response( [ 'detail' => 'Attendee already exists for this event.' ], 400 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( $url, 'attendees/' ) && str_contains( $url, 'email' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 8123, 'email' => 'pat@example.org' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( $url, 'tickets/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 9002, 'title' => 'Free pass', 'is_paid' => 'false', 'prices' => '[]' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				return null;
			}
		);

		$response = ( new RegisterController() )->create( $this->request() );

		$this->assertSame( 'registered', $response->get_data()['status'], 'duplicate answers like fresh (D103)' );
		$this->assertCount( 1, \EEX_Test_State::$mail, 'the session-added email fills the gap HeySummit leaves silent' );
		$this->assertStringContainsString( "You're registered:", (string) \EEX_Test_State::$mail[0]['subject'] );
	}

	public function test_the_form_carries_unbundled_consent_and_the_privacy_link(): void {
		$html = \Emailexpert\Events\Frontend\Components::render( 'register-inline', [ 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertStringContainsString( 'name="consent"', $html );
		$this->assertStringContainsString( 'creates my free account', $html, 'the disclosure names the account creation' );
		$this->assertStringContainsString( 'name="marketing"', $html, 'marketing is its own checkbox' );
		$this->assertStringNotContainsString( 'name="marketing" value="1" checked', $html, 'never pre-ticked' );
		$this->assertStringContainsString( 'https://example.test/privacy/', $html, 'privacy policy linked' );

		// Marketing off: the checkbox disappears; consent remains.
		Options::update_settings( [ 'reg_marketing_show' => 0 ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		\Emailexpert\Events\Frontend\Cache::flush();
		$plain = \Emailexpert\Events\Frontend\Components::render( 'register-inline', [ 'event' => '101' ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertStringNotContainsString( 'name="marketing"', $plain );
		$this->assertStringContainsString( 'name="consent"', $plain );
	}

	public function test_one_ip_cannot_stream_confirmation_emails_to_different_addresses(): void {
		// Three distinct addresses (a household) go through; the fourth
		// from the same IP inside the hour does not — and the response
		// stays the same neutral body, so the cap is not probeable either.
		foreach ( [ 'a@example.org', 'b@example.org', 'c@example.org', 'd@example.org' ] as $i => $address ) {
			// Stay under the general 5-per-10-minutes request limit.
			delete_transient( 'eex_reg_rl_' . md5( '203.0.113.9' ) );
			$response = ( new RegisterController() )->create( $this->request( [ 'email' => $address ] ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			$this->assertSame( 'submitted', $response->get_data()['status'], 'attempt ' . ( $i + 1 ) . ' answers neutrally' );
		}

		$this->assertCount( 3, \EEX_Test_State::$mail, 'the fourth distinct address sends nothing' );
		$this->assertSame( [ 'a@example.org', 'b@example.org', 'c@example.org' ], array_column( \EEX_Test_State::$mail, 'to' ) );
	}

	public function test_the_session_added_email_can_be_handed_to_heysummit(): void {
		// Operator publishes HeySummit's "Schedule Updated" template and
		// turns ours off: the attach still happens, no double email.
		Options::update_settings(
			[
				'reg_confirm_mode'    => 'off',
				'session_added_email' => 0,
			]
		);

		remove_all_filters( 'pre_http_request' );
		$this->mock_http(
			static function ( $url, $args ) {
				$url = (string) $url;

				if ( 'POST' === strtoupper( (string) ( $args['method'] ?? 'GET' ) ) ) {
					if ( str_contains( $url, '/talks/' ) ) {
						return self::json_response( [ 'status' => 'added' ], 200 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					}

					return self::json_response( [ 'detail' => 'Attendee already exists for this event.' ], 400 ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( $url, 'attendees/' ) && str_contains( $url, 'email' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 8123, 'email' => 'pat@example.org' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				if ( str_contains( $url, 'tickets/' ) ) {
					return self::json_response( [ 'results' => [ [ 'id' => 9002, 'title' => 'Free pass', 'is_paid' => 'false', 'prices' => '[]' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				}

				return null;
			}
		);

		$rsvp_fired = 0;
		add_action(
			'eex_session_rsvp',
			static function () use ( &$rsvp_fired ) {
				++$rsvp_fired;
			}
		);

		( new RegisterController() )->create( $this->request() );

		$this->assertCount( 0, \EEX_Test_State::$mail, 'our email stands down when the platform owns it' );
		$this->assertSame( 1, $rsvp_fired, 'the CRM hook still fires' );
	}

	public function test_the_mail_gate_filters_stop_a_send(): void {
		add_filter( 'eex_should_send', static fn(): bool => false );

		( new RegisterController() )->create( $this->request() );

		$this->assertCount( 0, \EEX_Test_State::$mail, 'eex_should_send=false stops the confirmation email' );
	}
}
