<?php
/**
 * Integration tests for Proviso.
 *
 * Runs against a real WordPress install (no WP test scaffolding required),
 * creates everything it needs, and removes all of it again — including its own
 * storage tables — so the site is left exactly as it was found.
 *
 * Usage: php tests/run.php
 */

declare( strict_types = 1 );

namespace McpAbilityGuard\Tests;

use McpAbilityGuard\AuditLog;
use McpAbilityGuard\Identity;
use McpAbilityGuard\Interceptor;
use McpAbilityGuard\Rollback;
use McpAbilityGuard\Plugin;
use McpAbilityGuard\Policy;
use McpAbilityGuard\Preview;
use McpAbilityGuard\Profiles;
use McpAbilityGuard\Recorder;
use McpAbilityGuard\Redactor;
use McpAbilityGuard\Requests;
use McpAbilityGuard\Rest;
use McpAbilityGuard\Resolver;
use McpAbilityGuard\Schema;

define( 'WP_USE_THEMES', false );

$root = dirname( __DIR__, 4 ); // .../wordpress
require $root . '/wp-load.php';

/* -------------------------------------------------------------------------
 * Tiny assertion harness
 * ---------------------------------------------------------------------- */

final class T {
	public static $passed = 0;
	public static $failed = 0;
	private static $group = '';

	public static function group( string $name ): void {
		self::$group = $name;
		echo "\n\033[1m{$name}\033[0m\n";
	}

	public static function ok( bool $cond, string $what, string $detail = '' ): void {
		if ( $cond ) {
			++self::$passed;
			echo "  \033[32m✓\033[0m {$what}\n";
			return;
		}
		++self::$failed;
		echo "  \033[31m✗ {$what}\033[0m\n";
		if ( '' !== $detail ) {
			echo "      {$detail}\n";
		}
	}

	/** @param mixed $a @param mixed $b */
	public static function same( $a, $b, string $what ): void {
		self::ok(
			$a === $b,
			$what,
			'expected ' . var_export( $b, true ) . ', got ' . var_export( $a, true )
		);
	}

	public static function wp_error( $thing, string $code, string $what ): void {
		$is = is_wp_error( $thing );
		self::ok(
			$is && $thing->get_error_code() === $code,
			$what,
			$is ? 'got error code ' . $thing->get_error_code() : 'not a WP_Error: ' . var_export( $thing, true )
		);
	}

	public static function summary(): int {
		$total = self::$passed + self::$failed;
		echo "\n" . str_repeat( '=', 60 ) . "\n";
		if ( self::$failed ) {
			echo "\033[31m{$total} assertions, " . self::$failed . " FAILED\033[0m\n";
			return 1;
		}
		echo "\033[32mAll {$total} assertions passed\033[0m\n";
		return 0;
	}
}

/* -------------------------------------------------------------------------
 * Setup
 * ---------------------------------------------------------------------- */

global $wpdb;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admins ) {
	fwrite( STDERR, "No administrator on this install.\n" );
	exit( 1 );
}
wp_set_current_user( (int) $admins[0] );

require_once __DIR__ . '/../proviso.php';

// The suite writes to, and finally drops, the plugin's own tables. That is fine
// on a machine where the plugin is dormant and destructive where it is live, so
// refuse rather than quietly deleting somebody's approval queue.
$is_active = in_array( 'proviso/proviso.php', (array) get_option( 'active_plugins', array() ), true );

if ( $is_active && ! getenv( 'MAG_TEST_FORCE' ) ) {
	fwrite(
		STDERR,
		"Proviso is ACTIVE on this site.\n" .
		"These tests drop the plugin's tables when they finish, which would\n" .
		"delete real pending requests and audit history.\n\n" .
		"Deactivate the plugin first, or re-run with MAG_TEST_FORCE=1 to accept\n" .
		"the loss (the schema is rebuilt afterwards, but the data is not).\n"
	);
	exit( 2 );
}

Schema::install();
Policy::update(
	array(
		'learning_mode'    => false,
		'auto_readonly'    => true,
		'rules'            => array(),
		// Most groups here exercise gating, so opt in explicitly instead of
		// depending on the shipped default.
		'default_decision' => Policy::REQUIRE,
	)
);
Profiles::reset();

Plugin::boot();

// Named like a third-party plugin's own table, not like ours.
$probe_table = $wpdb->prefix . 'guardtest_contacts';
$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$probe_table}` (id INT AUTO_INCREMENT PRIMARY KEY, email VARCHAR(100), status VARCHAR(20)) ENGINE=InnoDB" );

// A scratch post for the update tests.
$post_id = wp_insert_post(
	array(
		'post_title'   => 'Guard test subject',
		'post_content' => 'Original content.',
		'post_status'  => 'draft',
	)
);

/* -------------------------------------------------------------------------
 * Test abilities — stand-ins for third-party abilities the plugin has never
 * seen before. Registered AFTER Interceptor::boot(), exactly as a real plugin's
 * would be.
 * ---------------------------------------------------------------------- */

add_action(
	'wp_abilities_api_categories_init',
	static function (): void {
		wp_register_ability_category( 'magtest', array( 'label' => 'Guard tests', 'description' => 'Fixtures.' ) );
	}
);

add_action(
	'wp_abilities_api_init',
	static function () use ( $probe_table ): void {

wp_register_ability(
	'magtest/read-site',
	array(
		'label'               => 'Read site name',
		'description'         => 'Returns the site title. Touches nothing.',
		'category'            => 'magtest',
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'name' => array( 'type' => 'string' ) ) ),
		'execute_callback'    => static fn() => array( 'name' => (string) get_bloginfo( 'name' ) ),
		'permission_callback' => static fn() => true,
	)
);

wp_register_ability(
	'magtest/update-post',
	array(
		'label'        => 'Update a post',
		'description'  => 'Updates a post title and content.',
		'category'     => 'magtest',
		'input_schema' => array(
			'type'       => 'object',
			'required'   => array( 'post_id' ),
			'properties' => array(
				'post_id'      => array( 'type' => 'integer', 'description' => 'The post to update.' ),
				'post_title'   => array( 'type' => 'string', 'description' => 'New title.' ),
				'post_content' => array( 'type' => 'string', 'description' => 'New body.' ),
			),
		),
		'output_schema' => array( 'type' => 'object', 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) ),
		'execute_callback' => static function ( $input ) {
			wp_update_post(
				array(
					'ID'           => (int) $input['post_id'],
					'post_title'   => (string) ( $input['post_title'] ?? '' ),
					'post_content' => (string) ( $input['post_content'] ?? '' ),
				)
			);
			return array( 'post_id' => (int) $input['post_id'] );
		},
		'permission_callback' => static fn() => current_user_can( 'edit_posts' ),
	)
);

// Claims to be read-only; actually writes. Used to prove that provisional
// trust is revoked the moment that claim turns out to be false.
wp_register_ability(
	'magtest/get-things',
	array(
		'label'               => 'Get things',
		'description'         => 'Claims to read. Actually writes.',
		'category'            => 'magtest',
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'execute_callback'    => static function () {
			update_option( 'proviso_liar_probe', 'written' );
			return array( 'ok' => true );
		},
		'permission_callback' => static fn() => true,
		'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) ),
	)
);

// Declares its schema with objects rather than arrays, exactly as FluentCRM
// does. Valid JSON Schema, and fatal to any consumer that assumes arrays.
wp_register_ability(
	'magtest/object-schema',
	array(
		'label'               => 'Object-shaped schema',
		'description'         => 'Third-party abilities do not all build schemas the same way.',
		'category'            => 'magtest',
		// Array at the top level, object for `properties` — the exact shape that
		// crashed the abilities screen in the wild.
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => (object) array(
				'post_id'  => (object) array( 'type' => 'integer', 'description' => 'Target post.' ),
				'password' => (object) array( 'type' => 'string', 'format' => 'password' ),
			),
		),
		'output_schema'       => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'execute_callback'    => static fn( $input ) => array( 'ok' => true ),
		'permission_callback' => static fn() => true,
	)
);

wp_register_ability(
	'magtest/custom-write',
	array(
		'label'        => 'Write to a plugin-owned table',
		'description'  => 'Stands in for FluentCRM / WooCommerce HPOS: writes only to its own table, so no core action hook fires.',
		'category'     => 'magtest',
		'input_schema' => array(
			'type'       => 'object',
			'properties' => array(
				'email'    => array( 'type' => 'string', 'description' => 'Contact email.' ),
				'password' => array( 'type' => 'string', 'description' => 'Should never be displayed.' ),
			),
		),
		'output_schema'    => array( 'type' => 'object', 'properties' => array( 'ok' => array( 'type' => 'boolean' ) ) ),
		'execute_callback' => static function ( $input ) use ( $probe_table ) {
			global $wpdb;
			$wpdb->insert( $probe_table, array( 'email' => (string) ( $input['email'] ?? '' ), 'status' => 'new' ) );
			return array( 'ok' => true );
		},
		'permission_callback' => static fn() => true,
	)
);

	}
);

// Now force registration: every plugin's abilities register here, through our
// filter, which is what the first group asserts.
\WP_Abilities_Registry::get_instance();

/* -------------------------------------------------------------------------
 * Tests
 * ---------------------------------------------------------------------- */

T::group( '1. Interception' );

T::ok(
	in_array( 'magtest/update-post', Interceptor::governed(), true ),
	'wraps abilities registered by other code'
);
T::ok(
	! in_array( 'mag/check-request', Interceptor::governed(), true ),
	'does not wrap its own abilities'
);
T::ok(
	count( Interceptor::governed() ) > 3,
	'wraps third-party abilities already on the site (' . count( Interceptor::governed() ) . ' governed)'
);

T::group( '2. Unknown abilities are gated, not executed' );

$original_title = get_post( $post_id )->post_title;

$result = wp_get_ability( 'magtest/update-post' )->execute(
	array(
		'post_id'      => $post_id,
		'post_title'   => 'Rewritten by an agent',
		'post_content' => 'Agent body.',
	)
);

T::wp_error( $result, 'proviso_pending_approval', 'never-observed ability returns pending_approval' );

$request_id = (int) ( $result->get_error_data()['request_id'] ?? 0 );
T::ok( $request_id > 0, 'a change request was created (#' . $request_id . ')' );
T::same( get_post( $post_id )->post_title, $original_title, 'the live post was NOT modified' );

$stored = Requests::find( $request_id );
T::same( $stored['status'], Requests::PENDING, 'request is pending' );
T::same( $stored['args']['post_id'], $post_id, 'arguments were persisted for replay' );
T::ok( ! empty( $stored['precondition']['hash'] ), 'a precondition hash was captured' );

T::group( '3. Preview builds a real diff with no per-ability knowledge' );

$preview = Preview::build( 'magtest/update-post', $stored['args'] );

T::same( $preview['tier'], Preview::TIER_DIFF, 'preview is a verified diff, not args-only' );
T::ok( isset( $preview['diff']['post_title'] ), 'diff includes the changed title' );
T::same( $preview['diff']['post_title']['before'], $original_title, 'diff before-state is the live value' );
T::same( $preview['diff']['post_title']['after'], 'Rewritten by an agent', 'diff after-state comes from the args' );
T::ok( ! isset( $preview['diff']['post_id'] ), 'unchanged/identifier fields are excluded from the diff' );

$labelled = array_column( $preview['fields'], 'label', 'key' );
T::same( $labelled['post_id'], 'Post ID', 'arguments are rendered with human labels' );

T::group( '4. Approval applies the change' );

$applied = Requests::approve( $request_id );

T::ok( ! is_wp_error( $applied ), 'approve() succeeded', is_wp_error( $applied ) ? $applied->get_error_message() : '' );
T::same( get_post( $post_id )->post_title, 'Rewritten by an agent', 'the post is now actually updated' );
T::same( Requests::find( $request_id )['status'], Requests::APPLIED, 'request marked applied' );
T::ok(
	in_array( 'post.update', Profiles::get( 'magtest/update-post' )['operations'], true ),
	'replay was observed: profile learned post.update'
);

$again = Requests::approve( $request_id );
T::wp_error( $again, 'proviso_not_pending', 'an applied request cannot be approved twice' );

T::group( '5. Staleness — a human edit between propose and approve' );

$r2 = wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'Second agent edit', 'post_content' => 'Second body.' )
);
$r2_id = (int) $r2->get_error_data()['request_id'];

// A human edits the post in the meantime.
wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Edited by a human' ) );

$stale = Requests::approve( $r2_id );
T::wp_error( $stale, 'proviso_stale', 'approving a stale request is refused' );
T::same( get_post( $post_id )->post_title, 'Edited by a human', "the human's edit was not overwritten" );

Requests::reject( $r2_id );
T::same( Requests::find( $r2_id )['status'], Requests::REJECTED, 'stale request can be rejected' );

T::group( '6. Custom tables — the blind spot that matters' );

Policy::update( array( 'learning_mode' => true ) );

$rec = Recorder::start();
wp_get_ability( 'magtest/custom-write' )->execute( array( 'email' => 'lead@example.com', 'password' => 'hunter2' ) );
$rec->stop();

$profile = Profiles::get( 'magtest/custom-write' );

T::same( $profile['readonly'], false, 'an ability writing only to its own table is NOT profiled readonly' );
T::ok( ! empty( $profile['tables'] ), 'the custom table was identified: ' . implode( ', ', $profile['tables'] ) );
T::ok(
	(bool) array_filter( $profile['operations'], static fn( $o ) => 0 === strpos( $o, 'sql.insert' ) ),
	'the INSERT was captured by the SQL backstop'
);

T::group( '7. Readonly abilities are confirmed by observation, not annotation' );

$ability   = wp_get_ability( 'magtest/read-site' );
$annotated = (array) $ability->get_meta( 'annotations' );
T::same(
	$annotated['readonly'] ?? null,
	null,
	'the fixture declares no readonly annotation (as most abilities do not): ' . wp_json_encode( $annotated )
);

for ( $i = 0; $i < Profiles::CONFIRM_AFTER; $i++ ) {
	$ability->execute( null );
}

$rp = Profiles::get( 'magtest/read-site' );
T::same( $rp['readonly'], true, 'observed readonly' );
T::same( $rp['confidence'], Profiles::CONFIRMED, 'confidence reached confirmed after ' . Profiles::CONFIRM_AFTER . ' runs' );

Policy::update( array( 'learning_mode' => false ) );

T::same( Policy::decide( 'magtest/read-site' )['decision'], Policy::AUTO, 'confirmed-readonly ability is auto-approved' );
T::same( Policy::decide( 'magtest/custom-write' )['decision'], Policy::REQUIRE, 'custom-table writer still requires approval' );
T::same( Policy::decide( 'magtest/never-seen' )['decision'], Policy::REQUIRE, 'an unheard-of ability defaults to require' );

$read_result = $ability->execute( null );
T::ok( is_array( $read_result ) && isset( $read_result['name'] ), 'auto-approved ability executes normally' );

T::group( '8. Block rule' );

Policy::set_rule( 'magtest/custom-write', Policy::BLOCK );
$blocked = wp_get_ability( 'magtest/custom-write' )->execute( array( 'email' => 'x@example.com' ) );
T::wp_error( $blocked, 'proviso_blocked', 'blocked ability refuses to run' );
T::ok(
	false !== strpos( $blocked->get_error_message(), 'Do not retry' ),
	'the error tells the agent not to route around the gate'
);
Policy::clear_rule( 'magtest/custom-write' );

T::group( '9. Secrets are never displayed' );

$fields = Preview::fields(
	array( 'email' => 'a@b.c', 'password' => 'hunter2', 'api_key' => 'sk-live-123' ),
	Interceptor::input_schema( 'magtest/custom-write' )
);
$bykey = array_column( $fields, 'value', 'key' );

T::same( $bykey['password'], Redactor::MASK, 'password is masked in the review UI' );
T::same( $bykey['api_key'], Redactor::MASK, 'api_key is masked' );
T::same( $bykey['email'], 'a@b.c', 'ordinary values are shown' );

$audit_args = AuditLog::entries( 1, 'magtest/custom-write' );
T::ok(
	empty( $audit_args ) || ! in_array( 'hunter2', (array) ( $audit_args[0]['args'] ?? array() ), true ),
	'the audit log did not store the raw password'
);

T::group( '10. Agent can look up its own request' );

$status = wp_get_ability( 'mag/check-request' )->execute( array( 'request_id' => $request_id ) );
T::ok( ! is_wp_error( $status ), 'check-request executes', is_wp_error( $status ) ? $status->get_error_message() : '' );
T::same( $status['status'] ?? '', Requests::APPLIED, 'reports the applied status back to the agent' );
T::wp_error(
	wp_get_ability( 'mag/check-request' )->execute( array( 'request_id' => 99999999 ) ),
	'proviso_not_found',
	'unknown request IDs are rejected'
);

T::group( '11. Audit log covers everything' );

$entries   = AuditLog::entries( 200 );
$decisions = array_count_values( array_column( $entries, 'decision' ) );

T::ok( ! empty( $decisions['require'] ), 'queued calls are logged' );
T::ok( ! empty( $decisions['auto'] ), 'auto-approved calls are logged' );
T::ok( ! empty( $decisions['block'] ), 'blocked calls are logged' );
T::ok( ! empty( $decisions['approve'] ), 'human decisions are logged' );

$abilities_logged = array_unique( array_column( $entries, 'ability' ) );
T::ok( count( $abilities_logged ) >= 3, 'multiple distinct abilities appear in the log' );

T::group( '12. Identity — trust tiers' );

Identity::force( null );
$me = Identity::current();

T::same( $me['user_id'], (int) $admins[0], 'the WordPress user is always known' );
T::ok( in_array( $me['tier'], array( Identity::BOUND, Identity::OBSERVED, Identity::UNRESOLVED ), true ), 'a tier is always assigned (' . $me['tier'] . ')' );

$bound    = array( 'key' => 'apw:aaa', 'tier' => Identity::BOUND, 'label' => 'Agent A', 'user_id' => 1, 'channel' => 'application_password', 'signals' => array() );
$observed = array( 'key' => 'client:agent-a:1', 'tier' => Identity::OBSERVED, 'label' => 'Agent A', 'user_id' => 1, 'channel' => 'mcp', 'signals' => array() );
$unknown  = array( 'key' => 'unresolved', 'tier' => Identity::UNRESOLVED, 'label' => 'Unidentified', 'user_id' => 1, 'channel' => 'unknown', 'signals' => array() );

T::same( Identity::may_relax( $bound ), true, 'a bound identity may relax policy' );
T::same( Identity::may_relax( $observed ), false, 'an observed identity may not relax policy' );
T::same( Identity::may_relax( $unknown ), false, 'an unresolved identity may not relax policy' );

T::group( '13. A self-reported client cannot inherit auto-approve' );

Policy::set_requester_rule( 'apw:aaa', 'magtest/custom-write', Policy::AUTO );

T::same(
	Policy::decide( 'magtest/custom-write', $bound )['decision'],
	Policy::AUTO,
	'the authenticated caller gets its auto-approve rule'
);

Policy::set_requester_rule( 'client:agent-a:1', 'magtest/custom-write', Policy::AUTO );
$spoofed = Policy::decide( 'magtest/custom-write', $observed );

T::same( $spoofed['decision'], Policy::REQUIRE, 'the same rule via a self-reported identity is refused' );
T::ok( false !== strpos( $spoofed['reason'], 'self-reported' ), 'and the reason says why' );

// Tightening from an untrusted identity is still allowed.
Policy::set_requester_rule( 'client:agent-a:1', 'magtest/read-site', Policy::BLOCK );
T::same(
	Policy::decide( 'magtest/read-site', $observed )['decision'],
	Policy::BLOCK,
	'an observed identity may still make policy stricter'
);

Policy::update( array( 'requester_rules' => array() ) );

T::same(
	Policy::decide( 'magtest/read-site', $unknown )['decision'],
	Policy::REQUIRE,
	'an unidentifiable caller is gated even under the permissive default'
);

T::group( '14. Timeout — silence is not consent' );

Policy::update( array( 'timeout_minutes' => 60, 'gate_unresolved' => false ) );
Identity::force( $bound );

$t1 = wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'Will expire', 'post_content' => 'x' )
);
$t1_id = (int) $t1->get_error_data()['request_id'];

T::ok( ! empty( Requests::find( $t1_id )['expires_at'] ), 'a deadline was recorded' );
T::same( Requests::expire_due(), 0, 'nothing expires before its deadline' );

// Backdate the deadline rather than waiting an hour.
$wpdb->update( Schema::requests_table(), array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $t1_id ) );

T::same( Requests::expire_due(), 1, 'an overdue request expires' );
T::same( Requests::find( $t1_id )['status'], Requests::EXPIRED, 'and is marked expired, not approved' );
T::wp_error( Requests::approve( $t1_id ), 'proviso_not_pending', 'an expired request cannot then be approved' );

Policy::update( array( 'timeout_minutes' => 0 ) );

T::group( '15. Rollback' );

Policy::update( array( 'learning_mode' => true ) );

$before_title = get_post( $post_id )->post_title;

wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'Auto-approved edit', 'post_content' => 'Body from agent.' )
);

T::same( get_post( $post_id )->post_title, 'Auto-approved edit', 'the auto-approved edit went live' );

$entry = AuditLog::entries( 1, 'magtest/update-post' )[0];
$plan  = AuditLog::rollback_plan( (int) $entry['id'] );

T::ok( is_array( $plan ) && ! empty( $plan['steps'] ), 'a rollback plan was recorded' );
T::same( $plan['steps'][0]['do'], 'post.restore', 'the plan knows how to reverse a post update' );
T::same( $plan['reversible'], true, 'the plan reports itself fully reversible' );

$undo = AuditLog::undo( (int) $entry['id'] );

T::ok( ! is_wp_error( $undo ), 'undo succeeded', is_wp_error( $undo ) ? $undo->get_error_message() : '' );
T::same( get_post( $post_id )->post_title, $before_title, 'the post is back to its previous title' );
T::wp_error( AuditLog::undo( (int) $entry['id'] ), 'proviso_already_undone', 'undo is not repeatable' );

T::group( '16. Rollback refuses to clobber later edits' );

wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'Second auto edit', 'post_content' => 'More.' )
);
$entry2 = AuditLog::entries( 1, 'magtest/update-post' )[0];

// A human edits afterwards.
wp_update_post( array( 'ID' => $post_id, 'post_title' => 'Human had the last word' ) );

$undo2 = AuditLog::undo( (int) $entry2['id'] );

T::ok( ! is_wp_error( $undo2 ), 'undo returns a report rather than erroring' );
T::same( $undo2['applied'], 0, 'nothing was reverted' );
T::ok( ! empty( $undo2['skipped'] ), 'the skip was reported: ' . ( $undo2['skipped'][0] ?? '' ) );
T::same( get_post( $post_id )->post_title, 'Human had the last word', "the human's edit survived" );

T::group( '17. Custom-table writes are honestly reported as irreversible' );

wp_get_ability( 'magtest/custom-write' )->execute( array( 'email' => 'undo@example.com' ) );
$entry3 = AuditLog::entries( 1, 'magtest/custom-write' )[0];
$plan3  = AuditLog::rollback_plan( (int) $entry3['id'] );

T::same( $plan3['reversible'], false, 'a plugin-table write is not claimed reversible' );
T::ok( ! empty( $plan3['blocked'] ), 'and the reason is recorded: ' . ( $plan3['blocked'][0] ?? '' ) );
T::wp_error( AuditLog::undo( (int) $entry3['id'] ), 'proviso_nothing_to_undo', 'undo refuses rather than half-working' );

Policy::update( array( 'learning_mode' => false ) );

T::group( '18. Reads are not gated; the claim is verified' );

Profiles::reset();
Policy::update( array( 'rules' => array(), 'trust_declared_readonly' => true ) );

// magtest/read-site declares nothing at all — the heuristic has to carry it.
$hint = Policy::cold_start_hint( 'magtest/read-site' );
T::same( $hint['read'], true, 'an unobserved "read-site" is recognised as a read from its name' );
T::same(
	Policy::decide( 'magtest/read-site', $bound )['decision'],
	Policy::AUTO,
	'a never-seen read runs without approval'
);
T::same(
	Policy::decide( 'magtest/update-post', $bound )['decision'],
	Policy::REQUIRE,
	'a never-seen write is gated'
);
T::same(
	Policy::decide( 'magtest/custom-write', $bound )['decision'],
	Policy::REQUIRE,
	'"custom-write" is not mistaken for a read'
);

T::ok( ! empty( Policy::decide( 'magtest/read-site', $bound )['provisional'] ), 'and the trust is marked provisional' );

T::group( '19. An ability that lies about being read-only loses the privilege' );

$liar = wp_get_ability( 'magtest/get-things' );

if ( $liar ) {
	T::same( Policy::decide( 'magtest/get-things', $bound )['decision'], Policy::AUTO, 'its readonly claim buys it one run' );

	$liar->execute( null );

	T::same( Profiles::get( 'magtest/get-things' )['readonly'], false, 'observation caught the write' );
	T::same(
		Policy::decide( 'magtest/get-things', $bound )['decision'],
		Policy::REQUIRE,
		'trust is revoked — the second call is gated'
	);

	$violations = array_filter( AuditLog::entries( 50 ), static fn( $e ) => 'violation' === $e['decision'] );
	T::ok( ! empty( $violations ), 'the broken claim is logged as a violation, not a routine observation' );

	delete_option( 'proviso_liar_probe' );
} else {
	T::ok( false, 'liar fixture registered' );
}

T::group( '20. Schemas declared with objects instead of arrays' );

$obj_schema = Interceptor::input_schema( 'magtest/object-schema' );

T::ok( is_array( $obj_schema ), 'the stored schema was normalised to arrays' );
T::ok( is_array( $obj_schema['properties'] ?? null ), 'nested properties were normalised too' );

// Each of these used to fatal on an object-shaped schema.
$g = Profiles::guess( 'magtest/object-schema', $obj_schema );
T::ok( is_array( $g ) && isset( $g['verb'] ), 'Profiles::guess() survives it' );

$hint = Policy::cold_start_hint( 'magtest/object-schema' );
T::ok( is_array( $hint ), 'Policy::cold_start_hint() survives it' );

$decision = Policy::decide( 'magtest/object-schema' );
T::ok( ! empty( $decision['decision'] ), 'Policy::decide() survives it — this is what crashed the abilities screen' );

$of = Preview::fields( array( 'post_id' => 5, 'password' => 'hunter2' ), $obj_schema );
$ok = array_column( $of, 'value', 'key' );
T::same( $ok['password'], Redactor::MASK, 'format:password is still honoured through an object schema' );
T::same( array_column( $of, 'description', 'key' )['post_id'], 'Target post.', 'descriptions survive normalisation' );

T::ok( is_array( Preview::build( 'magtest/object-schema', array( 'post_id' => $post_id ) ) ), 'Preview::build() survives it' );

T::group( '21. Reads offer only allow/block; writes can be gated' );

Profiles::reset();
Policy::update( array( 'rules' => array(), 'learning_mode' => true ) );

for ( $i = 0; $i < Profiles::CONFIRM_AFTER; $i++ ) {
	wp_get_ability( 'magtest/read-site' )->execute( null );
}
Policy::update( array( 'learning_mode' => false ) );

T::same( Policy::is_known_read( 'magtest/read-site' ), true, 'the read is confirmed by observation' );
T::same(
	Policy::available_decisions( 'magtest/read-site' ),
	array( Policy::AUTO, Policy::BLOCK ),
	'a confirmed read offers only allow or block — approval could never return its data'
);
T::same(
	Policy::available_decisions( 'magtest/custom-write' ),
	array( Policy::AUTO, Policy::REQUIRE, Policy::BLOCK ),
	'a writer keeps all three'
);
T::same( Policy::is_known_read( 'magtest/never-seen' ), false, 'an unobserved ability with an unrevealing name is not assumed to be a read' );

// The cold-start case: nothing has run yet, which is what a fresh install looks
// like. A `get-*` ability must not offer approval just because it is unprofiled.
Profiles::reset();
T::same(
	Policy::available_decisions( 'magtest/get-things' ),
	array( Policy::AUTO, Policy::BLOCK ),
	'an unobserved "get-things" offers only allow or block on a fresh install'
);

// ...and the moment it is caught writing, approval becomes available again.
Policy::update( array( 'learning_mode' => true, 'rules' => array() ) );
wp_get_ability( 'magtest/get-things' )->execute( null );
Policy::update( array( 'learning_mode' => false ) );

T::same( Policy::is_known_read( 'magtest/get-things' ), false, 'once observed writing it is no longer treated as a read' );
T::same(
	Policy::available_decisions( 'magtest/get-things' ),
	array( Policy::AUTO, Policy::REQUIRE, Policy::BLOCK ),
	'and approval becomes available for it'
);
delete_option( 'proviso_liar_probe' );

T::group( '22. Default approvers are administrators' );

Policy::update( array( 'approver_rules' => array() ) );
$default_rule = Policy::approver_rule( 'magtest/update-post' );

T::same( $default_rule['values'], array( 'role:administrator' ), 'the default approver is the administrator role' );
T::ok(
	in_array( (int) $admins[0], Policy::approvers_for( 'magtest/update-post' ), true ),
	'which resolves to the actual administrator'
);
T::same( $default_rule['quorum'], Policy::ANY, 'and any one of them suffices' );

T::group( '23. Who approves — users, roles and quorum' );

$editor_a = wp_insert_user( array( 'user_login' => 'proviso_editor_a', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$editor_b = wp_insert_user( array( 'user_login' => 'proviso_editor_b', 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$admin_id = (int) $admins[0];

Policy::update( array( 'approve_cap' => 'edit_posts', 'rules' => array(), 'learning_mode' => false ) );

// --- mixed selection, as the picker saves it ---
Policy::set_approver_rule(
	'magtest/update-post',
	Policy::APPROVER_MIXED,
	array( 'user:' . $editor_a, 'role:editor' ),
	Policy::ANY
);
$mixed = Policy::approvers_for( 'magtest/update-post' );
T::ok(
	in_array( (int) $editor_a, $mixed, true ) && in_array( (int) $editor_b, $mixed, true ),
	'a mixed user+role selection resolves both'
);

// --- specific users, ANY ---
Policy::set_approver_rule( 'magtest/update-post', Policy::APPROVER_USERS, array( $editor_a, $editor_b ), Policy::ANY );

T::same( Policy::approvers_for( 'magtest/update-post' ), array( (int) $editor_a, (int) $editor_b ), 'named approvers resolve' );
T::same( Policy::required_count( 'magtest/update-post' ), 1, 'ANY needs one approval' );
T::same( Policy::can_approve( $admin_id, 'magtest/update-post' ), false, 'an administrator who is not a named approver cannot decide' );
T::same( Policy::can_approve( (int) $editor_a, 'magtest/update-post' ), true, 'a named approver can' );

Policy::set_rule( 'magtest/update-post', Policy::REQUIRE );
$q1 = wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'ANY quorum', 'post_content' => 'a' )
);
$q1_id = (int) $q1->get_error_data()['request_id'];

T::wp_error( Requests::approve( $q1_id, $admin_id ), 'proviso_cannot_approve', 'a non-approver is refused' );

$r1 = Requests::approve( $q1_id, (int) $editor_a );
T::ok( ! is_wp_error( $r1 ), 'the named approver succeeds', is_wp_error( $r1 ) ? $r1->get_error_message() : '' );
T::same( Requests::find( $q1_id )['status'], Requests::APPLIED, 'one approval was enough under ANY' );
T::same( get_post( $post_id )->post_title, 'ANY quorum', 'and the change landed' );

// --- roles, ALL ---
Policy::set_approver_rule( 'magtest/update-post', Policy::APPROVER_ROLES, array( 'editor' ), Policy::ALL );

$role_approvers = Policy::approvers_for( 'magtest/update-post' );
T::ok( in_array( (int) $editor_a, $role_approvers, true ) && in_array( (int) $editor_b, $role_approvers, true ), 'a role expands to its members' );
T::same( Policy::required_count( 'magtest/update-post' ), count( $role_approvers ), 'ALL needs every approver' );

$q2 = wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'ALL quorum', 'post_content' => 'b' )
);
$q2_id = (int) $q2->get_error_data()['request_id'];

$first = Requests::approve( $q2_id, (int) $editor_a );
T::same( $first['status'] ?? '', Requests::PENDING, 'the first approval does not execute under ALL' );
T::same( get_post( $post_id )->post_title, 'ANY quorum', 'and nothing has changed yet' );
T::wp_error( Requests::approve( $q2_id, (int) $editor_a ), 'proviso_already_voted', 'one person cannot approve twice to reach quorum alone' );

$second = Requests::approve( $q2_id, (int) $editor_b );
T::ok( ! is_wp_error( $second ), 'the last approval executes', is_wp_error( $second ) ? $second->get_error_message() : '' );
T::same( get_post( $post_id )->post_title, 'ALL quorum', 'the change landed only after everyone approved' );

// --- one rejection ends it, even under ALL ---
$q3 = wp_get_ability( 'magtest/update-post' )->execute(
	array( 'post_id' => $post_id, 'post_title' => 'Should never land', 'post_content' => 'c' )
);
$q3_id = (int) $q3->get_error_data()['request_id'];

Requests::approve( $q3_id, (int) $editor_a );
Requests::reject( $q3_id, (int) $editor_b );

T::same( Requests::find( $q3_id )['status'], Requests::REJECTED, 'a single rejection ends the request under ALL' );
T::same( get_post( $post_id )->post_title, 'ALL quorum', 'the rejected change never ran' );
T::same( count( Requests::votes( $q3_id ) ), 2, 'both votes are on the record' );

// --- an empty approver set must not mean "no approval needed" ---
Policy::set_approver_rule( 'magtest/update-post', Policy::APPROVER_USERS, array(), Policy::ALL );
T::same( Policy::required_count( 'magtest/update-post' ), 1, 'an empty approver list still requires one approval, not zero' );

Policy::clear_approver_rule( 'magtest/update-post' );
Policy::update( array( 'approve_cap' => 'manage_options' ) );
Identity::force( null );

T::group( '24. REST layer feeds the interface' );

Policy::update( array( 'rules' => array(), 'approver_rules' => array(), 'learning_mode' => false ) );
Identity::force( null );

do_action( 'rest_api_init' );

$routes = rest_get_server()->get_routes();
foreach ( array(
	'/mag/v1/bootstrap',
	'/mag/v1/abilities',
	'/mag/v1/abilities/rule',
	'/mag/v1/requests',
	'/mag/v1/audit',
	'/mag/v1/settings',
) as $route ) {
	T::ok( isset( $routes[ $route ] ), "route {$route} is registered" );
}

$boot = rest_do_request( new \WP_REST_Request( 'GET', '/mag/v1/bootstrap' ) );
T::same( $boot->get_status(), 200, 'bootstrap responds 200' );

$data = $boot->get_data();
foreach ( array( 'abilities', 'requests', 'audit', 'settings', 'meta' ) as $key ) {
	T::ok( array_key_exists( $key, $data ), "bootstrap carries {$key}" );
}

T::ok( count( $data['abilities'] ) > 3, 'it lists the real abilities (' . count( $data['abilities'] ) . ')' );

$byName = array_column( $data['abilities'], null, 'name' );

T::same( $byName['magtest/update-post']['kind'], 'write', 'a writer is tagged write' );
T::same( $byName['magtest/read-site']['kind'], 'read', 'a reader is tagged read' );
T::ok(
	in_array( $byName['magtest/read-site']['kindBasis'], array( 'observed', 'heuristic', 'declared' ), true ),
	'the tag records how it was decided (' . $byName['magtest/read-site']['kindBasis'] . ')'
);
T::same( $byName['magtest/read-site']['isRead'], true, 'reads are flagged so the UI hides approval' );
T::same(
	$byName['magtest/read-site']['choices'],
	array( Policy::AUTO, Policy::BLOCK ),
	'and the payload offers only allow/block for them'
);

T::ok( is_array( $byName['magtest/update-post']['approvers']['resolved'] ), 'approvers are resolved to names' );
T::same( $byName['magtest/update-post']['approvers']['required'], 1, 'quorum requirement is exposed' );

// Every real ability on this site must serialise without throwing.
T::ok(
	count( array_filter( $data['abilities'], static fn( $a ) => '' !== $a['name'] ) ) === count( $data['abilities'] ),
	'every registered ability serialised'
);

$meta = $data['meta'];
T::ok( ! isset( $meta['roles'] ), 'roles are not offered as approvers — people are' );
T::ok( ! empty( $meta['users'] ), 'eligible users are offered to the picker' );
T::ok( ! empty( $meta['users'][0]['avatar'] ), 'each with an avatar for the picker' );
T::ok( array_key_exists( 'role', $meta['users'][0] ), 'and their role as secondary context' );
T::same( $meta['canManage'], true, 'the administrator is told it may manage' );

T::group( '25. REST enforces the same rules as the engine' );

$req = new \WP_REST_Request( 'POST', '/mag/v1/abilities/rule' );
$req->set_body_params( array() );
$req->set_body( (string) wp_json_encode( array( 'ability' => 'magtest/read-site', 'rule' => 'require' ) ) );
$req->add_header( 'content-type', 'application/json' );
$res = rest_do_request( $req );

T::same( $res->get_status(), 200, 'setting a rule responds 200' );
T::same(
	$res->get_data()['ability']['rule'],
	'',
	'"require approval" is refused for a read even when posted directly'
);

$req2 = new \WP_REST_Request( 'POST', '/mag/v1/abilities/rule' );
$req2->set_body( (string) wp_json_encode( array( 'ability' => 'magtest/update-post', 'rule' => 'block' ) ) );
$req2->add_header( 'content-type', 'application/json' );
$res2 = rest_do_request( $req2 );

T::same( $res2->get_data()['ability']['rule'], 'block', 'a valid rule is accepted for a writer' );
Policy::clear_rule( 'magtest/update-post' );

/* -------------------------------------------------------------------------
 * Teardown — leave the site exactly as found.
 * ---------------------------------------------------------------------- */

echo "\n\033[1mTeardown\033[0m\n";

foreach ( array( 'proviso_editor_a', 'proviso_editor_b' ) as $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $u->ID );
		echo "  removed test user {$login}\n";
	}
}
delete_option( 'proviso_liar_probe' );

if ( $post_id ) {
	wp_delete_post( (int) $post_id, true );
	echo "  removed test post #{$post_id}\n";
}

$wpdb->query( "DROP TABLE IF EXISTS `{$probe_table}`" );
echo "  dropped {$probe_table}\n";

Schema::uninstall();
echo "  dropped plugin tables and options\n";

// Leave an active installation in a working state rather than a half-removed one.
if ( $is_active ) {
	Schema::install();
	if ( ! wp_next_scheduled( Plugin::CRON_EXPIRE ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', Plugin::CRON_EXPIRE );
	}
	echo "  plugin is active — schema and cron rebuilt (stored data was not preserved)\n";
}

exit( T::summary() );
