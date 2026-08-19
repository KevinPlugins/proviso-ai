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
 * Storage. Two tables: pending requests, and the audit log.
 *
 * Both are deliberately plain tables rather than custom post types — the audit
 * log is append-only and high volume, and requests carry arbitrary JSON that has
 * no business living in wp_posts.
 */
final class Schema {

	public static function requests_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'proviso_requests';
	}

	public static function audit_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'proviso_audit';
	}

	public static function approvals_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'proviso_approvals';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$req     = self::requests_table();
		$audit   = self::audit_table();

		dbDelta(
			"CREATE TABLE {$req} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ability       VARCHAR(191)    NOT NULL,
				args          LONGTEXT        NULL,
				summary       TEXT            NULL,
				status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
				requested_by  BIGINT UNSIGNED NOT NULL DEFAULT 0,
				decided_by    BIGINT UNSIGNED NOT NULL DEFAULT 0,
				requester_key VARCHAR(191)    NOT NULL DEFAULT '',
				requester_tier VARCHAR(20)    NOT NULL DEFAULT '',
				requester_label VARCHAR(191)  NOT NULL DEFAULT '',
				precondition  LONGTEXT        NULL,
				result        LONGTEXT        NULL,
				created_at    DATETIME        NOT NULL,
				decided_at    DATETIME        NULL,
				expires_at    DATETIME        NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY ability (ability),
				KEY expires_at (expires_at),
				KEY requester_key (requester_key)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$audit} (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				ability      VARCHAR(191)    NOT NULL,
				decision     VARCHAR(20)     NOT NULL,
				outcome      VARCHAR(20)     NOT NULL DEFAULT '',
				user_id      BIGINT UNSIGNED NOT NULL DEFAULT 0,
				request_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
				requester_key VARCHAR(191)   NOT NULL DEFAULT '',
				requester_tier VARCHAR(20)   NOT NULL DEFAULT '',
				requester_label VARCHAR(191) NOT NULL DEFAULT '',
				footprint    VARCHAR(255)    NOT NULL DEFAULT '',
				operations   LONGTEXT        NULL,
				rollback     LONGTEXT        NULL,
				undone_at    DATETIME        NULL,
				args         LONGTEXT        NULL,
				created_at   DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				KEY ability (ability),
				KEY created_at (created_at),
				KEY requester_key (requester_key)
			) {$charset};"
		);

		dbDelta(
			'CREATE TABLE ' . self::approvals_table() . " (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				request_id  BIGINT UNSIGNED NOT NULL,
				user_id     BIGINT UNSIGNED NOT NULL,
				decision    VARCHAR(20)     NOT NULL,
				comment     VARCHAR(500)    NULL,
				decided_at  DATETIME        NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY request_user (request_id, user_id),
				KEY request_id (request_id)
			) {$charset};"
		);

		update_option( 'proviso_db_version', DB_VERSION );
	}

	/** Whether install() has run. Tests and the admin notice both need this. */
	public static function is_installed(): bool {
		global $wpdb;
		$t = self::requests_table();
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::requests_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::audit_table() ) );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::approvals_table() ) );
		delete_option( 'proviso_db_version' );
		delete_option( Profiles::OPTION );
		delete_option( Policy::OPTION );
		wp_clear_scheduled_hook( Plugin::CRON_EXPIRE );
	}
}
