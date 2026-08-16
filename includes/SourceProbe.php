<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads an ability's callback source and reports what it can actually do.
 *
 * Static analysis beats naming because it looks at the work rather than the
 * label: `wp-count-posts` is a read no matter how its slug scans, and a tool
 * called `get-report` that quietly calls wp_mail() is not.
 *
 * The analysis is deliberately incomplete. PHP can dispatch dynamically —
 * call_user_func on a variable, __call, eval — so no reader can be exhaustive.
 * That is why the fourth verdict is OPAQUE rather than a guess: an honest
 * "cannot tell" gates safely, while an invented answer does not. The one
 * property this class guarantees is that it never returns READ for source it
 * did not fully understand.
 */
final class SourceProbe {

	public const READ  = 'read';
	public const WRITE = 'write';
	/** Irreversible and invisible to the Recorder: mail, HTTP, filesystem. */
	public const EMIT   = 'emit';
	public const OPAQUE = 'opaque';

	/** Hops to follow when a callback only delegates. */
	private const MAX_DEPTH = 3;

	/** Database and object mutation. */
	private const WRITE_MARKERS = array(
		'wp_insert_post', 'wp_update_post', 'wp_delete_post', 'wp_trash_post',
		'wp_untrash_post', 'wp_publish_post', 'wp_insert_attachment',
		'wp_delete_attachment', 'wp_insert_user', 'wp_update_user',
		'wp_delete_user', 'wp_create_user', 'wp_insert_term', 'wp_update_term',
		'wp_delete_term', 'wp_set_object_terms', 'wp_insert_comment',
		'wp_update_comment', 'wp_delete_comment', 'wp_trash_comment',
		'wp_set_comment_status', 'update_option', 'add_option', 'delete_option',
		'update_post_meta', 'add_post_meta', 'delete_post_meta',
		'update_user_meta', 'add_user_meta', 'delete_user_meta',
		'update_term_meta', 'add_term_meta', 'delete_term_meta',
		'update_comment_meta', 'add_comment_meta', 'delete_comment_meta',
		'set_transient', 'delete_transient', 'set_site_transient',
		'wp_schedule_event', 'wp_schedule_single_event', 'wp_unschedule_event',
		'wp_clear_scheduled_hook', 'wp_handle_upload', 'wp_handle_sideload',
		'media_handle_upload', 'media_handle_sideload', 'wp_generate_attachment_metadata',
	);

	/** Irreversible side effects that leave the site. */
	private const EMIT_MARKERS = array(
		'wp_mail', 'wp_remote_post', 'wp_remote_request', 'wp_remote_head',
		'file_put_contents', 'fwrite', 'fputs', 'unlink', 'rename', 'copy',
		'mkdir', 'rmdir', 'move_uploaded_file', 'curl_exec', 'mail',
		'wp_safe_remote_post', 'wp_safe_remote_request',
	);

	/** $wpdb methods that mutate. */
	private const WPDB_WRITE_METHODS = array(
		'insert', 'update', 'delete', 'replace',
	);

	/**
	 * Constructs that defeat static reading. Their presence forces OPAQUE
	 * rather than a clean READ, because what runs is decided at runtime.
	 */
	private const DYNAMIC_MARKERS = array(
		'call_user_func', 'call_user_func_array', 'eval', 'create_function',
		'assert', 'preg_replace_callback_array',
	);

	/** @var array<string,array> Memoised results, by ability name. */
	private static $cache = array();

	/**
	 * Classify an ability by reading its callback.
	 *
	 * @param string $ability Ability name.
	 * @return array{kind:string,reason:string,markers:array<int,string>}
	 */
	public static function inspect( string $ability ): array {
		if ( isset( self::$cache[ $ability ] ) ) {
			return self::$cache[ $ability ];
		}

		$callback = Interceptor::original_callback( $ability );
		if ( ! is_callable( $callback ) ) {
			return self::$cache[ $ability ] = self::verdict(
				self::OPAQUE,
				__( 'The original callback is not available to inspect.', 'mcp-ability-guard' )
			);
		}

		$seen   = array();
		$result = self::walk( $callback, 0, $seen );

		return self::$cache[ $ability ] = $result;
	}

	/**
	 * Read one callable, following delegation up to MAX_DEPTH.
	 *
	 * @param callable            $callback Callable to read.
	 * @param int                 $depth    Current hop.
	 * @param array<string,bool>  $seen     Guards against mutual recursion.
	 * @return array{kind:string,reason:string,markers:array<int,string>}
	 */
	private static function walk( $callback, int $depth, array &$seen ): array {
		if ( $depth > self::MAX_DEPTH ) {
			return self::verdict(
				self::OPAQUE,
				__( 'Delegation ran deeper than this reader follows.', 'mcp-ability-guard' )
			);
		}

		$source = self::source_of( $callback, $seen );
		if ( null === $source ) {
			return self::verdict(
				self::OPAQUE,
				__( 'The callback source could not be read.', 'mcp-ability-guard' )
			);
		}

		$found = self::scan( $source );

		// Any irreversible effect outranks everything: it cannot be observed
		// by the Recorder afterwards, so it must be caught here or not at all.
		if ( $found['emit'] ) {
			return self::verdict(
				self::EMIT,
				sprintf(
					/* translators: %s: comma-separated function names. */
					__( 'Sends or writes outside the database: %s', 'mcp-ability-guard' ),
					implode( ', ', $found['emit'] )
				),
				$found['emit']
			);
		}

		if ( $found['write'] ) {
			return self::verdict(
				self::WRITE,
				sprintf(
					/* translators: %s: comma-separated function names. */
					__( 'Calls: %s', 'mcp-ability-guard' ),
					implode( ', ', $found['write'] )
				),
				$found['write']
			);
		}

		// Nothing mutating here, but the body may only be a hand-off. Follow it
		// before concluding anything: Royal MCP's abilities delegate into a
		// switch, and stopping at the wrapper would read every one as a read.
		foreach ( $found['delegates'] as $target ) {
			$next = self::walk( $target, $depth + 1, $seen );
			if ( self::READ !== $next['kind'] ) {
				return $next;
			}
		}

		// Unresolvable delegation or runtime dispatch: refuse to call it a read.
		if ( $found['dynamic'] || $found['unresolved'] ) {
			return self::verdict(
				self::OPAQUE,
				$found['dynamic']
					? __( 'Dispatches at runtime, so what it calls cannot be read.', 'mcp-ability-guard' )
					: __( 'Hands off to something this reader could not resolve.', 'mcp-ability-guard' )
			);
		}

		return self::verdict(
			self::READ,
			__( 'No database writes, no outbound requests, nothing dispatched at runtime.', 'mcp-ability-guard' )
		);
	}

	/**
	 * Source text for a callable, plus the class it lives in for hop
	 * resolution.
	 *
	 * @param callable           $callback Callable.
	 * @param array<string,bool> $seen     Visited signatures.
	 * @return array{code:string,class:string}|null
	 */
	private static function source_of( $callback, array &$seen ): ?array {
		try {
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$ref   = new \ReflectionMethod( $callback[0], $callback[1] );
				$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			} elseif ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				list( $c, $m ) = explode( '::', $callback, 2 );
				$ref           = new \ReflectionMethod( $c, $m );
				$class         = $c;
			} elseif ( is_object( $callback ) && ! $callback instanceof \Closure ) {
				$ref   = new \ReflectionMethod( $callback, '__invoke' );
				$class = get_class( $callback );
			} else {
				$ref   = new \ReflectionFunction( $callback );
				$class = '';
				$scope = $ref->getClosureScopeClass();
				if ( $scope ) {
					$class = $scope->getName();
				}
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		$signature = ( $class ?: '' ) . '::' . $ref->getName() . '@' . (string) $ref->getFileName() . ':' . (string) $ref->getStartLine();
		if ( isset( $seen[ $signature ] ) ) {
			return null;
		}
		$seen[ $signature ] = true;

		// Internal functions have no readable body.
		if ( method_exists( $ref, 'isInternal' ) && $ref->isInternal() ) {
			return null;
		}

		$file = $ref->getFileName();
		$from = $ref->getStartLine();
		$to   = $ref->getEndLine();

		if ( ! $file || ! $from || ! $to || ! is_readable( $file ) ) {
			return null;
		}

		$lines = @file( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- unreadable file is handled below.
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$code = implode( '', array_slice( $lines, $from - 1, $to - $from + 1 ) );

		return array( 'code' => $code, 'class' => $class );
	}

	/**
	 * Tokenise a body and collect what it does.
	 *
	 * Tokens rather than a regex so that a marker inside a string literal or a
	 * comment — a description mentioning "wp_delete_post", say — is not counted
	 * as a call.
	 *
	 * @param array{code:string,class:string} $source Source fragment.
	 * @return array{write:array,emit:array,delegates:array,dynamic:bool,unresolved:bool}
	 */
	private static function scan( array $source ): array {
		$out = array(
			'write'      => array(),
			'emit'       => array(),
			'delegates'  => array(),
			'dynamic'    => false,
			'unresolved' => false,
		);

		$tokens = @token_get_all( '<?php ' . $source['code'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- partial bodies can warn.
		if ( ! is_array( $tokens ) ) {
			$out['unresolved'] = true;
			return $out;
		}

		$flat = array();
		foreach ( $tokens as $t ) {
			$flat[] = is_array( $t ) ? array( $t[0], $t[1] ) : array( -1, $t );
		}

		$count = count( $flat );

		for ( $i = 0; $i < $count; $i++ ) {
			list( $id, $text ) = $flat[ $i ];

			if ( T_STRING !== $id && T_VARIABLE !== $id ) {
				continue;
			}

			$next = self::next_meaningful( $flat, $i );

			/* ---- $wpdb->method(...) and $this->method(...) ---- */
			if ( T_VARIABLE === $id && $next && T_OBJECT_OPERATOR === $next[0] ) {
				$method = self::next_meaningful( $flat, $next[1] );
				if ( ! $method || T_STRING !== $method[0] ) {
					continue;
				}
				$name  = strtolower( $method[2] );
				$after = self::next_meaningful( $flat, $method[1] );
				$is_call = $after && '(' === $after[2];

				if ( '$wpdb' === strtolower( $text ) && $is_call ) {
					if ( in_array( $name, self::WPDB_WRITE_METHODS, true ) ) {
						$out['write'][] = '$wpdb->' . $name;
					} elseif ( 'query' === $name || 'get_results' === $name ) {
						// A raw statement cannot be judged from here; the
						// Recorder's query filter is the authority at runtime.
						$out['unresolved'] = true;
					}
					continue;
				}

				if ( $is_call && '' !== $source['class'] ) {
					$target = self::resolve_method( $source['class'], $method[2] );
					if ( $target ) {
						$out['delegates'][] = $target;
					} else {
						$out['unresolved'] = true;
					}
				}
				continue;
			}

			if ( T_STRING !== $id ) {
				continue;
			}

			// Static call Class::method(...)
			$prev = self::prev_meaningful( $flat, $i );
			if ( $prev && T_DOUBLE_COLON === $prev[0] ) {
				$owner = self::prev_meaningful( $flat, $prev[1] );
				$after = self::next_meaningful( $flat, $i );
				if ( $owner && $after && '(' === $after[2] ) {
					$class  = 'self' === strtolower( $owner[2] ) || 'static' === strtolower( $owner[2] )
						? $source['class']
						: $owner[2];
					$target = $class ? self::resolve_method( $class, $text ) : null;
					if ( $target ) {
						$out['delegates'][] = $target;
					} else {
						$out['unresolved'] = true;
					}
				}
				continue;
			}

			// Plain function call name(...)
			if ( ! $next || '(' !== $next[2] ) {
				continue;
			}

			$lower = strtolower( $text );

			if ( in_array( $lower, self::EMIT_MARKERS, true ) ) {
				$out['emit'][] = $lower;
			} elseif ( in_array( $lower, self::WRITE_MARKERS, true ) ) {
				$out['write'][] = $lower;
			} elseif ( in_array( $lower, self::DYNAMIC_MARKERS, true ) ) {
				$out['dynamic'] = true;
			}
		}

		$out['write'] = array_values( array_unique( $out['write'] ) );
		$out['emit']  = array_values( array_unique( $out['emit'] ) );

		return $out;
	}

	/**
	 * Turn a class/method pair into a callable this probe can read.
	 *
	 * @return callable|null
	 */
	private static function resolve_method( string $class, string $method ) {
		if ( '' === $class || ! class_exists( $class ) ) {
			return null;
		}
		try {
			$ref = new \ReflectionMethod( $class, $method );
		} catch ( \Throwable $e ) {
			return null;
		}
		if ( $ref->isInternal() ) {
			return null;
		}

		return array( $class, $method );
	}

	/**
	 * @param array<int,array{0:int,1:string}> $flat
	 * @return array{0:int,1:int,2:string}|null {token id, index, text}
	 */
	private static function next_meaningful( array $flat, int $i ): ?array {
		$count = count( $flat );
		for ( $j = $i + 1; $j < $count; $j++ ) {
			if ( in_array( $flat[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return array( $flat[ $j ][0], $j, $flat[ $j ][1] );
		}
		return null;
	}

	/**
	 * @param array<int,array{0:int,1:string}> $flat
	 * @return array{0:int,1:int,2:string}|null
	 */
	private static function prev_meaningful( array $flat, int $i ): ?array {
		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( in_array( $flat[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			return array( $flat[ $j ][0], $j, $flat[ $j ][1] );
		}
		return null;
	}

	/**
	 * @param array<int,string> $markers
	 * @return array{kind:string,reason:string,markers:array<int,string>}
	 */
	private static function verdict( string $kind, string $reason, array $markers = array() ): array {
		return array(
			'kind'    => $kind,
			'reason'  => $reason,
			'markers' => $markers,
		);
	}

	/** Drop memoised verdicts (profiles reset, plugin activation). */
	public static function flush(): void {
		self::$cache = array();
	}
}
