<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The chokepoint.
 *
 * WP_Ability::execute() offers no way to deny: `wp_before_execute_ability` is a
 * do_action with no return value, and gating on permission_callback is wrong —
 * the REST run controller calls it as its HTTP permission check, so a pending
 * approval would surface as a flat 403 and tell the agent it lacks permission,
 * which is a lie it will act on.
 *
 * So interception happens at registration instead. `wp_register_ability_args`
 * hands us the arguments before WP_Ability is constructed, letting us keep the
 * original execute_callback and substitute our own. Returning a WP_Error from
 * it short-circuits before output validation, so a "pending approval" response
 * does not have to satisfy the ability's output schema.
 */
final class Interceptor {

	/** @var array<string,callable> Original callbacks, by ability name. */
	private static $originals = array();

	/** @var array<string,array> Input schemas, kept for preview rendering. */
	private static $schemas = array();

	/** @var bool True while replaying an approved request. */
	private static $replaying = false;

	public static function boot(): void {
		add_filter( 'wp_register_ability_args', array( self::class, 'wrap' ), 5, 2 );
	}

	/**
	 * @param array  $args Ability registration arguments.
	 * @param string $name Ability name, with namespace.
	 */
	public static function wrap( $args, $name ) {
		if ( ! is_array( $args ) || ! isset( $args['execute_callback'] ) || ! is_callable( $args['execute_callback'] ) ) {
			return $args;
		}

		// Never govern our own abilities — that would deadlock the queue.
		if ( 0 === strpos( (string) $name, 'mag/' ) ) {
			return $args;
		}

		self::$originals[ $name ] = $args['execute_callback'];
		self::$schemas[ $name ]   = self::normalize_schema( $args['input_schema'] ?? array() );

		$args['execute_callback'] = static function ( $input = null ) use ( $name ) {
			return self::gate( (string) $name, $input );
		};

		return $args;
	}

	/**
	 * Decide, then either run or defer.
	 *
	 * @param mixed $input
	 * @return mixed|\WP_Error
	 */
	private static function gate( string $name, $input ) {
		// A replay is an already-approved execution; do not gate it again.
		if ( self::$replaying ) {
			return self::invoke( $name, $input );
		}

		// The wire gate may have judged this same call already, when a plugin
		// serves MCP and then dispatches through the ability. Deciding twice
		// would queue one change as two requests and ask for two approvals.
		if ( Transport::already_decided( $name ) ) {
			return self::invoke( $name, $input );
		}

		$identity = Identity::current();
		$verdict  = Policy::decide( $name, $identity );

		if ( Policy::BLOCK === $verdict['decision'] ) {
			AuditLog::record( $name, 'block', 'blocked', null, $input );
			return new \WP_Error(
				'mag_blocked',
				sprintf(
					/* translators: %s: ability name. */
					__( 'The ability "%s" is blocked by site policy. Do not retry, and do not attempt the same change through another ability.', 'kevin-mcp-ability-guard' ),
					$name
				),
				array( 'status' => 'blocked' )
			);
		}

		if ( Policy::REQUIRE === $verdict['decision'] ) {
			$id = Requests::queue( $name, $input, array( 'reason' => $verdict['reason'] ) );
			if ( is_wp_error( $id ) ) {
				return $id;
			}

			AuditLog::record( $name, 'require', 'queued', null, $input, (int) $id );

			// The wording matters. An agent told only "denied" will route around
			// the gate using a different ability; it needs to know the request
			// is live and that waiting is the correct behaviour.
			return new \WP_Error(
				'mag_pending_approval',
				sprintf(
					/* translators: %d: change request ID. */
					__( 'This change needs human approval and has been queued as request #%d. Nothing has been changed yet. Do not retry and do not attempt the same change another way — report to the user that approval is pending, and use the mag/check-request ability to look up the outcome later.', 'kevin-mcp-ability-guard' ),
					(int) $id
				),
				array(
					'request_id' => (int) $id,
					'status'     => Requests::PENDING,
				)
			);
		}

		// Auto-approved: run it, but watch what it does so the profile improves.
		$rec    = Recorder::start();
		$result = self::invoke( $name, $input );
		$rec->stop();

		list( $profile, $new_ops ) = Profiles::observe( $name, $rec );

		AuditLog::record( $name, 'auto', is_wp_error( $result ) ? 'failed' : 'executed', $rec, $input );

		// Provisional trust was extended on the ability's own claim that it only
		// reads. If it wrote, that claim was wrong: revoke immediately so the
		// second call is gated, and record it as a violation rather than a
		// routine observation.
		if ( ! empty( $verdict['provisional'] ) && ! $rec->is_readonly() ) {
			Policy::set_rule( $name, Policy::REQUIRE );
			AuditLog::record( $name, 'violation', 'trust_revoked', $rec, $input );

			/**
			 * Fires when an ability that presented itself as read-only wrote.
			 *
			 * @param string $name    Ability name.
			 * @param array  $new_ops Operations observed.
			 */
			do_action( 'mcp_ability_guard_readonly_claim_violated', $name, $new_ops );
		}

		// An ability that was auto-approved as readonly and then wrote something
		// is a policy failure worth surfacing loudly rather than swallowing.
		if ( $new_ops && ! $rec->is_readonly() ) {
			/**
			 * Fires when an auto-approved ability performs an operation that
			 * was not in its profile.
			 *
			 * @param string $name    Ability name.
			 * @param array  $new_ops Newly observed operations.
			 * @param array  $profile Updated profile.
			 */
			do_action( 'mcp_ability_guard_unexpected_operation', $name, $new_ops, $profile );
		}

		return $result;
	}

	/**
	 * Call the ability's real callback, matching core's own calling convention.
	 *
	 * @param mixed $input
	 * @return mixed
	 */
	private static function invoke( string $name, $input ) {
		$callback = self::$originals[ $name ] ?? null;
		if ( ! $callback ) {
			return new \WP_Error( 'mag_no_callback', __( 'Original ability callback is unavailable.', 'kevin-mcp-ability-guard' ) );
		}

		// Core passes no argument when the ability declares no input schema.
		$schema = self::$schemas[ $name ] ?? array();
		return empty( $schema ) ? $callback() : $callback( $input );
	}

	/**
	 * Replay an approved request through the original callback.
	 *
	 * @param mixed $input
	 * @return mixed
	 */
	public static function call_original( string $name, $input ) {
		self::$replaying = true;
		try {
			return self::invoke( $name, $input );
		} finally {
			self::$replaying = false;
		}
	}

	public static function original_callback( string $name ): ?callable {
		return self::$originals[ $name ] ?? null;
	}

	public static function input_schema( string $name ): array {
		return self::$schemas[ $name ] ?? array();
	}

	/**
	 * Coerce a JSON Schema into nested arrays.
	 *
	 * JSON Schema is often written with objects rather than associative arrays —
	 * FluentCRM declares `properties` as a stdClass, which is entirely valid and
	 * which every `$schema['properties'][$key]` lookup downstream would choke on.
	 * Normalising once, here, is safer than defending in each consumer.
	 *
	 * The ability's own copy is untouched; this is only what we keep for
	 * rendering previews.
	 *
	 * @param mixed $schema
	 */
	private static function normalize_schema( $schema ): array {
		if ( is_array( $schema ) && ! self::has_object( $schema ) ) {
			return $schema;
		}

		$json = wp_json_encode( $schema );
		if ( false === $json ) {
			return array();
		}

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/** @param mixed $value */
	private static function has_object( $value ): bool {
		if ( is_object( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $v ) {
			if ( self::has_object( $v ) ) {
				return true;
			}
		}
		return false;
	}

	/** Ability names this plugin has wrapped. */
	public static function governed(): array {
		return array_keys( self::$originals );
	}
}
