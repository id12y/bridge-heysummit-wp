<?php
/**
 * Forms bridge: capture gates, dedupe, questions, pusher, retries.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\Forms\Adapters\FluentForms;
use Emailexpert\Events\Forms\Adapters\GravityForms;
use Emailexpert\Events\Forms\Adapters\WpForms;
use Emailexpert\Events\Forms\Capture;
use Emailexpert\Events\Forms\Mappings;
use Emailexpert\Events\Forms\Pusher;
use Emailexpert\Events\Forms\Queue;
use Emailexpert\Events\Mappers\AttendeeRequestBuilder;
use Emailexpert\Events\Options;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\Forms\Capture
 * @covers \Emailexpert\Events\Forms\Mappings
 * @covers \Emailexpert\Events\Forms\Queue
 * @covers \Emailexpert\Events\Forms\Pusher
 * @covers \Emailexpert\Events\Mappers\AttendeeRequestBuilder
 */
final class FormsBridgeTest extends TestCase {

	/**
	 * POST calls captured by the HTTP mock.
	 *
	 * @var array<int,array{url:string,body:array}>
	 */
	private array $posts = [];

	protected function setUp(): void {
		parent::setUp();
		$this->posts = [];

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

		update_option(
			Mappings::OPTION,
			[
				[
					'id'            => 'fm_test1',
					'label'         => 'Summit interest form',
					'source'        => 'gravity',
					'form_id'       => '7',
					'connection'    => 'c1',
					'event'         => '101',
					'ticket'        => '9001',
					'email_field'   => '2',
					'name_field'    => '1.3',
					'consent_mode'  => 'field',
					'consent_field' => '5',
					'questions'     => [ '8' => 4711 ],
				],
			]
		);

		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => (array) json_decode( (string) ( $args['body'] ?? '' ), true ),
				];

				return self::json_response(
					[
						'id'    => 66001,
						'email' => 'lead@example.org',
					],
					201
				);
			}

			return self::json_response( [ 'results' => [] ] );
		} );
	}

	/**
	 * A consented Gravity Forms entry matching the fixture mapping.
	 *
	 * @return array<string,mixed>
	 */
	private function entry( array $overrides = [] ): array {
		// Union, not array_merge: GF-style numeric field keys must survive.
		return $overrides + [
			'1.3' => 'Ada',
			'1.6' => 'Lovelace',
			'2'   => 'lead@example.org',
			'5'   => 'I agree',
			'8'   => 'Analytical Engines Ltd',
		];
	}

	/**
	 * Run all queued eex_forms_push jobs (repeatedly, to follow retries).
	 */
	private function run_queue( Pusher $pusher ): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$jobs                        = array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_forms_push' === $e['hook'] );
			\EEX_Test_State::$scheduled = array_values( array_filter( \EEX_Test_State::$scheduled, static fn( $e ) => 'eex_forms_push' !== $e['hook'] ) );

			if ( empty( $jobs ) ) {
				return;
			}

			foreach ( $jobs as $job ) {
				$pusher->run_job( ...$job['args'] );
			}
		}
	}

	public function test_submission_pushes_allowlisted_path_with_ticket_and_questions(): void {
		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );

		$this->assertCount( 1, Queue::all(), 'one queued entry' );

		$this->run_queue( new Pusher() );

		$this->assertCount( 1, $this->posts );
		$this->assertStringContainsString( 'events/101/attendees/', $this->posts[0]['url'] );

		$body = $this->posts[0]['body'];
		$this->assertSame( 'Ada', $body['name'] );
		$this->assertSame( 'lead@example.org', $body['email'] );
		$this->assertSame( 9001, $body['ticket_price_id'] );
		$this->assertSame( [ [ 'question_id' => 4711, 'answer' => 'Analytical Engines Ltd' ] ], $body['questions'] );

		$this->assertSame( [], Queue::all(), 'entry (and the stored address) deleted on success' );
		$this->assertSame( 1, did_action( 'eex_forms_pushed' ) );

		// The attribution row is tagged with the form origin.
		global $wpdb;
		$rows = $wpdb->tables['wp_eex_attribution'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'form', $rows[0]['utm_source'] );
	}

	public function test_no_consent_means_no_queue_and_no_api_calls(): void {
		GravityForms::on_submission( $this->entry( [ '5' => '' ] ), [ 'id' => 7 ] );

		$this->assertSame( [], Queue::all() );
		$this->assertSame( [], $this->posts );

		// Explicit negatives do not consent either.
		GravityForms::on_submission( $this->entry( [ '5' => 'no' ] ), [ 'id' => 7 ] );
		$this->assertSame( [], Queue::all() );
	}

	public function test_implied_consent_mode_queues_without_a_consent_field(): void {
		$mapping                 = Mappings::get( 'fm_test1' );
		$mapping['consent_mode'] = 'implied';
		$mapping['consent_field'] = '';
		Mappings::save( [ $mapping ] );

		GravityForms::on_submission( $this->entry( [ '5' => '' ] ), [ 'id' => 7 ] );

		$this->assertCount( 1, Queue::all(), 'the operator declared submission itself as consent' );
	}

	public function test_suppressed_address_is_silently_skipped_at_capture(): void {
		\Emailexpert\Events\Accounts\Suppression::add( 'lead@example.org', '101', 'opt_out' );

		$result = Capture::capture( (array) Mappings::get( 'fm_test1' ), $this->entry() );

		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( [], Queue::all() );
		$this->assertSame( [], $this->posts );
	}

	public function test_suppression_recheck_at_delivery_wins_and_drops_the_entry(): void {
		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );
		$this->assertCount( 1, Queue::all() );

		// The opt-out lands between queueing and delivery.
		\Emailexpert\Events\Accounts\Suppression::add( 'lead@example.org', \Emailexpert\Events\Accounts\Suppression::ALL_EVENTS, 'erasure' );

		$this->run_queue( new Pusher() );

		$this->assertSame( [], $this->posts, 'no API call after the opt-out' );
		$this->assertSame( [], Queue::all(), 'entry deleted, address not retained' );
	}

	public function test_double_submission_dedupes_to_one_push(): void {
		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );
		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );

		$this->assertCount( 1, Queue::all() );

		$this->run_queue( new Pusher() );

		$this->assertCount( 1, $this->posts );
	}

	public function test_unmapped_form_and_invalid_email_produce_nothing(): void {
		GravityForms::on_submission( $this->entry(), [ 'id' => 99 ] );
		$this->assertSame( [], Queue::all(), 'no mapping for form 99' );

		GravityForms::on_submission( $this->entry( [ '2' => 'not-an-email' ] ), [ 'id' => 7 ] );
		$this->assertSame( [], Queue::all(), 'invalid email never queues' );
	}

	public function test_missing_name_falls_back_to_the_local_part(): void {
		GravityForms::on_submission( $this->entry( [ '1.3' => '' ] ), [ 'id' => 7 ] );
		$this->run_queue( new Pusher() );

		$this->assertSame( 'lead', $this->posts[0]['body']['name'] );
	}

	public function test_already_exists_error_is_success_and_deletes_the_entry(): void {
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => [],
				];

				return self::json_response( [ 'email' => [ 'attendee with this email already exists' ] ], 400 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );
		$this->run_queue( new Pusher() );

		$this->assertCount( 1, $this->posts, 'no retries: a duplicate is success' );
		$this->assertSame( [], Queue::all() );
	}

	public function test_failed_push_retries_three_times_with_backoff_then_flags(): void {
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => [],
				];

				return self::json_response( [ 'detail' => 'boom' ], 500 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );
		$pusher = new Pusher();
		$this->run_queue( $pusher );

		$this->assertCount( 3, $this->posts, 'three attempts with backoff' );

		$entries = array_values( Queue::all() );
		$this->assertCount( 1, $entries );
		$this->assertSame( 'failed', $entries[0]['status'] );
		$this->assertSame( 3, $entries[0]['attempts'] );

		// The API recovers; the Bridges screen retry re-queues and succeeds.
		remove_all_filters( 'pre_http_request' );
		$this->mock_http( function ( $url, $args ) {
			if ( 'POST' === ( $args['method'] ?? '' ) ) {
				$this->posts[] = [
					'url'  => $url,
					'body' => [],
				];

				return self::json_response( [ 'id' => 66002 ], 201 );
			}

			return self::json_response( [ 'results' => [] ] );
		} );

		$this->assertSame( 1, $pusher->retry_failed() );
		$this->run_queue( $pusher );

		$this->assertSame( [], Queue::all(), 'retried entry pushed and deleted' );
	}

	public function test_deleted_mapping_drops_the_queued_entry_instead_of_pushing(): void {
		GravityForms::on_submission( $this->entry(), [ 'id' => 7 ] );
		Mappings::save( [] );

		$this->run_queue( new Pusher() );

		$this->assertSame( [], $this->posts, 'no push without the mapping\'s event and consent context' );
		$this->assertSame( [], Queue::all() );
	}

	public function test_builder_drops_malformed_questions_and_omits_empty(): void {
		$request = AttendeeRequestBuilder::build(
			[
				'name'        => 'Ada',
				'email'       => 'lead@example.org',
				'event_hs_id' => '101',
				'questions'   => [
					[
						'question_id' => 4711,
						'answer'      => ' Yes ',
					],
					[
						'question_id' => 0,
						'answer'      => 'orphaned',
					],
					[ 'answer' => 'no id' ],
					[
						'question_id' => 4712,
						'answer'      => '',
					],
					'not-an-array',
				],
			]
		);

		$this->assertSame( [ [ 'question_id' => 4711, 'answer' => 'Yes' ] ], $request['body']['questions'] );

		$plain = AttendeeRequestBuilder::build(
			[
				'name'        => 'Ada',
				'email'       => 'lead@example.org',
				'event_hs_id' => '101',
			]
		);

		$this->assertArrayNotHasKey( 'questions', $plain['body'], 'no questions key when there are none' );
	}

	public function test_wpforms_and_fluent_adapters_normalise_their_field_shapes(): void {
		$wpforms_mapping = [
			'id'           => 'fm_wp1',
			'label'        => 'WPForms',
			'source'       => 'wpforms',
			'form_id'      => '31',
			'connection'   => 'c1',
			'event'        => '101',
			'ticket'       => '',
			'email_field'  => '1',
			'name_field'   => '2',
			'consent_mode' => 'implied',
			'questions'    => [],
		];
		$fluent_mapping  = [
			'id'           => 'fm_fl1',
			'label'        => 'Fluent',
			'source'       => 'fluent',
			'form_id'      => '12',
			'connection'   => 'c1',
			'event'        => '101',
			'ticket'       => '',
			'email_field'  => 'email',
			'name_field'   => 'names',
			'consent_mode' => 'implied',
			'questions'    => [],
		];
		Mappings::save( [ $wpforms_mapping, $fluent_mapping ] );

		WpForms::on_complete(
			[
				1 => [
					'value' => 'wp@example.org',
					'name'  => 'Email',
				],
				2 => [ 'value' => 'Wilma Woo' ],
			],
			[],
			[ 'id' => 31 ],
			77
		);

		FluentForms::on_inserted( 5, [
			'email' => 'fluent@example.org',
			'names' => [ 'first_name' => 'Fred' ],
		], (object) [ 'id' => 12 ] );

		$this->assertCount( 2, Queue::all() );

		$this->run_queue( new Pusher() );

		$emails = array_map( static fn( $p ) => $p['body']['email'] ?? '', $this->posts );
		sort( $emails );
		$this->assertSame( [ 'fluent@example.org', 'wp@example.org' ], $emails );

		$fluent_call = array_values( array_filter( $this->posts, static fn( $p ) => 'fluent@example.org' === ( $p['body']['email'] ?? '' ) ) )[0];
		$this->assertSame( 'Fred', $fluent_call['body']['name'], 'array field values flatten' );
	}

	public function test_queue_cap_refuses_new_entries_when_full(): void {
		$entries = [];
		for ( $i = 0; $i < Queue::MAX_ENTRIES; $i++ ) {
			$entries[ 'e' . $i ] = [ 'status' => 'pending' ];
		}
		update_option( Queue::OPTION, $entries );

		$result = Capture::capture( (array) Mappings::get( 'fm_test1' ), $this->entry() );

		$this->assertSame( 'duplicate', $result['status'] );
		$this->assertCount( Queue::MAX_ENTRIES, Queue::all(), 'nothing added past the cap' );
	}

	public function test_sanitise_row_parses_question_lines_and_rejects_unusable_rows(): void {
		$row = Mappings::sanitise_row(
			[
				'label'         => 'New form',
				'source'        => 'wpforms',
				'form_id'       => '9',
				'connection'    => 'c1',
				'event'         => '202',
				'ticket'        => '9002',
				'email_field'   => '1',
				'name_field'    => '2',
				'consent_mode'  => 'field',
				'consent_field' => '3',
				'questions'     => "4 | 4711\n5 | not-numeric\n | 99\n6|4712",
			]
		);

		$this->assertNotNull( $row );
		$this->assertStringStartsWith( 'fm_', $row['id'] );
		$this->assertSame(
			[
				'4' => 4711,
				'6' => 4712,
			],
			$row['questions']
		);

		$this->assertNull( Mappings::sanitise_row( [ 'source' => 'wpforms', 'event' => '202' ] ), 'no email field, no mapping' );
		$this->assertNull( Mappings::sanitise_row( [ 'source' => 'carrier-pigeon', 'event' => '202', 'email_field' => '1' ] ), 'unknown source rejected' );
	}
}
