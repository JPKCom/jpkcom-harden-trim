<?php
/**
 * Tests for import / export: payload shape, import validation (size, JSON,
 * plugin, schema, depth, BOM, partial imports), round trip, the export and
 * import handlers (capability, nonce, sources, write, notice) and the
 * rendered section.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';

$root = dirname( path: __DIR__ );

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_FILE' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_FILE', value: $root . '/jpkcom-harden-trim.php' );
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION', value: '1.0.0' );
}

if ( ! function_exists( function: 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( function: 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['jht_nocache'] = true;
	}
}

jht_reset_admin();

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/admin-page.php', 'includes/import-export.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

$jht_missing = array_values(
	array: array_filter(
		array: array(
			'jpkcom_harden_trim_export_payload',
			'jpkcom_harden_trim_export_headers',
			'jpkcom_harden_trim_handle_export',
			'jpkcom_harden_trim_parse_import',
			'jpkcom_harden_trim_import_error_message',
			'jpkcom_harden_trim_handle_import',
			'jpkcom_harden_trim_render_import_export',
		),
		callback: static fn( string $fn ): bool => ! function_exists( $fn )
	)
);
chk( 'import/export API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

// Not jht_-prefixed: jht_reset_wp() clears every jht_* global.
$option_name = jpkcom_harden_trim_option_name();

/**
 * Store a settings array as the option.
 *
 * @param array<string, mixed> $overrides Values over the defaults.
 * @return void
 */
function jht_store( array $overrides = array() ): void {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $overrides ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * Run a handler and return what ended it.
 *
 * @param callable $handler Handler.
 * @return array{die: ?int, redirect: ?string}
 */
function jht_run_handler( callable $handler ): array {
	$result = array( 'die' => null, 'redirect' => null );
	try {
		$handler();
	} catch ( JhtDie $e ) {
		$result['die'] = $e->status;
	} catch ( JhtRedirect $e ) {
		$result['redirect'] = $e->url;
	}
	return $result;
}

/**
 * A JSON import document.
 *
 * @param array<string, mixed> $settings Settings to put in it.
 * @param array<string, mixed> $override Top-level overrides.
 * @return string
 */
function jht_import_json( array $settings, array $override = array() ): string {
	return (string) json_encode( array_merge( array( 'plugin' => 'jpkcom-harden-trim', 'schema' => 1, 'version' => '1.0.0', 'settings' => $settings ), $override ) );
}

/**
 * The error code of a parse result, or '' when it is not an error.
 *
 * @param mixed $result Parse result.
 * @return string
 */
function jht_error_code( mixed $result ): string {
	return $result instanceof WP_Error ? (string) ( $result->get_error_codes()[0] ?? '' ) : '';
}

// Hooks.

chk( 'export handler hooked on admin_post_jpkcom_harden_trim_export', false !== has_action( 'admin_post_jpkcom_harden_trim_export', 'jpkcom_harden_trim_handle_export' ) );
chk( 'import handler hooked on admin_post_jpkcom_harden_trim_import', false !== has_action( 'admin_post_jpkcom_harden_trim_import', 'jpkcom_harden_trim_handle_import' ) );
chk( 'no nopriv handlers', false === has_action( 'admin_post_nopriv_jpkcom_harden_trim_export' ) && false === has_action( 'admin_post_nopriv_jpkcom_harden_trim_import' ) );

// export_shape.

jht_store( array( 'emoji' => false, 'heartbeat' => 'optimized', 'exempt_roles' => array( 'editor' ), 'post_revisions' => 20 ) );
add_filter( 'jpkcom_harden_trim_settings', static fn( array $s ): array => array_merge( $s, array( 'dashicons' => true ) ) );
$payload = jpkcom_harden_trim_export_payload();
chk( 'export: keys exactly plugin, schema, version, settings', array( 'plugin', 'schema', 'version', 'settings' ) === array_keys( array: $payload ) );
chk( 'export: plugin is jpkcom-harden-trim', 'jpkcom-harden-trim' === $payload['plugin'] );
chk( 'export: schema is int 1', 1 === $payload['schema'] );
chk( 'export: version is the plugin version', JPKCOM_HARDEN_TRIM_VERSION === $payload['version'] );
chk( 'export: settings are the stored values', jpkcom_harden_trim_get_stored() === $payload['settings'] );
chk( 'export: filtered (effective) values are not exported', false === $payload['settings']['dashicons'] );
chk( 'export: every schema key present', array_keys( array: jpkcom_harden_trim_schema() ) === array_keys( array: $payload['settings'] ) );
$jht_json = (string) wp_json_encode( $payload );
chk( 'export: no home/siteurl key or value in the JSON', ! str_contains( haystack: $jht_json, needle: 'home' ) && ! str_contains( haystack: $jht_json, needle: 'siteurl' ) && ! str_contains( haystack: $jht_json, needle: 'http' ) && ! str_contains( haystack: $jht_json, needle: 'example.test' ) );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] = array();
jpkcom_harden_trim_settings_reset_cache();

$jht_headers = jpkcom_harden_trim_export_headers();
chk( 'export headers: Content-Type json utf-8', 'application/json; charset=utf-8' === ( $jht_headers['Content-Type'] ?? null ) );
chk( 'export headers: attachment with dated filename', 1 === preg_match( '/^attachment; filename="jpkcom-harden-trim-settings-\d{4}-\d{2}-\d{2}\.json"$/', $jht_headers['Content-Disposition'] ?? '' ) );
chk( 'export headers: filename carries today\'s date', str_contains( haystack: $jht_headers['Content-Disposition'] ?? '', needle: gmdate( 'Y-m-d' ) ) );
chk( 'export headers: nosniff', 'nosniff' === ( $jht_headers['X-Content-Type-Options'] ?? null ) );

// import_errors.

chk( 'import: 70 000 bytes => too_large', 'too_large' === jht_error_code( jpkcom_harden_trim_parse_import( str_repeat( string: ' ', times: 70000 ) ) ) );
$jht_doc = jht_import_json( array( 'emoji' => false ) );
chk( 'import: exactly 65 536 bytes is accepted', is_array( jpkcom_harden_trim_parse_import( str_pad( string: $jht_doc, length: 65536 ) ) ) );
chk( 'import: 65 537 bytes => too_large', 'too_large' === jht_error_code( jpkcom_harden_trim_parse_import( str_pad( string: $jht_doc, length: 65537 ) ) ) );
chk( 'import: "{" => invalid_json', 'invalid_json' === jht_error_code( jpkcom_harden_trim_parse_import( '{' ) ) );
chk( 'import: empty string => invalid_json', 'invalid_json' === jht_error_code( jpkcom_harden_trim_parse_import( '' ) ) );
chk( 'import: JSON scalar => invalid_json', 'invalid_json' === jht_error_code( jpkcom_harden_trim_parse_import( '"jpkcom-harden-trim"' ) ) );
chk( 'import: other plugin => wrong_plugin', 'wrong_plugin' === jht_error_code( jpkcom_harden_trim_parse_import( '{"plugin":"x","schema":1,"settings":{}}' ) ) );
chk( 'import: no plugin field => wrong_plugin', 'wrong_plugin' === jht_error_code( jpkcom_harden_trim_parse_import( '{"schema":1,"settings":{"emoji":false}}' ) ) );
chk( 'import: JSON list => wrong_plugin', 'wrong_plugin' === jht_error_code( jpkcom_harden_trim_parse_import( '[1,2]' ) ) );
chk( 'import: schema 2 => unsupported_schema', 'unsupported_schema' === jht_error_code( jpkcom_harden_trim_parse_import( jht_import_json( array( 'emoji' => false ), array( 'schema' => 2 ) ) ) ) );
chk( 'import: schema "1" (string) => unsupported_schema', 'unsupported_schema' === jht_error_code( jpkcom_harden_trim_parse_import( jht_import_json( array( 'emoji' => false ), array( 'schema' => '1' ) ) ) ) );
chk( 'import: missing settings => no_settings', 'no_settings' === jht_error_code( jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1}' ) ) );
chk( 'import: empty settings => no_settings', 'no_settings' === jht_error_code( jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{}}' ) ) );
chk( 'import: settings as a list => no_settings', 'no_settings' === jht_error_code( jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1,"settings":[true,false]}' ) ) );
chk( 'import: settings as a string => no_settings', 'no_settings' === jht_error_code( jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1,"settings":"emoji"}' ) ) );
// Object, settings, emoji value: three levels; 6 more nested arrays reach 9.
$jht_deep = '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":' . str_repeat( string: '[', times: 6 ) . '1' . str_repeat( string: ']', times: 6 ) . '}}';
chk( 'import: 9-level nesting => invalid_json', 'invalid_json' === jht_error_code( jpkcom_harden_trim_parse_import( $jht_deep ) ) );
$jht_eight = '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":' . str_repeat( string: '[', times: 5 ) . '1' . str_repeat( string: ']', times: 5 ) . ',"dashicons":true}}';
$jht_r     = jpkcom_harden_trim_parse_import( $jht_eight );
chk( 'import: 8-level nesting decodes, the nested value is rejected', is_array( $jht_r ) && in_array( needle: 'emoji', haystack: $jht_r['rejected'], strict: true ) && array( 'dashicons' ) === $jht_r['applied'] );
foreach ( array( 'too_large', 'invalid_json', 'wrong_plugin', 'unsupported_schema', 'no_settings', 'no_input', 'upload_failed' ) as $jht_code ) {
	chk( "import: error message for {$jht_code}", '' !== jpkcom_harden_trim_import_error_message( $jht_code ) );
}
chk( 'import: unknown error code has no message', '' === jpkcom_harden_trim_import_error_message( '<script>' ) );
$jht_r = jpkcom_harden_trim_parse_import( '{' );
chk( 'import: WP_Error carries the mapped message', $jht_r instanceof WP_Error && jpkcom_harden_trim_import_error_message( 'invalid_json' ) === $jht_r->get_error_message() );

// import_partial.

jht_store( array( 'heartbeat' => 'optimized' ) );
$jht_before = $GLOBALS['jht_options'][ $option_name ];
$jht_r      = jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":false,"heartbeat":"turbo","foo":1,"exempt_roles":["editor","ghost"]}}' );
chk( 'partial: result is an array', is_array( $jht_r ) );
$jht_r = is_array( $jht_r ) ? $jht_r : array( 'settings' => array(), 'applied' => array(), 'rejected' => array() );
chk( 'partial: applied contains emoji and exempt_roles', in_array( needle: 'emoji', haystack: $jht_r['applied'], strict: true ) && in_array( needle: 'exempt_roles', haystack: $jht_r['applied'], strict: true ) );
chk( 'partial: applied has nothing else', 2 === count( $jht_r['applied'] ) );
chk( 'partial: rejected contains heartbeat, foo, exempt_roles:ghost', array() === array_diff( array( 'heartbeat', 'foo', 'exempt_roles:ghost' ), $jht_r['rejected'] ) );
chk( 'partial: heartbeat keeps the previous stored value (not the default)', 'optimized' === ( $jht_r['settings']['heartbeat'] ?? null ) );
chk( 'partial: applied values set', false === ( $jht_r['settings']['emoji'] ?? null ) && array( 'editor' ) === ( $jht_r['settings']['exempt_roles'] ?? null ) );
chk( 'partial: keys not in the file keep their stored value', true === ( $jht_r['settings']['head_generator'] ?? null ) );
chk( 'partial: unknown key not in the settings', ! array_key_exists( key: 'foo', array: $jht_r['settings'] ) );
chk( 'partial: settings complete', array_keys( array: jpkcom_harden_trim_schema() ) === array_keys( array: $jht_r['settings'] ) );
chk( 'partial: parsing writes nothing', $jht_before === $GLOBALS['jht_options'][ $option_name ] );
$jht_r = jpkcom_harden_trim_parse_import( '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"0":true,"emoji":{"a":1}}}' );
chk( 'partial: numeric key and object value rejected, no fatal', is_array( $jht_r ) && array() === $jht_r['applied'] && array() === array_diff( array( '0', 'emoji' ), $jht_r['rejected'] ) );

// import_bom.

$jht_r = jpkcom_harden_trim_parse_import( "\xEF\xBB\xBF" . jht_import_json( array( 'emoji' => false ) ) );
chk( 'bom: BOM + valid JSON is accepted', is_array( $jht_r ) && array( 'emoji' ) === $jht_r['applied'] );

// roundtrip.

jht_store( array( 'emoji' => false, 'asset_version' => 'hash', 'post_revisions' => 5, 'autosave_interval' => 120, 'app_passwords' => 'roles', 'app_password_roles' => array( 'administrator', 'editor' ), 'exempt_roles' => array( 'author' ) ) );
$jht_stored = jpkcom_harden_trim_get_stored();
$jht_r      = jpkcom_harden_trim_parse_import( (string) wp_json_encode( jpkcom_harden_trim_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
chk( 'roundtrip: export -> parse gives identical settings', is_array( $jht_r ) && $jht_stored === $jht_r['settings'] );
chk( 'roundtrip: nothing rejected, every key applied', is_array( $jht_r ) && array() === $jht_r['rejected'] && array_keys( array: jpkcom_harden_trim_schema() ) === $jht_r['applied'] );

// handlers_guarded.

jht_reset_admin();
jht_store();
$jht_before = $GLOBALS['jht_options'][ $option_name ];

$GLOBALS['jht_caps'] = array();
$_POST               = array( 'jpkcom_harden_trim_export_nonce' => 'nonce-jpkcom_harden_trim_export' );
$_REQUEST            = $_POST;
ob_start();
$r = jht_run_handler( 'jpkcom_harden_trim_handle_export' );
$jht_out = (string) ob_get_clean();
chk( 'export without capability: wp_die 403, no output', 403 === $r['die'] && '' === $jht_out );

$GLOBALS['jht_caps'] = array( 'manage_options' => true );
$_POST               = array( 'jpkcom_harden_trim_export_nonce' => 'nonce-jpkcom_harden_trim_import' );
$_REQUEST            = $_POST;
ob_start();
$r = jht_run_handler( 'jpkcom_harden_trim_handle_export' );
$jht_out = (string) ob_get_clean();
chk( 'export with bad nonce: dies, no output', null !== $r['die'] && '' === $jht_out && ! isset( $GLOBALS['jht_nocache'] ) );

$GLOBALS['jht_caps'] = array();
$_POST               = array( 'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import', 'jpkcom_harden_trim_json' => jht_import_json( array( 'emoji' => false ) ) );
$_REQUEST            = $_POST;
$r                   = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import without capability: wp_die 403, no write', 403 === $r['die'] && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$GLOBALS['jht_caps'] = array( 'manage_options' => true );
$_POST['jpkcom_harden_trim_import_nonce'] = 'nonce-jpkcom_harden_trim_export';
$_REQUEST                                 = $_POST;
$r                                        = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import with bad nonce: dies, no write', null !== $r['die'] && null === $r['redirect'] && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

// Import handler: pasted JSON.

jht_reset_admin();
jht_store( array( 'heartbeat' => 'optimized' ) );
$_POST    = array(
	'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import',
	// WordPress slashes $_POST; the handler must unslash.
	'jpkcom_harden_trim_json'         => addslashes( string: '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":false,"heartbeat":"turbo","foo":1,"exempt_roles":["editor","ghost"],"head_generator":true}}' ),
);
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
$jht_s    = $GLOBALS['jht_options'][ $option_name ]['settings'] ?? array();
chk( 'import paste: redirect with jht_msg=imported', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=imported' ) );
chk( 'import paste: redirect carries the changed count', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_changed=2' ) );
chk( 'import paste: applied values stored', false === ( $jht_s['emoji'] ?? null ) && array( 'editor' ) === ( $jht_s['exempt_roles'] ?? null ) );
chk( 'import paste: rejected value keeps the previous stored value', 'optimized' === ( $jht_s['heartbeat'] ?? null ) );
$jht_notice = get_transient( 'jpkcom_harden_trim_notice_7' );
chk( 'import paste: notice transient has the imported code', is_array( $jht_notice ) && 'imported' === ( $jht_notice['code'] ?? null ) );
chk( 'import paste: applied list in the notice', is_array( $jht_notice ) && array( 'emoji', 'exempt_roles', 'head_generator' ) === ( $jht_notice['applied'] ?? null ) );
chk( 'import paste: changed list only has real changes', is_array( $jht_notice ) && array( 'emoji', 'exempt_roles' ) === ( $jht_notice['changed'] ?? null ) );
chk( 'import paste: rejected list in the notice', is_array( $jht_notice ) && array() === array_diff( array( 'heartbeat', 'foo', 'exempt_roles:ghost' ), $jht_notice['rejected'] ?? array() ) );
chk( 'import paste: no locked list without constants', is_array( $jht_notice ) && ! isset( $jht_notice['locked'] ) );

$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'imported', 'jht_changed' => '2' );
ob_start();
jpkcom_harden_trim_render_notices();
$jht_html = (string) ob_get_clean();
chk( 'import notice: success message', str_contains( haystack: $jht_html, needle: 'Settings imported.' ) && str_contains( haystack: $jht_html, needle: 'notice-success' ) );
chk( 'import notice: applied and rejected counts', str_contains( haystack: $jht_html, needle: '3 applied, 3 rejected.' ) );
chk( 'import notice: rejected keys listed by label and value', str_contains( haystack: $jht_html, needle: 'Rejected: Heartbeat, foo, Roles exempt from the role-scoped features (ghost)' ) );
chk( 'import notice: changed keys listed', str_contains( haystack: $jht_html, needle: 'Changed: Remove emoji scripts, Roles exempt from the role-scoped features' ) );

// A hostile file with many unknown keys: the lists in the transient and the notice are capped at 50.
jht_reset_admin();
jht_store();
$ie_keys = array();
for ( $ie_i = 0; $ie_i < 120; $ie_i++ ) {
	$ie_keys[ 'junk_' . $ie_i ] = 1;
}
$_POST    = array(
	'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import',
	'jpkcom_harden_trim_json'         => (string) json_encode( array( 'plugin' => 'jpkcom-harden-trim', 'schema' => 1, 'settings' => $ie_keys ) ),
);
$_REQUEST = $_POST;
jht_run_handler( 'jpkcom_harden_trim_handle_import' );
$jht_notice = get_transient( 'jpkcom_harden_trim_notice_7' );
chk( 'import cap: transient keeps 50 rejected entries', is_array( $jht_notice ) && 50 === count( $jht_notice['rejected'] ?? array() ) );
chk( 'import cap: transient remembers how many more', is_array( $jht_notice ) && 70 === ( $jht_notice['more']['rejected'] ?? null ) );
$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'imported', 'jht_changed' => '0' );
ob_start();
jpkcom_harden_trim_render_notices();
$jht_html = (string) ob_get_clean();
chk( 'import cap: counts stay complete', str_contains( haystack: $jht_html, needle: '0 applied, 120 rejected.' ) );
chk( 'import cap: list ends with "and 70 more"', str_contains( haystack: $jht_html, needle: 'junk_49 and 70 more' ) && ! str_contains( haystack: $jht_html, needle: 'junk_50' ) );

// Import handler: failures write nothing and say why.

jht_reset_admin();
jht_store();
$jht_before = $GLOBALS['jht_options'][ $option_name ];
$_POST      = array( 'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import', 'jpkcom_harden_trim_json' => '{"plugin":' );
$_REQUEST   = $_POST;
$r          = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import invalid: redirect with import_failed', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=import_failed' ) );
chk( 'import invalid: no write', $jht_before === $GLOBALS['jht_options'][ $option_name ] );
chk( 'import invalid: error code in the transient', array( 'invalid_json' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) );
chk( 'import invalid: error code not in the URL', is_string( $r['redirect'] ) && ! str_contains( haystack: $r['redirect'], needle: 'invalid_json' ) );
$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'import_failed' );
ob_start();
jpkcom_harden_trim_render_notices();
$jht_html = (string) ob_get_clean();
chk( 'import failed notice: error notice with the reason', str_contains( haystack: $jht_html, needle: 'notice-error' ) && str_contains( haystack: $jht_html, needle: esc_html( jpkcom_harden_trim_import_error_message( 'invalid_json' ) ) ) );

set_transient( 'jpkcom_harden_trim_notice_7', array( 'code' => 'import_failed', 'error' => array( '<b>forged</b>' ) ), 60 );
ob_start();
jpkcom_harden_trim_render_notices();
$jht_html = (string) ob_get_clean();
chk( 'import failed notice: unknown error code shows nothing extra', ! str_contains( haystack: $jht_html, needle: 'forged' ) );

$_POST    = array( 'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import', 'jpkcom_harden_trim_json' => "  \n" );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import empty: import_failed with no_input', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=import_failed' ) && array( 'no_input' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) );

$_POST    = array( 'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import', 'jpkcom_harden_trim_json' => array( 'x' ) );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import json as array: no_input, no fatal', array( 'no_input' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

// Import handler: uploaded file.

$jht_tmp = (string) tempnam( directory: sys_get_temp_dir(), prefix: 'jht' );
file_put_contents( filename: $jht_tmp, data: jht_import_json( array( 'emoji' => false ) ) );

$_POST    = array( 'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import' );
$_REQUEST = $_POST;
$_FILES   = array( 'jpkcom_harden_trim_file' => array( 'name' => 'x.json', 'type' => 'application/json', 'tmp_name' => $jht_tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize( filename: $jht_tmp ) ) );
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import file not uploaded via HTTP: upload_failed, no write', array( 'upload_failed' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_FILES['jpkcom_harden_trim_file']['error'] = UPLOAD_ERR_INI_SIZE;
$r                                          = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import file over upload_max_filesize: too_large', array( 'too_large' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_FILES['jpkcom_harden_trim_file']['error'] = UPLOAD_ERR_PARTIAL;
$r                                          = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import partial upload: upload_failed', array( 'upload_failed' ) === ( get_transient( 'jpkcom_harden_trim_notice_7' )['error'] ?? null ) );

$_FILES['jpkcom_harden_trim_file'] = array( 'name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0 );
$_POST['jpkcom_harden_trim_json']  = jht_import_json( array( 'dashicons' => true ) );
$_REQUEST                          = $_POST;
$r                                 = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import with no file chosen falls back to the pasted JSON', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=imported' ) && true === $GLOBALS['jht_options'][ $option_name ]['settings']['dashicons'] );

$_FILES['jpkcom_harden_trim_file'] = 'garbage';
$_POST['jpkcom_harden_trim_json']  = jht_import_json( array( 'dashicons' => false ) );
$_REQUEST                          = $_POST;
$r                                 = jht_run_handler( 'jpkcom_harden_trim_handle_import' );
chk( 'import with malformed $_FILES entry: no fatal, pasted JSON used', is_string( $r['redirect'] ) && false === $GLOBALS['jht_options'][ $option_name ]['settings']['dashicons'] );
$_FILES = array();
unlink( filename: $jht_tmp );

// Rendered section.

jht_reset_admin();
jht_store();
ob_start();
jpkcom_harden_trim_render_import_export();
$jht_html = (string) ob_get_clean();
chk( 'render: heading', str_contains( haystack: $jht_html, needle: '<h2 id="jht-import-export">' ) );
chk( 'render: export form posts to admin-post.php with action and nonce', 1 === preg_match( '/<form method="post" action="https:\/\/example\.test\/wp-admin\/admin-post\.php"[^>]*>(?:(?!<\/form>).)*name="action" value="jpkcom_harden_trim_export"(?:(?!<\/form>).)*value="nonce-jpkcom_harden_trim_export"/s', $jht_html ) || 1 === preg_match( '/<form method="post" action="https:\/\/example\.test\/wp-admin\/admin-post\.php"[^>]*>(?:(?!<\/form>).)*value="nonce-jpkcom_harden_trim_export"(?:(?!<\/form>).)*name="action" value="jpkcom_harden_trim_export"/s', $jht_html ) );
chk( 'render: import form is multipart with file, textarea, action and nonce', 1 === preg_match( '/<form method="post" action="https:\/\/example\.test\/wp-admin\/admin-post\.php" enctype="multipart\/form-data"[^>]*>(?:(?!<\/form>).)*value="nonce-jpkcom_harden_trim_import"(?:(?!<\/form>).)*name="action" value="jpkcom_harden_trim_import"(?:(?!<\/form>).)*type="file"[^>]*name="jpkcom_harden_trim_file"(?:(?!<\/form>).)*<textarea[^>]*name="jpkcom_harden_trim_json"/s', $jht_html ) );
chk( 'render: file input and textarea have labels', 1 === preg_match( '/<label for="jht-import-file">/', $jht_html ) && 1 === preg_match( '/id="jht-import-file"/', $jht_html ) && 1 === preg_match( '/<label for="jht-import-json">/', $jht_html ) && 1 === preg_match( '/id="jht-import-json"/', $jht_html ) );
chk( 'render: no site URL other than the admin-post target', ! str_contains( haystack: str_replace( search: 'https://example.test/wp-admin/admin-post.php', replace: '', subject: $jht_html ), needle: 'example.test' ) );

$GLOBALS['jht_caps'] = array( 'manage_options' => true );
ob_start();
jpkcom_harden_trim_render_page();
$jht_html = (string) ob_get_clean();
chk( 'render: the settings page draws the section', str_contains( haystack: $jht_html, needle: 'id="jht-import-export"' ) );

// Constant scenarios in a child process each.

$run = jht_run_fixture( __DIR__ . '/fixture-import-export.php', array( 'JHT_MODE' => 'locked' ) );
chk( 'fixture locked: exits 0 (' . substr( string: $run['out'], offset: 0, length: 300 ) . ')', 0 === $run['exit'] );
$jht_data = json_decode( $run['out'], true );
chk( 'locked import: the imported value is stored although a constant locks it', is_array( $jht_data ) && false === ( $jht_data['stored']['emoji'] ?? null ) );
chk( 'locked import: core-locked key stored too', is_array( $jht_data ) && true === ( $jht_data['stored']['file_edit'] ?? null ) );
chk( 'locked import: the notice lists the locked keys', is_array( $jht_data ) && array( 'emoji', 'file_edit' ) === ( $jht_data['notice']['locked'] ?? null ) );
chk( 'locked import: the effective value is still the constant\'s', is_array( $jht_data ) && true === ( $jht_data['effective']['emoji'] ?? null ) );
chk( 'locked import: the notice says the constant still wins', is_array( $jht_data ) && str_contains( haystack: (string) ( $jht_data['html'] ?? '' ), needle: 'Stored, but a wp-config.php constant still wins: Remove emoji scripts, Disable the file editors' ) );
chk( 'locked save: the form still keeps the stored value of a locked key', is_array( $jht_data ) && false === ( $jht_data['after_save']['emoji'] ?? null ) );

// A lowered JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES shows up in the texts (formatted with size_format()).
$run      = jht_run_fixture( __DIR__ . '/fixture-import-export.php', array( 'JHT_MODE' => 'small' ) );
$jht_data = json_decode( $run['out'], true );
chk( 'fixture small: exits 0 (' . substr( string: $run['out'], offset: 0, length: 200 ) . ')', 0 === $run['exit'] && is_array( $jht_data ) );
chk( 'small limit: too_large message names 2 KB', is_array( $jht_data ) && str_contains( haystack: (string) $jht_data['message'], needle: '2 KB' ) && ! str_contains( haystack: (string) $jht_data['message'], needle: '64 KB' ) );
chk( 'small limit: import description names 2 KB', is_array( $jht_data ) && str_contains( haystack: (string) $jht_data['html'], needle: '(up to 2 KB)' ) && ! str_contains( haystack: (string) $jht_data['html'], needle: '64 KB' ) );
chk( 'small limit: 3000 bytes rejected', is_array( $jht_data ) && true === $jht_data['parse'] );
chk( 'default limit: texts name 64 KB', str_contains( haystack: jpkcom_harden_trim_import_error_message( 'too_large' ), needle: '64 KB' ) );

$run = jht_run_fixture( __DIR__ . '/fixture-import-export.php', array( 'JHT_MODE' => 'export' ) );
chk( 'fixture export: exits 0 (' . substr( string: $run['out'], offset: -200 ) . ')', 0 === $run['exit'] );
$jht_parts = explode( separator: "\n--jht-", string: $run['out'] );
$jht_data  = json_decode( $jht_parts[0], true );
chk( 'export download: body is the payload as JSON', is_array( $jht_data ) && 'jpkcom-harden-trim' === ( $jht_data['plugin'] ?? null ) && false === ( $jht_data['settings']['emoji'] ?? null ) );
chk( 'export download: body equals the payload', is_array( $jht_data ) && array( 'plugin', 'schema', 'version', 'settings' ) === array_keys( array: $jht_data ) );
chk( 'export download: pretty printed', str_contains( haystack: $jht_parts[0], needle: "\n    \"plugin\"" ) );
chk( 'export download: handler exits after the body, nocache_headers() ran', array( 'nocache:1' ) === array_slice( array: $jht_parts, offset: 1 ) );
chk( 'export download: no PHP notice in the output', ! str_contains( haystack: $run['out'], needle: 'Warning' ) && ! str_contains( haystack: $run['out'], needle: 'Notice' ) && ! str_contains( haystack: $run['out'], needle: 'Deprecated' ) );

summary();
