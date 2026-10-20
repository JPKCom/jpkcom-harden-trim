<?php
/**
 * Fixture for the constant scenarios in test-admin-page.php.
 *
 * Constants cannot be undefined, so every scenario renders the settings page
 * in its own process and prints the HTML. JHT_MODE selects it:
 * - locked: a valid plugin constant (emoji), an invalid one (heartbeat) and a
 *           core lock (DISALLOW_FILE_EDIT defined before the plugin loaded).
 * - kill:   JPKCOM_HARDEN_TRIM_DISABLE_ALL is true.
 * - conflict: plugin constants for file_edit and post_revisions plus the core
 *           constants that win over them (DISALLOW_FILE_EDIT, WP_POST_REVISIONS).
 * - kill_json: JPKCOM_HARDEN_TRIM_DISABLE_ALL is the JSON value in JHT_KILL_JSON.
 * - core:   the core constants in JHT_CORE_JSON (object) were defined before the
 *           plugin loaded, and the stored settings carry JHT_STORED_JSON (object),
 *           so the page must show the core value, not the stored one.
 * - standalone_xmlrpc / standalone_comments: JPKCom Disable XML-RPC or JPKCom
 *           Disable Comments is active (its version constant is defined), so the
 *           matching field shows the standalone hint.
 * - sitewide_locked: JPKCOM_HARDEN_TRIM_XMLRPC is false and
 *           JPKCOM_HARDEN_TRIM_COMMENTS is true in wp-config.php.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';

$jht_mode = (string) getenv( 'JHT_MODE' );

if ( 'locked' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_EMOJI', false );
	define( 'JPKCOM_HARDEN_TRIM_HEARTBEAT', 'off' );
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'DISALLOW_FILE_EDIT' => true ) );
} elseif ( 'kill' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', true );
} elseif ( 'conflict' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_FILE_EDIT', true );
	define( 'JPKCOM_HARDEN_TRIM_POST_REVISIONS', 5 );
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'DISALLOW_FILE_EDIT' => false, 'WP_POST_REVISIONS' => 3 ) );
} elseif ( 'core' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', (array) json_decode( (string) getenv( 'JHT_CORE_JSON' ), true ) );
} elseif ( 'kill_json' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', json_decode( (string) getenv( 'JHT_KILL_JSON' ), true ) );
} elseif ( 'standalone_xmlrpc' === $jht_mode ) {
	define( 'JPKCOM_DISABLE_XMLRPC_VERSION', '1.0.10' );
} elseif ( 'standalone_comments' === $jht_mode ) {
	define( 'JPKCOM_DISABLE_COMMENTS_VERSION', '1.0.10' );
} elseif ( 'sitewide_locked' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_XMLRPC', false );
	define( 'JPKCOM_HARDEN_TRIM_COMMENTS', true );
} else {
	echo 'unknown JHT_MODE';
	exit( 1 );
}

define( 'JPKCOM_HARDEN_TRIM_FILE', dirname( path: __DIR__ ) . '/jpkcom-harden-trim.php' );
define( 'JPKCOM_HARDEN_TRIM_VERSION', '1.0.0' );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/admin-page.php' ) as $jht_module ) {
	require_once dirname( path: __DIR__ ) . '/' . $jht_module;
}

jht_reset_admin();

if ( 'core' === $jht_mode ) {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), (array) json_decode( (string) getenv( 'JHT_STORED_JSON' ), true ) ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

ob_start();
jpkcom_harden_trim_render_page();
echo ob_get_clean();
exit( 0 );
