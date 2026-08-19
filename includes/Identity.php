<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This class reads and writes
// the plugin's own tables. WordPress has no Core API for custom tables, so every
// access here is necessarily a direct query; table names come from Schema and are
// passed through prepare()'s %i placeholder, never from input. Caching is omitted
// deliberately: these rows decide whether a change may proceed and record what
// happened, and a stale read could approve one request twice or lose an audit
// entry. They run only while an ability executes, never on a front-end request.

/**
 * Who is calling.
 *
 * Every MCP plugin invents its own authentication, so there is no canonical
 * requester to look up. But policy does not need a canonical identity — it needs
 * a *stable equivalence class*: the same caller recognised across requests, and
 * distinguishable from a different one. That is achievable generically, because
 * the useful signals live in the MCP protocol rather than in any plugin:
 * `Mcp-Session-Id` (Streamable HTTP) and `clientInfo` (the initialize handshake).
 *
 * The critical rule is the trust tier. Session IDs, clientInfo and user agents
 * are client-asserted strings with no cryptographic binding — a caller can set
 * them to anything. So:
 *
 *   BOUND     — proven by authentication (app-password UUID, or an identity a
 *               plugin vouches for through the filter). May relax policy.
 *   OBSERVED  — self-reported. Distinguishes callers and labels the audit log,
 *               and may only make policy STRICTER. Never looser, or agent B
 *               claims to be agent A and inherits its auto-approve.
 *   UNRESOLVED— nothing usable. Treated like an unprofiled ability: strictest.
 */
final class Identity {

	public const BOUND      = 'bound';
	public const OBSERVED   = 'observed';
	public const UNRESOLVED = 'unresolved';

	/** @var array|null Resolved once per request. */
	private static $current = null;

	/** @var array{uuid:string,name:string}|null Captured during authentication. */
	private static $app_password = null;

	public static function boot(): void {
		add_action(
			'application_password_did_authenticate',
			static function ( $user, $item ): void {
				self::$app_password = array(
					'uuid' => (string) ( $item['uuid'] ?? '' ),
					'name' => (string) ( $item['name'] ?? '' ),
				);
				self::$current = null; // Re-resolve now that we know more.
			},
			10,
			2
		);
	}

	/**
	 * @return array{
	 *   key:string, tier:string, label:string, user_id:int,
	 *   channel:string, signals:array
	 * }
	 */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}

		$signals = self::signals();
		$user_id = get_current_user_id();

		/**
		 * Let an MCP plugin supply the identity it already computed.
		 *
		 * Royal MCP, for instance, derives a fingerprint from client_id + user_id
		 * precisely because hashing the raw bearer token would mint a new identity
		 * on every hourly refresh. Anything returned here is treated as BOUND, so
		 * only return a value the plugin actually authenticated.
		 *
		 * @param array|null $identity {key, label} or null.
		 * @param array      $signals  Raw request signals.
		 */
		$supplied = apply_filters( 'mcp_ability_guard_requester_identity', null, $signals );

		if ( is_array( $supplied ) && ! empty( $supplied['key'] ) ) {
			return self::$current = self::build(
				(string) $supplied['key'],
				self::BOUND,
				(string) ( $supplied['label'] ?? $supplied['key'] ),
				$user_id,
				'plugin',
				$signals
			);
		}

		// Bound: proven by authentication, does not rotate.
		if ( self::$app_password && '' !== self::$app_password['uuid'] ) {
			return self::$current = self::build(
				'apw:' . self::$app_password['uuid'],
				self::BOUND,
				self::$app_password['name'] ?: __( 'Application password', 'proviso-ai' ),
				$user_id,
				'application_password',
				$signals
			);
		}

		// A cookie session is authenticated by WordPress itself, so it is bound.
		// This must be tested before the MCP signals and must NOT exclude REST:
		// the admin screen talks to its own REST API with a cookie and a nonce,
		// and treating that as an unidentifiable caller makes the plugin gate
		// every ability against its own interface.
		if (
			$user_id
			&& '' === $signals['auth_scheme']
			&& '' === $signals['session_id']
			&& ! $signals['is_cli']
		) {
			return self::$current = self::build(
				'wpuser:' . $user_id,
				self::BOUND,
				self::user_label( $user_id ),
				$user_id,
				'cookie',
				$signals
			);
		}

		// Observed: self-reported, useful for separation and never for trust.
		if ( '' !== $signals['client_name'] ) {
			return self::$current = self::build(
				'client:' . $signals['client_name'] . ':' . $user_id,
				self::OBSERVED,
				$signals['client_name'],
				$user_id,
				$signals['auth_scheme'] ?: 'mcp',
				$signals
			);
		}

		if ( '' !== $signals['session_id'] ) {
			return self::$current = self::build(
				'session:' . hash( 'sha256', $signals['session_id'] ),
				self::OBSERVED,
				__( 'MCP session', 'proviso-ai' ) . ' ' . substr( $signals['session_id'], 0, 8 ),
				$user_id,
				$signals['auth_scheme'] ?: 'mcp',
				$signals
			);
		}

		if ( $signals['is_cli'] ) {
			return self::$current = self::build( 'cli', self::BOUND, 'WP-CLI', $user_id, 'cli', $signals );
		}

		return self::$current = self::build(
			$user_id ? 'unresolved:' . $user_id : 'unresolved',
			self::UNRESOLVED,
			__( 'Unidentified client', 'proviso-ai' ),
			$user_id,
			$signals['auth_scheme'] ?: 'unknown',
			$signals
		);
	}

	private static function build( string $key, string $tier, string $label, int $user_id, string $channel, array $signals ): array {
		return compact( 'key', 'tier', 'label', 'user_id', 'channel', 'signals' );
	}

	/** Raw, plugin-independent request signals. */
	private static function signals(): array {
		$server = $_SERVER; // phpcs:ignore WordPress.Security.NonceVerification

		$auth   = (string) ( $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '' );
		$scheme = '';
		if ( 0 === stripos( $auth, 'bearer ' ) ) {
			$scheme = 'oauth';
		} elseif ( 0 === stripos( $auth, 'basic ' ) || ! empty( $server['PHP_AUTH_USER'] ) ) {
			$scheme = 'basic';
		}

		$session = (string) ( $server['HTTP_MCP_SESSION_ID'] ?? '' );

		return array(
			'session_id'  => $session,
			'client_name' => self::client_name( $session ),
			'auth_scheme' => $scheme,
			'route'       => (string) ( $server['REQUEST_URI'] ?? '' ),
			'user_agent'  => substr( (string) ( $server['HTTP_USER_AGENT'] ?? '' ), 0, 200 ),
			'ip'          => (string) ( $server['REMOTE_ADDR'] ?? '' ),
			'is_rest'     => defined( 'REST_REQUEST' ) && REST_REQUEST,
			'is_cli'      => defined( 'WP_CLI' ) && \WP_CLI,
		);
	}

	/**
	 * The name the agent announced in the MCP initialize handshake.
	 *
	 * Sent once per session, so it is cached against the session ID rather than
	 * re-read on every tool call.
	 */
	private static function client_name( string $session ): string {
		if ( '' === $session ) {
			return '';
		}

		$cached = get_transient( 'proviso_client_' . md5( $session ) );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		$name = '';
		$body = self::request_body();

		if ( '' !== $body && false !== strpos( $body, '"initialize"' ) ) {
			$json = json_decode( $body, true );
			$candidate = $json['params']['clientInfo']['name'] ?? '';
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$name = sanitize_text_field( $candidate );
				set_transient( 'proviso_client_' . md5( $session ), $name, DAY_IN_SECONDS );
			}
		}

		return $name;
	}

	/** Read the raw body defensively; never disturb other consumers. */
	private static function request_body(): string {
		// Read-only sniff of the content type; no state changes, so there is no
		// nonce to verify here.
		$type = isset( $_SERVER['CONTENT_TYPE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) )
			: ''; // phpcs:ignore WordPress.Security.NonceVerification -- inspection only, nothing is written.
		if ( false === stripos( $type, 'json' ) ) {
			return '';
		}
		$body = file_get_contents( 'php://input' );
		return is_string( $body ) ? $body : '';
	}

	private static function user_label( int $user_id ): string {
		$u = get_userdata( $user_id );
		return $u ? $u->user_login : (string) $user_id;
	}

	/** Only a bound identity may be used to relax policy. */
	public static function may_relax( array $identity ): bool {
		return self::BOUND === ( $identity['tier'] ?? self::UNRESOLVED );
	}

	/**
	 * Distinct requesters seen per WordPress user.
	 *
	 * When several agents share one account — which is what happens whenever an
	 * MCP plugin authenticates by shared API key and elevates to the first
	 * administrator — per-agent rules cannot be applied and the audit trail
	 * cannot attribute anything. Surfacing that is more useful than pretending
	 * the identity is good.
	 *
	 * @return array<int,array{user:string,requesters:array<int,string>}>
	 */
	public static function shared_accounts(): array {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT user_id, requester_key, requester_label
				   FROM %i
				  WHERE user_id > 0 AND requester_key <> ""
				  GROUP BY user_id, requester_key, requester_label',
				Schema::audit_table()
			),
			ARRAY_A
		);

		$by_user = array();
		foreach ( $rows ?: array() as $r ) {
			$by_user[ (int) $r['user_id'] ][ $r['requester_key'] ] = $r['requester_label'];
		}

		$shared = array();
		foreach ( $by_user as $user_id => $requesters ) {
			if ( count( $requesters ) > 1 ) {
				$shared[ $user_id ] = array(
					'user'       => self::user_label( $user_id ),
					'requesters' => array_values( $requesters ),
				);
			}
		}

		return $shared;
	}

	/** Tests and long-running processes need to clear the per-request cache. */
	public static function flush(): void {
		self::$current = null;
	}

	/** @internal Test seam. */
	public static function force( ?array $identity ): void {
		self::$current = $identity;
	}
}
