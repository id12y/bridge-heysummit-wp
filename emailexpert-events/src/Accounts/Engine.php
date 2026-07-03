<?php
/**
 * Rule evaluation engine.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

use Emailexpert\Events\Logging\Logger;
use Emailexpert\Events\Registrations;
use Emailexpert\Events\Webhooks\Attribution;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates rules for a user and queues at most one push per event. The
 * order of gates is deliberate and non-negotiable: trigger/conditions,
 * exclusions, suppression + opt-out, consent, idempotency (shared
 * registration ledger plus attribution dedupe against the WooCommerce path
 * and webhook-recorded registrations). A skip at any gate is logged with
 * the reason. Backfill runs the identical `run_rule` gates (dry_run first),
 * so its dry-run count always matches what a confirmed run pushes.
 */
class Engine {

	/**
	 * Evaluate all enabled rules for a trigger against one user.
	 *
	 * @param int                 $user_id Trigger subject.
	 * @param string              $trigger Rules::TRIGGER_* key ('' = every trigger, manual push).
	 * @param array<string,mixed> $context confirmed_point, gained_roles, listing_type.
	 * @return array<string,string> Queued events: event_hs_id => rule ID.
	 */
	public function evaluate( int $user_id, string $trigger, array $context = [] ): array {
		$rules = '' === $trigger
			? array_filter( Rules::all(), static fn( array $rule ): bool => ! empty( $rule['enabled'] ) )
			: Rules::for_trigger( $trigger );

		$queued = [];

		foreach ( $rules as $rule ) {
			$event_hs_id = (string) $rule['event'];

			if ( '' === $event_hs_id || isset( $queued[ $event_hs_id ] ) ) {
				continue; // No target, or an earlier rule already owns this event in this evaluation.
			}

			if ( $this->run_rule( $user_id, $rule, $trigger, $context ) ) {
				$queued[ $event_hs_id ] = (string) $rule['id'];
			}
		}

		return $queued;
	}

	/**
	 * Run one rule's full gate chain for one user; queue the push unless
	 * dry-running. Returns whether a push was (or would be) queued.
	 *
	 * @param int                 $user_id Subject.
	 * @param array<string,mixed> $rule    Normalised rule.
	 * @param string              $trigger Trigger being evaluated ('' = manual/backfill-any).
	 * @param array<string,mixed> $context Trigger context.
	 * @param bool                $dry_run Check every gate but write and queue nothing.
	 */
	public function run_rule( int $user_id, array $rule, string $trigger, array $context = [], bool $dry_run = false ): bool {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$event_hs_id = (string) $rule['event'];
		if ( '' === $event_hs_id ) {
			return false;
		}

		$verdict = $this->qualifies( $user, $rule, $trigger, $context );

		if ( true !== $verdict ) {
			if ( '' !== $verdict && ! $dry_run ) {
				Logger::info(
					Logger::CONTEXT_SYNC,
					sprintf( 'Account rule %s skipped for user %d: %s', (string) $rule['id'], $user_id, $verdict ),
					[ 'rule' => (string) $rule['id'] ]
				);
			}

			return false;
		}

		$consent = Consent::satisfied( $user_id, (string) $rule['consent_source'] );

		// Consent is a hard rule: no satisfied source, no push, logged.
		if ( ! $consent['ok'] ) {
			if ( ! $dry_run ) {
				Logger::info(
					Logger::CONTEXT_SYNC,
					sprintf( 'Account rule %s skipped for user %d: consent source "%s" not satisfied.', (string) $rule['id'], $user_id, (string) $rule['consent_source'] ),
					[ 'rule' => (string) $rule['id'] ]
				);
			}

			return false;
		}

		if ( $dry_run ) {
			return true;
		}

		// Idempotency: write the pending record first — it is the lock,
		// exactly like the v2 order-item record.
		Registrations::record(
			$user_id,
			$event_hs_id,
			[
				'status'     => Registrations::STATUS_PENDING,
				'rule'       => (string) $rule['id'],
				'trigger'    => '' !== $trigger ? $trigger : 'manual',
				'consent'    => $consent['source'],
				'source'     => 'accounts',
				'connection' => (string) $rule['connection'],
			]
		);

		wp_schedule_single_event(
			time() - 1,
			'eex_accounts_push',
			[ $user_id, $event_hs_id, (string) $rule['id'], 1 ]
		);
		spawn_cron();

		return true;
	}

	/**
	 * Whether a rule fires for this user in this context. Returns true, or
	 * a skip reason ('' = silent skip for plain non-matches).
	 *
	 * @param \WP_User            $user    User.
	 * @param array<string,mixed> $rule    Rule.
	 * @param string              $trigger Trigger being evaluated ('' = manual: trigger matching is waived).
	 * @param array<string,mixed> $context Trigger context.
	 * @return true|string
	 */
	protected function qualifies( $user, array $rule, string $trigger, array $context ): bool|string {
		$user_id = (int) $user->ID;

		// Trigger-specific matching (waived for manual pushes).
		if ( Rules::TRIGGER_CONFIRMED === $trigger && (string) $rule['confirmed_point'] !== (string) ( $context['confirmed_point'] ?? '' ) ) {
			return '';
		}

		if ( Rules::TRIGGER_ROLE === $trigger ) {
			$gained  = array_map( 'strval', (array) ( $context['gained_roles'] ?? [] ) );
			$watched = array_map( 'strval', (array) $rule['roles'] );

			if ( empty( array_intersect( $gained, $watched ) ) ) {
				return '';
			}
		}

		if ( Rules::TRIGGER_LISTING === $trigger ) {
			$types = array_map( 'strval', (array) $rule['listing_types'] );

			if ( ! empty( $types ) && ! in_array( (string) ( $context['listing_type'] ?? '' ), $types, true ) ) {
				return '';
			}
		}

		// Condition: role allowlist (the role trigger already matched on the
		// gained role; every other path checks the user's current roles).
		if ( Rules::TRIGGER_ROLE !== $trigger && ! empty( $rule['roles'] ) ) {
			$user_roles = array_map( 'strval', (array) $user->roles );

			if ( empty( array_intersect( $user_roles, array_map( 'strval', (array) $rule['roles'] ) ) ) ) {
				return '';
			}
		}

		// Exclusions.
		if ( ! empty( array_intersect( array_map( 'strval', (array) $user->roles ), array_map( 'strval', (array) $rule['exclude_roles'] ) ) ) ) {
			return 'user holds an excluded role';
		}
		if ( in_array( $user_id, array_map( 'intval', (array) $rule['exclude_users'] ), true ) ) {
			return 'user is individually excluded';
		}

		// Suppression: checked before every push, overrides everything.
		if ( Suppression::is_suppressed( (string) $user->user_email, (string) $rule['event'] ) ) {
			return 'email is on the suppression list';
		}
		if ( '' !== (string) get_user_meta( $user_id, Consent::OPT_OUT_META_KEY, true ) ) {
			return 'user has opted out of event registration';
		}

		// Idempotency: the shared ledger, then the attribution table (which
		// covers WooCommerce purchases and webhook-recorded registrations).
		if ( Registrations::is_registered_or_pending( $user_id, (string) $rule['event'] ) ) {
			return '';
		}
		if ( Attribution::has_completed( Suppression::hash( (string) $user->user_email ), (string) $rule['event'] ) ) {
			// Registered through another path; record it so future checks are cheap.
			Registrations::record(
				$user_id,
				(string) $rule['event'],
				[
					'status'  => Registrations::STATUS_DONE,
					'source'  => 'attribution',
					'trigger' => 'external',
					'consent' => 'external',
					'rule'    => '',
					'note'    => 'Already registered via another path (attribution match).',
				]
			);

			return 'already registered via another path';
		}

		return true;
	}

	/**
	 * The synthetic trigger context a backfill uses so run_rule sees the
	 * same shape a live trigger would produce.
	 *
	 * @param int                 $user_id User.
	 * @param array<string,mixed> $rule    Rule.
	 * @return array<string,mixed>|null Null when the user cannot satisfy the
	 *                                  rule's trigger at all (e.g. no
	 *                                  published listing).
	 */
	public function backfill_context( int $user_id, array $rule ): ?array {
		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return null;
		}

		switch ( (string) $rule['trigger'] ) {
			case Rules::TRIGGER_ROLE:
				$matching = array_intersect( array_map( 'strval', (array) $user->roles ), array_map( 'strval', (array) $rule['roles'] ) );

				return empty( $matching ) ? null : [ 'gained_roles' => array_values( $matching ) ];

			case Rules::TRIGGER_LISTING:
				$type = $this->published_listing_type( $user_id, (array) $rule['listing_types'] );

				return null === $type ? null : [ 'listing_type' => $type ];

			default:
				return [ 'confirmed_point' => (string) $rule['confirmed_point'] ];
		}
	}

	/**
	 * The first published listing type a user owns among the watched types
	 * (any type when the watch list is empty).
	 *
	 * @param int      $user_id Owner.
	 * @param string[] $types   Watched listing types ([] = any).
	 * @return string|null
	 */
	private function published_listing_type( int $user_id, array $types ): ?string {
		if ( ! \Emailexpert\Events\MyListing\Module::detected() ) {
			return null;
		}

		$detection = \Emailexpert\Events\MyListing\Detection::get();
		if ( empty( $detection['confident'] ) ) {
			return null;
		}

		$listings = get_posts(
			[
				'post_type'      => (string) $detection['post_type'],
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 20,
				'no_found_rows'  => true,
			]
		);

		foreach ( $listings as $listing ) {
			$type = (string) get_post_meta( (int) $listing->ID, (string) $detection['type_meta_key'], true );

			if ( empty( $types ) || in_array( $type, array_map( 'strval', $types ), true ) ) {
				return $type;
			}
		}

		return null;
	}
}
