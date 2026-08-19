<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * REST surface for the admin application.
 *
 * The UI is a single-page app, so everything it needs is exposed here rather
 * than rendered server-side. Two permission levels: managing policy needs
 * `manage_options`, while deciding a queued request only needs whatever the
 * approver rules say — an Editor named as approver must be able to act without
 * being handed administrator powers.
 */
final class Rest {

	public const NAMESPACE = 'proviso/v1';

	public static function boot(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function can_review(): bool {
		return Policy::can_approve( get_current_user_id() );
	}

	public static function register_routes(): void {
		$manage = array( self::class, 'can_manage' );
		$review = array( self::class, 'can_review' );

		register_rest_route(
			self::NAMESPACE,
			'/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'bootstrap' ),
				'permission_callback' => $review,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/abilities',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'abilities' ),
				'permission_callback' => $review,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/abilities/rule',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'set_rule' ),
				'permission_callback' => $manage,
				'args'                => array(
					'ability' => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/requests',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'requests' ),
				'permission_callback' => $review,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/requests/(?P<id>\d+)/decide',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'decide_request' ),
				'permission_callback' => $review,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'audit' ),
				'permission_callback' => $review,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/audit/(?P<id>\d+)/undo',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'undo' ),
				'permission_callback' => $manage,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'save_settings' ),
					'permission_callback' => $manage,
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	public static function bootstrap( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'abilities' => self::ability_list(),
				'requests'  => self::request_list( Requests::PENDING ),
				'audit'     => self::audit_list( 60 ),
				'settings'  => self::settings_payload(),
				'meta'      => self::meta(),
			)
		);
	}

	public static function abilities( \WP_REST_Request $request ) {
		return rest_ensure_response( self::ability_list() );
	}

	public static function requests( \WP_REST_Request $request ) {
		$status = (string) $request->get_param( 'status' );
		return rest_ensure_response( self::request_list( $status ?: null ) );
	}

	public static function audit( \WP_REST_Request $request ) {
		$limit = (int) ( $request->get_param( 'limit' ) ?: 60 );
		return rest_ensure_response( self::audit_list( $limit ) );
	}

	public static function get_settings( \WP_REST_Request $request ) {
		return rest_ensure_response(
			array(
				'settings' => self::settings_payload(),
				'meta'     => self::meta(),
			)
		);
	}

	public static function save_settings( \WP_REST_Request $request ) {
		$body = (array) $request->get_json_params();

		$changes = array();
		foreach ( array( 'learning_mode', 'auto_readonly', 'trust_declared_readonly', 'gate_unresolved' ) as $flag ) {
			if ( array_key_exists( $flag, $body ) ) {
				$changes[ $flag ] = (bool) $body[ $flag ];
			}
		}
		if ( ! empty( $body['default_decision'] ) ) {
			$changes['default_decision'] = Policy::REQUIRE === $body['default_decision']
				? Policy::REQUIRE
				: Policy::AUTO;
		}
		if ( array_key_exists( 'timeout_minutes', $body ) ) {
			$changes['timeout_minutes'] = max( 0, (int) $body['timeout_minutes'] );
		}
		if ( ! empty( $body['approve_cap'] ) ) {
			$changes['approve_cap'] = sanitize_key( (string) $body['approve_cap'] );
		}
		if ( isset( $body['approver_default'] ) && is_array( $body['approver_default'] ) ) {
			$changes['approver_default'] = array(
				'type'   => Policy::APPROVER_MIXED,
				'values' => array_map( 'sanitize_text_field', (array) ( $body['approver_default']['values'] ?? array() ) ),
				'quorum' => Policy::ALL === ( $body['approver_default']['quorum'] ?? '' ) ? Policy::ALL : Policy::ANY,
			);
		}

		Policy::update( $changes );

		return rest_ensure_response(
			array(
				'settings' => self::settings_payload(),
				'meta'     => self::meta(),
			)
		);
	}

	public static function set_rule( \WP_REST_Request $request ) {
		$body    = (array) $request->get_json_params();
		$ability = sanitize_text_field( (string) ( $body['ability'] ?? '' ) );

		if ( '' === $ability ) {
			return new \WP_Error( 'proviso_bad_request', __( 'No ability given.', 'proviso-ai' ), array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'rule', $body ) ) {
			$rule = sanitize_key( (string) $body['rule'] );

			// Validated server-side, not merely hidden in the UI: "require
			// approval" is meaningless for a read, since approval returns a
			// status to the agent and never the data it asked for.
			if ( in_array( $rule, Policy::available_decisions( $ability ), true ) ) {
				Policy::set_rule( $ability, $rule );
			} else {
				Policy::clear_rule( $ability );
			}
		}

		if ( array_key_exists( 'approvers', $body ) ) {
			$approvers = array_values( array_filter( array_map( 'sanitize_text_field', (array) $body['approvers'] ) ) );
			$quorum    = Policy::ALL === ( $body['quorum'] ?? '' ) ? Policy::ALL : Policy::ANY;

			if ( $approvers ) {
				Policy::set_approver_rule( $ability, Policy::APPROVER_MIXED, $approvers, $quorum );
			} else {
				Policy::clear_approver_rule( $ability );
			}
		}

		return rest_ensure_response( array( 'ability' => self::ability_payload( $ability ) ) );
	}

	public static function decide_request( \WP_REST_Request $request ) {
		$id      = (int) $request['id'];
		$body    = (array) $request->get_json_params();
		$comment = sanitize_text_field( (string) ( $body['comment'] ?? '' ) );
		$action  = sanitize_key( (string) ( $body['decision'] ?? '' ) );

		$result = 'approve' === $action
			? Requests::approve( $id, 0, $comment )
			: Requests::reject( $id, 0, $comment );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response(
			array(
				'result'   => is_array( $result ) ? $result : array( 'status' => 'ok' ),
				'requests' => self::request_list( Requests::PENDING ),
				'audit'    => self::audit_list( 60 ),
			)
		);
	}

	public static function undo( \WP_REST_Request $request ) {
		$result = AuditLog::undo( (int) $request['id'] );

		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}

		return rest_ensure_response(
			array(
				'result' => $result,
				'audit'  => self::audit_list( 60 ),
			)
		);
	}

	/* ------------------------------------------------------------------ */

	private static function ability_list(): array {
		if ( ! class_exists( '\WP_Abilities_Registry' ) ) {
			return array();
		}

		$out = array();
		foreach ( \WP_Abilities_Registry::get_instance()->get_all_registered() as $name => $ability ) {
			$out[] = self::ability_payload( (string) $name, $ability );
		}

		usort( $out, static fn( $a, $b ) => strcmp( $a['name'], $b['name'] ) );

		return $out;
	}

	/** @param \WP_Ability|null $object */
	private static function ability_payload( string $name, $object = null ): array {
		$object  = $object ?? ( function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null );
		$profile = Profiles::get( $name );
		$verdict = Policy::decide( $name );
		$class   = Policy::classify( $name );
		$rule    = Policy::approver_rule( $name );
		$rules   = Policy::settings()['rules'];
		$assess  = Rollback::assess( $profile['operations'] );

		$annotations = $object ? (array) $object->get_meta( 'annotations' ) : array();

		return array(
			'name'        => $name,
			'namespace'   => strtok( $name, '/' ),
			'label'       => $object ? $object->get_label() : $name,
			'description' => $object ? $object->get_description() : '',
			'category'    => $object ? $object->get_category() : '',

			'kind'        => $class['kind'],
			'kindBasis'   => $class['basis'],

			'declared'    => array(
				'readonly'    => $annotations['readonly'] ?? null,
				'destructive' => $annotations['destructive'] ?? null,
				'idempotent'  => $annotations['idempotent'] ?? null,
			),

			'profile'     => array(
				'operations'   => array_values( $profile['operations'] ),
				'tables'       => array_values( $profile['tables'] ),
				'observations' => (int) $profile['observations'],
				'confidence'   => $profile['confidence'],
				'lastSeen'     => $profile['last_seen'],
			),

			'decision'    => $verdict['decision'],
			'reason'      => $verdict['reason'],
			'provisional' => ! empty( $verdict['provisional'] ),
			'rule'        => $rules[ $name ] ?? '',
			'choices'     => Policy::available_decisions( $name ),
			'isRead'      => Policy::is_known_read( $name ),

			'approvers'   => array(
				'values'   => array_values( (array) $rule['values'] ),
				'quorum'   => $rule['quorum'],
				'resolved' => array_map(
					static function ( int $uid ): array {
						$u = get_userdata( $uid );
						return array( 'id' => $uid, 'name' => $u ? $u->display_name : (string) $uid );
					},
					Policy::approvers_for( $name )
				),
				'required' => Policy::required_count( $name ),
			),

			'reversible'  => $assess['reversible'],
			'irreversible' => $assess['blocked'],
			'schema'      => Preview::schema_quality( $name ),
		);
	}

	private static function request_list( ?string $status ): array {
		$rows = Requests::fetch( array( 'status' => $status, 'per_page' => 100 ) );

		return array_map(
			static function ( array $r ): array {
				$preview = Preview::build( $r['ability'], $r['args'] );
				$votes   = Requests::votes( (int) $r['id'] );

				return array(
					'id'        => (int) $r['id'],
					'ability'   => $r['ability'],
					'summary'   => $r['summary'],
					'status'    => $r['status'],
					'createdAt' => $r['created_at'],
					'expiresAt' => $r['expires_at'],
					'requester' => array(
						'label' => $r['requester_label'],
						'tier'  => $r['requester_tier'],
						'user'  => self::user_name( (int) $r['requested_by'] ),
					),
					'preview'   => $preview,
					'kind'      => Policy::classify( $r['ability'] )['kind'],
					'approvals' => array(
						'have'     => Requests::vote_count( (int) $r['id'], 'approve' ),
						'required' => Policy::required_count( $r['ability'] ),
						'quorum'   => Policy::approver_rule( $r['ability'] )['quorum'],
						'votes'    => array_map(
							static fn( array $v ): array => array(
								'user'     => self::user_name( (int) $v['user_id'] ),
								'decision' => $v['decision'],
								'comment'  => $v['comment'],
								'at'       => $v['decided_at'],
							),
							$votes
						),
					),
					'canDecide' => Policy::can_approve( get_current_user_id(), $r['ability'] ),
				);
			},
			$rows
		);
	}

	private static function audit_list( int $limit ): array {
		return array_map(
			static function ( array $e ): array {
				$plan = json_decode( (string) ( $e['rollback'] ?? '' ), true );
				$plan = is_array( $plan ) ? $plan : array();

				return array(
					'id'        => (int) $e['id'],
					'ability'   => $e['ability'],
					'decision'  => $e['decision'],
					'outcome'   => $e['outcome'],
					'footprint' => array_values( array_filter( explode( ',', (string) $e['footprint'] ) ) ),
					'createdAt' => $e['created_at'],
					'undoneAt'  => $e['undone_at'],
					'requester' => array(
						'label' => $e['requester_label'],
						'tier'  => $e['requester_tier'],
						'user'  => self::user_name( (int) $e['user_id'] ),
					),
					'undo'      => array(
						'possible'   => ! empty( $plan['steps'] ) && empty( $e['undone_at'] ),
						'steps'      => count( $plan['steps'] ?? array() ),
						'reversible' => ! empty( $plan['reversible'] ),
						'partial'    => ! empty( $plan['partial'] ),
						'blocked'    => array_values( $plan['blocked'] ?? array() ),
					),
					// The raw operations answer "what columns changed"; these
					// answer "what happened", which is the question a reviewer
					// is actually asking.
					'headline'  => Narrator::headline( is_array( $e['operations'] ?? null ) ? $e['operations'] : array() ),
					'summary'   => Narrator::summarise( is_array( $e['operations'] ?? null ) ? $e['operations'] : array() ),
				);
			},
			AuditLog::entries( $limit )
		);
	}

	private static function settings_payload(): array {
		$s = Policy::settings();

		return array(
			'learningMode'          => (bool) $s['learning_mode'],
			'autoReadonly'          => (bool) $s['auto_readonly'],
			'trustDeclaredReadonly' => (bool) $s['trust_declared_readonly'],
			'gateUnresolved'        => (bool) $s['gate_unresolved'],
			'timeoutMinutes'        => (int) $s['timeout_minutes'],
			'defaultDecision'       => (string) ( $s['default_decision'] ?? Policy::AUTO ),
			'approveCap'            => (string) $s['approve_cap'],
			'approverDefault'       => Policy::approver_rule( '__default__' ),
		);
	}

	/** Reference data the UI needs to render pickers. */
	private static function meta(): array {
		// Approvers are chosen as people, not roles: an admin picking "Editor"
		// cannot see who that actually is, and the set changes underneath them
		// whenever someone's role changes. Roles remain supported in stored
		// policy for anyone configuring it programmatically.
		$users = array();
		foreach ( get_users( array( 'capability' => Policy::settings()['approve_cap'], 'number' => 200, 'orderby' => 'display_name' ) ) as $u ) {
			$users[] = array(
				'value'  => 'user:' . $u->ID,
				'label'  => $u->display_name,
				'login'  => $u->user_login,
				'avatar' => get_avatar_url( $u->ID, array( 'size' => 48 ) ),
				'role'   => translate_user_role( ucfirst( (string) ( $u->roles[0] ?? '' ) ) ),
			);
		}

		$shared = array();
		foreach ( Identity::shared_accounts() as $row ) {
			$shared[] = array( 'user' => $row['user'], 'requesters' => $row['requesters'] );
		}

		return array(
			'users'          => $users,
			'sharedAccounts' => $shared,
			'canManage'      => self::can_manage(),
			'pending'        => Requests::count_pending(),
		);
	}

	private static function user_name( int $id ): string {
		if ( ! $id ) {
			return __( 'system', 'proviso-ai' );
		}
		$u = get_userdata( $id );
		return $u ? $u->display_name : (string) $id;
	}
}
