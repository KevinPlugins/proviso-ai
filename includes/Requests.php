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
 * Pending change requests.
 *
 * Deferring execution means the arguments must be persisted and replayed later,
 * which is unavoidable but not free: an ability's arguments can contain
 * passwords, tokens or personal data. They are stored whole (the replay needs
 * them verbatim) and redacted only on display — see Redactor.
 */
final class Requests {

	public const PENDING  = 'pending';
	public const APPLIED  = 'applied';
	public const REJECTED = 'rejected';
	public const FAILED   = 'failed';
	public const EXPIRED  = 'expired';

	/**
	 * Queue a deferred execution.
	 *
	 * @param string $ability Ability name.
	 * @param mixed  $input   Arguments, stored verbatim for replay.
	 * @param array  $context {
	 *     @type string $reason Why this was gated, shown to the reviewer.
	 * }
	 * @return int|\WP_Error Request ID.
	 */
	public static function queue( string $ability, $input, array $context = array() ) {
		global $wpdb;

		$summary  = (string) ( $context['reason'] ?? '' );
		$identity = Identity::current();
		$timeout  = (int) Policy::settings()['timeout_minutes'];

		$ok = $wpdb->insert(
			Schema::requests_table(),
			array(
				'ability'         => $ability,
				'args'            => (string) wp_json_encode( $input ),
				'summary'         => $summary,
				'status'          => self::PENDING,
				'requested_by'    => get_current_user_id(),
				'requester_key'   => (string) $identity['key'],
				'requester_tier'  => (string) $identity['tier'],
				'requester_label' => (string) $identity['label'],
				'precondition'    => (string) wp_json_encode( Resolver::precondition( $input ) ),
				'created_at'      => current_time( 'mysql' ),
				'expires_at'      => $timeout > 0
					? gmdate( 'Y-m-d H:i:s', (int) current_time( 'timestamp' ) + ( $timeout * MINUTE_IN_SECONDS ) )
					: null,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $ok ) {
			return new \WP_Error( 'proviso_store_failed', __( 'Could not record the change request.', 'proviso-ai' ) );
		}

		return (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::requests_table(), $id ),
			ARRAY_A
		);
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * @param array $criteria {
	 *     @type string $status   Restrict to one status.
	 *     @type string $ability  Restrict to one ability.
	 *     @type int    $per_page Rows to return. Default 50.
	 * }
	 * @return array<int,array>
	 */
	public static function fetch( array $criteria = array() ): array {
		global $wpdb;

		$table   = Schema::requests_table();
		$status  = (string) ( $criteria['status'] ?? '' );
		$ability = (string) ( $criteria['ability'] ?? '' );
		$limit   = max( 1, (int) ( $criteria['per_page'] ?? 50 ) );

		// Each filter combination gets its own literal statement rather than a
		// clause list joined at runtime. Assembling the SQL from an array is
		// safe here — every fragment is a literal — but it cannot be *seen* to
		// be safe by a reader or a static analyser, and a query builder in the
		// approval path is exactly the wrong place to ask anyone to take that
		// on trust. Four branches, all provable.
		if ( '' !== $status && '' !== $ability ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s AND ability = %s ORDER BY id DESC LIMIT %d',
					$table,
					$status,
					$ability,
					$limit
				),
				ARRAY_A
			);
		} elseif ( '' !== $status ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE status = %s ORDER BY id DESC LIMIT %d',
					$table,
					$status,
					$limit
				),
				ARRAY_A
			);
		} elseif ( '' !== $ability ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE ability = %s ORDER BY id DESC LIMIT %d',
					$table,
					$ability,
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
					$table,
					$limit
				),
				ARRAY_A
			);
		}

		return array_map( array( self::class, 'hydrate' ), $rows ?: array() );
	}

	private static function hydrate( array $row ): array {
		$row['args']         = json_decode( (string) $row['args'], true );
		$row['precondition'] = json_decode( (string) $row['precondition'], true );
		$row['result']       = $row['result'] ? json_decode( (string) $row['result'], true ) : null;
		$row['id']           = (int) $row['id'];
		return $row;
	}

	/**
	 * Approve and execute.
	 *
	 * Re-checks three things at decision time rather than trusting what was
	 * true when the agent proposed: who is deciding, whether they still hold
	 * the capability, and whether the target has moved underneath us.
	 *
	 * @return array|\WP_Error
	 */
	public static function approve( int $id, int $user_id = 0, ?string $comment = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$request = self::find( $id );

		if ( ! $request ) {
			return new \WP_Error( 'proviso_not_found', __( 'Change request not found.', 'proviso-ai' ) );
		}

		if ( self::PENDING !== $request['status'] ) {
			return new \WP_Error(
				'proviso_not_pending',
				sprintf(
					/* translators: %s: current status. */
					__( 'This request is already %s.', 'proviso-ai' ),
					$request['status']
				)
			);
		}

		if ( ! Policy::can_approve( $user_id, $request['ability'] ) ) {
			return new \WP_Error( 'proviso_cannot_approve', __( 'You are not one of the approvers for this ability.', 'proviso-ai' ) );
		}

		// Record this person's vote before deciding whether the bar is met, so
		// an ALL quorum accumulates across several reviewers.
		$vote = self::cast( $id, $user_id, 'approve', (string) ( $comment ?? '' ) );
		if ( is_wp_error( $vote ) ) {
			return $vote;
		}

		$needed = Policy::required_count( $request['ability'] );
		$have   = self::vote_count( $id, 'approve' );

		if ( $have < $needed ) {
			AuditLog::record( $request['ability'], 'approve', 'awaiting_quorum', null, $request['args'], $id );
			return array(
				'request_id' => $id,
				'status'     => self::PENDING,
				'approvals'  => $have,
				'required'   => $needed,
				'message'    => sprintf(
					/* translators: 1: approvals so far, 2: approvals required */
					__( 'Recorded. %1$d of %2$d required approvals.', 'proviso-ai' ),
					$have,
					$needed
				),
			);
		}

		$fresh = Resolver::check_precondition( $request['precondition'], $request['args'] );
		if ( is_wp_error( $fresh ) ) {
			return $fresh;
		}

		$callback = Interceptor::original_callback( $request['ability'] );
		if ( ! $callback ) {
			return new \WP_Error(
				'proviso_no_callback',
				__( 'The ability that created this request is no longer registered.', 'proviso-ai' )
			);
		}

		$rec    = Recorder::start();
		$result = Interceptor::call_original( $request['ability'], $request['args'] );
		$rec->stop();

		Profiles::observe( $request['ability'], $rec );

		$failed = is_wp_error( $result );

		self::mark(
			$id,
			$failed ? self::FAILED : self::APPLIED,
			$user_id,
			$failed
				? array( 'error' => $result->get_error_code(), 'message' => $result->get_error_message() )
				: array( 'operations' => self::summarise( $rec ) )
		);

		AuditLog::record(
			$request['ability'],
			'approve',
			$failed ? 'failed' : 'applied',
			$rec,
			$request['args'],
			$id
		);

		return $failed ? $result : array(
			'request_id' => $id,
			'status'     => self::APPLIED,
			'result'     => $result,
			'operations' => self::summarise( $rec ),
		);
	}

	/**
	 * Reject.
	 *
	 * A single rejection ends the request even under an ALL quorum: requiring
	 * unanimity to *stop* a change would make the safe answer harder to give
	 * than the risky one.
	 *
	 * @return true|\WP_Error
	 */
	public static function reject( int $id, int $user_id = 0, ?string $comment = null ) {
		$user_id = $user_id ?: get_current_user_id();
		$request = self::find( $id );

		if ( ! $request ) {
			return new \WP_Error( 'proviso_not_found', __( 'Change request not found.', 'proviso-ai' ) );
		}
		if ( self::PENDING !== $request['status'] ) {
			return new \WP_Error( 'proviso_not_pending', __( 'This request has already been decided.', 'proviso-ai' ) );
		}
		if ( ! Policy::can_approve( $user_id, $request['ability'] ) ) {
			return new \WP_Error( 'proviso_cannot_approve', __( 'You are not one of the approvers for this ability.', 'proviso-ai' ) );
		}

		self::cast( $id, $user_id, 'reject', (string) ( $comment ?? '' ) );
		self::mark( $id, self::REJECTED, $user_id );
		AuditLog::record( $request['ability'], 'reject', 'rejected', null, $request['args'], $id );

		return true;
	}

	/**
	 * Record one person's decision. One vote per person per request.
	 *
	 * @return true|\WP_Error
	 */
	private static function cast( int $id, int $user_id, string $decision, string $comment = '' ) {
		global $wpdb;

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT decision FROM %i WHERE request_id = %d AND user_id = %d',
				Schema::approvals_table(),
				$id,
				$user_id
			)
		);

		if ( $existing ) {
			return new \WP_Error(
				'proviso_already_voted',
				__( 'You have already recorded a decision on this request.', 'proviso-ai' )
			);
		}

		$wpdb->insert(
			Schema::approvals_table(),
			array(
				'request_id' => $id,
				'user_id'    => $user_id,
				'decision'   => $decision,
				'comment'    => substr( $comment, 0, 500 ),
				'decided_at' => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		return true;
	}

	public static function vote_count( int $id, string $decision ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE request_id = %d AND decision = %s',
				Schema::approvals_table(),
				$id,
				$decision
			)
		);
	}

	/** @return array<int,array> */
	public static function votes( int $id ): array {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id', Schema::approvals_table(), $id ),
			ARRAY_A
		) ?: array();
	}

	private static function summarise( Recorder $rec ): array {
		$out = array();
		foreach ( $rec->operations() as $op ) {
			$out[] = $op['op'] . ' ' . $op['type'] . '#' . $op['id'];
		}
		foreach ( $rec->uncovered_writes() as $w ) {
			$out[] = strtolower( $w['op'] ) . ' ' . $w['table'];
		}
		return $out;
	}

	private static function mark( int $id, string $status, int $decided_by, ?array $outcome = null ): void {
		global $wpdb;
		$wpdb->update(
			Schema::requests_table(),
			array(
				'status'     => $status,
				'decided_by' => $decided_by,
				'decided_at' => current_time( 'mysql' ),
				'result'     => null === $outcome ? null : (string) wp_json_encode( $outcome ),
			),
			array( 'id' => $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Decide what happens when nobody answers.
	 *
	 * Silence is not consent: an unanswered request expires and is refused. The
	 * alternative — auto-approving on timeout — turns every approval gate into a
	 * delay, which is worse than having no gate because it looks like one.
	 *
	 * @return int Number of requests expired.
	 */
	public static function expire_due(): int {
		global $wpdb;

		$due = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i
				  WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s',
				Schema::requests_table(),
				self::PENDING,
				current_time( 'mysql' )
			)
		);

		foreach ( $due as $id ) {
			$request = self::find( (int) $id );
			self::mark( (int) $id, self::EXPIRED, 0 );
			if ( $request ) {
				AuditLog::record( $request['ability'], 'timeout', 'expired', null, $request['args'], (int) $id );
			}
		}

		return count( $due );
	}

	public static function count_pending(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', Schema::requests_table(), self::PENDING )
		);
	}
}
