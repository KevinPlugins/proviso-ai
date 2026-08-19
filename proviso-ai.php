<?php
/**
 * Plugin Name:       Proviso – Approvals and Undo for MCP AI Agents
 * Plugin URI:        https://www.kevinplugins.com/proviso-ai/
 * Description:       Governance for the WordPress Abilities API. Observes what every ability actually does, then gates the dangerous ones behind human approval — for abilities registered by any plugin.
 * Version:           1.0.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Kevin Plugins
 * Author URI:        https://www.kevinplugins.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       proviso-ai
 *
 * @package McpAbilityGuard
 */

declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

const VERSION  = '1.0.0';
const DB_VERSION = 3;

define( 'McpAbilityGuard\PATH', plugin_dir_path( __FILE__ ) );
define( 'McpAbilityGuard\FILE', __FILE__ );

/**
 * Tiny PSR-4-ish autoloader. Avoids a composer dependency for a plugin whose
 * whole selling point is that it is auditable.
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}
		$rel  = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$path = PATH . 'includes/' . str_replace( '\\', '/', $rel ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once PATH . 'includes/Schema.php';

register_activation_hook( __FILE__, array( Schema::class, 'install' ) );

// The interceptor must be attached before any ability registers. Abilities
// register on `wp_abilities_api_init`, so plugins_loaded is early enough.
add_action( 'plugins_loaded', array( Plugin::class, 'boot' ), 1 );
