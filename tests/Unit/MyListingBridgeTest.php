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

	/**
	 * Create a MyListing listing-type post, optionally with stored config.
	 */
	private function make_listing_type( string $slug, string $label, $config = null, string $post_type = 'case27_listing_type' ): int {
		$id = wp_insert_post(
			[
				'post_type'   => $post_type,
				'post_title'  => $label,
				'post_name'   => $slug,
				'post_status' => 'publish',
			]
		);

		if ( null !== $config ) {
			update_post_meta( $id, 'case27-listing-type', $config );
		}

		return (int) $id;
	}

	public function test_a_listing_type_without_readable_fields_is_still_a_usable_type(): void {
		// This is the case that used to force the manual mapping: the type
		// post is perfectly readable, only its field config is not.
		remove_all_filters( 'eex_mylisting_detection_override' );
		$this->make_listing_type( 'event-listing', 'Event listing' );

		$detection = Detection::get( true );

		$this->assertTrue( (bool) $detection['confident'], 'a type with no field config is still projectable' );
		$this->assertSame( 'auto', $detection['source'] );
		$this->assertSame( [ 'event-listing' ], array_column( $detection['types'], 'slug' ) );
		$this->assertSame( 'Event listing', $detection['types'][0]['label'] );
	}

	public function test_field_config_is_read_from_json_and_from_a_serialised_or_nested_shape(): void {
		remove_all_filters( 'eex_mylisting_detection_override' );

		$this->make_listing_type( 'json-type', 'JSON type', wp_json_encode( [ 'fields' => [ [ 'key' => 'job_date', 'label' => 'Date' ] ] ] ) );
		$this->make_listing_type( 'nested-type', 'Nested type', wp_json_encode( [ 'settings' => [ 'form' => [ 'custom_fields' => [ [ 'key' => 'job_link', 'label' => 'Link' ] ] ] ] ] ) );

		$detection = Detection::get( true );
		$by_slug   = array_column( $detection['types'], null, 'slug' );

		$this->assertSame( 'job_date', $by_slug['json-type']['fields'][0]['key'] );
		$this->assertSame( 'job_link', $by_slug['nested-type']['fields'][0]['key'], 'a field list nested under unknown keys is still found' );
	}

	public function test_types_are_recovered_from_existing_listings_when_no_type_posts_exist(): void {
		// No case27-listing-type posts at all — the theme API and its stored
		// configuration are both unavailable. The listings themselves still
		// carry the slugs the site actually uses.
		remove_all_filters( 'eex_mylisting_detection_override' );

		foreach ( [ 'event-listing', 'venue', 'event-listing' ] as $slug ) {
			$listing_id = wp_insert_post(
				[
					'post_type'   => 'job_listing',
					'post_title'  => 'A listing',
					'post_status' => 'publish',
				]
			);
			update_post_meta( $listing_id, '_case27_listing_type', $slug );
			update_post_meta( $listing_id, 'job_tagline', 'Tagline' );
		}

		$detection = Detection::get( true );

		$this->assertTrue( (bool) $detection['confident'], 'real listings are enough to read the structure' );
		$this->assertSame( [ 'event-listing', 'venue' ], array_column( $detection['types'], 'slug' ) );
		$this->assertSame( 'Event listing', $detection['types'][0]['label'], 'slugs become readable labels' );
		$this->assertContains( 'job_tagline', array_column( $detection['types'][0]['fields'], 'key' ), 'field keys are harvested from real listings' );
		$this->assertNotContains( '_case27_listing_type', array_column( $detection['types'][0]['fields'], 'key' ), 'internal keys are not offered as mapping targets' );
	}

	public function test_type_posts_and_listings_are_merged_without_duplicates(): void {
		remove_all_filters( 'eex_mylisting_detection_override' );

		$this->make_listing_type( 'event-listing', 'Event listing', wp_json_encode( [ 'fields' => [ [ 'key' => 'job_date', 'label' => 'Date' ] ] ] ) );

		$listing_id = wp_insert_post(
			[
				'post_type'   => 'job_listing',
				'post_title'  => 'A listing',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $listing_id, '_case27_listing_type', 'event-listing' );

		$orphan_id = wp_insert_post(
			[
				'post_type'   => 'job_listing',
				'post_title'  => 'Another listing',
				'post_status' => 'publish',
			]
		);
		update_post_meta( $orphan_id, '_case27_listing_type', 'legacy-type' );

		$detection = Detection::get( true );
		$slugs     = array_column( $detection['types'], 'slug' );

		$this->assertSame( [ 'event-listing', 'legacy-type' ], $slugs, 'the configured type is not duplicated by its listings' );

		$by_slug = array_column( $detection['types'], null, 'slug' );
		$this->assertSame( 'job_date', $by_slug['event-listing']['fields'][0]['key'], 'the richer configured definition wins' );
	}

	public function test_detection_stays_unconfident_when_the_site_has_no_structure_at_all(): void {
		remove_all_filters( 'eex_mylisting_detection_override' );

		$detection = Detection::get( true );

		$this->assertFalse( (bool) $detection['confident'] );
		$this->assertNotEmpty( $detection['evidence'], 'the operator is told what was looked at' );
	}

	public function test_detection_heals_itself_once_the_site_becomes_readable(): void {
		remove_all_filters( 'eex_mylisting_detection_override' );

		$this->assertFalse( (bool) Detection::get( true )['confident'], 'nothing to read yet' );

		// A listing type is created later; the cached failure must not stick.
		$this->make_listing_type( 'event-listing', 'Event listing' );
		Detection::heal();

		$this->assertTrue( (bool) Detection::get()['confident'], 'the daily check recovers without anyone clicking retry' );
	}

	public function test_saving_a_listing_type_invalidates_the_cached_detection(): void {
		remove_all_filters( 'eex_mylisting_detection_override' );

		Detection::get( true );
		$this->assertFalse( (bool) Detection::get()['confident'] );

		$type_id = $this->make_listing_type( 'event-listing', 'Event listing' );
		Detection::on_saved_post( $type_id, get_post( $type_id ) );

		$this->assertTrue( (bool) Detection::get()['confident'], 'the cache is dropped when a listing type is saved' );
	}

	public function test_a_manual_mapping_for_a_post_type_that_no_longer_exists_is_not_used(): void {
		Detection::save_manual(
			[
				'post_type'     => 'gone_listing',
				'type_meta_key' => '_case27_listing_type',
				'types'         => [ [ 'slug' => 'event', 'label' => 'Event' ] ],
				'fields'        => [],
			]
		);

		// Without post_type_exists() the mapping is taken at face value.
		$this->assertSame( 'manual', Detection::get()['source'] );

		// With it, a mapping pointing at a post type that has gone is
		// ignored rather than left silently pointing at nothing.
		$GLOBALS['eex_test_registered_post_types'] = [ 'job_listing' ];
		$this->assertNull( Detection::manual(), 'a stale mapping does not keep the bridge aimed at a dead post type' );
		unset( $GLOBALS['eex_test_registered_post_types'] );

		Detection::save_manual( null );
	}

	public function test_either_spelling_of_the_listing_type_post_type_is_found(): void {
		// MyListing registers case27_listing_type; older themes used the
		// hyphenated name. Detection used to assume the hyphenated one and
		// found nothing on a site that registers only the underscored one.
		remove_all_filters( 'eex_mylisting_detection_override' );

		$GLOBALS['eex_test_registered_post_types'] = [ 'job_listing', 'case27-listing-type' ];
		$this->make_listing_type( 'legacy-type', 'Legacy type', null, 'case27-listing-type' );

		$this->assertSame( [ 'legacy-type' ], array_column( Detection::get( true )['types'], 'slug' ), 'the hyphenated spelling still works' );

		unset( $GLOBALS['eex_test_registered_post_types'] );
	}

	/**
	 * A detected type shaped like a real MyListing event type.
	 */
	private function event_type(): array {
		return [
			'slug'       => 'event-listing',
			'label'      => 'Event listing',
			'fields'     => [
				[ 'key' => 'job_start_date', 'label' => 'Start date', 'type' => 'date' ],
				[ 'key' => 'job_end_date', 'label' => 'End date', 'type' => 'date' ],
				[ 'key' => 'job_registration_url', 'label' => 'Registration link', 'type' => 'url' ],
				[ 'key' => 'job_website', 'label' => 'Website', 'type' => 'url' ],
				[ 'key' => 'job_tagline', 'label' => 'Tagline', 'type' => 'text' ],
			],
			'taxonomies' => [ 'job_listing_category', 'job_listing_region' ],
		];
	}

	public function test_structural_fields_are_suggested_without_needing_a_listing_type(): void {
		// Title, description and photo have exactly one possible target, so
		// leaving them unset was asking for a decision that does not exist.
		$map = \Emailexpert\Events\MyListing\Suggestions::map( 'speakers', null );

		$this->assertSame( 'post', $map['title'] );
		$this->assertSame( 'post', $map['description'] );
		$this->assertSame( '_thumbnail', $map['photo'] );
	}

	public function test_fields_are_matched_to_the_listing_types_own_fields(): void {
		$map = \Emailexpert\Events\MyListing\Suggestions::map( 'events', $this->event_type() );

		$this->assertSame( 'job_start_date', $map['starts_at'] );
		$this->assertSame( 'job_end_date', $map['ends_at'] );
		$this->assertSame( 'job_registration_url', $map['register_url'] );
		$this->assertSame( 'job_website', $map['event_url'] );
		$this->assertSame( 'job_listing_category', $map['categories'], 'the category taxonomy is preferred over the others' );
	}

	public function test_nothing_is_suggested_when_the_type_has_no_matching_field(): void {
		$bare = [ 'slug' => 'bare', 'label' => 'Bare', 'fields' => [ [ 'key' => 'job_tagline', 'label' => 'Tagline', 'type' => 'text' ] ], 'taxonomies' => [] ];
		$map  = \Emailexpert\Events\MyListing\Suggestions::map( 'events', $bare );

		$this->assertArrayNotHasKey( 'starts_at', $map, 'a date is never guessed onto an unrelated field' );
		$this->assertArrayNotHasKey( 'register_url', $map );
		$this->assertArrayNotHasKey( 'categories', $map, 'no taxonomy means no category suggestion' );
		$this->assertSame( 'post', $map['title'], 'the structural targets still stand' );
	}

	public function test_an_existing_mapping_is_never_overridden(): void {
		$map = \Emailexpert\Events\MyListing\Suggestions::map(
			'events',
			$this->event_type(),
			[ 'starts_at' => 'a_field_the_operator_chose', 'title' => 'post' ]
		);

		$this->assertArrayNotHasKey( 'starts_at', $map, 'the operator has already decided this one' );
		$this->assertArrayNotHasKey( 'title', $map );
		$this->assertSame( 'job_end_date', $map['ends_at'], 'the untouched fields are still suggested' );
	}

	public function test_each_source_is_matched_to_the_listing_type_that_fits_it(): void {
		$types = [
			[ 'slug' => 'event-listing', 'label' => 'Event listing' ],
			[ 'slug' => 'talk', 'label' => 'Conference session' ],
			[ 'slug' => 'speaker', 'label' => 'Speaker profile' ],
			[ 'slug' => 'venue', 'label' => 'Venue' ],
		];

		$this->assertSame( 'event-listing', \Emailexpert\Events\MyListing\Suggestions::listing_type( 'events', $types ) );
		$this->assertSame( 'talk', \Emailexpert\Events\MyListing\Suggestions::listing_type( 'sessions', $types ) );
		$this->assertSame( 'speaker', \Emailexpert\Events\MyListing\Suggestions::listing_type( 'speakers', $types ) );
	}

	public function test_no_listing_type_is_suggested_when_none_resembles_the_source(): void {
		$types = [ [ 'slug' => 'restaurant', 'label' => 'Restaurant' ], [ 'slug' => 'hotel', 'label' => 'Hotel' ] ];

		$this->assertSame( '', \Emailexpert\Events\MyListing\Suggestions::listing_type( 'events', $types ), 'a wrong guess here would project into the wrong type' );
	}

	public function test_two_source_fields_never_claim_the_same_listing_field(): void {
		// A session type with a recording field but no website field: the
		// generic word "url" in job_replay_url used to win Event URL too,
		// pointing two source fields at one target.
		$talk = [
			'slug'       => 'talk',
			'label'      => 'Conference session',
			'fields'     => [
				[ 'key' => 'job_replay_url', 'label' => 'Recording', 'type' => 'url' ],
				[ 'key' => 'job_registration_url', 'label' => 'Sign up', 'type' => 'url' ],
			],
			'taxonomies' => [],
		];

		$map = \Emailexpert\Events\MyListing\Suggestions::map( 'sessions', $talk );

		$this->assertSame( 'job_replay_url', $map['replay_url'], 'the strongest claim keeps the field' );
		$this->assertSame( 'job_registration_url', $map['register_url'] );
		$this->assertArrayNotHasKey( 'event_url', $map, 'the weaker claim goes unsuggested rather than doubling up' );
		$this->assertSame( count( array_unique( array_diff( $map, [ 'post' ] ) ) ), count( array_diff( $map, [ 'post' ] ) ), 'no target appears twice' );
	}

	public function test_a_field_already_taken_by_a_saved_choice_is_not_suggested_again(): void {
		$talk = [
			'slug'       => 'talk',
			'label'      => 'Talk',
			'fields'     => [ [ 'key' => 'job_replay_url', 'label' => 'Recording', 'type' => 'url' ] ],
			'taxonomies' => [],
		];

		$map = \Emailexpert\Events\MyListing\Suggestions::map( 'sessions', $talk, [ 'replay_url' => 'job_replay_url' ] );

		$this->assertArrayNotHasKey( 'event_url', $map, 'a field the operator already assigned is not offered elsewhere' );
	}
}
