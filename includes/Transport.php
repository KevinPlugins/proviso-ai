<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * The second chokepoint: the wire.
 *
 * {@see Interceptor} wraps an ability's callback at registration, which guards
 * one entrance. It is not the only one. A plugin that both registers abilities
 * AND serves its own MCP endpoint usually dispatches internally — Royal MCP's
 * `tools/call` reaches `execute_tool()` and its switch directly, never touching
 * the ability object — so a rule set on the ability never fires for the door
 * agents actually use. A gate that looks armed and is not is worse than none,
 * because the admin stops watching.
 *
 * `rest_pre_dispatch` runs before route matching and before permission checks,
 * for every REST request, and returning a non-null value stops everything
 * downstream. That makes it the one place a single filter sees every MCP server
 * on the site.
 *
 * Detection is by payload, never by route. MCP is JSON-RPC, and its shape is
 * fixed by the spec: a plugin that invented its own envelope would not work with
 * any MCP client, so every server on earth sends `method: "tools/call"` with
 * `params.name`. Interoperability is what makes generic interception possible;
 * no route list is needed and none is kept.
 */
final class Transport {

	/** Marks calls already decided here, so the ability gate does not re-queue. */
	private static $decided = array();

	/** Coverage observations, flushed once per request. */
	private static $seen = array();

	public const COVERAGE_OPTION = 'mag_coverage';

	/** Distinct (route, tool) pairs retained. Bounded so the option cannot grow without limit. */
	private const COVERAGE_MAX = 400;

	public static function boot(): void {
		// Priority 5: ahead of plugins that answer on this filter, behind
		// anything that legitimately short-circuits earlier.
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate' ), 5, 3 );
		add_action( 'shutdown', array( self::class, 'flush_coverage' ) );
	}

	/**
	 * Inspect an inbound REST request for MCP tool calls.
	 *
	 * @param mixed            $result  Short-circuit value from earlier filters.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed Null to proceed, or a response that replaces dispatch.
	 */
	public static function gate( $result, $server, $request ) {
		unset( $server );

		if ( null !== $result ) {
			return $result;
		}
		if ( ! $request instanceof \WP_REST_Request || 'POST' !== $request->get_method() ) {
			return null;
		}

		$body = $request->get_json_params();
		if ( ! is_array( $body ) || array() === $body ) {
			return null;
		}

		$route = (string) $request->get_route();

		// JSON-RPC permits a batch: a bare list of call objects. Handling only
		// the single-object form would leave batching as a way around the gate.
		$calls    = isset( $body['method'] ) ? array( $body ) : $body;
		$is_batch = ! isset( $body['method'] );

		foreach ( $calls as $call ) {
			if ( ! is_array( $call ) ) {
				continue;
			}

			$method = (string) ( $call['method'] ?? '' );

			// Reads are not mutations, but they are disclosure: the audit trail
			// should answer "what did the agent see", not only "what did it
			// change". Recorded, never blocked.
			if ( 'resources/read' === $method ) {
				self::note( $route, 'resources/read:' . (string) ( $call['params']['uri'] ?? '' ), null );
				continue;
			}

			if ( 'tools/call' !== $method ) {
				continue;
			}

			$tool = (string) ( $call['params']['name'] ?? '' );
			if ( '' === $tool ) {
				continue;
			}

			$input   = $call['params']['arguments'] ?? array();
			$ability = self::resolve( $tool );

			self::note( $route, $tool, $ability );

			if ( null === $ability ) {
				// Nothing to map a rule onto. Surfaced in the coverage report,
				// and gated only if the operator asked for that.
				if ( ! empty( Policy::settings()['gate_unknown_transport'] ) ) {
					return self::deny(
						$call,
						$is_batch,
						'mag_ungoverned_tool',
						sprintf(
							/* translators: %s: tool name. */
							__( 'The tool "%s" is not governed by this site and site policy refuses ungoverned calls. Do not retry.', 'mcp-ability-guard' ),
							$tool
						)
					);
				}
				continue;
			}

			$verdict = Policy::decide( $ability, Identity::current() );

			if ( Policy::BLOCK === $verdict['decision'] ) {
				AuditLog::record( $ability, 'block', 'blocked', null, $input );

				return self::deny(
					$call,
					$is_batch,
					'mag_blocked',
					sprintf(
						/* translators: %s: ability name. */
						__( 'The ability "%s" is blocked by site policy. Do not retry, and do not attempt the same change through another ability.', 'mcp-ability-guard' ),
						$ability
					)
				);
			}

			if ( Policy::REQUIRE === $verdict['decision'] ) {
				$id = Requests::queue( $ability, $input, array( 'reason' => $verdict['reason'] ) );
				if ( is_wp_error( $id ) ) {
					return self::deny( $call, $is_batch, 'mag_queue_failed', $id->get_error_message() );
				}

				AuditLog::record( $ability, 'require', 'queued', null, $input, (int) $id );

				return self::deny(
					$call,
					$is_batch,
					'mag_pending_approval',
					sprintf(
						/* translators: %d: change request ID. */
						__( 'This change needs human approval and has been queued as request #%d. Nothing has been changed yet. Do not retry and do not attempt the same change another way — report to the user that approval is pending, and use the mag/check-request ability to look up the outcome later.', 'mcp-ability-guard' ),
						(int) $id
					),
					array(
						'request_id' => (int) $id,
						'status'     => Requests::PENDING,
					)
				);
			}

			// Allowed here. Record it so that if this call *does* also run
			// through the ability, the wrapper does not queue it a second time.
			self::$decided[ $ability ] = true;
		}

		return null;
	}

	/** Whether this request already cleared the transport gate for an ability. */
	public static function already_decided( string $ability ): bool {
		return ! empty( self::$decided[ $ability ] );
	}

	/**
	 * Map a transport tool name onto a governed ability.
	 *
	 * Naming is per-plugin — Royal MCP turns `wp_delete_post` into
	 * `royal-mcp/wp-delete-post` — so no single convention can be assumed. The
	 * match is therefore structural rather than vendor-specific, and an
	 * ambiguous result deliberately resolves to null: guessing which of two
	 * abilities was meant could apply one ability's rule to another's call.
	 */
	private static function resolve( string $tool ): ?string {
		$governed = Interceptor::governed();
		if ( ! $governed ) {
			return null;
		}

		// Already a fully-qualified ability name.
		if ( false !== strpos( $tool, '/' ) ) {
			return in_array( $tool, $governed, true ) ? $tool : null;
		}

		$needle  = str_replace( '_', '-', strtolower( $tool ) );
		$matches = array();

		foreach ( $governed as $name ) {
			$cut       = (int) strpos( $name, '/' );
			$namespace = strtolower( substr( $name, 0, $cut ) );
			$slug      = strtolower( substr( $name, $cut + 1 ) );

			// Two accepted forms. Plain `wp_delete_post` maps to the slug, but
			// some servers prefix tool names with their own namespace and strip
			// it when registering: Royal MCP turns `royal_mcp_connection_health`
			// into `royal-mcp/connection-health`. Matching the namespaced form
			// too covers that without encoding any vendor's convention.
			if ( $slug === $needle || $namespace . '-' . $slug === $needle ) {
				$matches[] = $name;
			}
		}

		if ( 1 !== count( $matches ) ) {
			return null;
		}

		return $matches[0];
	}

	/**
	 * Build a JSON-RPC error the agent can actually read.
	 *
	 * Returning a WP_Error here would surface as a bare HTTP failure, and the
	 * client would report a transport problem rather than the reason. The
	 * wording only works if it reaches the model, so the envelope has to be the
	 * one it is already parsing.
	 *
	 * @param array $call     The individual JSON-RPC call.
	 * @param bool  $is_batch Whether the request body was a batch.
	 * @param array $data     Extra fields for error.data.
	 */
	private static function deny( array $call, bool $is_batch, string $code, string $message, array $data = array() ): \WP_REST_Response {
		$error = array(
			'jsonrpc' => '2.0',
			'id'      => $call['id'] ?? null,
			'error'   => array(
				// -32000 is the JSON-RPC "implementation-defined server error"
				// range; a parse/method code would misdescribe this.
				'code'    => -32000,
				'message' => $message,
				'data'    => array_merge( array( 'reason' => $code ), $data ),
			),
		);

		return new \WP_REST_Response( $is_batch ? array( $error ) : $error, 200 );
	}

	/**
	 * Note that a tool was seen, and whether it mapped to something governable.
	 *
	 * Coverage is observed rather than declared, for the same reason profiles
	 * are: a list of what the site *should* expose is a claim, while a call that
	 * actually arrived is evidence.
	 */
	private static function note( string $route, string $tool, ?string $ability ): void {
		$key = $route . '|' . $tool;

		if ( isset( self::$seen[ $key ] ) ) {
			++self::$seen[ $key ]['calls'];
			return;
		}

		self::$seen[ $key ] = array(
			'route'    => $route,
			'tool'     => $tool,
			'ability'  => $ability,
			'governed' => null !== $ability,
			'calls'    => 1,
			'last'     => time(),
		);
	}

	/** Merge this request's observations into the stored coverage map. */
	public static function flush_coverage(): void {
		if ( ! self::$seen ) {
			return;
		}

		$stored = get_option( self::COVERAGE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		foreach ( self::$seen as $key => $row ) {
			if ( isset( $stored[ $key ] ) ) {
				$row['calls'] += (int) ( $stored[ $key ]['calls'] ?? 0 );
			}
			$stored[ $key ] = $row;
		}

		// Bounded: drop the least recently seen first.
		if ( count( $stored ) > self::COVERAGE_MAX ) {
			uasort(
				$stored,
				static function ( $a, $b ) {
					return (int) ( $b['last'] ?? 0 ) <=> (int) ( $a['last'] ?? 0 );
				}
			);
			$stored = array_slice( $stored, 0, self::COVERAGE_MAX, true );
		}

		update_option( self::COVERAGE_OPTION, $stored, false );
		self::$seen = array();
	}

	/**
	 * Coverage summary for the admin screen.
	 *
	 * @return array{routes:array<string,array>,total:int,governed:int,ungoverned:int}
	 */
	public static function coverage(): array {
		$stored = get_option( self::COVERAGE_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$routes     = array();
		$governed   = 0;
		$ungoverned = 0;

		foreach ( $stored as $row ) {
			if ( ! is_array( $row ) || empty( $row['route'] ) ) {
				continue;
			}
			$route = (string) $row['route'];

			if ( ! isset( $routes[ $route ] ) ) {
				$routes[ $route ] = array( 'governed' => array(), 'ungoverned' => array() );
			}

			if ( ! empty( $row['governed'] ) ) {
				$routes[ $route ]['governed'][] = $row;
				++$governed;
			} else {
				$routes[ $route ]['ungoverned'][] = $row;
				++$ungoverned;
			}
		}

		return array(
			'routes'     => $routes,
			'total'      => $governed + $ungoverned,
			'governed'   => $governed,
			'ungoverned' => $ungoverned,
		);
	}
}
