<?php
/**
 * Tests for jpkcom_harden_trim_status(): one row per schema key, the source of
 * every effective value (default, stored, filter, constant, core constant),
 * and why a feature does not act (kill switch, core constant, block theme,
 * unused role list, off).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';
require_once __DIR__ . '/stubs-cli.php';

foreach ( array( 'settings-schema', 'settings-store', 'status' ) as $st_module ) {
	require_once dirname( path: __DIR__ ) . '/includes/' . $st_module . '.php';
}

/**
 * Store settings and forget the cached effective settings.
 *
 * @param array<string, mixed> $settings Overrides of the defaults.
 * @return void
 */
function st_store( array $settings ): void {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * The status row of one key.
 *
 * @param array<string, mixed> $status Status.
 * @param string               $key    Key.
 * @return array<string, mixed>
 */
function st_row( array $status, string $key ): array {
	foreach ( $status['settings'] as $row ) {
		if ( $key === $row['key'] ) {
			return $row;
		}
	}
	return array();
}

jht_reset_admin();
st_store( array() );

// Shape.
$st = jpkcom_harden_trim_status();
chk( 'one row per schema key, in schema order', array_keys( jpkcom_harden_trim_schema() ) === array_column( $st['settings'], 'key' ) );
chk( 'every row carries the same fields', array() === array_filter( $st['settings'], static fn( array $r ): bool => array( 'key', 'group', 'type', 'value', 'stored', 'default', 'source', 'locked_by', 'core_value', 'acts', 'reason', 'role_scoped' ) !== array_keys( $r ) ) );
chk( 'defaults: every source is default', array( 'default' ) === array_values( array_unique( array_column( $st['settings'], 'source' ) ) ) );
chk( 'defaults: nothing locked, no core value', array( null ) === array_values( array_unique( array_merge( array_column( $st['settings'], 'locked_by' ), array_column( $st['settings'], 'core_value' ) ) ) ) );
chk( 'defaults: kill switch not defined', array( 'defined' => false, 'valid' => true, 'active' => false ) === $st['kill_switch'] );
chk( 'defaults: no invalid constants, no exempt roles', array() === $st['invalid_constants'] && array() === $st['exempt_roles'] );
chk( 'an active default acts (emoji)', true === st_row( $st, 'emoji' )['acts'] && null === st_row( $st, 'emoji' )['reason'] );
chk( 'an inactive default does not act, reason off (post_embeds)', false === st_row( $st, 'post_embeds' )['acts'] && 'off' === st_row( $st, 'post_embeds' )['reason'] );
chk( 'role-scoped flag is reported (emoji yes, xmlrpc no)', true === st_row( $st, 'emoji' )['role_scoped'] && false === st_row( $st, 'xmlrpc' )['role_scoped'] );

// Stored.
st_store( array( 'emoji' => false, 'heartbeat' => 'optimized', 'exempt_roles' => array( 'editor' ) ) );
$st = jpkcom_harden_trim_status();
chk( 'stored false: source stored, value false, does not act', 'stored' === st_row( $st, 'emoji' )['source'] && false === st_row( $st, 'emoji' )['value'] && 'off' === st_row( $st, 'emoji' )['reason'] );
chk( 'stored enum: source stored, value and default reported', 'stored' === st_row( $st, 'heartbeat' )['source'] && 'optimized' === st_row( $st, 'heartbeat' )['value'] && 'enabled' === st_row( $st, 'heartbeat' )['default'] );
chk( 'exempt roles are reported at the top', array( 'editor' ) === $st['exempt_roles'] );

// Filter.
st_store( array() );
add_filter( 'jpkcom_harden_trim_settings', static fn( array $s ): array => array_merge( $s, array( 'dashicons' => true ) ) );
$st = jpkcom_harden_trim_status();
chk( 'a value changed by the filter: source filter, stored unchanged', 'filter' === st_row( $st, 'dashicons' )['source'] && true === st_row( $st, 'dashicons' )['value'] && false === st_row( $st, 'dashicons' )['stored'] );
chk( 'the filter does not mark untouched keys', 'default' === st_row( $st, 'emoji' )['source'] );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] = array();
jpkcom_harden_trim_settings_reset_cache();

// Block theme.
st_store( array( 'block_library_css' => true ) );
$GLOBALS['jht_state']['block_theme']         = true;
$GLOBALS['jht_did']['after_setup_theme']    = 0;
$st = jpkcom_harden_trim_status();
chk( 'before after_setup_theme the block theme is not known yet', false === $st['block_theme'] && true === st_row( $st, 'block_library_css' )['acts'] );
$GLOBALS['jht_did']['after_setup_theme'] = 1;
$st = jpkcom_harden_trim_status();
chk( 'block theme: block_library_css is on but does not act, reason block_theme', true === $st['block_theme'] && true === st_row( $st, 'block_library_css' )['value'] && 'block_theme' === st_row( $st, 'block_library_css' )['reason'] );
chk( 'block theme: other switches unaffected', true === st_row( $st, 'emoji' )['acts'] );
$GLOBALS['jht_state']['block_theme'] = false;
$st = jpkcom_harden_trim_status();
chk( 'classic theme: block_library_css acts', false === $st['block_theme'] && true === st_row( $st, 'block_library_css' )['acts'] );

// Application-password roles only matter when application passwords are limited by role.
st_store( array( 'app_password_roles' => array( 'editor' ) ) );
$st = jpkcom_harden_trim_status();
chk( 'role list for application passwords while they are unchanged: unused', 'unused' === st_row( $st, 'app_password_roles' )['reason'] );
st_store( array( 'app_passwords' => 'roles', 'app_password_roles' => array( 'editor' ) ) );
$st = jpkcom_harden_trim_status();
chk( 'role list while application passwords are limited by role: acts', true === st_row( $st, 'app_password_roles' )['acts'] );

// Plain permalinks: the author-enumeration block needs pretty permalinks (features-privacy.php).
st_store( array() );
$GLOBALS['jht_options']['permalink_structure'] = '';
$st = jpkcom_harden_trim_status();
chk( 'plain permalinks: block_author_enum is on but does not act, reason plain_permalinks', true === st_row( $st, 'block_author_enum' )['value'] && 'plain_permalinks' === st_row( $st, 'block_author_enum' )['reason'] );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
jpkcom_harden_trim_settings_reset_cache();
$st = jpkcom_harden_trim_status();
chk( 'pretty permalinks: block_author_enum acts', true === st_row( $st, 'block_author_enum' )['acts'] );

// An EMPTY role list in roles mode acts: nobody may use application passwords (features-security.php).
st_store( array( 'app_passwords' => 'roles', 'app_password_roles' => array() ) );
$st = jpkcom_harden_trim_status();
chk( 'application passwords limited to an empty role list: the list acts (nobody)', true === st_row( $st, 'app_password_roles' )['acts'] );

// Exempt roles only matter while a role-scoped feature acts.
st_store( array( 'exempt_roles' => array( 'editor' ) ) );
$st = jpkcom_harden_trim_status();
chk( 'exempt roles with emoji (role-scoped) acting: the list acts', true === st_row( $st, 'exempt_roles' )['acts'] );
st_store( array( 'exempt_roles' => array( 'editor' ), 'emoji' => false, 'asset_version' => 'off', 'comment_reply' => false, 'block_library_css' => false, 'global_styles' => false, 'heartbeat' => 'enabled' ) );
$st = jpkcom_harden_trim_status();
chk( 'exempt roles with no role-scoped feature acting: unused', false === st_row( $st, 'exempt_roles' )['acts'] && 'unused' === st_row( $st, 'exempt_roles' )['reason'] );

// Constants, per process.
$st_run = static function ( array $defines, array $stored = array() ): array {
	$run = jht_run_fixture(
		__DIR__ . '/fixture-cli-status.php',
		array( 'JHT_ACTION' => 'status', 'JHT_DEFINE' => (string) json_encode( $defines ), 'JHT_STORED' => (string) json_encode( $stored ) )
	);
	$out = json_decode( json: $run['out'], associative: true );
	return is_array( $out ) ? $out : array( 'raw' => $run['out'] );
};

$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_EMOJI' => false, 'JPKCOM_HARDEN_TRIM_HEARTBEAT' => 'sometimes' ), array( 'emoji' => true ) );
chk( 'fixture ran (' . substr( (string) ( $st['raw'] ?? 'ok' ), 0, 200 ) . ')', isset( $st['settings'] ) );
chk( 'plugin constant: source constant, locked by it, value from it', 'constant' === ( st_row( $st, 'emoji' )['source'] ?? null ) && 'JPKCOM_HARDEN_TRIM_EMOJI' === st_row( $st, 'emoji' )['locked_by'] && false === st_row( $st, 'emoji' )['value'] && true === st_row( $st, 'emoji' )['stored'] );
chk( 'invalid plugin constant: named, ignored, not a lock', array( 'JPKCOM_HARDEN_TRIM_HEARTBEAT' ) === $st['invalid_constants'] && 'default' === st_row( $st, 'heartbeat' )['source'] && null === st_row( $st, 'heartbeat' )['locked_by'] );

$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' => array( 'DISALLOW_FILE_EDIT' => true, 'WP_POST_REVISIONS' => 7 ) ), array( 'file_edit' => true ) );
chk( 'core constant: source core_constant, locked by it, its value reported', 'core_constant' === ( st_row( $st, 'file_edit' )['source'] ?? null ) && 'DISALLOW_FILE_EDIT' === st_row( $st, 'file_edit' )['locked_by'] && true === st_row( $st, 'file_edit' )['core_value'] );
chk( 'core constant: the plugin does not act, reason core_constant', false === st_row( $st, 'file_edit' )['acts'] && 'core_constant' === st_row( $st, 'file_edit' )['reason'] );
chk( 'core constant with a number: core_value 7, plugin value unchanged', 7 === st_row( $st, 'post_revisions' )['core_value'] && null === st_row( $st, 'post_revisions' )['value'] );

$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' => true ) );
chk( 'kill switch: reported active', array( 'defined' => true, 'valid' => true, 'active' => true ) === ( $st['kill_switch'] ?? null ) );
chk( 'kill switch: no feature acts, every reason kill_switch', array( 'kill_switch' ) === array_values( array_unique( array_column( $st['settings'], 'reason' ) ) ) && array( false ) === array_values( array_unique( array_column( $st['settings'], 'acts' ) ) ) );

$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' => 'maybe' ) );
chk( 'unparseable kill switch: not active, named as invalid', false === $st['kill_switch']['active'] && in_array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', $st['invalid_constants'], true ) && true === st_row( $st, 'emoji' )['acts'] );

// The filter runs after the constants and may replace a constant's value.
$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_HEARTBEAT' => 'disabled', 'JHT_FILTER_HEARTBEAT' => 'optimized' ) );
chk( 'constant overridden by the filter: source filter, value from the filter', 'filter' === ( st_row( $st, 'heartbeat' )['source'] ?? null ) && 'optimized' === st_row( $st, 'heartbeat' )['value'] );
$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_HEARTBEAT' => 'disabled', 'JHT_FILTER_HEARTBEAT' => 'disabled' ) );
chk( 'constant the filter leaves alone: source constant', 'constant' === ( st_row( $st, 'heartbeat' )['source'] ?? null ) );

// A core constant defined after the snapshot (another plugin's file body) still wins for file_edit.
$st = $st_run( array( 'DISALLOW_FILE_EDIT' => false ), array( 'file_edit' => true ) );
chk( 'late DISALLOW_FILE_EDIT false with file_edit on: does not act, reason core_constant, core_value false', false === st_row( $st, 'file_edit' )['acts'] && 'core_constant' === st_row( $st, 'file_edit' )['reason'] && false === st_row( $st, 'file_edit' )['core_value'] );
$st = $st_run( array( 'DISALLOW_FILE_EDIT' => true ), array( 'file_edit' => true ) );
chk( 'DISALLOW_FILE_EDIT true equal to the setting (as the plugin defines it): acts', true === st_row( $st, 'file_edit' )['acts'] );
$st = $st_run( array( 'AUTOSAVE_INTERVAL' => 60 ) );
chk( 'core default AUTOSAVE_INTERVAL with the setting off: off, not core_constant', 'off' === st_row( $st, 'autosave_interval' )['reason'] );

// JPKCOM_HARDEN_TRIM_ABILITIES is reported when invalid.
$st = $st_run( array( 'JPKCOM_HARDEN_TRIM_ABILITIES' => 'sometimes' ) );
chk( 'invalid JPKCOM_HARDEN_TRIM_ABILITIES is named', in_array( 'JPKCOM_HARDEN_TRIM_ABILITIES', $st['invalid_constants'] ?? array(), true ) );

summary();
