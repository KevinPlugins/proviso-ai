<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the current state of whatever an ability's arguments point at.
 *
 * For conventional CRUD abilities the *after* state is already in the args —
 * the agent supplies the new title, content or value. So a real diff only needs
 * the *before* state, and that is one read once you know which object the args
 * identify. WordPress argument naming is conventional enough to resolve that
 * without any per-ability knowledge.
 *
 * This is deliberately best-effort: it returns null when it cannot tell, and
 * callers must present that honestly rather than implying a verified preview.
 */
final class Resolver {

	/**
	 * Argument name => [object type, reader].
	 *
	 * @return array<string,array{0:string,1:callable}>
	 */
	private static function map(): array {
		$map = array(
			'post_id'    => array( 'post', static fn( $id ) => get_post( (int) $id, ARRAY_A ) ),
			'user_id'    => array(
				'user',
				static function ( $id ) {
					$u = get_userdata( (int) $id );
					return $u ? (array) $u->data : null;
				},
			),
			'term_id'    => array( 'term', static fn( $id ) => get_term( (int) $id, '', ARRAY_A ) ),
			'comment_id' => array( 'comment', static fn( $id ) => get_comment( (int) $id, ARRAY_A ) ),
			'option_name' => array( 'option', static fn( $n ) => get_option( (string) $n ) ),
			'attachment_id' => array( 'post', static fn( $id ) => get_post( (int) $id, ARRAY_A ) ),
		);

		/**
		 * Extend before-state resolution for arguments this plugin does not
		 * recognise — the opt-in path for abilities backed by custom tables.
		 *
		 * @param array $map Argument name => [type, reader].
		 */
		return (array) apply_filters( 'mcp_ability_guard_resolver_map', $map );
	}

	/**
	 * Identify the target object from an ability's input.
	 *
	 * @param mixed $input
	 * @return array{type:string,key:string,id:mixed,state:mixed}|null
	 */
	public static function resolve( $input ): ?array {
		if ( ! is_array( $input ) ) {
			return null;
		}

		foreach ( self::map() as $arg => list( $type, $reader ) ) {
			if ( ! isset( $input[ $arg ] ) ) {
				continue;
			}
			$state = $reader( $input[ $arg ] );
			return array(
				'type'  => $type,
				'key'   => $arg,
				'id'    => $input[ $arg ],
				'state' => is_wp_error( $state ) ? null : $state,
			);
		}

		// A bare `id` is ambiguous on its own; only trust it alongside a
		// post_type hint.
		if ( isset( $input['id'], $input['post_type'] ) ) {
			return array(
				'type'  => 'post',
				'key'   => 'id',
				'id'    => $input['id'],
				'state' => get_post( (int) $input['id'], ARRAY_A ),
			);
		}

		return null;
	}

	/**
	 * A stable fingerprint of the target's current state.
	 *
	 * Stored when a request is created and re-computed at apply time. If it
	 * moved, a human edited the object in between and applying would silently
	 * overwrite that work.
	 *
	 * @param mixed $input
	 */
	public static function precondition( $input ): ?array {
		$target = self::resolve( $input );
		if ( null === $target || null === $target['state'] ) {
			return null;
		}

		return array(
			'type' => $target['type'],
			'id'   => $target['id'],
			'hash' => md5( (string) wp_json_encode( $target['state'] ) ),
		);
	}

	/**
	 * @return true|\WP_Error True when the world has not moved.
	 */
	public static function check_precondition( ?array $stored, $input ) {
		if ( empty( $stored ) ) {
			return true; // Nothing was resolvable; nothing to verify.
		}

		$now = self::precondition( $input );

		if ( null === $now ) {
			return new \WP_Error(
				'mag_target_gone',
				__( 'The object this change targets no longer exists.', 'mcp-ability-guard' )
			);
		}

		if ( $now['hash'] !== $stored['hash'] ) {
			return new \WP_Error(
				'mag_stale',
				__( 'Someone has changed this since the request was queued, so the preview above no longer matches reality. Applying it would discard whatever was done in the meantime. Discard this request and have the agent start again from the current state.', 'mcp-ability-guard' )
			);
		}

		return true;
	}
}
