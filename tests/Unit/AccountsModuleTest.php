<?php
/**
 * Accounts module: rules engine, consent, suppression, idempotency,
 * backfill, ticket assignment.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Accounts\Backfill;
use Emailexpert\Events\Accounts\Consent;
use Emailexpert\Events\Accounts\Engine;
use Emailexpert\Events\Accounts\Pusher;
use Emailexpert\Events\Accounts\Rules;
use Emailexpert\Events\Accounts\Suppression;
use Emailexpert\Events\Accounts\Triggers;
use Emailexpert\Events\Options;
use Emailexpert\Events\Registrations;
use Emailexpert\Events\Tests\TestCase;
use Emailexpert\Events\Webhooks\Attribution;
use Emailexpert\Events\Webhooks\Privacy;

/**
 * @covers \Emailexpert\Events\Accounts\Engine
 * @covers \Emailexpert\Events\Accounts\Pusher
 * @covers \Emailexpert\Events\Accounts\Triggers
 * @covers \Emailexpert\Events\Accounts\Backfill
 * @covers \Emailexpert\Events\Accounts\Suppression
 * @covers \Emailexpert\Events\Accounts\Consent
 * @covers \Emailexpert\Events\Registrations
 */
final class AccountsModuleTest extends TestCase {

	/**
	 * POST calls captured by the HTTP mock.
	 *
	 * @var array<int,array{url:string,body:array}>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->posts = [];

		Options::update_settings( [ 'accounts_enabled' => 1 ] );
		update_option(
			Options::CONNECTIONS,
			[
				[
					'id'      => 'c1',
					'label'   => 'Primary',
					'api_key' => 'k',
				],
			]
		);

		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => (array) json_decode( (string) ( $args['body'] ?? '' ), true ),
				];

				return str_contains( $url, 'attendees/' )
					? self::json_response( [ 'id' => 61001 ], 201 )
					: self::json_response( [ 'id' => 62001 ], 201 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );
	}

	/**
	 * A standard "member → hub free ticket" rule.
	 */
	private function member_rule( array $overrides = [] ): void {
		Rules::save(
			[
				'r1' => array_merge(
					[
						'id'             => 'r1',
						'enabled'        => 1,
						'trigger'        => 'role_gained',
						'roles'          => [ 'member' ],
						'connection'     => 'c1',
						'event'          => '101',
						'ticket'         => 'T-FREE',
						'consent_source' => 'assertion',
					],
					$overrides
				),
			]
		);
		Consent::set_assertion( true, 'admin' );
	}

	/**
	 * Run every queued eex_accounts_push job (following retries).
	 */
	private function run_queue(): void {
		$pusher = new Pusher();

		for ( $i = 0; $i < 5; $i++ ) {
			$jobs                        = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_accounts_push' === $e['hook'] );
			\EEX_Test_State::$scheduled = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_accounts_push' !== $e['hook'] ) );

			if ( empty( $jobs ) ) {
				return;
			}

			foreach ( $jobs as $job ) {
				$pusher->run_job( ...$job['args'] );
			}
		}
	}

	private function attendee_calls(): array {
		return array_values( array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], 'attendees/' ) ) );
	}

	public function test_role_gained_pushes_exactly_once_despite_repeats_and_overlapping_rules(): void {
		$this->member_rule();

		// An overlapping second rule targeting the same event.
		$rules       = Rules::all();
		$rules['r2'] = Rules::normalise(
			'r2',
			[
				'id'             => 'r2',
				'enabled'        => 1,
				'trigger'        => 'role_gained',
				'roles'          => [ 'member' ],
				'connection'     => 'c1',
				'event'          => '101',
				'ticket'         => 'T-FREE',
				'consent_source' => 'assertion',
			]
		);
		Rules::save( $rules );

		$user_id  = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		$triggers = new Triggers();

		// The role event fires repeatedly (plugins do this).
		$triggers->on_add_role( $user_id, 'member' );
		$triggers->on_add_role( $user_id, 'member' );
		$triggers->on_set_role( $user_id, 'member', [ 'subscriber' ] );
		$this->run_queue();

		$this->assertCount( 1, $this->attendee_calls(), 'exactly one attendee-create' );
		$record = Registrations::get( $user_id, '101' );
		$this->assertSame( 'done', $record['status'] );
		$this->assertSame( 'r1', $record['rule'], 'first matching rule owns the registration' );
		$this->assertSame( 'role_gained', $record['trigger'] );
		$this->assertStringStartsWith( 'assertion:', $record['consent'] );

		// Later role churn changes nothing.
		$triggers->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->assertCount( 1, $this->attendee_calls() );
	}

	public function test_already_existing_attendee_is_success_never_error(): void {
		$this->member_rule();

		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [ 'url' => $url, 'body' => [] ];

				return self::json_response( [ 'email' => [ 'attendee with this email already exists.' ] ], 400 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		$record = Registrations::get( $user_id, '101' );
		$this->assertSame( 'done', $record['status'], 'already-exists is success' );
		$this->assertStringContainsString( 'already existed', (string) $record['note'] );
		$this->assertCount( 1, $this->posts, 'no retries for an already-exists response' );
	}

	public function test_no_push_without_satisfied_consent_and_skip_is_logged(): void {
		$this->member_rule( [ 'consent_source' => 'checkbox' ] ); // No checkbox meta set.

		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		$this->assertSame( [], $this->posts, 'consent is a hard rule' );
		$this->assertNull( Registrations::get( $user_id, '101' ) );

		global $wpdb;
		$logged = json_encode( $wpdb->tables['wp_eex_log'] ?? [] ); // phpcs:ignore
		$this->assertStringContainsString( 'consent source', $logged );
		$this->assertStringContainsString( 'not satisfied', $logged );

		// The checkbox meta satisfies it.
		update_user_meta( $user_id, Consent::META_KEY, gmdate( 'Y-m-d\TH:i:s\Z' ) );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->assertCount( 1, $this->attendee_calls() );
		$this->assertStringStartsWith( 'checkbox:', (string) Registrations::get( $user_id, '101' )['consent'] );
	}

	public function test_suppressed_email_is_never_pushed_by_rule_backfill_or_manual(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );

		Suppression::add( 'jane@example.org', Suppression::ALL_EVENTS, 'manual' );

		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->assertSame( [], $this->posts, 'rule path suppressed' );

		$dry = ( new Backfill() )->dry_run( 'r1' );
		$this->assertSame( 0, $dry['count'], 'backfill suppressed' );

		$engine = new Engine();
		$this->assertSame( [], $engine->evaluate( $user_id, '' ), 'manual path suppressed' );
	}

	public function test_profile_opt_out_suppresses_immediately(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );

		// Simulate saving the profile with the opt-out ticked.
		$_POST['eex_opt_out_nonce']   = 'x';
		$_POST['eex_events_opt_out'] = '1';
		( new Consent() )->save_opt_out( $user_id );
		unset( $_POST['eex_opt_out_nonce'], $_POST['eex_events_opt_out'] );

		$this->assertTrue( Suppression::is_suppressed( 'jane@example.org', '101' ) );

		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->assertSame( [], $this->posts );
	}

	public function test_suppression_wins_even_between_queue_and_delivery(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );

		( new Triggers() )->on_add_role( $user_id, 'member' );

		// Suppressed after queueing, before the job runs.
		Suppression::add( 'jane@example.org' );
		$this->run_queue();

		$this->assertSame( [], $this->posts, 'push-time re-check wins' );
	}

	public function test_dedupes_against_woo_purchase_via_shared_ledger_and_attribution(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );

		// A Woo purchase already registered her (shared ledger write).
		Registrations::record_woo_purchase( 'jane@example.org', '101', '9001', 501 );

		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->assertSame( [], $this->posts, 'ledger dedupe' );

		// And a fresh user known only through attribution (webhook path).
		$user_b = eex_test_create_user( 'sam', 'sam@example.org', [ 'member' ] );
		Attribution::insert(
			[
				'hs_id'      => '7002',
				'email_hash' => hash( 'sha256', 'sam@example.org' ),
			],
			'101',
			'completed'
		);

		( new Triggers() )->on_add_role( $user_b, 'member' );
		$this->run_queue();
		$this->assertSame( [], $this->posts, 'attribution dedupe' );
		$this->assertSame( 'done', Registrations::get( $user_b, '101' )['status'], 'external registration recorded to the ledger' );
	}

	public function test_backfill_dry_run_matches_confirmed_run_in_batches(): void {
		$this->member_rule();

		// 25 members (more than one batch), 5 excluded by various gates.
		for ( $i = 1; $i <= 25; $i++ ) {
			eex_test_create_user( 'member' . $i, "member{$i}@example.org", [ 'member' ] );
		}
		eex_test_create_user( 'nonmember', 'nonmember@example.org', [ 'subscriber' ] );
		$suppressed = eex_test_create_user( 'suppressed', 'suppressed@example.org', [ 'member' ] );
		Suppression::add( 'suppressed@example.org' );
		$registered = eex_test_create_user( 'registered', 'registered@example.org', [ 'member' ] );
		Registrations::record( $registered, '101', [ 'status' => 'done' ] );

		$backfill = new Backfill();
		$dry      = $backfill->dry_run( 'r1' );
		$this->assertSame( 25, $dry['count'] );
		$this->assertCount( 10, $dry['sample'] );

		$queued = $backfill->confirm( 'r1' );
		$this->assertSame( 25, $queued );

		// Run batches to completion (each batch schedules pushes; run both queues).
		$pusher = new Pusher();
		for ( $i = 0; $i < 10; $i++ ) {
			$batches                     = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_accounts_backfill' === $e['hook'] );
			\EEX_Test_State::$scheduled = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_accounts_backfill' !== $e['hook'] ) );
			foreach ( $batches as $batch ) {
				$backfill->run_batch( ...$batch['args'] );
			}
			$this->run_queue();
			if ( empty( $batches ) ) {
				break;
			}
		}

		$this->assertCount( 25, $this->attendee_calls(), 'confirmed run pushes exactly the dry-run count' );
		$this->assertNull( Backfill::progress( 'r1' ), 'state cleared on completion' );
	}

	public function test_backfill_is_resumable_from_persisted_state(): void {
		$this->member_rule();
		for ( $i = 1; $i <= 30; $i++ ) {
			eex_test_create_user( 'member' . $i, "m{$i}@example.org", [ 'member' ] );
		}

		$backfill = new Backfill();
		$backfill->confirm( 'r1' );

		// Process exactly one batch, then simulate a lost queue.
		$backfill->run_batch( 'r1' );
		\EEX_Test_State::$scheduled = [];

		$progress = Backfill::progress( 'r1' );
		$this->assertSame( 20, $progress['position'] );
		$this->assertSame( 30, $progress['total'] );

		// Resume picks up where it left off.
		$this->assertTrue( $backfill->resume( 'r1' ) );
		$batches = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_accounts_backfill' === $e['hook'] );
		$this->assertNotEmpty( $batches );
	}

	public function test_listing_published_registers_owner_once_and_unpublish_pushes_nothing(): void {
		add_filter( 'eex_mylisting_present', '__return_true' );
		add_filter(
			'eex_mylisting_detection_override',
			static fn() => [
				'confident'     => true,
				'post_type'     => 'job_listing',
				'type_meta_key' => '_case27_listing_type',
				'types'         => [
					[
						'slug'   => 'vendor',
						'label'  => 'Vendor',
						'fields' => [ [ 'key' => 'k', 'label' => 'K' ] ],
					],
				],
			]
		);

		Rules::save(
			[
				'r1' => Rules::normalise(
					'r1',
					[
						'enabled'        => 1,
						'trigger'        => 'listing_published',
						'listing_types'  => [ 'vendor' ],
						'connection'     => 'c1',
						'event'          => '101',
						'ticket'         => 'T-FREE',
						'consent_source' => 'assertion',
					]
				),
			]
		);
		Consent::set_assertion( true, 'admin' );

		$owner_id   = eex_test_create_user( 'vendor', 'vendor@example.org', [ 'subscriber' ] );
		$listing_id = wp_insert_post(
			[
				'post_type'   => 'job_listing',
				'post_status' => 'publish',
				'post_title'  => 'My stand',
				'post_author' => $owner_id,
				'meta_input'  => [ '_case27_listing_type' => 'vendor' ],
			]
		);

		$triggers = new Triggers();
		$post     = get_post( $listing_id );

		$triggers->on_listing_transition( 'publish', 'pending', $post );
		$triggers->on_listing_transition( 'publish', 'pending', $post ); // Duplicate fire.
		$this->run_queue();

		$this->assertCount( 1, $this->attendee_calls(), 'owner registered once' );

		// Unpublish: hook fires, nothing pushed, registration stays.
		$this->posts = [];
		$triggers->on_listing_transition( 'draft', 'publish', $post );
		$this->run_queue();

		$this->assertSame( [], $this->posts );
		$this->assertSame( 1, did_action( 'eex_listing_unpublished_after_registration' ) );
		$this->assertSame( 'done', Registrations::get( $owner_id, '101' )['status'] );
	}

	public function test_ticket_price_rides_in_the_create_body(): void {
		// The spec's AttendeeCreateRequest carries ticket_price_id: one POST
		// to the nested attendee-create route, nothing else.
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		$this->assertCount( 1, $this->posts, 'exactly one write' );
		$this->assertStringContainsString( 'events/101/attendees/', $this->posts[0]['url'], 'the documented nested route' );
		$this->assertSame( 'T-FREE', $this->posts[0]['body']['ticket_price_id'] );
		$this->assertArrayNotHasKey( 'event', $this->posts[0]['body'], 'the event travels in the path' );
	}

	public function test_existing_attendee_still_gets_the_ticket_via_idempotent_attach(): void {
		// Create answers "already exists" -> the attendee is found by the
		// documented ?email= filter and the ticket attached through the
		// documented-idempotent attach endpoint (docs/decisions.md D45).
		$this->member_rule();

		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => (array) json_decode( (string) ( $args['body'] ?? '' ), true ),
				];

				if ( str_contains( $url, '/tickets/' ) ) {
					return self::json_response( [ 'id' => 61001 ], 200 );
				}

				return self::json_response( [ 'email' => [ 'attendee with this email already exists.' ] ], 400 );
			}

			if ( str_contains( $url, 'attendees/' ) && str_contains( $url, 'email=' ) ) {
				return self::json_response( [ 'results' => [ [ 'id' => 61001, 'email' => 'jane@example.org' ] ] ] ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		$record = Registrations::get( $user_id, '101' );
		$this->assertSame( 'done', $record['status'] );
		$this->assertSame( '61001', (string) $record['attendee_hs_id'], 'the existing attendee was found by email' );

		$attach_calls = array_values( array_filter( $this->posts, static fn( $p ) => str_contains( $p['url'], '/tickets/' ) ) );
		$this->assertCount( 1, $attach_calls, 'ticket attached idempotently' );
		$this->assertStringContainsString( 'events/101/attendees/61001/tickets/', $attach_calls[0]['url'] );
		$this->assertSame( 'T-FREE', $attach_calls[0]['body']['ticket_price_id'] );
	}

	public function test_failed_push_retries_then_flags_user(): void {
		$this->member_rule();

		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [ 'url' => $url, 'body' => [] ];

				return self::json_response( [], 500 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		$this->assertCount( 3, $this->posts, 'three attempts with backoff' );
		$this->assertSame( 'failed', Registrations::get( $user_id, '101' )['status'] );
		$this->assertNotSame( '', (string) get_user_meta( $user_id, '_eex_hs_push_failed', true ) );
		$this->assertArrayHasKey( 'accounts_push_failed', (array) get_option( 'eex_notices', [] ) );
	}

	public function test_role_loss_and_email_change_log_and_fire_hooks_only(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();
		$this->posts = [];

		$triggers = new Triggers();
		$triggers->on_remove_role( $user_id, 'member' );
		$this->assertSame( 1, did_action( 'eex_role_lost_after_registration' ) );

		$old             = clone $GLOBALS['eex_test_users'][ $user_id ];
		$GLOBALS['eex_test_users'][ $user_id ]->user_email = 'newjane@example.org';
		$triggers->on_profile_update( $user_id, $old );
		$this->assertSame( 1, did_action( 'eex_user_email_changed_after_registration' ) );

		$this->run_queue();
		$this->assertSame( [], $this->posts, 'neither event pushes anything' );
	}

	public function test_erasure_suppresses_and_notes_manual_removal(): void {
		$result = ( new Privacy() )->erase( 'gone@example.org' );

		$this->assertTrue( Suppression::is_suppressed( 'gone@example.org', '101' ) );
		$this->assertStringContainsString( 'manual step', strtolower( implode( ' ', $result['messages'] ) ) );
		$this->assertStringContainsString( 'suppression', strtolower( implode( ' ', $result['messages'] ) ) );
	}

	public function test_account_pushes_record_attribution_for_dashboard_and_digest(): void {
		$this->member_rule();
		$user_id = eex_test_create_user( 'jane', 'jane@example.org', [ 'member' ] );
		( new Triggers() )->on_add_role( $user_id, 'member' );
		$this->run_queue();

		global $wpdb;
		$rows = $wpdb->tables['wp_eex_attribution'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'account-rule', $rows[0]['utm_source'] );
		$this->assertSame( '101', $rows[0]['event_hs_id'] );
		$this->assertSame( 1, did_action( 'eex_account_pushed' ) );
	}

	public function test_module_disabled_loads_zero_code(): void {
		// The whole rest of the suite runs with Plugin::boot gating on the
		// toggle; here we assert the gate itself.
		Options::update_settings( [ 'accounts_enabled' => 0 ] );

		$this->assertFalse( (bool) Options::setting( 'accounts_enabled' ) );

		// Plugin::boot only calls Accounts\Module::register() inside the
		// toggle check (asserted structurally: the gate reads the single
		// autoloaded option, so zero extra queries either way).
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringContainsString( "if ( ! \$lite && (bool) Options::setting( 'accounts_enabled' ) )", $source, 'the gate reads the mode and the toggle, both from the single autoloaded option' );
	}
}
