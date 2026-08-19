<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Decides what happens when an ability is invoked.
 *
 * Order of precedence, most specific first:
 *   1. An explicit per-ability rule set by the admin.
 *   2. Learning mode — record everything, gate nothing (opt-in, off by default).
 *   3. A confirmed-readonly profile — nothing to approve.
 *   4. Everything else, including anything never observed — require approval.
 *
 * Rule 4 is the important one. An unprofiled ability is the one we know least
 * about, so it gets the strictest treatment rather than the loosest.
 */
final class Policy {

	public const OPTION = 'proviso_policy';

	public const AUTO    = 'auto';
	public const REQUIRE = 'require';
	public const BLOCK   = 'block';

	public const APPROVER_CAP   = 'cap';
	public const APPROVER_USERS = 'users';
	public const APPROVER_ROLES = 'roles';
	/** Values are prefixed: "user:12", "role:editor". */
	public const APPROVER_MIXED = 'mixed';

	public const ANY = 'any';
	public const ALL = 'all';

	/** @var array|null */
	private static $cache = null;

	public static function settings(): array {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			self::$cache = wp_parse_args(
				is_array( $stored ) ? $stored : array(),
				array(
					// When true, nothing is gated but everything is observed
					// and logged. Lets a site build profiles before switching
					// enforcement on.
					'learning_mode'    => false,
					// Auto-approve abilities confirmed readonly by observation.
					'auto_readonly'    => true,
					// Let an unobserved ability that claims to be read-only run
					// once, under observation, rather than gating every read.
					'trust_declared_readonly' => true,
					// What happens to an ability with no rule of its own.
					// Permissive by default: the plugin observes, logs and can
					// undo everything, and gating is something you turn on for
					// the abilities you care about rather than the posture the
					// whole site starts in.
					'default_decision' => self::AUTO,
					// Per-ability overrides: name => auto|require|block.
					'rules'            => array(),
					// Per-requester overrides: "requester_key|ability" => decision.
					// Only honoured for BOUND identities — see decide().
					'requester_rules'  => array(),
					// Minutes before an unanswered request expires. 0 = never.
					'timeout_minutes'  => 0,
					// Require approval when the caller cannot be identified.
					'gate_unresolved'  => true,
					// Refuse MCP tool calls that map to no registered ability.
					// Off by default: those tools are outside this plugin's
					// reach, so refusing them breaks working setups without
					// making anything safer. The coverage report names them so
					// the choice is informed rather than silent.
					'gate_unknown_transport' => false,
					// Default approver rule, used when an ability has no override.
					'approver_default' => self::default_approver_rule(),
					// Per-ability approver rules, keyed by ability name.
					'approver_rules'   => array(),
					// Capability required to reach the queue at all.
					'approve_cap'      => 'manage_options',
				)
			);
		}
		return self::$cache;
	}

	public static function update( array $changes ): void {
		$settings    = array_merge( self::settings(), $changes );
		self::$cache = $settings;
		update_option( self::OPTION, $settings, false );
	}

	public static function set_rule( string $ability, string $decision ): void {
		$s                      = self::settings();
		$s['rules'][ $ability ] = $decision;
		self::update( $s );
	}

	public static function clear_rule( string $ability ): void {
		$s = self::settings();
		unset( $s['rules'][ $ability ] );
		self::update( $s );
	}

	public static function set_requester_rule( string $requester_key, string $ability, string $decision ): void {
		$s = self::settings();
		$s['requester_rules'][ $requester_key . '|' . $ability ] = $decision;
		self::update( $s );
	}

	/**
	 * @param array|null $identity Resolved caller, or null to look it up.
	 * @return array{decision:string,reason:string}
	 */
	public static function decide( string $ability, ?array $identity = null ): array {
		$s        = self::settings();
		$identity = $identity ?? Identity::current();

		// Per-requester rules are the most specific thing we have, but they may
		// only be honoured for an identity that was actually authenticated. A
		// self-reported client name is trivially spoofed, so letting it grant
		// auto-approve would mean any caller could claim to be the trusted agent.
		$rule_key = ( $identity['key'] ?? '' ) . '|' . $ability;
		if ( isset( $s['requester_rules'][ $rule_key ] ) ) {
			$requested = $s['requester_rules'][ $rule_key ];
			$relaxing  = self::AUTO === $requested;

			if ( ! $relaxing || Identity::may_relax( $identity ) ) {
				return array(
					'decision' => $requested,
					'reason'   => sprintf(
						/* translators: %s: requester label. */
						__( 'Rule for this caller (%s).', 'proviso-ai' ),
						$identity['label'] ?? ''
					),
				);
			}

			return array(
				'decision' => self::REQUIRE,
				'reason'   => sprintf(
					/* translators: %s: requester label. */
					__( 'An auto-approve rule exists for "%s", but this caller is only self-reported and cannot be trusted to claim that identity.', 'proviso-ai' ),
					$identity['label'] ?? ''
				),
			);
		}

		if ( isset( $s['rules'][ $ability ] ) ) {
			return array(
				'decision' => $s['rules'][ $ability ],
				'reason'   => __( 'Explicit rule set by an administrator.', 'proviso-ai' ),
			);
		}

		if ( ! empty( $s['gate_unresolved'] ) && Identity::UNRESOLVED === ( $identity['tier'] ?? '' ) ) {
			return array(
				'decision' => self::REQUIRE,
				'reason'   => __( 'The calling client could not be identified.', 'proviso-ai' ),
			);
		}

		if ( ! empty( $s['learning_mode'] ) ) {
			return array(
				'decision' => self::AUTO,
				'reason'   => __( 'Learning mode: recording behaviour without gating.', 'proviso-ai' ),
			);
		}

		$profile = Profiles::get( $ability );

		// Irreversible effects — mail, outbound requests, filesystem — are the
		// one class observation cannot clear. The Recorder does not see them, so
		// a clean run history is not evidence of safety, and Rollback has
		// nothing to undo afterwards. Gate them regardless of how often they
		// have run without incident.
		$probe = SourceProbe::inspect( $ability );
		if ( SourceProbe::EMIT === $probe['kind'] ) {
			return array(
				'decision' => self::REQUIRE,
				'reason'   => $probe['reason'],
			);
		}

		if (
			! empty( $s['auto_readonly'] )
			&& true === $profile['readonly']
			&& Profiles::CONFIRMED === $profile['confidence']
		) {
			return array(
				'decision' => self::AUTO,
				'reason'   => sprintf(
					/* translators: %d: number of observed executions. */
					__( 'Observed %d times, never wrote anything.', 'proviso-ai' ),
					(int) $profile['observations']
				),
			);
		}

		$fallback = self::AUTO === ( $s['default_decision'] ?? self::AUTO ) ? self::AUTO : self::REQUIRE;

		if ( Profiles::UNKNOWN === $profile['confidence'] ) {
			$hint = self::cold_start_hint( $ability );

			// Marked provisional whenever an unproven ability is let through on
			// the strength of its own read-only claim. The Interceptor watches
			// for that flag and revokes the moment the claim turns out false, so
			// dropping it here would silently disable violation detection.
			if ( $hint['read'] ) {
				return array(
					'decision'    => self::AUTO,
					'provisional' => true,
					'reason'      => 'declared' === $hint['basis']
						? __( 'Declares itself read-only. Unverified — being checked as it runs.', 'proviso-ai' )
						: __( 'Looks read-only from its name and arguments. Unverified — being checked as it runs.', 'proviso-ai' ),
				);
			}

			return array(
				'decision' => $fallback,
				'reason'   => self::AUTO === $fallback
					? __( 'No rule set — allowed by default. Recorded, and undoable from the audit log.', 'proviso-ai' )
					: __( 'No rule set, and this ability has never been observed.', 'proviso-ai' ),
			);
		}

		if ( self::AUTO === $fallback ) {
			return array(
				'decision' => self::AUTO,
				'reason'   => $profile['operations']
					? sprintf(
						/* translators: %s: comma-separated list of operations. */
						__( 'No rule set — allowed by default. Observed: %s', 'proviso-ai' ),
						implode( ', ', $profile['operations'] )
					)
					: __( 'No rule set — allowed by default.', 'proviso-ai' ),
			);
		}

		return array(
			'decision' => self::REQUIRE,
			'reason'   => sprintf(
				/* translators: %s: comma-separated list of operations. */
				__( 'Observed writing: %s', 'proviso-ai' ),
				implode( ', ', $profile['operations'] ) ?: __( 'unclassified', 'proviso-ai' )
			),
		);
	}

	/**
	 * The read/write character of an ability, for display.
	 *
	 * Returns the classification and how confident we are in it, because a tag
	 * derived from a name guess must not look identical to one derived from
	 * three observed executions.
	 *
	 * @return array{kind:string,basis:string,confidence:string}
	 */
	public static function classify( string $ability ): array {
		$profile = Profiles::get( $ability );

		if ( false === $profile['readonly'] ) {
			return array( 'kind' => 'write', 'basis' => 'observed', 'confidence' => $profile['confidence'] );
		}

		if ( true === $profile['readonly'] ) {
			return array( 'kind' => 'read', 'basis' => 'observed', 'confidence' => $profile['confidence'] );
		}

		// Reading the callback beats reading its name: it judges the work rather
		// than the label. Ranked above the declared annotation because a plugin
		// author's `readonly` claim is a promise, while this is evidence.
		$probe = SourceProbe::inspect( $ability );
		if ( SourceProbe::EMIT === $probe['kind'] ) {
			return array( 'kind' => 'emit', 'basis' => 'source', 'confidence' => Profiles::UNKNOWN );
		}
		if ( SourceProbe::WRITE === $probe['kind'] ) {
			return array( 'kind' => 'write', 'basis' => 'source', 'confidence' => Profiles::UNKNOWN );
		}
		if ( SourceProbe::READ === $probe['kind'] ) {
			return array( 'kind' => 'read', 'basis' => 'source', 'confidence' => Profiles::UNKNOWN );
		}

		$hint = self::cold_start_hint( $ability );
		if ( $hint['read'] ) {
			return array( 'kind' => 'read', 'basis' => $hint['basis'] ?: 'heuristic', 'confidence' => Profiles::UNKNOWN );
		}

		$guess = Profiles::guess( $ability, Interceptor::input_schema( $ability ) );
		if ( ! empty( $guess['irreversible'] ) ) {
			return array( 'kind' => 'emit', 'basis' => 'heuristic', 'confidence' => Profiles::UNKNOWN );
		}
		if ( ! empty( $guess['writes'] ) ) {
			return array( 'kind' => 'write', 'basis' => 'heuristic', 'confidence' => Profiles::UNKNOWN );
		}

		return array( 'kind' => 'unknown', 'basis' => 'none', 'confidence' => Profiles::UNKNOWN );
	}

	/**
	 * Whether this ability is known to only read.
	 *
	 * Used to hide "require approval" for reads. Queuing a read is not merely
	 * unnecessary friction: the approval flow returns a status to the agent, not
	 * a payload, so an approved read would never deliver the data that was asked
	 * for. Allow or block are the only two answers that mean anything.
	 */
	public static function is_known_read( string $ability ): bool {
		$profile = Profiles::get( $ability );

		// Proven to write. Nothing else matters.
		if ( false === $profile['readonly'] ) {
			return false;
		}

		// Observed at least once and never wrote.
		if ( true === $profile['readonly'] ) {
			return true;
		}

		// Never observed: fall back to the same evidence the decision uses, so a
		// fresh install does not offer approval on every `get-*` ability just
		// because nothing has run yet. If the guess is wrong, the first execution
		// proves it, `readonly` flips to false, and approval becomes available.
		return self::cold_start_hint( $ability )['read'];
	}

	/** Decisions that make sense for this ability. */
	public static function available_decisions( string $ability ): array {
		if ( self::is_known_read( $ability ) ) {
			return array( self::AUTO, self::BLOCK );
		}

		return array( self::AUTO, self::REQUIRE, self::BLOCK );
	}

	/**
	 * Evidence that an unobserved ability is a read.
	 *
	 * Two sources, both weak on their own. The annotation is authoritative when
	 * present but core defaults it to null, so most abilities say nothing. The
	 * name/schema heuristic covers that gap using WordPress's strong naming
	 * conventions. Neither is trusted beyond the first execution.
	 *
	 * @return array{read:bool,basis:string}
	 */
	public static function cold_start_hint( string $ability ): array {
		$s = self::settings();

		if ( empty( $s['trust_declared_readonly'] ) ) {
			return array( 'read' => false, 'basis' => '' );
		}

		$obj = function_exists( 'wp_get_ability' ) ? wp_get_ability( $ability ) : null;

		if ( $obj ) {
			$annotations = (array) $obj->get_meta( 'annotations' );
			if ( true === ( $annotations['readonly'] ?? null ) ) {
				return array( 'read' => true, 'basis' => 'declared' );
			}
			// An explicit `false` settles it — do not second-guess with a guess.
			if ( false === ( $annotations['readonly'] ?? null ) ) {
				return array( 'read' => false, 'basis' => 'declared' );
			}
		}

		$guess = Profiles::guess( $ability, Interceptor::input_schema( $ability ) );

		return array(
			'read'  => 'read' === $guess['verb'],
			'basis' => 'heuristic',
		);
	}

	/**
	 * Who approves when nothing has been configured.
	 *
	 * Defined once and used both as the stored default and as the fallback when
	 * a stored rule is empty or predates this shape — two separate defaults is
	 * how "the default approver is the administrator" quietly becomes "nobody".
	 *
	 * @return array{type:string,values:array,quorum:string}
	 */
	public static function default_approver_rule(): array {
		return array(
			'type'   => self::APPROVER_MIXED,
			'values' => array( 'role:administrator' ),
			'quorum' => self::ANY,
		);
	}

	/**
	 * The approver rule that applies to an ability.
	 *
	 * @return array{type:string,values:array,quorum:string}
	 */
	public static function approver_rule( string $ability ): array {
		$s = self::settings();
		$r = $s['approver_rules'][ $ability ] ?? ( $s['approver_default'] ?? array() );

		$rule = wp_parse_args( is_array( $r ) ? $r : array(), self::default_approver_rule() );

		// A rule naming nobody is not a rule. This also upgrades settings stored
		// before mixed selection existed, where the default was an empty list.
		if ( empty( $rule['values'] ) ) {
			$rule = self::default_approver_rule();
		}

		return $rule;
	}

	public static function set_approver_rule( string $ability, string $type, array $values, string $quorum = self::ANY ): void {
		$s = self::settings();
		$s['approver_rules'][ $ability ] = array(
			'type'   => $type,
			'values' => array_values( $values ),
			'quorum' => self::ALL === $quorum ? self::ALL : self::ANY,
		);
		self::update( $s );
	}

	public static function clear_approver_rule( string $ability ): void {
		$s = self::settings();
		unset( $s['approver_rules'][ $ability ] );
		self::update( $s );
	}

	/**
	 * Everyone entitled to decide this ability's requests.
	 *
	 * Roles are expanded to concrete user IDs so that ALL-quorum has a definite
	 * denominator. That is also why a role with no members must not silently
	 * mean "nobody needs to approve" — see required_count().
	 *
	 * @return int[]
	 */
	public static function approvers_for( string $ability ): array {
		$rule = self::approver_rule( $ability );

		switch ( $rule['type'] ) {
			case self::APPROVER_MIXED:
				$ids = array();
				foreach ( (array) $rule['values'] as $entry ) {
					list( $kind, $value ) = array_pad( explode( ':', (string) $entry, 2 ), 2, '' );
					if ( 'user' === $kind ) {
						$ids[] = (int) $value;
					} elseif ( 'role' === $kind ) {
						foreach ( get_users( array( 'role' => $value, 'fields' => 'ID' ) ) as $uid ) {
							$ids[] = (int) $uid;
						}
					}
				}
				break;

			case self::APPROVER_USERS:
				$ids = array_map( 'intval', $rule['values'] );
				break;

			case self::APPROVER_ROLES:
				$ids = array();
				foreach ( (array) $rule['values'] as $role ) {
					foreach ( get_users( array( 'role' => (string) $role, 'fields' => 'ID' ) ) as $uid ) {
						$ids[] = (int) $uid;
					}
				}
				break;

			default:
				$ids = array_map(
					'intval',
					get_users( array( 'capability' => self::settings()['approve_cap'], 'fields' => 'ID' ) )
				);
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** How many distinct approvals are needed before the change runs. */
	public static function required_count( string $ability ): int {
		$rule      = self::approver_rule( $ability );
		$approvers = self::approvers_for( $ability );

		if ( self::ALL !== $rule['quorum'] ) {
			return 1;
		}

		// An empty approver set must never resolve to "zero approvals needed".
		return max( 1, count( $approvers ) );
	}

	/**
	 * Whether a user may decide requests — for one ability, or at all.
	 *
	 * @param string|null $ability Null checks general eligibility.
	 */
	public static function can_approve( int $user_id, ?string $ability = null ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$s = self::settings();

		// The capability is a floor that no approver rule can lower. Naming an
		// Editor as approver is not a way to hand them administrator powers.
		if ( ! user_can( $user_id, $s['approve_cap'] ) ) {
			return false;
		}

		if ( null === $ability ) {
			return true;
		}

		$approvers = self::approvers_for( $ability );

		return empty( $approvers ) || in_array( $user_id, $approvers, true );
	}
}
