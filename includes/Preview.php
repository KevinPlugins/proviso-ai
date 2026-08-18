<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Builds what the reviewer sees.
 *
 * Fidelity varies, and the UI must say which kind it is looking at. A reviewer
 * who believes they are reading a verified diff when they are reading a guess
 * is worse off than one who knows the preview is only the arguments.
 */
final class Preview {

	public const TIER_DECLARED = 'declared'; // Supplied by the ability author.
	public const TIER_DIFF     = 'diff';     // Real before/after, resolved.
	public const TIER_ARGS     = 'args';     // Arguments only, effect unverified.

	/**
	 * @param mixed $input
	 */
	public static function build( string $ability, $input ): array {
		$schema = Interceptor::input_schema( $ability );

		/**
		 * Let an ability author supply an exact preview for their own ability.
		 *
		 * @param array|null $preview Preview, or null to fall through.
		 * @param mixed      $input   Ability input.
		 * @param string     $ability Ability name.
		 */
		$declared = apply_filters( "mcp_ability_guard_preview_{$ability}", null, $input, $ability );
		if ( is_array( $declared ) ) {
			$declared['tier'] = self::TIER_DECLARED;
			return $declared;
		}

		$fields = self::fields( $input, $schema );
		$target = Resolver::resolve( $input );

		if ( null === $target || ! is_array( $target['state'] ) ) {
			return array(
				'tier'    => self::TIER_ARGS,
				'target'  => $target,
				'fields'  => $fields,
				'diff'    => array(),
				'notice'  => __( 'Arguments only — the effect of this ability could not be verified.', 'kevin-mcp-ability-guard' ),
			);
		}

		// For conventional CRUD the after-state is already in the arguments,
		// so the diff is the overlap between them and the object's current
		// state. Only keys the ability actually supplies are compared.
		$before = $target['state'];
		$diff   = array();
		foreach ( (array) $input as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) ) {
				continue;
			}
			if ( (string) $before[ $key ] !== (string) $value ) {
				$diff[ $key ] = array(
					'before' => $before[ $key ],
					'after'  => $value,
				);
			}
		}

		return array(
			'tier'   => self::TIER_DIFF,
			'target' => array(
				'type' => $target['type'],
				'id'   => $target['id'],
			),
			'fields' => $fields,
			'diff'   => Redactor::redact( $diff, $schema ),
			'notice' => $diff
				? ''
				: __( 'The supplied values match the current state — this change would alter nothing.', 'kevin-mcp-ability-guard' ),
		);
	}

	/**
	 * Arguments rendered with their schema labels, redacted.
	 *
	 * @param mixed $input
	 */
	public static function fields( $input, array $schema = array() ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$props = self::properties( $schema );
		$out   = array();

		foreach ( Redactor::redact( $input, $schema ) as $key => $value ) {
			$out[] = array(
				'key'         => $key,
				'label'       => self::humanise( (string) $key ),
				'description' => $props[ $key ]['description'] ?? '',
				'type'        => $props[ $key ]['type'] ?? gettype( $value ),
				'value'       => $value,
				'documented'  => isset( $props[ $key ] ),
			);
		}

		return $out;
	}

	/** Schema `properties` as an array, whatever shape the author used. */
	public static function properties( array $schema ): array {
		$props = $schema['properties'] ?? array();
		if ( is_object( $props ) ) {
			return get_object_vars( $props );
		}
		return is_array( $props ) ? $props : array();
	}

	/** post_id => "Post ID". Fallback when an ability documents nothing. */
	public static function humanise( string $key ): string {
		$label = str_replace( array( '_', '-' ), ' ', $key );
		$label = ucwords( trim( $label ) );
		return (string) preg_replace( '/\bId\b/', 'ID', $label );
	}

	/** Whether an ability's schema is documented well enough to review safely. */
	public static function schema_quality( string $ability ): array {
		$schema = Interceptor::input_schema( $ability );
		$props  = self::properties( $schema );

		if ( empty( $props ) ) {
			return array( 'score' => 'none', 'documented' => 0, 'total' => 0 );
		}

		$documented = 0;
		foreach ( $props as $p ) {
			if ( ! empty( $p['description'] ) ) {
				++$documented;
			}
		}

		$total = count( $props );
		$ratio = $documented / $total;

		return array(
			'score'      => $ratio >= 0.8 ? 'good' : ( $ratio >= 0.4 ? 'partial' : 'poor' ),
			'documented' => $documented,
			'total'      => $total,
		);
	}
}
