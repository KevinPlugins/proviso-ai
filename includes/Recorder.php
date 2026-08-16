<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Observes what an ability actually mutates.
 *
 * Two layers, because neither alone is sufficient:
 *
 *  1. Core action hooks — give precise operations WITH before/after state, but
 *     only for core objects (posts, options, users, terms, comments).
 *  2. The `query` filter on $wpdb — sees every statement, including plugins with
 *     their own tables (FluentCRM, WooCommerce HPOS, Action Scheduler). Gives
 *     detection but not diffable state.
 *
 * Without layer 2 an ability that writes only to its own tables profiles as
 * readonly, which is the dangerous direction to be wrong in.
 */
final class Recorder {

	/** Core tables already covered by action hooks. */
	private const CORE_TABLES = array(
		'posts', 'postmeta', 'options', 'users', 'usermeta', 'terms',
		'termmeta', 'term_taxonomy', 'term_relationships', 'comments',
		'commentmeta', 'links',
	);

	private const DDL = array( 'ALTER', 'CREATE', 'DROP', 'TRUNCATE', 'RENAME' );

	/** @var array<int,array> Operations seen via core hooks. */
	private $ops = array();

	/** @var array<int,array> Write statements seen via the query filter. */
	private $sql = array();

	/** @var array<string,array> Before-state snapshots keyed "type:id". */
	private $before = array();

	/** @var array<int,array> {hook, callback, args, kind} */
	private $listeners = array();

	/** @var bool Guards against recursion when our own code writes. */
	private $paused = false;

	public static function start(): self {
		$r = new self();
		$r->attach();
		return $r;
	}

	public function pause(): void {
		$this->paused = true;
	}

	public function resume(): void {
		$this->paused = false;
	}

	private function action( string $hook, callable $fn, int $args = 1 ): void {
		add_action( $hook, $fn, -PHP_INT_MAX, $args );
		$this->listeners[] = array( $hook, $fn, $args, 'action' );
	}

	private function attach(): void {

		/* ---------------- Posts ---------------- */

		// Fires only on updates, before the write — the last moment the old
		// row is still readable.
		$this->action(
			'pre_post_update',
			function ( $post_id ): void {
				$post = get_post( (int) $post_id, ARRAY_A );
				if ( $post ) {
					$this->before[ 'post:' . $post_id ] = $post;
				}
			}
		);

		$this->action(
			'wp_insert_post',
			function ( $post_id, $post, $update ): void {
				// Revisions and autosaves are core bookkeeping, not intent.
				if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
					return;
				}
				$this->record(
					$update ? 'post.update' : 'post.create',
					'post',
					(int) $post_id,
					$update ? ( $this->before[ 'post:' . $post_id ] ?? null ) : null,
					get_post( (int) $post_id, ARRAY_A )
				);
			},
			3
		);

		$this->action(
			'before_delete_post',
			function ( $post_id ): void {
				$this->before[ 'post:' . $post_id ] = get_post( (int) $post_id, ARRAY_A );
			}
		);

		$this->action(
			'deleted_post',
			function ( $post_id ): void {
				$this->record( 'post.delete', 'post', (int) $post_id, $this->before[ 'post:' . $post_id ] ?? null, null );
			}
		);

		$this->action(
			'trashed_post',
			function ( $post_id ): void {
				$this->record( 'post.trash', 'post', (int) $post_id, $this->before[ 'post:' . $post_id ] ?? null, null );
			}
		);

		/* ---------------- Options ---------------- */

		$this->action(
			'updated_option',
			function ( $option, $old, $new ): void {
				$this->record( 'option.update', 'option', (string) $option, $old, $new );
			},
			3
		);

		$this->action(
			'added_option',
			function ( $option, $value ): void {
				$this->record( 'option.create', 'option', (string) $option, null, $value );
			},
			2
		);

		// Fires before the row goes, which is the only chance to keep the value
		// an undo would need.
		$this->action(
			'delete_option',
			function ( $option ): void {
				$this->before[ 'option:' . $option ] = get_option( (string) $option );
			}
		);

		$this->action(
			'deleted_option',
			function ( $option ): void {
				$this->record( 'option.delete', 'option', (string) $option, $this->before[ 'option:' . $option ] ?? null, null );
			}
		);

		/* ---------------- Users ---------------- */

		$this->action(
			'user_register',
			function ( $user_id ): void {
				$this->record( 'user.create', 'user', (int) $user_id, null, $this->user_state( (int) $user_id ) );
			}
		);

		$this->action(
			'profile_update',
			function ( $user_id, $old ): void {
				$this->record(
					'user.update',
					'user',
					(int) $user_id,
					is_object( $old ) ? (array) $old->data : null,
					$this->user_state( (int) $user_id )
				);
			},
			2
		);

		$this->action(
			'deleted_user',
			function ( $user_id ): void {
				$this->record( 'user.delete', 'user', (int) $user_id, null, null );
			}
		);

		/* ---------------- Terms ---------------- */

		$this->action(
			'created_term',
			function ( $term_id, $tt_id, $taxonomy ): void {
				$this->record( 'term.create', 'term', (int) $term_id, null, get_term( (int) $term_id, (string) $taxonomy, ARRAY_A ) );
			},
			3
		);

		$this->action(
			'edited_term',
			function ( $term_id, $tt_id, $taxonomy ): void {
				$this->record( 'term.update', 'term', (int) $term_id, null, get_term( (int) $term_id, (string) $taxonomy, ARRAY_A ) );
			},
			3
		);

		$this->action(
			'delete_term',
			function ( $term, $tt_id, $taxonomy, $deleted ): void {
				$this->record(
					'term.delete',
					'term',
					is_object( $deleted ) ? (int) $deleted->term_id : (int) $term,
					is_object( $deleted ) ? (array) $deleted : null,
					null
				);
			},
			4
		);

		/* ---------------- Comments ---------------- */

		$this->action(
			'wp_insert_comment',
			function ( $id ): void {
				$this->record( 'comment.create', 'comment', (int) $id, null, get_comment( (int) $id, ARRAY_A ) );
			}
		);

		$this->action(
			'deleted_comment',
			function ( $id ): void {
				$this->record( 'comment.delete', 'comment', (int) $id, null, null );
			}
		);

		/* ---------------- Outbound side effects ---------------- */

		$this->action(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->record( 'http.request', 'http', (string) $url, null, null );
				return $pre;
			},
			3
		);

		/* ---------------- SQL backstop ---------------- */

		add_filter( 'query', array( $this, 'watch_sql' ), PHP_INT_MAX );
		$this->listeners[] = array( 'query', array( $this, 'watch_sql' ), 1, 'filter' );
	}

	/**
	 * Records every write statement, whichever table it targets.
	 *
	 * @param string $query SQL.
	 * @return string Unmodified — this is an observer, not a rewriter.
	 */
	public function watch_sql( $query ) {
		if ( $this->paused || ! is_string( $query ) ) {
			return $query;
		}
		if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|CREATE|DROP|RENAME)\b/i', $query, $verb ) ) {
			return $query;
		}

		$op    = strtoupper( $verb[1] );
		$table = '(unknown)';
		if ( preg_match( '/(?:INTO|UPDATE|FROM|TABLE)\s+`?([A-Za-z0-9_]+)`?/i', $query, $m ) ) {
			$table = $m[1];
		}

		global $wpdb;
		$bare = preg_replace( '/^' . preg_quote( $wpdb->prefix, '/' ) . '/', '', $table );

		// Never profile our own bookkeeping as the ability's behaviour. Matched
		// exactly rather than by prefix — another plugin is free to own tables
		// that merely start the same way, and silently ignoring their writes
		// would reintroduce the blind spot this backstop exists to close.
		if ( in_array( $table, array( Schema::requests_table(), Schema::audit_table() ), true ) ) {
			return $query;
		}

		$this->sql[] = array(
			'op'     => $op,
			'table'  => $table,
			'core'   => in_array( $bare, self::CORE_TABLES, true ),
			'schema' => in_array( $op, self::DDL, true ),
			'query'  => trim( (string) preg_replace( '/\s+/', ' ', $query ) ),
		);

		return $query;
	}

	private function user_state( int $user_id ): ?array {
		$u = get_userdata( $user_id );
		return $u ? (array) $u->data : null;
	}

	/**
	 * @param mixed $id
	 * @param mixed $before
	 * @param mixed $after
	 */
	private function record( string $op, string $type, $id, $before, $after ): void {
		if ( $this->paused ) {
			return;
		}
		$this->ops[] = array(
			'op'     => $op,
			'type'   => $type,
			'id'     => $id,
			'before' => $before,
			'after'  => $after,
		);
	}

	public function stop(): void {
		foreach ( $this->listeners as $l ) {
			if ( 'filter' === $l[3] ) {
				remove_filter( $l[0], $l[1], PHP_INT_MAX );
			} else {
				remove_action( $l[0], $l[1], -PHP_INT_MAX );
			}
		}
		$this->listeners = array();
	}

	/* ---------------- Results ---------------- */

	/** @return array<int,array> */
	public function operations(): array {
		return $this->ops;
	}

	/** @return array<int,array> */
	public function sql_writes(): array {
		return $this->sql;
	}

	/** Writes to non-core tables — no action hook reported these. */
	public function uncovered_writes(): array {
		return array_values( array_filter( $this->sql, static fn( $s ) => ! $s['core'] ) );
	}

	/** Distinct operations, including a synthetic entry per custom table. */
	public function footprint(): array {
		$fp = array_column( $this->ops, 'op' );
		foreach ( $this->uncovered_writes() as $s ) {
			$fp[] = 'sql.' . strtolower( $s['op'] ) . ':' . $s['table'];
		}
		return array_values( array_unique( $fp ) );
	}

	/** True only when nothing was written anywhere. */
	public function is_readonly(): bool {
		foreach ( $this->ops as $o ) {
			if ( 'http' !== $o['type'] ) {
				return false;
			}
		}
		return empty( $this->uncovered_writes() );
	}

	/** DDL implicitly commits in MySQL, so a dry-run cannot roll it back. */
	public function has_schema_change(): bool {
		foreach ( $this->sql as $s ) {
			if ( $s['schema'] ) {
				return true;
			}
		}
		return false;
	}

	/** Field-level diff, reporting only keys that actually changed. */
	public static function diff_fields( ?array $before, ?array $after ): array {
		$before = $before ?? array();
		$after  = $after ?? array();
		$out    = array();
		foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $k ) {
			$b = $before[ $k ] ?? null;
			$a = $after[ $k ] ?? null;
			if ( $b !== $a ) {
				$out[ $k ] = array( 'before' => $b, 'after' => $a );
			}
		}
		return $out;
	}
}
