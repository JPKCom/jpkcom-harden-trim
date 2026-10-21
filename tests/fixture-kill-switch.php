<?php
/**
 * Fixture for the kill-switch test in test-settings-store.php.
 *
 * Loads the real main file, fires its recorded plugins_loaded callbacks and
 * prints whether jpkcom_harden_trim_register_features() was called and how many
 * hook callbacks the loader added. JHT_KILL=1 defines
 * JPKCOM_HARDEN_TRIM_DISABLE_ALL as true; JHT_KILL=0 is the baseline that
 * proves the probe sees a call at all (otherwise the kill-switch case would
 * pass while proving nothing). JHT_KILL_JSON defines the constant with any
 * JSON-decoded value instead.
 *
 * The stub of jpkcom_harden_trim_register_features() is defined before the
 * main file is read; the real one in features.php is function_exists-guarded
 * and therefore yields to it. JHT_REAL=1 skips the stub, so the real
 * registrar and every feature module run; the output then says whether the
 * XML-RPC and comment hooks were registered. JHT_COMMENTS=1 defines
 * JPKCOM_HARDEN_TRIM_COMMENTS as true (the switch is off by default).
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if ( '1' === getenv( 'JHT_KILL' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', true );
} elseif ( false !== getenv( 'JHT_KILL_JSON' ) ) {
	// Any value a wp-config.php could hold, as JSON: "false", "off", 0, true, ...
	define( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', json_decode( (string) getenv( 'JHT_KILL_JSON' ), true ) );
}

if ( '1' === getenv( 'JHT_COMMENTS' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_COMMENTS', true );
}

$GLOBALS['jht_register_called'] = false;

if ( '1' !== getenv( 'JHT_REAL' ) ) {
	function jpkcom_harden_trim_register_features( array $settings ): void {
		$GLOBALS['jht_register_called'] = true;
		add_action( 'wp_head', 'jht_probe_feature_hook' );
	}
}

function plugin_dir_path( string $file ): string {
	return rtrim( string: dirname( path: $file ), characters: '/\\' ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/jpkcom-harden-trim/';
}

function plugin_basename( string $file ): string {
	return 'jpkcom-harden-trim/' . basename( path: $file );
}

function register_activation_hook( string $file, $callback ): void {
}

/**
 * Count every recorded feature hook callback.
 *
 * The two Abilities API registration hooks are left out: abilities.php adds
 * them on purpose whatever the kill switch says, because the ability is how
 * to see that the kill switch is on. They are reported as `abilities`.
 *
 * @return int
 */
function jht_count_hooks(): int {
	$count = 0;
	foreach ( $GLOBALS['jht_hooks'] as $hook => $priorities ) {
		if ( in_array( needle: $hook, haystack: array( 'wp_abilities_api_categories_init', 'wp_abilities_api_init' ), strict: true ) ) {
			continue;
		}
		foreach ( $priorities as $list ) {
			$count += count( $list );
		}
	}
	return $count;
}

require_once dirname( path: __DIR__ ) . '/jpkcom-harden-trim.php';

$jht_loader = $GLOBALS['jht_hooks']['plugins_loaded'] ?? array();
$jht_before = jht_count_hooks();

ksort( array: $jht_loader );
foreach ( $jht_loader as $jht_list ) {
	foreach ( $jht_list as $jht_callback ) {
		$jht_callback();
	}
}

echo json_encode(
	array(
		'called'       => $GLOBALS['jht_register_called'],
		'new_hooks'    => jht_count_hooks() - $jht_before,
		'store_loaded' => function_exists( 'jpkcom_harden_trim_settings' ),
		'abilities'    => array() !== ( $GLOBALS['jht_hooks']['wp_abilities_api_init'] ?? array() ) && array() !== ( $GLOBALS['jht_hooks']['wp_abilities_api_categories_init'] ?? array() ),
		'invalid'      => function_exists( 'jpkcom_harden_trim_invalid_constants' ) ? jpkcom_harden_trim_invalid_constants() : null,
		'xmlrpc'       => array() !== ( $GLOBALS['jht_hooks']['xmlrpc_enabled'] ?? array() ) || array() !== ( $GLOBALS['jht_hooks']['wp_xmlrpc_server_class'] ?? array() ),
		'comments'     => array() !== ( $GLOBALS['jht_hooks']['comments_open'] ?? array() ) || array() !== ( $GLOBALS['jht_hooks']['get_comments_number'] ?? array() ),
	)
);
exit( 0 );
