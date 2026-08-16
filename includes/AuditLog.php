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
 * Append-only record of every governed ability call.
 *
 * This covers every ability on the site, not just one MCP server's tools —
 * which is the point of intercepting at registration rather than at a
 * particular transport.
 */
final class AuditLog {

	/**
	 * @param mixed $input
	 */
	public static function record(
		string $ability,
		string $decision,
		string $outcome,
		?Recorder $rec = null,
		$input = null,
		int $request_id = 0
	): void {
		global $wpdb;

		$operations = array();
		$footprint  = '';

		if ( $rec ) {
			foreach ( $rec->operations() as $op ) {
				$operations[] = array(
					'op'    => $op['op'],
					'type'  => $op['type'],
					'id'    => $op['id'],
					'diff'  => Recorder::diff_fields(
						is_array( $op['before'] ) ? $op['before'] : null,
						is_array( $op['after'] ) ? $op['after'] : null
					),
				);
			}
			foreach ( $rec->uncovered_writes() as $w ) {
				$operations[] = array(
					'op'    => 'sql.' . strtolower( $w['op'] ),
					'type'  => 'table',
					'id'    => $w['table'],
					'diff'  => array(),
				);
			}
			$footprint = implode( ',', $rec->footprint() );
		}

		$identity = Identity::current();

		$wpdb->insert(
			Schema::audit_table(),
			array(
				'ability'         => $ability,
				'decision'        => $decision,
				'outcome'         => $outcome,
				'user_id'         => get_current_user_id(),
				'request_id'      => $request_id,
				'requester_key'   => (string) $identity['key'],
				'requester_tier'  => (string) $identity['tier'],
				'requester_label' => (string) $identity['label'],
				'footprint'       => substr( $footprint, 0, 255 ),
				'operations'      => (string) wp_json_encode( $operations ),
				// Kept separately from `operations` because an undo needs full
				// before-state, while the display only needs the changed fields.
				'rollback'        => $rec ? (string) wp_json_encode( Rollback::plan( $rec ) ) : null,
				'args'            => (string) wp_json_encode(
					Redactor::redact( is_array( $input ) ? $input : array(), Interceptor::input_schema( $ability ) )
				),
				'created_at'      => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Undo a previously executed entry.
	 *
	 * @return array|\WP_Error
	 */
	public static function undo( int $entry_id, bool $force = false ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::audit_table(), $entry_id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return new \WP_Error( 'mag_not_found', __( 'No such audit entry.', 'mcp-ability-guard' ) );
		}
		if ( ! empty( $row['undone_at'] ) ) {
			return new \WP_Error( 'mag_already_undone', __( 'This action has already been undone.', 'mcp-ability-guard' ) );
		}

		$plan = json_decode( (string) $row['rollback'], true );
		if ( ! is_array( $plan ) ) {
			return new \WP_Error( 'mag_no_plan', __( 'No rollback information was recorded for this action.', 'mcp-ability-guard' ) );
		}

		$result = Rollback::apply( $plan, $force );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$wpdb->update(
			Schema::audit_table(),
			array( 'undone_at' => current_time( 'mysql' ) ),
			array( 'id' => $entry_id ),
			array( '%s' ),
			array( '%d' )
		);

		self::record( (string) $row['ability'], 'undo', 'undone', null, null, (int) $row['request_id'] );

		return $result;
	}

	public static function rollback_plan( int $entry_id ): ?array {
		global $wpdb;
		$json = $wpdb->get_var( $wpdb->prepare( 'SELECT rollback FROM %i WHERE id = %d', Schema::audit_table(), $entry_id ) );
		$plan = json_decode( (string) $json, true );
		return is_array( $plan ) ? $plan : null;
	}

	/** @return array<int,array> */
	public static function entries( int $limit = 50, ?string $ability = null ): array {
		global $wpdb;
		$table = Schema::audit_table();

		$rows = null === $ability
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ability = %s ORDER BY id DESC LIMIT %d', $table, $ability, $limit ), ARRAY_A );

		return array_map(
			static function ( array $r ): array {
				$r['operations'] = json_decode( (string) $r['operations'], true ) ?: array();
				$r['args']       = json_decode( (string) $r['args'], true ) ?: array();
				$r['id']         = (int) $r['id'];
				return $r;
			},
			$rows ?: array()
		);
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::audit_table() ) );
	}

	/** Retention. Arguments may contain personal data, so this matters. */
	public static function purge_older_than( int $days ): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				Schema::audit_table(),
				$days
			)
		);
	}
}
