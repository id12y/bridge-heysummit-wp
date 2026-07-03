<?php
/**
 * MyListing bridge: detection gating, projection rules, canonical control.
 *
 * @package Emailexpert\Events\Tests
 */

namespace Emailexpert\Events\Tests\Unit;

use Emailexpert\Events\MyListing\Canonical;
use Emailexpert\Events\MyListing\Detection;
use Emailexpert\Events\MyListing\Module;
use Emailexpert\Events\MyListing\Projector;
use Emailexpert\Events\Tests\TestCase;

/**
 * @covers \Emailexpert\Events\MyListing\Module
 * @covers \Emailexpert\Events\MyListing\Detection
 * @covers \Emailexpert\Events\MyListing\Projector
 * @covers \Emailexpert\Events\MyListing\Canonical
 */
final class MyListingBridgeTest extends TestCase {

	/**
	 * Install a confident fake detection and a sessions mapping.
	 */
	private function configure_bridge( array $overrides = [] ): void {
		add_filter(
			'eex_mylisting_detection_override',
			static fn() => [
				'confident'     => true,
				'post_type'     => 'job_listing',
				'type_meta_key' => '_case27_listing_type',
				'types'         => [
					[
						'id'         => 5,
						'slug'       => 'event-listing',
						'label'      => 'Event listing',
						'fields'     => [
							[ 'key' => 'job_date', 'label' => 'Date', 'type' => 'date' ],
							[ 'key' => 'job_link', 'label' => 'Link', 'type' => 'url' ],
						],
						'taxonomies' => [ 'job_listing_category' ],
					],
				],
			]
		);

		update_option(
			'eex_mylisting',
			[
				'sessions' => array_merge(
					[
						'enabled'       => 1,
						'listing_type'  => 'event-listing',
						'canonical'     => 'eex',
						'listings_only' => 0,
						'map'           => [
							'title'        => 'post',
							'description'  => 'post',
							'starts_at'    => 'job_date',
							'register_url' => 'job_link',
							'categories'   => 'job_listing_category',
						],
					],
					$overrides
				),
			]
		);
	}

	private function make_talk( array $meta = [], string $status = 'publish' ): int {
		return wp_insert_post(
			[
				'post_type'   => 'eex_talk',
				'post_status' => $status,
				'post_title'  => 'Bridged session',
				'meta_input'  => $meta + [
					'_eex_heysummit_id' => '9001',
					'_eex_starts_at'    => '2026-08-01T15:00:00Z',
					'_eex_description'  => 'A projected session.',
					'_eex_talk_url'     => 'https://hub.example/talks/1',
				],
			]
		);
	}

	public function test_unconfident_detection_disables_bridge_and_projects_nothing(): void {
		add_filter( 'eex_mylisting_detection_override', static fn() => [ 'confident' => false ] );
		$this->configure_bridge(); // Overridden by the earlier filter (first added wins last? both run; ensure order).
		remove_all_filters( 'eex_mylisting_detection_override' );
		add_filter( 'eex_mylisting_detection_override', static fn() => [ 'confident' => false ] );

		$this->make_talk();

		$counts = ( new Projector() )->project_all();

		$this->assertSame( [], $counts );
		$this->assertCount( 0, get_posts( [ 'post_type' => 'job_listing', 'post_status' => 'any' ] ) );
	}

	public function test_projection_creates_listing_with_mapped_fields_only(): void {
		$this->configure_bridge();
		$talk_id = $this->make_talk();
		wp_insert_term( 'Deliverability', 'eex_category' );
		wp_set_object_terms( $talk_id, [ 'deliverability' ], 'eex_category' );

		( new Projector() )->project_all();

		$listings = get_posts( [ 'post_type' => 'job_listing', 'post_status' => 'any' ] );
		$this->assertCount( 1, $listings );
		$listing = $listings[0];

		$this->assertSame( 'Bridged session', $listing->post_title );
		$this->assertSame( 'A projected session.', $listing->post_content );
		$this->assertSame( 'publish', $listing->post_status );
		$this->assertSame( '2026-08-01T15:00:00Z', get_post_meta( $listing->ID, '_job_date', true ) );
		$this->assertSame( 'https://hub.example/talks/1', get_post_meta( $listing->ID, '_job_link', true ) );
		$this->assertSame( 'event-listing', get_post_meta( $listing->ID, '_case27_listing_type', true ) );
		$this->assertSame( [ 'Deliverability' ], \EEX_Test_State::$object_terms[ $listing->ID ]['job_listing_category'] ?? [] );

		// Reciprocal linkage.
		$this->assertSame( $listing->ID, (int) get_post_meta( $talk_id, '_eex_mylisting_id', true ) );
		$this->assertSame( $talk_id, (int) get_post_meta( $listing->ID, '_eex_source_post_id', true ) );

		// Unmapped fields are not written.
		$this->assertSame( '', (string) get_post_meta( $listing->ID, '_replay_url', true ) );
	}

	public function test_projection_is_hash_idempotent(): void {
		$this->configure_bridge();
		$this->make_talk();

		$projector = new Projector();
		$projector->project_all();
		$writes = \EEX_Test_State::$post_write_count;

		$projector->project_all();

		$this->assertSame( $writes, \EEX_Test_State::$post_write_count, 'second projection writes nothing' );
	}

	public function test_pending_source_produces_pending_listing(): void {
		$this->configure_bridge();
		$this->make_talk( [], 'pending' );

		( new Projector() )->project_all();

		$listing = get_posts( [ 'post_type' => 'job_listing', 'post_status' => 'any' ] )[0];
		$this->assertSame( 'pending', $listing->post_status );
	}

	public function test_detached_source_stops_updating_its_listing(): void {
		$this->configure_bridge();
		$talk_id = $this->make_talk();

		$projector = new Projector();
		$projector->project_all();

		$listing_id = (int) get_post_meta( $talk_id, '_eex_mylisting_id', true );
		wp_update_post(
			[
				'ID'         => $listing_id,
				'post_title' => 'Hand-styled listing',
			]
		);

		update_post_meta( $talk_id, '_eex_sync_mode', 'detached' );
		wp_update_post(
			[
				'ID'         => $talk_id,
				'post_title' => 'Source changed',
			]
		);
		$projector->project_all();

		$this->assertSame( 'Hand-styled listing', get_post( $listing_id )->post_title );
	}

	public function test_excluded_source_drafts_its_listing(): void {
		$this->configure_bridge();
		$talk_id = $this->make_talk();

		$projector = new Projector();
		$projector->project_all();
		$listing_id = (int) get_post_meta( $talk_id, '_eex_mylisting_id', true );

		update_post_meta( $talk_id, '_eex_sync_mode', 'excluded' );
		$projector->project_all();

		$this->assertSame( 'draft', get_post_status( $listing_id ) );
	}

	public function test_orphaned_draft_source_mirrors_to_listing(): void {
		$this->configure_bridge();
		$talk_id = $this->make_talk();

		$projector = new Projector();
		$projector->project_all();
		$listing_id = (int) get_post_meta( $talk_id, '_eex_mylisting_id', true );

		wp_update_post(
			[
				'ID'          => $talk_id,
				'post_status' => 'draft',
			]
		);
		update_post_meta( $talk_id, '_eex_orphaned', 1 );
		$projector->project_all();

		$this->assertSame( 'draft', get_post_status( $listing_id ) );
	}

	public function test_canonical_default_eex_marks_listing_non_canonical(): void {
		$this->configure_bridge(); // canonical => eex.
		$talk_id = $this->make_talk();
		( new Projector() )->project_all();
		$listing_id = (int) get_post_meta( $talk_id, '_eex_mylisting_id', true );

		$canonical = new Canonical();

		// The listing points at the eex_ post; the eex_ post is canonical.
		$this->assertSame( get_permalink( $talk_id ), $canonical->canonical_partner_url( $listing_id ) );
		$this->assertSame( '', $canonical->canonical_partner_url( $talk_id ) );

		// Schema stays on the canonical (eex) side.
		$this->assertFalse( $canonical->suppress_schema( false, $talk_id ) );
	}

	public function test_canonical_listing_side_flips_rel_canonical_and_schema(): void {
		$this->configure_bridge( [ 'canonical' => 'listing' ] );
		$talk_id = $this->make_talk();
		( new Projector() )->project_all();
		$listing_id = (int) get_post_meta( $talk_id, '_eex_mylisting_id', true );

		$canonical = new Canonical();

		$this->assertSame( get_permalink( $listing_id ), $canonical->canonical_partner_url( $talk_id ) );
		$this->assertSame( '', $canonical->canonical_partner_url( $listing_id ) );

		// Schema is suppressed on the non-canonical eex side.
		$this->assertTrue( $canonical->suppress_schema( false, $talk_id ) );
	}

	public function test_module_config_never_leaves_canonical_unset(): void {
		update_option( 'eex_mylisting', [ 'sessions' => [ 'canonical' => 'nonsense' ] ] );

		$this->assertSame( 'eex', Module::config()['sessions']['canonical'] );
	}

	public function test_detection_logs_flagged_discovery(): void {
		add_filter(
			'eex_mylisting_detection_override',
			static fn() => [
				'confident' => true,
				'types'     => [
					[
						'slug'   => 't',
						'label'  => 'T',
						'fields' => [ [ 'key' => 'k', 'label' => 'K' ] ],
					],
				],
			]
		);

		Detection::get( true );

		global $wpdb;
		$logged = json_encode( $wpdb->tables['wp_eex_log'] ?? [] ); // phpcs:ignore
		$this->assertStringContainsString( 'discovery', $logged );
		$this->assertStringContainsString( 'MyListing', $logged );
	}

	public function test_manual_mapping_makes_detection_confident_and_bridge_usable(): void {
		// Automatic detection failed…
		remove_all_filters( 'eex_mylisting_detection_override' );
		add_filter( 'eex_mylisting_detection_override', static fn() => [ 'confident' => false ] );
		$this->assertFalse( (bool) \Emailexpert\Events\MyListing\Detection::get( true )['confident'] );

		// …so the operator maps the structure by hand (the helper form).
		$mapping = \Emailexpert\Events\Admin\BridgePage::parse_manual_mapping(
			'job_listing',
			'_case27_listing_type',
			"event | Event\nvenue | Venue",
			"job_date | Event date\njob_location | Location"
		);

		$this->assertNotNull( $mapping );
		\Emailexpert\Events\MyListing\Detection::save_manual( $mapping );

		$detection = \Emailexpert\Events\MyListing\Detection::get();
		$this->assertTrue( (bool) $detection['confident'], 'a manual mapping restores the bridge' );
		$this->assertSame( 'manual', $detection['source'] );
		$this->assertSame( 'job_listing', $detection['post_type'] );
		$this->assertSame( [ 'event', 'venue' ], array_column( $detection['types'], 'slug' ) );
		$this->assertSame( 'Event date', $detection['types'][0]['fields'][0]['label'] );

		// Discarding it returns to (still unconfident) automatic detection.
		\Emailexpert\Events\MyListing\Detection::save_manual( null );
		$this->assertFalse( (bool) \Emailexpert\Events\MyListing\Detection::get( true )['confident'] );
	}

	public function test_manual_mapping_parser_rejects_unusable_input_and_fills_defaults(): void {
		$this->assertNull( \Emailexpert\Events\Admin\BridgePage::parse_manual_mapping( '', '', 'event | Event', '' ), 'a post type is required' );
		$this->assertNull( \Emailexpert\Events\Admin\BridgePage::parse_manual_mapping( 'job_listing', '', '', '' ), 'at least one type line is required' );

		$mapping = \Emailexpert\Events\Admin\BridgePage::parse_manual_mapping( 'job_listing', '', 'Event Spaces', '' );
		$this->assertSame( '_case27_listing_type', $mapping['type_meta_key'], 'meta key defaults to the MyListing standard' );
		$this->assertSame( 'event-spaces', $mapping['types'][0]['slug'], 'bare lines become slug + label' );
		$this->assertSame( 'Event Spaces', $mapping['types'][0]['label'] );
		$this->assertSame( [], $mapping['fields'], 'fields are optional' );
	}
}
