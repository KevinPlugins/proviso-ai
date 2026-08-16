<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Hides secrets in stored arguments when they are displayed.
 *
 * Deferring execution forces the arguments to be persisted verbatim — the
 * replay needs them exactly as sent. Redaction therefore happens on the way
 * out, not on the way in.
 */
final class Redactor {

	private const SENSITIVE = array(
		'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 'apikey',
		'access_token', 'refresh_token', 'auth', 'authorization', 'key',
		'private_key', 'credential', 'credentials', 'nonce', 'salt',
		'card', 'cvv', 'ssn',
	);

	public const MASK = '••••••••';

	public static function is_sensitive( string $key, array $schema = array() ): bool {
		// JSON Schema can say so explicitly. `properties` may be an object.
		$props  = $schema['properties'] ?? array();
		$props  = is_object( $props ) ? get_object_vars( $props ) : $props;
		$field  = is_array( $props ) ? ( $props[ $key ] ?? array() ) : array();
		$field  = is_object( $field ) ? get_object_vars( $field ) : $field;
		if ( 'password' === ( $field['format'] ?? '' ) ) {
			return true;
		}

		$k = strtolower( $key );
		foreach ( self::SENSITIVE as $needle ) {
			if ( false !== strpos( $k, $needle ) ) {
				return true;
			}
		}

		/**
		 * Filter whether an argument should be masked in the review UI.
		 *
		 * @param bool   $sensitive Whether to mask.
		 * @param string $key       Argument name.
		 */
		return (bool) apply_filters( 'mcp_ability_guard_is_sensitive_arg', false, $key );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	public static function redact( $value, array $schema = array() ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$out = array();
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && self::is_sensitive( $k, $schema ) ) {
				$out[ $k ] = self::MASK;
				continue;
			}
			$out[ $k ] = is_array( $v ) ? self::redact( $v ) : $v;
		}
		return $out;
	}
}
