<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * What each ability has been observed to do.
 *
 * The plugin does not trust an ability's declared annotations — core defaults
 * `readonly`, `destructive` and `idempotent` to NULL, so most third-party
 * abilities declare nothing at all. Instead each execution is observed and the
 * footprint accumulated here. Confidence rises with observation count.
 */
final class Profiles {

	public const OPTION = 'mag_profiles';

	public const UNKNOWN   = 'unknown';    // Never observed.
	public const LEARNING  = 'learning';   // Seen, but not enough to trust.
	public const CONFIRMED = 'confirmed';  // Seen consistently.

	/** Observations before a profile is treated as settled. */
	public const CONFIRM_AFTER = 3;

	/** @var array<string,array>|null Request-level cache. */
	private static $cache = null;

	/** @return array<string,array> */
	public static function all(): array {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = is_array( $stored ) ? $stored : array();
		}
		return self::$cache;
	}

	public static function get( string $ability ): array {
		$all = self::all();
		return $all[ $ability ] ?? array(
			'operations'   => array(),
			'tables'       => array(),
			'observations' => 0,
			'confidence'   => self::UNKNOWN,
			'readonly'     => null,
			'ddl'          => false,
			'last_seen'    => null,
		);
	}

	/**
	 * Fold one observed execution into the ability's profile.
	 *
	 * Operations accumulate as a union rather than replacing: plenty of
	 * abilities branch (create when absent, update when present), and a
	 * reviewer needs to know the ability *can* delete even if today it didn't.
	 *
	 * @return array{0:array,1:array} The new profile and any newly seen ops.
	 */
	public static function observe( string $ability, Recorder $rec ): array {
		$profile = self::get( $ability );

		$seen = $rec->footprint();
		$new  = array_values( array_diff( $seen, $profile['operations'] ) );

		$profile['operations']   = array_values( array_unique( array_merge( $profile['operations'], $seen ) ) );
		$profile['observations'] = (int) $profile['observations'] + 1;
		$profile['last_seen']    = current_time( 'mysql' );
		$profile['ddl']          = $profile['ddl'] || $rec->has_schema_change();

		foreach ( $rec->uncovered_writes() as $w ) {
			if ( ! in_array( $w['table'], $profile['tables'], true ) ) {
				$profile['tables'][] = $w['table'];
			}
		}

		// Readonly is sticky-false: one observed write settles it forever.
		if ( false !== $profile['readonly'] ) {
			$profile['readonly'] = $rec->is_readonly();
		}

		$profile['confidence'] = $profile['observations'] >= self::CONFIRM_AFTER
			? self::CONFIRMED
			: self::LEARNING;

		self::save( $ability, $profile );

		return array( $profile, $new );
	}

	private static function save( string $ability, array $profile ): void {
		$all              = self::all();
		$all[ $ability ]  = $profile;
		self::$cache      = $all;
		update_option( self::OPTION, $all, false );
	}

	public static function forget( string $ability ): void {
		$all = self::all();
		unset( $all[ $ability ] );
		self::$cache = $all;
		update_option( self::OPTION, $all, false );
	}

	public static function reset(): void {
		self::$cache = array();
		update_option( self::OPTION, array(), false );
	}

	/**
	 * Verbs that only ever look. Kept generous: a read misfiled as a write
	 * costs one needless approval, so the expensive mistake is the other way.
	 */
	private const READ_VERBS = array(
		'get', 'list', 'read', 'search', 'find', 'query', 'fetch', 'view',
		'show', 'load', 'count', 'check', 'validate', 'verify', 'test',
		'status', 'health', 'exists', 'has', 'is', 'can', 'preview',
		'describe', 'summarize', 'summarise', 'export', 'inspect', 'lookup',
		'resolve', 'compare', 'diff', 'calculate', 'analyze', 'analyse',
		'report', 'stats', 'discover', 'info', 'overview', 'browse', 'scan',
	);

	/**
	 * Verbs that change something, mapped to the kind of change.
	 *
	 * `emit` is its own class because those effects leave the site: the
	 * Recorder cannot see them and Rollback cannot undo them, so they must
	 * never be eligible for auto-approve however cleanly they have run.
	 */
	private const WRITE_VERBS = array(
		'create'     => 'create',
		'add'        => 'create',
		'insert'     => 'create',
		'new'        => 'create',
		'register'   => 'create',
		'publish'    => 'create',
		'upload'     => 'create',
		'import'     => 'create',
		'duplicate'  => 'create',
		'clone'      => 'create',
		'generate'   => 'create',
		'update'     => 'update',
		'edit'       => 'update',
		'set'        => 'update',
		'modify'     => 'update',
		'change'     => 'update',
		'rename'     => 'update',
		'move'       => 'update',
		'assign'     => 'update',
		'approve'    => 'update',
		'reject'     => 'update',
		'enable'     => 'update',
		'disable'    => 'update',
		'activate'   => 'update',
		'deactivate' => 'update',
		'sync'       => 'update',
		'merge'      => 'update',
		'restore'    => 'update',
		'reset'      => 'update',
		'schedule'   => 'update',
		'replace'    => 'update',
		'propose'    => 'update',
		'delete'     => 'delete',
		'remove'     => 'delete',
		'trash'      => 'delete',
		'destroy'    => 'delete',
		'purge'      => 'delete',
		'revoke'     => 'delete',
		'cancel'     => 'delete',
		'clear'      => 'delete',
		'undo'       => 'delete',
		'send'       => 'emit',
		'email'      => 'emit',
		'notify'     => 'emit',
		'dispatch'   => 'emit',
		'charge'     => 'emit',
		'refund'     => 'emit',
		'broadcast'  => 'emit',
	);

	/**
	 * Best guess before anything has been observed.
	 *
	 * Deliberately advisory only — it is shown to the admin labelled as a
	 * guess and never used to auto-approve. WordPress naming is conventional
	 * enough for this to be right most of the time and harmless when wrong.
	 */
	public static function guess( string $ability, array $input_schema = array() ): array {
		$slug = strtolower( substr( $ability, (int) strpos( $ability, '/' ) + 1 ) );

		// The leading verb is the reliable signal. Matching anywhere in the slug
		// misreads names whose object happens to contain a verb, and the first
		// recognised token is the action in every naming convention worth
		// supporting: wp-get-posts, create_contact, royal-mcp/wp-count-posts.
		foreach ( preg_split( '/[-_]+/', $slug ) ?: array() as $token ) {
			if ( isset( self::WRITE_VERBS[ $token ] ) ) {
				$kind = self::WRITE_VERBS[ $token ];

				return array(
					'verb'         => $kind,
					'writes'       => true,
					'irreversible' => 'emit' === $kind,
					'confidence'   => 'guess',
				);
			}

			if ( in_array( $token, self::READ_VERBS, true ) ) {
				return array(
					'verb'         => 'read',
					'writes'       => false,
					'irreversible' => false,
					'confidence'   => 'guess',
				);
			}
		}

		// No recognised verb. Deliberately no fallback: inferring intent from
		// parameter shape reads every filtered list ("has a field that is not an
		// id, therefore it creates something") as a write, which is how
		// wp-count-posts came out as a create. "Unknown" gates safely; a
		// confident wrong answer does not.
		unset( $input_schema );

		return array(
			'verb'         => 'unknown',
			'writes'       => false,
			'irreversible' => false,
			'confidence'   => 'none',
		);
	}
}
