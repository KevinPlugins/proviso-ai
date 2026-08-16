<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public const CRON_EXPIRE = 'mag_expire_requests';

	public static function boot(): void {
		// Must be attached before any ability registers.
		Identity::boot();
		Interceptor::boot();
		Transport::boot();
		Rest::boot();

		add_action( self::CRON_EXPIRE, array( Requests::class, 'expire_due' ) );
		if ( ! wp_next_scheduled( self::CRON_EXPIRE ) && ! wp_installing() ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_EXPIRE );
		}

		// Core insists categories register on their own action, before abilities.
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );

		if ( is_admin() ) {
			Admin::boot();
		}
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'mag',
			array(
				'label'       => __( 'Ability Guard', 'mcp-ability-guard' ),
				'description' => __( 'Governance and approval status.', 'mcp-ability-guard' ),
			)
		);
	}

	/**
	 * Abilities the agent itself needs.
	 *
	 * An agent told "queued as #47" must have some way to discover the outcome,
	 * or its only options are to give up or to try routing around the gate.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'mag/check-request',
			array(
				'label'       => __( 'Check change request', 'mcp-ability-guard' ),
				'description' => __( 'Look up whether a change you proposed has been approved, rejected, or is still awaiting review. Call this instead of retrying a change that returned mag_pending_approval.', 'mcp-ability-guard' ),
				'category'    => 'mag',
				'input_schema' => array(
					'type'       => 'object',
					'required'   => array( 'request_id' ),
					'properties' => array(
						'request_id' => array(
							'type'        => 'integer',
							'description' => __( 'The change request ID returned when the change was queued.', 'mcp-ability-guard' ),
						),
					),
				),
				'output_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'request_id' => array( 'type' => 'integer' ),
						'ability'    => array( 'type' => 'string' ),
						'status'     => array( 'type' => 'string' ),
						'created_at' => array( 'type' => 'string' ),
						'decided_at' => array( 'type' => 'string' ),
					),
				),
				'execute_callback' => static function ( $input ) {
					$request = Requests::find( (int) ( $input['request_id'] ?? 0 ) );
					if ( ! $request ) {
						return new \WP_Error( 'mag_not_found', __( 'No such change request.', 'mcp-ability-guard' ) );
					}
					// Agents see status only — never another user's arguments.
					return array(
						'request_id' => (int) $request['id'],
						'ability'    => (string) $request['ability'],
						'status'     => (string) $request['status'],
						'created_at' => (string) $request['created_at'],
						'decided_at' => (string) ( $request['decided_at'] ?? '' ),
					);
				},
				'permission_callback' => static fn() => is_user_logged_in(),
				'meta' => array(
					'annotations' => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
	}
}
