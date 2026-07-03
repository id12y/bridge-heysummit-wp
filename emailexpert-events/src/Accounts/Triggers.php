<?php
/**
 * Trigger hooks and confirmation adapters.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Registrations;

defined( 'ABSPATH' ) || exit;

/**
 * Wires WordPress events to the rules engine. "Account confirmed" is only
 * as strong as the configured point per rule: on registration, on first
 * login, or on the canonical `eex_user_confirmed` action, which the shipped
 * adapters fire for WooCommerce account creation, MyListing registration
 * and common email-verification plugins — and which any plugin can fire
 * itself: do_action( 'eex_user_confirmed', $user_id ).
 */
class Triggers {

	/**
	 * Candidate third-party confirmation hooks the adapters listen to.
	 * Hooking a name that never fires is a no-op, so candidates are cheap.
	 */
	private const CONFIRMATION_ADAPTERS = [
		'woocommerce_created_customer'     => 1, // WooCommerce account creation.
		'mylisting/register'               => 1, // MyListing registration (candidate name).
		'case27_register_user'             => 1, // MyListing registration (older candidate).
		'alg_wc_ev_user_account_activated' => 1, // Email Verification for WooCommerce.
		'wpum_user_email_verified'         => 1, // WP User Manager.
		'ur_user_email_confirmed'          => 1, // User Registration.
	];

	/**
	 * Engine.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Constructor.
	 */
	public function __construct( ?Engine $engine = null ) {
		$this->engine = $engine ?? new Engine();
	}

	/**
	 * Hook up.
	 */
	public function register(): void {
		// Confirmed trigger points.
		add_action( 'user_register', [ $this, 'on_register' ], 20 ); // After Consent::save_from_registration (priority 5).
		add_action( 'wp_login', [ $this, 'on_login' ], 10, 2 );
		add_action( 'eex_user_confirmed', [ $this, 'on_confirmed' ] );

		// Adapters: translate third-party confirmations to the canonical action.
		foreach ( array_keys( self::CONFIRMATION_ADAPTERS ) as $hook ) {
			add_action( $hook, [ $this, 'adapter_confirm' ] );
		}

		// Role changes.
		add_action( 'set_user_role', [ $this, 'on_set_role' ], 10, 3 );
		add_action( 'add_user_role', [ $this, 'on_add_role' ], 10, 2 );
		add_action( 'remove_user_role', [ $this, 'on_remove_role' ], 10, 2 );

		// Listings (evaluated only when the MyListing bridge is active).
		add_action( 'transition_post_status', [ $this, 'on_listing_transition' ], 10, 3 );

		// One-way limitation instrumentation.
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );
	}

	/**
	 * Registration: confirmed(point=register) plus role rules for roles
	 * assigned at creation.
	 *
	 * @param int $user_id New user ID.
	 */
	public function on_register( $user_id ): void {
		$user_id = (int) $user_id;

		$this->engine->evaluate( $user_id, Rules::TRIGGER_CONFIRMED, [ 'confirmed_point' => 'register' ] );

		$user = get_userdata( $user_id );
		if ( $user && ! empty( $user->roles ) ) {
			$this->engine->evaluate( $user_id, Rules::TRIGGER_ROLE, [ 'gained_roles' => (array) $user->roles ] );
		}
	}

	/**
	 * First successful login.
	 *
	 * @param string   $user_login Login name.
	 * @param \WP_User $user       User.
	 */
	public function on_login( $user_login, $user = null ): void {
		if ( ! $user || empty( $user->ID ) ) {
			return;
		}

		$user_id = (int) $user->ID;

		if ( '' !== (string) get_user_meta( $user_id, '_eex_first_login_at', true ) ) {
			return;
		}
		update_user_meta( $user_id, '_eex_first_login_at', gmdate( 'Y-m-d\TH:i:s\Z' ) );

		$this->engine->evaluate( $user_id, Rules::TRIGGER_CONFIRMED, [ 'confirmed_point' => 'first_login' ] );
	}

	/**
	 * The canonical confirmation action.
	 *
	 * @param int $user_id User ID.
	 */
	public function on_confirmed( $user_id ): void {
		$this->engine->evaluate( (int) $user_id, Rules::TRIGGER_CONFIRMED, [ 'confirmed_point' => 'confirmed_action' ] );
	}

	/**
	 * Adapter: third-party confirmation hooks re-fire the canonical action.
	 * Guards against loops (the canonical action itself is not adapted).
	 *
	 * @param mixed $user_id User ID (every adapted hook passes it first).
	 */
	public function adapter_confirm( $user_id ): void {
		$user_id = is_object( $user_id ) && isset( $user_id->ID ) ? (int) $user_id->ID : (int) $user_id;

		if ( $user_id > 0 ) {
			do_action( 'eex_user_confirmed', $user_id );
		}
	}

	/**
	 * Role replaced: evaluate gained roles; log and hook lost roles.
	 *
	 * @param int      $user_id   User ID.
	 * @param string   $role      New role.
	 * @param string[] $old_roles Previous roles.
	 */
	public function on_set_role( $user_id, $role, $old_roles = [] ): void {
		$user_id   = (int) $user_id;
		$old_roles = array_map( 'strval', (array) $old_roles );
		$gained    = array_diff( [ (string) $role ], $old_roles );
		$lost      = array_diff( $old_roles, [ (string) $role ] );

		if ( ! empty( $gained ) ) {
			$this->engine->evaluate( $user_id, Rules::TRIGGER_ROLE, [ 'gained_roles' => array_values( $gained ) ] );
		}

		if ( ! empty( $lost ) ) {
			$this->handle_role_loss( $user_id, array_values( $lost ) );
		}
	}

	/**
	 * Role added (additive).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Added role.
	 */
	public function on_add_role( $user_id, $role ): void {
		$this->engine->evaluate( (int) $user_id, Rules::TRIGGER_ROLE, [ 'gained_roles' => [ (string) $role ] ] );
	}

	/**
	 * Role removed (additive removal).
	 *
	 * @param int    $user_id User ID.
	 * @param string $role    Removed role.
	 */
	public function on_remove_role( $user_id, $role ): void {
		$this->handle_role_loss( (int) $user_id, [ (string) $role ] );
	}

	/**
	 * Role loss never removes the attendee (no removal endpoint in the
	 * write allowlist); it is logged and hooked for site-specific handling.
	 *
	 * @param int      $user_id    User ID.
	 * @param string[] $lost_roles Lost roles.
	 */
	private function handle_role_loss( int $user_id, array $lost_roles ): void {
		if ( empty( Registrations::all( $user_id ) ) ) {
			return;
		}

		Logger::info(
			Logger::CONTEXT_SYNC,
			sprintf( 'User %d lost role(s) %s after HeySummit registration; access is not revoked automatically.', $user_id, implode( ', ', $lost_roles ) )
		);

		/**
		 * A registered user lost a role. Event access is NOT revoked
		 * automatically; hook this for site-specific handling.
		 *
		 * @param int      $user_id    User ID.
		 * @param string[] $lost_roles Roles lost.
		 */
		do_action( 'eex_role_lost_after_registration', $user_id, $lost_roles );
	}

	/**
	 * Listing published / unpublished (MyListing bridge active only).
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_listing_transition( $new_status, $old_status, $post ): void {
		if ( ! \Emailexpert\Events\MyListing\Module::detected() ) {
			return;
		}

		$detection = \Emailexpert\Events\MyListing\Detection::get();

		if ( empty( $detection['confident'] ) || ( $post->post_type ?? '' ) !== (string) $detection['post_type'] ) {
			return;
		}

		$owner_id     = (int) ( $post->post_author ?? 0 );
		$listing_type = (string) get_post_meta( (int) $post->ID, (string) $detection['type_meta_key'], true );

		if ( 'publish' === $new_status && 'publish' !== $old_status && $owner_id > 0 ) {
			$this->engine->evaluate( $owner_id, Rules::TRIGGER_LISTING, [ 'listing_type' => $listing_type ] );

			return;
		}

		if ( 'publish' === $old_status && 'publish' !== $new_status && $owner_id > 0 && ! empty( Registrations::all( $owner_id ) ) ) {
			Logger::info(
				Logger::CONTEXT_SYNC,
				sprintf( 'Listing %d unpublished after its owner (user %d) was registered; access is not revoked automatically.', (int) $post->ID, $owner_id )
			);

			/**
			 * A registered user's listing was unpublished. Nothing is pushed
			 * and access is NOT revoked; hook for site-specific handling.
			 *
			 * @param int    $owner_id     Listing owner.
			 * @param int    $listing_id   Listing post ID.
			 * @param string $listing_type Listing type slug.
			 */
			do_action( 'eex_listing_unpublished_after_registration', $owner_id, (int) $post->ID, $listing_type );
		}
	}

	/**
	 * Email changes do not propagate to HeySummit (no verified update
	 * endpoint); log and hook.
	 *
	 * @param int      $user_id       User ID.
	 * @param \WP_User $old_user_data Pre-update user object.
	 */
	public function on_profile_update( $user_id, $old_user_data = null ): void {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );

		if ( ! $user || ! $old_user_data || (string) $user->user_email === (string) ( $old_user_data->user_email ?? '' ) ) {
			return;
		}

		if ( empty( Registrations::all( $user_id ) ) ) {
			return;
		}

		Logger::warning(
			Logger::CONTEXT_SYNC,
			sprintf( 'User %d changed their email after HeySummit registration; the change does NOT propagate to HeySummit.', $user_id ),
			[
				'old' => (string) ( $old_user_data->user_email ?? '' ),
				'new' => (string) $user->user_email,
			] // Redacted to hashes by the logger.
		);

		/**
		 * A registered user changed their email. HeySummit is NOT updated
		 * (one-way limitation); hook for site-specific handling.
		 *
		 * @param int    $user_id   User ID.
		 * @param string $old_email Previous address.
		 * @param string $new_email New address.
		 */
		do_action( 'eex_user_email_changed_after_registration', $user_id, (string) ( $old_user_data->user_email ?? '' ), (string) $user->user_email );
	}
}
