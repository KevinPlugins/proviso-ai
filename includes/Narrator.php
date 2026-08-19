<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Turns recorded operations into sentences a person can check.
 *
 * The audit log stores what changed with perfect fidelity and no meaning:
 * `post.update` on 1742 with six altered columns. A reviewer asked "is this
 * alright?" needs "the Pricing page went from draft to published", and the
 * distance between those two is the whole reason approval queues get
 * rubber-stamped.
 *
 * Three rules shape everything here:
 *
 *  1. Never print a value that is large or secret. Post content becomes a size
 *     delta, option values are truncated, and anything {@see Redactor} calls
 *     sensitive is masked. A summary that dumps a 40KB body is not a summary.
 *  2. Suppress bookkeeping. Saving a post also writes post_modified, guid and
 *     comment_count; rendering those buries the one line that mattered.
 *  3. Say when it does not know. Writes to a plugin's own tables and outbound
 *     requests are reported as exactly that, rather than omitted — an
 *     incomplete summary that looks complete is worse than an honest gap.
 */
final class Narrator {

	/** Columns written by WordPress as a side effect of saving, not as intent. */
	private const NOISE = array(
		'post_modified', 'post_modified_gmt', 'guid', 'comment_count',
		'post_date_gmt', 'post_content_filtered', 'to_ping', 'pinged',
		'post_mime_type', 'filter', 'ancestors', 'page_template',
		'user_activation_key', 'user_pass', 'session_tokens',
		'term_group', 'filter_key', 'count',
	);

	/** Longest value fragment shown inline. */
	private const MAX_VALUE = 60;

	/** Ops beyond this many of one kind collapse into a count. */
	private const COLLAPSE_AFTER = 4;

	/**
	 * Render a stored operations array into display lines.
	 *
	 * @param array $operations As persisted by {@see AuditLog::record()}.
	 * @return array<int,array{title:string,changes:array<int,string>,note:string}>
	 */
	public static function summarise( array $operations ): array {
		$lines   = array();
		$grouped = array();

		foreach ( $operations as $op ) {
			if ( ! is_array( $op ) || empty( $op['op'] ) ) {
				continue;
			}
			$grouped[ (string) $op['op'] ][] = $op;
		}

		foreach ( $grouped as $name => $ops ) {
			// Many operations of one kind are a batch, not a story. Naming the
			// first few and counting the rest keeps a twenty-change run legible.
			if ( count( $ops ) > self::COLLAPSE_AFTER ) {
				$shown = array_slice( $ops, 0, self::COLLAPSE_AFTER );
				foreach ( $shown as $op ) {
					$lines[] = self::render( $op );
				}
				$rest    = count( $ops ) - self::COLLAPSE_AFTER;
				$lines[] = array(
					'title'   => sprintf(
						/* translators: 1: number of further operations, 2: operation verb, e.g. "updated". */
						_n( '…and %1$d more %2$s', '…and %1$d more %2$s', $rest, 'proviso-ai' ),
						$rest,
						self::verb( (string) $name )
					),
					'changes' => array(),
					'note'    => '',
				);
				continue;
			}

			foreach ( $ops as $op ) {
				$lines[] = self::render( $op );
			}
		}

		return $lines;
	}

	/**
	 * One operation as a heading, a list of changes, and an optional caveat.
	 *
	 * @param array $op Stored operation.
	 * @return array{title:string,changes:array<int,string>,note:string}
	 */
	private static function render( array $op ): array {
		$name = (string) ( $op['op'] ?? '' );
		$type = (string) ( $op['type'] ?? '' );
		$id   = $op['id'] ?? '';
		$diff = is_array( $op['diff'] ?? null ) ? $op['diff'] : array();

		if ( 'table' === $type ) {
			return array(
				'title'   => sprintf(
					/* translators: %s: database table name. */
					__( 'Wrote to %s', 'proviso-ai' ),
					(string) $id
				),
				'changes' => array(),
				'note'    => __( 'A plugin’s own table. The change was detected but cannot be shown or undone.', 'proviso-ai' ),
			);
		}

		if ( 'http' === $type ) {
			return array(
				'title'   => sprintf(
					/* translators: %s: destination URL. */
					__( 'Sent a request to %s', 'proviso-ai' ),
					self::clip( (string) $id )
				),
				'changes' => array(),
				'note'    => __( 'Left the site. This cannot be undone.', 'proviso-ai' ),
			);
		}

		return array(
			'title'   => trim( self::verb( $name ) . ' ' . self::label( $type, $id, $diff ) ),
			'changes' => self::changes( $type, $diff ),
			'note'    => '',
		);
	}

	/** Human verb for an operation name. */
	private static function verb( string $op ): string {
		$action = (string) strstr( $op, '.', false );
		$action = ltrim( $action, '.' );

		switch ( $action ) {
			case 'create':
				return __( 'Created', 'proviso-ai' );
			case 'update':
				return __( 'Updated', 'proviso-ai' );
			case 'delete':
				return __( 'Deleted', 'proviso-ai' );
			case 'trash':
				return __( 'Moved to trash', 'proviso-ai' );
			case 'untrash':
			case 'restore':
				return __( 'Restored', 'proviso-ai' );
			default:
				return __( 'Changed', 'proviso-ai' );
		}
	}

	/**
	 * Name the thing that changed, preferring a title a person would recognise.
	 *
	 * @param mixed $id
	 * @param array $diff
	 */
	private static function label( string $type, $id, array $diff ): string {
		switch ( $type ) {
			case 'post':
				$title = self::from_diff( $diff, 'post_title' );
				if ( '' === $title && is_numeric( $id ) ) {
					$title = (string) get_the_title( (int) $id );
				}
				return $title
					? sprintf( '%s #%s · “%s”', __( 'post', 'proviso-ai' ), (string) $id, self::clip( $title ) )
					: sprintf( '%s #%s', __( 'post', 'proviso-ai' ), (string) $id );

			case 'option':
				return sprintf( '%s “%s”', __( 'setting', 'proviso-ai' ), (string) $id );

			case 'user':
				$login = self::from_diff( $diff, 'user_login' );
				if ( '' === $login && is_numeric( $id ) ) {
					$u     = get_userdata( (int) $id );
					$login = $u ? (string) $u->user_login : '';
				}
				return $login
					? sprintf( '%s “%s”', __( 'user', 'proviso-ai' ), self::clip( $login ) )
					: sprintf( '%s #%s', __( 'user', 'proviso-ai' ), (string) $id );

			case 'term':
				$name = self::from_diff( $diff, 'name' );
				return $name
					? sprintf( '%s “%s”', __( 'term', 'proviso-ai' ), self::clip( $name ) )
					: sprintf( '%s #%s', __( 'term', 'proviso-ai' ), (string) $id );

			case 'comment':
				return sprintf( '%s #%s', __( 'comment', 'proviso-ai' ), (string) $id );

			default:
				return sprintf( '%s #%s', esc_html( $type ), (string) $id );
		}
	}

	/**
	 * Field-level changes, noise removed.
	 *
	 * @return array<int,string>
	 */
	private static function changes( string $type, array $diff ): array {
		$out = array();

		foreach ( $diff as $field => $pair ) {
			$field = (string) $field;

			if ( in_array( $field, self::NOISE, true ) ) {
				continue;
			}
			if ( ! is_array( $pair ) ) {
				continue;
			}

			$before = $pair['before'] ?? null;
			$after  = $pair['after'] ?? null;
			$line   = self::field( $type, $field, $before, $after );

			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * One field, rendered the way that field deserves.
	 *
	 * @param mixed $before
	 * @param mixed $after
	 */
	private static function field( string $type, string $field, $before, $after ): string {
		// Bodies are measured, never printed: the point of a summary is that it
		// is shorter than the thing it summarises.
		if ( in_array( $field, array( 'post_content', 'post_excerpt', 'description', 'comment_content' ), true ) ) {
			$delta = strlen( (string) $after ) - strlen( (string) $before );
			if ( 0 === $delta ) {
				return sprintf(
					/* translators: %s: field label. */
					__( '%s rewritten, same length', 'proviso-ai' ),
					self::field_label( $field )
				);
			}
			return sprintf(
				/* translators: 1: field label, 2: signed character count, e.g. "+2,140". */
				__( '%1$s %2$s characters', 'proviso-ai' ),
				self::field_label( $field ),
				( $delta > 0 ? '+' : '−' ) . number_format_i18n( abs( $delta ) )
			);
		}

		if ( 'post_status' === $field ) {
			return sprintf( '%s: %s → %s', self::field_label( $field ), self::status( (string) $before ), self::status( (string) $after ) );
		}

		if ( in_array( $field, array( 'post_author', 'user_id' ), true ) ) {
			return sprintf( '%s: %s → %s', self::field_label( $field ), self::user_name( $before ), self::user_name( $after ) );
		}

		if ( 'post_parent' === $field ) {
			return sprintf( '%s: %s → %s', self::field_label( $field ), self::post_name( $before ), self::post_name( $after ) );
		}

		// A secret that changed must still read as changed. Masking both sides
		// first would make them equal and the difference would vanish, which is
		// the one outcome worse than showing the value.
		if ( Redactor::is_sensitive( $field ) ) {
			if ( $before === $after ) {
				return '';
			}
			return sprintf(
				/* translators: %s: field label. */
				__( '%s changed (value hidden)', 'proviso-ai' ),
				self::field_label( $field )
			);
		}

		// Anything unrecognised falls back to a clipped value pair — which is
		// also the path every option takes, since an option can hold literally
		// anything.
		$b = self::scalar( $field, $before );
		$a = self::scalar( $field, $after );

		if ( $b === $a ) {
			return '';
		}
		if ( '' === $b ) {
			return sprintf( '%s: %s', self::field_label( $field ), $a );
		}

		return sprintf( '%s: %s → %s', self::field_label( $field ), $b, $a );
	}

	/**
	 * A value reduced to something safe and short.
	 *
	 * @param mixed $value
	 */
	private static function scalar( string $field, $value ): string {
		if ( null === $value ) {
			return __( '(none)', 'proviso-ai' );
		}

		if ( Redactor::is_sensitive( $field ) ) {
			return Redactor::MASK;
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'yes', 'proviso-ai' ) : __( 'no', 'proviso-ai' );
		}

		if ( is_scalar( $value ) ) {
			return self::clip( (string) $value );
		}

		// Arrays are described by shape rather than dumped; a serialised option
		// can be enormous and is never readable inline.
		if ( is_array( $value ) ) {
			return sprintf(
				/* translators: %d: number of entries in a list. */
				_n( '%d entry', '%d entries', count( $value ), 'proviso-ai' ),
				count( $value )
			);
		}

		return __( '(complex value)', 'proviso-ai' );
	}

	/** Post status slugs are not words people use. */
	private static function status( string $slug ): string {
		$map = array(
			'publish' => __( 'published', 'proviso-ai' ),
			'draft'   => __( 'draft', 'proviso-ai' ),
			'pending' => __( 'pending review', 'proviso-ai' ),
			'private' => __( 'private', 'proviso-ai' ),
			'future'  => __( 'scheduled', 'proviso-ai' ),
			'trash'   => __( 'trashed', 'proviso-ai' ),
			'auto-draft' => __( 'auto-draft', 'proviso-ai' ),
			'inherit' => __( 'inherited', 'proviso-ai' ),
		);

		return $map[ $slug ] ?? ( '' === $slug ? __( '(none)', 'proviso-ai' ) : $slug );
	}

	/** @param mixed $id */
	private static function user_name( $id ): string {
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			return __( '(none)', 'proviso-ai' );
		}
		$u = get_userdata( (int) $id );
		return $u ? self::clip( (string) $u->user_login ) : '#' . (string) (int) $id;
	}

	/** @param mixed $id */
	private static function post_name( $id ): string {
		if ( ! is_numeric( $id ) || (int) $id <= 0 ) {
			return __( '(none)', 'proviso-ai' );
		}
		$t = get_the_title( (int) $id );
		return $t ? self::clip( (string) $t ) : '#' . (string) (int) $id;
	}

	/** Column names as a person would say them. */
	private static function field_label( string $field ): string {
		$map = array(
			'post_title'     => __( 'title', 'proviso-ai' ),
			'post_content'   => __( 'content', 'proviso-ai' ),
			'post_excerpt'   => __( 'excerpt', 'proviso-ai' ),
			'post_status'    => __( 'status', 'proviso-ai' ),
			'post_name'      => __( 'slug', 'proviso-ai' ),
			'post_author'    => __( 'author', 'proviso-ai' ),
			'post_parent'    => __( 'parent', 'proviso-ai' ),
			'post_date'      => __( 'date', 'proviso-ai' ),
			'menu_order'     => __( 'order', 'proviso-ai' ),
			'comment_status' => __( 'comments', 'proviso-ai' ),
			'ping_status'    => __( 'pingbacks', 'proviso-ai' ),
			'post_password'  => __( 'password', 'proviso-ai' ),
			'user_email'     => __( 'email', 'proviso-ai' ),
			'user_login'     => __( 'username', 'proviso-ai' ),
			'display_name'   => __( 'display name', 'proviso-ai' ),
			'user_url'       => __( 'website', 'proviso-ai' ),
			'name'           => __( 'name', 'proviso-ai' ),
			'slug'           => __( 'slug', 'proviso-ai' ),
			'description'    => __( 'description', 'proviso-ai' ),
			'parent'         => __( 'parent', 'proviso-ai' ),
			'comment_approved' => __( 'approval', 'proviso-ai' ),
		);

		return $map[ $field ] ?? str_replace( '_', ' ', $field );
	}

	/** Shorten without cutting mid-entity. */
	private static function clip( string $value ): string {
		$value = trim( wp_strip_all_tags( $value ) );
		if ( '' === $value ) {
			return __( '(empty)', 'proviso-ai' );
		}
		if ( mb_strlen( $value ) <= self::MAX_VALUE ) {
			return $value;
		}
		return mb_substr( $value, 0, self::MAX_VALUE - 1 ) . '…';
	}

	/** @param array $diff */
	private static function from_diff( array $diff, string $field ): string {
		$pair = $diff[ $field ] ?? null;
		if ( ! is_array( $pair ) ) {
			return '';
		}
		$value = $pair['after'] ?? $pair['before'] ?? '';
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * A single sentence for a whole run, for list views and notifications.
	 *
	 * @param array $operations Stored operations.
	 */
	public static function headline( array $operations ): string {
		if ( ! $operations ) {
			return __( 'No changes recorded.', 'proviso-ai' );
		}

		$writes = 0;
		$emits  = 0;
		$tables = 0;

		foreach ( $operations as $op ) {
			$type = (string) ( $op['type'] ?? '' );
			if ( 'http' === $type ) {
				++$emits;
			} elseif ( 'table' === $type ) {
				++$tables;
			} else {
				++$writes;
			}
		}

		$parts = array();
		if ( $writes ) {
			/* translators: %d: number of changed objects. */
			$parts[] = sprintf( _n( '%d change', '%d changes', $writes, 'proviso-ai' ), $writes );
		}
		if ( $tables ) {
			/* translators: %d: number of custom-table writes. */
			$parts[] = sprintf( _n( '%d table write', '%d table writes', $tables, 'proviso-ai' ), $tables );
		}
		if ( $emits ) {
			/* translators: %d: number of outbound requests. */
			$parts[] = sprintf( _n( '%d outbound request', '%d outbound requests', $emits, 'proviso-ai' ), $emits );
		}

		return implode( __( ', ', 'proviso-ai' ), $parts );
	}
}
