<?php
/**
 * Account registration rules storage.
 *
 * @package Emailexpert\Events
 */

namespace Emailexpert\Events\Accounts;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-managed rules: trigger, conditions, target (connection + event +
 * ticket), consent source and notes. Stored in one non-autoloaded option.
 */
final class Rules {

	private const OPTION = 'eex_account_rules';

	public const TRIGGER_CONFIRMED = 'confirmed';
	public const TRIGGER_ROLE      = 'role_gained';
	public const TRIGGER_LISTING   = 'listing_published';

	/**
	 * All rules, normalised, keyed by rule ID.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all(): array {
		$rules = [];

		foreach ( (array) get_option( self::OPTION, [] ) as $id => $rule ) {
			if ( is_array( $rule ) ) {
				$rules[ (string) $id ] = self::normalise( (string) $id, $rule );
			}
		}

		return $rules;
	}

	/**
	 * One rule.
	 *
	 * @param string $rule_id Rule ID.
	 * @return array<string,mixed>|null
	 */
	public static function get( string $rule_id ): ?array {
		return self::all()[ $rule_id ] ?? null;
	}

	/**
	 * Enabled rules for a trigger.
	 *
	 * @param string $trigger Trigger key.
	 * @return array<string,array<string,mixed>>
	 */
	public static function for_trigger( string $trigger ): array {
		return array_filter(
			self::all(),
			static fn( array $rule ): bool => ! empty( $rule['enabled'] ) && $rule['trigger'] === $trigger
		);
	}

	/**
	 * Persist a full rule set (admin save).
	 *
	 * @param array<string,array<string,mixed>> $rules Rules keyed by ID.
	 */
	public static function save( array $rules ): void {
		update_option( self::OPTION, $rules, false );
	}

	/**
	 * Fill a rule with defaults.
	 *
	 * @param string              $id   Rule ID.
	 * @param array<string,mixed> $rule Raw rule.
	 * @return array<string,mixed>
	 */
	public static function normalise( string $id, array $rule ): array {
		return wp_parse_args(
			$rule,
			[
				'id'              => $id,
				'enabled'         => 0,
				'trigger'         => self::TRIGGER_CONFIRMED,
				// For the confirmed trigger: register|first_login|confirmed_action.
				'confirmed_point' => 'confirmed_action',
				// Trigger roles (role_gained) doubling as the role condition
				// allowlist for other triggers.
				'roles'           => [],
				'listing_types'   => [],
				'exclude_roles'   => [],
				'exclude_users'   => [],
				'connection'      => '',
				'event'           => '',
				'ticket'          => '',
				'consent_source'  => 'checkbox',
				'notes'           => '',
			]
		);
	}
}
