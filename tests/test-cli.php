<?php
/**
 * Tests for `wp harden-trim`: registration, every subcommand, and that a
 * value the store would not take as given (invalid, locked, unknown role) is
 * an error with nothing written.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';
require_once __DIR__ . '/stubs-cli.php';

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION', value: '1.2.0' );
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES', value: 65536 );
}

foreach ( array( 'settings-schema', 'settings-store', 'status', 'import-export', 'cli' ) as $cl_module ) {
	require_once dirname( path: __DIR__ ) . '/includes/' . $cl_module . '.php';
}

/**
 * Reset the site to stored defaults (plus overrides).
 *
 * @param array<string, mixed> $settings Overrides.
 * @return void
 */
function cl_store( array $settings = array() ): void {
	jht_reset_admin();
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * Whether the option was written since cl_store().
 *
 * @return bool
 */
function cl_written(): bool {
	return '' !== ( $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ]['updated'] ?? '' );
}

$cl_tmp = sys_get_temp_dir() . '/jht-cli-' . getmypid();
@mkdir( $cl_tmp );

chk( 'command registered as harden-trim', 'JPKCom_Harden_Trim_CLI' === ( $GLOBALS['jht_cli_commands']['harden-trim'] ?? null ) );

// status.
cl_store( array( 'emoji' => false ) );
chk( 'status runs through', null === jht_cli( 'status' ) );
$cl_items = WP_CLI::of( 'items' )[0] ?? array();
chk( 'status: one row per key, default fields', count( jpkcom_harden_trim_schema() ) === count( $cl_items['items'] ?? array() ) && array( 'key', 'value', 'source', 'locked_by', 'acts', 'reason' ) === ( $cl_items['fields'] ?? null ) );
$cl_emoji = array_values( array_filter( $cl_items['items'], static fn( array $r ): bool => 'emoji' === $r['key'] ) )[0] ?? array();
chk( 'status table: values printed as set accepts them', 'false' === ( $cl_emoji['value'] ?? null ) && 'no' === $cl_emoji['acts'] && 'stored' === $cl_emoji['source'] );
jht_cli( 'status', array(), array( 'format' => 'json' ) );
$cl_items = WP_CLI::of( 'items' )[0] ?? array();
$cl_emoji = array_values( array_filter( $cl_items['items'], static fn( array $r ): bool => 'emoji' === $r['key'] ) )[0] ?? array();
chk( 'status json: raw values', false === ( $cl_emoji['value'] ?? null ) && false === $cl_emoji['acts'] );
chk( 'status: no warning without kill switch or invalid constants', array() === WP_CLI::of( 'warning' ) );

// get.
cl_store( array( 'heartbeat' => 'optimized', 'exempt_roles' => array( 'editor', 'author' ) ) );
jht_cli( 'get', array( 'heartbeat' ) );
chk( 'get: plain value', array( 'optimized' ) === WP_CLI::of( 'line' ) );
jht_cli( 'get', array( 'post_revisions' ) );
chk( 'get: null prints default', array( 'default' ) === WP_CLI::of( 'line' ) );
jht_cli( 'get', array( 'exempt_roles' ) );
chk( 'get: role list comma-separated', array( 'editor,author' ) === WP_CLI::of( 'line' ) );
jht_cli( 'get', array( 'post_revisions' ), array( 'format' => 'json' ) );
chk( 'get --format=json: null', array( 'null' ) === WP_CLI::of( 'line' ) );
chk( 'get unknown key: error', null !== jht_cli( 'get', array( 'nope' ) ) && str_contains( WP_CLI::of( 'error' )[0] ?? '', "Unknown setting 'nope'" ) );

// set.
cl_store();
chk( 'set emoji false: runs through', null === jht_cli( 'set', array( 'emoji', 'false' ) ) );
chk( 'set emoji false: stored', false === jpkcom_harden_trim_get_stored()['emoji'] && cl_written() );
chk( 'set: changes reported as before/after table', array( array( 'key' => 'emoji', 'before' => 'true', 'after' => 'false' ) ) === ( WP_CLI::of( 'items' )[0]['items'] ?? null ) );
chk( 'set: effective settings follow at once', false === jpkcom_harden_trim_settings()['emoji'] );

foreach ( array(
	array( 'emoji', 'maybe', 'Accepted: true or false.' ),
	array( 'emoji', '', 'Accepted: true or false.' ),
	array( 'heartbeat', 'fast', 'Accepted: enabled, optimized, disabled.' ),
	array( 'post_revisions', '7', 'or default.' ),
	array( 'post_revisions', '-1', 'or default.' ),
) as $cl_case ) {
	cl_store();
	$cl_halt = jht_cli( 'set', array( $cl_case[0], $cl_case[1] ) );
	chk( "set {$cl_case[0]} '{$cl_case[1]}': error naming the accepted values, nothing written", null !== $cl_halt && str_contains( (string) $cl_halt, $cl_case[2] ) && ! cl_written() );
}

cl_store();
jht_cli( 'set', array( 'post_revisions', '20' ) );
chk( 'set post_revisions 20', 20 === jpkcom_harden_trim_get_stored()['post_revisions'] );
jht_cli( 'set', array( 'post_revisions', 'default' ) );
chk( 'set post_revisions default: null (WordPress default)', null === jpkcom_harden_trim_get_stored()['post_revisions'] );
jht_cli( 'set', array( 'heartbeat', 'optimized' ) );
chk( 'set heartbeat optimized', 'optimized' === jpkcom_harden_trim_get_stored()['heartbeat'] );
foreach ( array( '1', '0', 'yes', 'no', 'on', 'off', 'TRUE' ) as $cl_bool ) {
	cl_store( array( 'emoji' => ! filter_var( $cl_bool, FILTER_VALIDATE_BOOLEAN ) ) );
	jht_cli( 'set', array( 'emoji', $cl_bool ) );
	chk( "set emoji {$cl_bool}: accepted like the settings store", filter_var( $cl_bool, FILTER_VALIDATE_BOOLEAN ) === jpkcom_harden_trim_get_stored()['emoji'] );
}

cl_store();
$cl_halt = jht_cli( 'set', array( 'exempt_roles', 'editor,ghost' ) );
chk( 'set role list with an unknown role: error naming it, nothing written', null !== $cl_halt && str_contains( (string) $cl_halt, 'ghost' ) && ! cl_written() );
jht_cli( 'set', array( 'exempt_roles', 'editor, author' ) );
chk( 'set role list', array( 'editor', 'author' ) === jpkcom_harden_trim_get_stored()['exempt_roles'] );
jht_cli( 'set', array( 'exempt_roles', '' ) );
chk( 'set role list to empty', array() === jpkcom_harden_trim_get_stored()['exempt_roles'] );

cl_store();
chk( 'set without a value: error', null !== jht_cli( 'set', array( 'emoji' ) ) && ! cl_written() );
chk( 'set unknown key: error', null !== jht_cli( 'set', array( 'nope', 'true' ) ) && ! cl_written() );
jht_cli( 'set', array( 'emoji', 'false' ), array( 'dry-run' => true ) );
chk( 'set --dry-run: reports, writes nothing', ! cl_written() && str_contains( WP_CLI::of( 'success' )[0] ?? '', 'Dry run' ) );
jht_cli( 'set', array( 'emoji', 'true' ) );
chk( 'set to the current value: nothing to change, nothing written', ! cl_written() && array( 'Nothing to change.' ) === WP_CLI::of( 'success' ) );

cl_store();
$GLOBALS['jht_state']['block_theme']      = true;
$GLOBALS['jht_did']['after_setup_theme'] = 1;
$cl_halt = jht_cli( 'set', array( 'global_styles', 'true' ) );
chk( 'set a block-theme-only switch under a block theme: refused like the settings page, nothing written', null !== $cl_halt && str_contains( (string) $cl_halt, 'block theme' ) && ! cl_written() );
cl_store( array( 'global_styles' => true, 'block_library_css' => true, 'emoji' => false ) );
$GLOBALS['jht_state']['block_theme']      = true;
$GLOBALS['jht_did']['after_setup_theme'] = 1;
jht_cli( 'reset', array(), array( 'all' => true, 'yes' => true ) );
chk( 'reset --all under a block theme keeps the block-theme-only switches (as the settings page)', true === jpkcom_harden_trim_get_stored()['global_styles'] && true === jpkcom_harden_trim_get_stored()['block_library_css'] && true === jpkcom_harden_trim_get_stored()['emoji'] );
$GLOBALS['jht_state']['block_theme'] = false;
cl_store();
jht_cli( 'set', array( 'global_styles', 'true' ) );
chk( 'set a block-theme-only switch under a classic theme: stored', true === jpkcom_harden_trim_get_stored()['global_styles'] );

cl_store();
add_filter( 'jpkcom_harden_trim_settings', static fn( array $s ): array => array_merge( $s, array( 'emoji' => true ) ) );
jht_cli( 'set', array( 'emoji', 'false' ) );
chk( 'set a value the filter overrides: stored, with a warning naming the filter and its value', false === jpkcom_harden_trim_get_stored()['emoji'] && str_contains( implode( ' ', WP_CLI::of( 'warning' ) ), 'jpkcom_harden_trim_settings filter returns true for it' ) );
jht_cli( 'set', array( 'heartbeat', 'optimized' ) );
chk( 'set a key the filter does not touch: no warning', array() === WP_CLI::of( 'warning' ) );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] = array();
jpkcom_harden_trim_settings_reset_cache();

// reset.
cl_store( array( 'heartbeat' => 'disabled', 'emoji' => false ) );
jht_cli( 'reset', array( 'heartbeat' ) );
chk( 'reset one key: back to default, others untouched', 'enabled' === jpkcom_harden_trim_get_stored()['heartbeat'] && false === jpkcom_harden_trim_get_stored()['emoji'] );
cl_store( array( 'heartbeat' => 'disabled', 'emoji' => false ) );
chk( 'reset --all without --yes: asks and stops, nothing written', null !== jht_cli( 'reset', array(), array( 'all' => true ) ) && array() !== WP_CLI::of( 'confirm' ) && ! cl_written() );
jht_cli( 'reset', array(), array( 'all' => true, 'yes' => true ) );
chk( 'reset --all --yes: every setting default', jpkcom_harden_trim_defaults() === jpkcom_harden_trim_get_stored() );
chk( 'reset keys and --all: error', null !== jht_cli( 'reset', array( 'emoji' ), array( 'all' => true ) ) );
chk( 'reset nothing named: error', null !== jht_cli( 'reset' ) );

// presets.
cl_store();
jht_cli( 'presets' );
$cl_items = WP_CLI::of( 'items' )[0]['items'] ?? array();
chk( 'presets: three rows', array( 'harmless', 'recommended', 'strict' ) === array_column( $cl_items, 'preset' ) );
chk( 'presets: change counts from apply_preset on the stored settings', count( jpkcom_harden_trim_apply_preset( 'recommended', jpkcom_harden_trim_get_stored() )['changed'] ) === $cl_items[1]['changes'] && 0 === $cl_items[0]['changes'] );

cl_store();
jht_cli( 'preset', array( 'recommended' ), array( 'dry-run' => true ) );
chk( 'preset --dry-run: nothing written', ! cl_written() );
jht_cli( 'preset', array( 'recommended' ) );
chk( 'preset recommended: stored settings equal apply_preset()', jpkcom_harden_trim_apply_preset( 'recommended', jpkcom_harden_trim_defaults() )['settings'] === jpkcom_harden_trim_get_stored() );
cl_store();
jht_cli( 'preset', array( 'harmless' ) );
chk( 'preset that changes nothing: says so, writes nothing', ! cl_written() && str_contains( WP_CLI::of( 'success' )[0] ?? '', 'changes nothing' ) );
chk( 'unknown preset: error naming the presets', str_contains( (string) jht_cli( 'preset', array( 'paranoid' ) ), 'harmless, recommended, strict' ) );

// export / import.
cl_store( array( 'emoji' => false, 'exempt_roles' => array( 'editor' ) ) );
jht_cli( 'export' );
chk( 'export to stdout: the settings page document', jpkcom_harden_trim_export_payload() === json_decode( WP_CLI::of( 'line' )[0] ?? '', true ) );
$cl_file = $cl_tmp . '/export.json';
@unlink( $cl_file );
jht_cli( 'export', array(), array( 'file' => $cl_file ) );
chk( 'export --file: written', jpkcom_harden_trim_export_payload() === json_decode( (string) file_get_contents( $cl_file ), true ) );
chk( 'export to an existing file without --force: error', null !== jht_cli( 'export', array(), array( 'file' => $cl_file ) ) );
chk( 'export --force overwrites', null === jht_cli( 'export', array(), array( 'file' => $cl_file, 'force' => true ) ) );
chk( 'export --no-force does not overwrite (WP-CLI stores false for --no-force)', null !== jht_cli( 'export', array(), array( 'file' => $cl_file, 'force' => false ) ) );
cl_store();
jht_cli( 'set', array( 'emoji', 'false' ), array( 'dry-run' => false ) );
chk( '--no-dry-run writes', cl_written() );
cl_store( array( 'heartbeat' => 'disabled' ) );
chk( 'reset --no-all with no keys: error, nothing written', null !== jht_cli( 'reset', array(), array( 'all' => false ) ) && ! cl_written() );

cl_store();
jht_cli( 'import', array( $cl_file ), array( 'dry-run' => true ) );
chk( 'import --dry-run: nothing written', ! cl_written() );
jht_cli( 'import', array( $cl_file ) );
chk( 'import: round trip', false === jpkcom_harden_trim_get_stored()['emoji'] && array( 'editor' ) === jpkcom_harden_trim_get_stored()['exempt_roles'] );

$cl_bad = $cl_tmp . '/bad.json';
file_put_contents( $cl_bad, '{"plugin":"something-else","schema":1,"settings":{"emoji":false}}' );
cl_store();
chk( 'import of another plugin\'s export: error, nothing written', str_contains( (string) jht_cli( 'import', array( $cl_bad ) ), 'not a Harden & Trim settings export' ) && ! cl_written() );
file_put_contents( $cl_bad, '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":false,"nope":1,"heartbeat":"fast"}}' );
jht_cli( 'import', array( $cl_bad ) );
chk( 'import: valid keys applied, rejected keys warned and left as they were', false === jpkcom_harden_trim_get_stored()['emoji'] && 'enabled' === jpkcom_harden_trim_get_stored()['heartbeat'] && 2 === count( WP_CLI::of( 'warning' ) ) );
file_put_contents( $cl_bad, '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"exempt_roles":["editor","ghost"]}}' );
cl_store();
jht_cli( 'import', array( $cl_bad ) );
chk( 'import: an unknown role is reported as dropped, the rest of the list applied', array( 'editor' ) === jpkcom_harden_trim_get_stored()['exempt_roles'] && str_contains( implode( ' ', WP_CLI::of( 'warning' ) ), "Unknown role 'ghost' dropped from exempt_roles" ) );
chk( 'import of a missing file: error', null !== jht_cli( 'import', array( $cl_tmp . '/missing.json' ) ) );

// Constants, per process.
$cl_run = static function ( array $call, array $defines, array $stored = array() ): array {
	$run = jht_run_fixture(
		__DIR__ . '/fixture-cli-status.php',
		array( 'JHT_ACTION' => 'cli', 'JHT_CLI' => (string) json_encode( $call ), 'JHT_DEFINE' => (string) json_encode( $defines ), 'JHT_STORED' => (string) json_encode( $stored ) )
	);
	$out = json_decode( json: $run['out'], associative: true );
	return is_array( $out ) ? $out : array( 'raw' => $run['out'], 'halt' => null, 'log' => array(), 'stored' => array() );
};

$cl_out = $cl_run( array( 'set', array( 'emoji', 'false' ) ), array( 'JPKCOM_HARDEN_TRIM_EMOJI' => true ) );
chk( 'set a key locked by a plugin constant: error naming it, nothing written (' . substr( (string) ( $cl_out['raw'] ?? '' ), 0, 200 ) . ')', str_contains( (string) $cl_out['halt'], 'locked by JPKCOM_HARDEN_TRIM_EMOJI' ) && true === ( $cl_out['stored']['emoji'] ?? null ) );
$cl_out = $cl_run( array( 'set', array( 'file_edit', 'true' ) ), array( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' => array( 'DISALLOW_FILE_EDIT' => false ) ) );
chk( 'set a key locked by a core constant: error naming it', str_contains( (string) $cl_out['halt'], 'locked by DISALLOW_FILE_EDIT' ) && false === ( $cl_out['stored']['file_edit'] ?? null ) );
$cl_out = $cl_run( array( 'reset', array( 'emoji', 'heartbeat' ) ), array( 'JPKCOM_HARDEN_TRIM_EMOJI' => true ), array( 'emoji' => false, 'heartbeat' => 'disabled' ) );
chk( 'reset skips a locked key with a warning, resets the rest', null === $cl_out['halt'] && false === ( $cl_out['stored']['emoji'] ?? null ) && 'enabled' === ( $cl_out['stored']['heartbeat'] ?? null ) );
$cl_out = $cl_run( array( 'status', array(), array() ), array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' => true, 'JPKCOM_HARDEN_TRIM_HEARTBEAT' => 'sometimes' ) );
$cl_warn = implode( ' ', array_column( array_filter( $cl_out['log'], static fn( array $e ): bool => 'warning' === $e[0] ), 1 ) );
chk( 'status warns about the kill switch and invalid constants', str_contains( $cl_warn, 'JPKCOM_HARDEN_TRIM_DISABLE_ALL is set' ) && str_contains( $cl_warn, 'JPKCOM_HARDEN_TRIM_HEARTBEAT' ) );
$cl_out  = $cl_run( array( 'set', array( 'emoji', 'false' ), array() ), array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' => true ) );
$cl_warn = implode( ' ', array_column( array_filter( $cl_out['log'], static fn( array $e ): bool => 'warning' === $e[0] ), 1 ) );
chk( 'a write under the kill switch is stored, with a warning that nothing acts', false === ( $cl_out['stored']['emoji'] ?? null ) && str_contains( $cl_warn, 'no feature of this plugin acts' ) );
$cl_out  = $cl_run( array( 'get', array( 'file_edit' ), array() ), array( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' => array( 'DISALLOW_FILE_EDIT' => true ) ) );
$cl_warn = implode( ' ', array_column( array_filter( $cl_out['log'], static fn( array $e ): bool => 'warning' === $e[0] ), 1 ) );
chk( 'get a core-locked key: warns that the core constant decides, with its value', str_contains( $cl_warn, 'DISALLOW_FILE_EDIT in wp-config.php decides' ) && str_contains( $cl_warn, 'true' ) );

array_map( 'unlink', glob( $cl_tmp . '/*' ) ?: array() );
@rmdir( $cl_tmp );

summary();
