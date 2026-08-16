<?php
declare( strict_types = 1 );

namespace McpAbilityGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Admin shell.
 *
 * The screen itself is a Vue application; this class exists only to register the
 * menu, mount a root element, and hand the app its REST root and nonce. All data
 * and every action goes through Rest.
 */
final class Admin {

	public const SLUG = 'mag';

	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	private static function capability(): string {
		// Whoever may decide a request must be able to reach the screen, and that
		// is not necessarily an administrator.
		return (string) Policy::settings()['approve_cap'];
	}

	public static function menu(): void {
		$cap     = self::capability();
		$pending = Requests::count_pending();

		$title = __( 'Ability Guard', 'mcp-ability-guard' );
		if ( $pending ) {
			$title .= sprintf(
				' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
				$pending
			);
		}

		add_menu_page(
			__( 'MCP Ability Guard', 'mcp-ability-guard' ),
			$title,
			$cap,
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-shield-alt',
			76
		);

		// Submenus deep-link into the app rather than rendering separate screens.
		$views = array(
			'abilities' => __( 'Abilities', 'mcp-ability-guard' ),
			'queue'     => __( 'Approval Queue', 'mcp-ability-guard' ),
			'audit'     => __( 'Audit Log', 'mcp-ability-guard' ),
		);

		foreach ( $views as $view => $label ) {
			add_submenu_page(
				self::SLUG,
				$label,
				$label,
				$cap,
				self::SLUG . '&view=' . $view,
				'__return_null'
			);
		}

		add_submenu_page(
			self::SLUG,
			__( 'Settings', 'mcp-ability-guard' ),
			__( 'Settings', 'mcp-ability-guard' ),
			'manage_options',
			self::SLUG . '&view=settings',
			'__return_null'
		);

		// The auto-generated first submenu duplicates the top-level entry.
		remove_submenu_page( self::SLUG, self::SLUG );
	}

	public static function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$base = plugin_dir_url( FILE ) . 'assets/';
		$path = PATH . 'assets/';

		$js  = $path . 'app.js';
		$css = $path . 'app.css';

		if ( ! is_readable( $js ) ) {
			return;
		}

		wp_enqueue_script( 'mag-app', $base . 'app.js', array(), (string) filemtime( $js ), true );

		if ( is_readable( $css ) ) {
			wp_enqueue_style( 'mag-app', $base . 'app.css', array(), (string) filemtime( $css ) );
		}

		wp_localize_script(
			'mag-app',
			'magGuard',
			array(
				'root'  => esc_url_raw( rest_url() ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	public static function render(): void {
		if ( ! Schema::is_installed() ) {
			printf(
				'<div class="wrap"><div class="notice notice-error"><p>%s</p></div></div>',
				esc_html__( 'Storage tables are missing. Deactivate and reactivate MCP Ability Guard.', 'mcp-ability-guard' )
			);
			return;
		}

		echo '<div id="mag-app"></div>';
	}
}
