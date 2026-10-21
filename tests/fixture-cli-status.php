<?php
/**
 * Fixture for the per-process scenarios of test-status.php, test-cli.php and
 * test-abilities.php: constants cannot be undefined once set.
 *
 * JHT_DEFINE  JSON object constant name => value, defined before the modules
 *             load (JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD stands for the
 *             core constants wp-config.php defined before the plugin).
 * JHT_ACTION  status    print jpkcom_harden_trim_status() as JSON;
 *             cli       run the subcommand in JHT_CLI (JSON [name, args, assoc])
 *                       and print the halt message, the CLI log and the stored
 *                       settings as JSON;
 *             abilities run both Abilities API hooks and print what was
 *                       registered.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';
require_once __DIR__ . '/stubs-cli.php';

$jht_defines = json_decode( json: (string) getenv( 'JHT_DEFINE' ), associative: true );

foreach ( is_array( $jht_defines ) ? $jht_defines : array() as $jht_name => $jht_value ) {
	if ( 'JHT_FILTER_HEARTBEAT' === $jht_name ) {
		// Not a constant: a jpkcom_harden_trim_settings callback setting heartbeat.
		$GLOBALS['htfix_filter_heartbeat'] = $jht_value;
		continue;
	}
	define( $jht_name, $jht_value );
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION', value: '1.2.0' );
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES', value: 65536 );
}

foreach ( array( 'settings-schema', 'settings-store', 'status', 'import-export', 'cli', 'abilities' ) as $jht_module ) {
	require_once dirname( path: __DIR__ ) . '/includes/' . $jht_module . '.php';
}

jht_reset_admin();

$jht_stored = json_decode( json: (string) getenv( 'JHT_STORED' ), associative: true );

if ( is_array( $jht_stored ) ) {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $jht_stored ),
	);
}

if ( isset( $GLOBALS['htfix_filter_heartbeat'] ) ) {
	$jht_filter_value = $GLOBALS['htfix_filter_heartbeat'];
	add_filter( 'jpkcom_harden_trim_settings', static fn( array $s ): array => array_merge( $s, array( 'heartbeat' => $jht_filter_value ) ) );
}

$jht_action = (string) getenv( 'JHT_ACTION' );

if ( 'status' === $jht_action ) {
	echo json_encode( jpkcom_harden_trim_status() );
	exit( 0 );
}

if ( 'cli' === $jht_action ) {
	$jht_call = json_decode( json: (string) getenv( 'JHT_CLI' ), associative: true );
	$jht_halt = jht_cli( (string) $jht_call[0], (array) ( $jht_call[1] ?? array() ), (array) ( $jht_call[2] ?? array() ) );
	echo json_encode(
		array(
			'halt'   => $jht_halt,
			'log'    => WP_CLI::$log,
			'stored' => jpkcom_harden_trim_get_stored(),
		)
	);
	exit( 0 );
}

if ( 'abilities' === $jht_action ) {
	$GLOBALS['jht_abilities']          = array();
	$GLOBALS['jht_ability_categories'] = array();
	jpkcom_harden_trim_register_ability_category();
	jpkcom_harden_trim_register_abilities();
	echo json_encode(
		array(
			'enabled'    => jpkcom_harden_trim_abilities_enabled(),
			'abilities'  => array_keys( $GLOBALS['jht_abilities'] ),
			'categories' => array_keys( $GLOBALS['jht_ability_categories'] ),
		)
	);
	exit( 0 );
}

echo 'unknown JHT_ACTION';
exit( 1 );
