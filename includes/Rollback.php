<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Undo for actions that already ran.
 *
 * This is what makes auto-approve defensible. An approval queue protects you
 * from the calls you gated; rollback protects you from the ones you did not —
 * which, on any usable configuration, is most of them.
 *
 * A plan is built from what the Recorder actually observed, so it covers
 * abilities nobody wrote an integration for. It is not universal: writes to a
 * plugin's own tables are detected but not reversible, and the plan says so
 * rather than pretending otherwise.
 */
final class Rollback {

	public const IRREVERSIBLE = 'irreversible';

	/**
	 * Build a reversal plan from an observed execution.
	 *
	 * Each step carries the state needed to undo it plus a fingerprint of what
	 * the object looked like immediately after the write. If that fingerprint no
	 * longer matches at undo time, something else has edited the object since
	 * and reverting would destroy that work.
	 */
	public static function plan( Recorder $rec ): array {
		$steps  = array();
		$blocks = array();

		foreach ( $rec->operations() as $op ) {
			$before = is_array( $op['before'] ) ? $op['before'] : null;
			$after  = is_array( $op['after'] ) ? $op['after'] : null;

			switch ( $op['op'] ) {
				case 'post.create':
					$steps[] = self::step( 'post.delete', $op['id'], null, $after );
					break;

				case 'post.update':
					if ( $before ) {
						$steps[] = self::step( 'post.restore', $op['id'], $before, $after );
					} else {
						$blocks[] = sprintf( 'post #%s was updated but its previous state was not captured', $op['id'] );
					}
					break;

				case 'post.trash':
					$steps[] = self::step( 'post.untrash', $op['id'], $before, null );
					break;

				case 'post.delete':
					if ( $before ) {
						$steps[] = self::step( 'post.recreate', $op['id'], $before, null );
					} else {
						$blocks[] = sprintf( 'post #%s was deleted and cannot be reconstructed', $op['id'] );
					}
					break;

				case 'option.create':
					$steps[] = self::step( 'option.delete', $op['id'], null, $op['after'] );
					break;

				case 'option.update':
				case 'option.delete':
					$steps[] = self::step( 'option.restore', $op['id'], $op['before'], $op['after'] );
					break;

				case 'term.create':
					$steps[] = self::step( 'term.delete', $op['id'], null, $after );
					break;

				case 'comment.create':
					$steps[] = self::step( 'comment.delete', $op['id'], null, $after );
					break;

				case 'user.update':
					if ( $before ) {
						$steps[] = self::step( 'user.restore', $op['id'], $before, $after );
					}
					break;

				case 'user.create':
				case 'user.delete':
				case 'term.update':
				case 'term.delete':
				case 'comment.delete':
					$blocks[] = $op['op'] . ' on #' . $op['id'] . ' is not automatically reversible';
					break;

				case 'http.request':
					$blocks[] = 'an outbound request to ' . $op['id'] . ' cannot be recalled';
					break;
			}
		}

		foreach ( $rec->uncovered_writes() as $w ) {
			$blocks[] = sprintf( '%s on %s (plugin-owned table)', strtolower( $w['op'] ), $w['table'] );
		}

		return array(
			'steps'      => $steps,
			'blocked'    => array_values( array_unique( $blocks ) ),
			'reversible' => ! empty( $steps ) && empty( $blocks ),
			'partial'    => ! empty( $steps ) && ! empty( $blocks ),
		);
	}

	/**
	 * @param mixed $id
	 * @param mixed $before
	 * @param mixed $after
	 */
	private static function step( string $do, $id, $before, $after ): array {
		return array(
			'do'         => $do,
			'id'         => $id,
			'before'     => $before,
			'after_hash' => null === $after ? null : md5( (string) wp_json_encode( $after ) ),
		);
	}

	/**
	 * Apply a plan.
	 *
	 * Steps are reversed so that compound operations unwind in the opposite
	 * order they were applied.
	 *
	 * @return array{applied:int,skipped:array<int,string>}|\WP_Error
	 */
	public static function apply( array $plan, bool $force = false ) {
		if ( empty( $plan['steps'] ) ) {
			return new \WP_Error( 'proviso_nothing_to_undo', __( 'This action recorded no reversible changes.', 'proviso-ai' ) );
		}

		$applied = 0;
		$skipped = array();

		foreach ( array_reverse( $plan['steps'] ) as $step ) {
			$stale = self::is_stale( $step );

			if ( $stale && ! $force ) {
				$skipped[] = sprintf(
					/* translators: 1: operation, 2: object id */
					__( '%1$s #%2$s was changed again after this action; leaving it alone.', 'proviso-ai' ),
					$step['do'],
					(string) $step['id']
				);
				continue;
			}

			$done = self::run_step( $step );
			if ( is_wp_error( $done ) ) {
				$skipped[] = $done->get_error_message();
				continue;
			}
			++$applied;
		}

		return array( 'applied' => $applied, 'skipped' => $skipped );
	}

	/** Has the object moved since we wrote it? */
	private static function is_stale( array $step ): bool {
		if ( empty( $step['after_hash'] ) ) {
			return false;
		}

		$current = self::current_state( $step );
		if ( null === $current ) {
			return true; // Gone, or unreadable — do not guess.
		}

		return md5( (string) wp_json_encode( $current ) ) !== $step['after_hash'];
	}

	/** @return mixed */
	private static function current_state( array $step ) {
		switch ( strtok( (string) $step['do'], '.' ) ) {
			case 'post':
				return get_post( (int) $step['id'], ARRAY_A );
			case 'option':
				return get_option( (string) $step['id'] );
			case 'term':
				$t = get_term( (int) $step['id'], '', ARRAY_A );
				return is_wp_error( $t ) ? null : $t;
			case 'comment':
				return get_comment( (int) $step['id'], ARRAY_A );
			case 'user':
				$u = get_userdata( (int) $step['id'] );
				return $u ? (array) $u->data : null;
		}
		return null;
	}

	/** @return true|\WP_Error */
	private static function run_step( array $step ) {
		$id     = $step['id'];
		$before = $step['before'];

		switch ( $step['do'] ) {
			case 'post.delete':
				return wp_delete_post( (int) $id, true )
					? true
					: new \WP_Error( 'proviso_undo_failed', 'Could not remove post #' . $id );

			case 'post.restore':
				$ok = wp_update_post( array_merge( $before, array( 'ID' => (int) $id ) ), true );
				return is_wp_error( $ok ) ? $ok : true;

			case 'post.untrash':
				return wp_untrash_post( (int) $id ) ? true : new \WP_Error( 'proviso_undo_failed', 'Could not restore post #' . $id );

			case 'post.recreate':
				$data = $before;
				unset( $data['ID'] );
				// Reuse the original ID when it is still free, so links survive.
				if ( ! get_post( (int) $id ) ) {
					$data['import_id'] = (int) $id;
				}
				$new = wp_insert_post( $data, true );
				return is_wp_error( $new ) ? $new : true;

			case 'option.delete':
				delete_option( (string) $id );
				return true;

			case 'option.restore':
				if ( null === $before ) {
					delete_option( (string) $id );
				} else {
					update_option( (string) $id, $before );
				}
				return true;

			case 'term.delete':
				$term = get_term( (int) $id );
				if ( $term && ! is_wp_error( $term ) ) {
					wp_delete_term( (int) $id, $term->taxonomy );
				}
				return true;

			case 'comment.delete':
				return wp_delete_comment( (int) $id, true ) ? true : new \WP_Error( 'proviso_undo_failed', 'Could not remove comment #' . $id );

			case 'user.restore':
				$fields       = $before;
				$fields['ID'] = (int) $id;
				unset( $fields['user_pass'] ); // Never rewrite a password hash.
				$ok = wp_update_user( $fields );
				return is_wp_error( $ok ) ? $ok : true;
		}

		return new \WP_Error( 'proviso_unknown_step', 'Unknown undo step: ' . $step['do'] );
	}

	/** Operations this plugin knows how to reverse. */
	private const REVERSIBLE_OPS = array(
		'post.create', 'post.update', 'post.trash', 'post.delete',
		'option.create', 'option.update', 'option.delete',
		'term.create', 'comment.create', 'user.update',
	);

	public static function is_reversible_op( string $op ): bool {
		// Anything the SQL backstop caught is a plugin's own table: detected,
		// but there is no generic way to reconstruct the previous row.
		if ( 0 === strpos( $op, 'sql.' ) ) {
			return false;
		}

		return in_array( $op, self::REVERSIBLE_OPS, true );
	}

	/**
	 * Whether everything an ability is known to do could be undone.
	 *
	 * @param string[] $operations Profile footprint.
	 * @return array{reversible:bool,blocked:string[]}
	 */
	public static function assess( array $operations ): array {
		$blocked = array();
		foreach ( $operations as $op ) {
			if ( ! self::is_reversible_op( (string) $op ) ) {
				$blocked[] = (string) $op;
			}
		}

		return array(
			'reversible' => empty( $blocked ),
			'blocked'    => array_values( array_unique( $blocked ) ),
		);
	}

	/** Human summary for the audit screen. */
	public static function describe( array $plan ): string {
		if ( empty( $plan['steps'] ) && empty( $plan['blocked'] ) ) {
			return __( 'Nothing was changed.', 'proviso-ai' );
		}
		if ( ! empty( $plan['reversible'] ) ) {
			return sprintf(
				/* translators: %d: number of steps. */
				_n( 'Fully reversible (%d step).', 'Fully reversible (%d steps).', count( $plan['steps'] ), 'proviso-ai' ),
				count( $plan['steps'] )
			);
		}
		if ( ! empty( $plan['partial'] ) ) {
			return sprintf(
				/* translators: 1: reversible steps, 2: blocking reasons */
				__( 'Partly reversible: %1$d step(s) can be undone, but %2$s.', 'proviso-ai' ),
				count( $plan['steps'] ),
				implode( '; ', $plan['blocked'] )
			);
		}
		return sprintf(
			/* translators: %s: blocking reasons. */
			__( 'Not reversible: %s.', 'proviso-ai' ),
			implode( '; ', $plan['blocked'] )
		);
	}
}
