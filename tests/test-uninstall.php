<?php
/**
 * Tests for uninstall.php: nothing happens without WP_UNINSTALL_PLUGIN; the
 * option and leftover notice transients go on a single site and on every site
 * of a network.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

chk( 'uninstall.php exists', is_file( filename: $root . '/uninstall.php' ) );

if ( ! is_file( filename: $root . '/uninstall.php' ) ) {
	summary();
}

/**
 * Run one uninstall scenario.
 *
 * @param string $mode direct, single or multisite.
 * @return array{exit: int, log: ?array, out: string}
 */
function jht_uninstall( string $mode ): array {
	$run = jht_run_fixture( __DIR__ . '/fixture-uninstall.php', array( 'JHT_MODE' => $mode ) );
	$log = json_decode( $run['out'], true );
	return array( 'exit' => $run['exit'], 'log' => is_array( $log ) ? $log : null, 'out' => $run['out'] );
}

/**
 * Entries of one kind from a log.
 *
 * @param array  $log  Log.
 * @param string $kind First element of an entry.
 * @return list<array>
 */
function jht_entries( array $log, string $kind ): array {
	return array_values( array: array_filter( array: $log, callback: static fn( array $e ): bool => $kind === $e[0] ) );
}

// Without WP_UNINSTALL_PLUGIN.

$run = jht_uninstall( 'direct' );
chk( 'direct: clean output (' . substr( string: $run['out'], offset: 0, length: 300 ) . ')', is_array( $run['log'] ) );
chk( 'direct: nothing deleted, nothing queried', array() === $run['log'] );

// Single site.

$run = jht_uninstall( 'single' );
$log = $run['log'] ?? array();
chk( 'single: exits 0, clean output (' . substr( string: $run['out'], offset: 0, length: 300 ) . ')', 0 === $run['exit'] && is_array( $run['log'] ) );
chk( 'single: option deleted', array( array( 'delete_option', 1, 'jpkcom_harden_trim_settings' ) ) === jht_entries( $log, 'delete_option' ) );
$jht_q = jht_entries( $log, 'get_col' );
chk( 'single: one query for leftover notice transients', 1 === count( $jht_q ) );
chk( 'single: LIKE pattern escapes the underscores', isset( $jht_q[0][2] ) && str_contains( haystack: $jht_q[0][2], needle: "wp_options WHERE option_name LIKE '\\_transient\\_jpkcom\\_harden\\_trim\\_notice\\_%'" ) );
// The family updater caches under jpk_git_update_<md5 of the plugin directory> (+ _fail, _lock).
$jht_upd = 'jpk_git_update_' . md5( string: basename( path: $root ) );
$jht_notice_only = static fn( array $entries ): array => array_values( array: array_filter( array: $entries, callback: static fn( array $e ): bool => str_starts_with( haystack: (string) $e[2], needle: 'jpkcom_harden_trim_notice_' ) ) );
$jht_updater_only = static fn( array $entries ): array => array_values( array: array_filter( array: $entries, callback: static fn( array $e ): bool => str_starts_with( haystack: (string) $e[2], needle: 'jpk_git_update_' ) ) );
chk( 'single: leftover transient deleted through delete_transient', array( array( 'delete_transient', 1, 'jpkcom_harden_trim_notice_1' ) ) === $jht_notice_only( jht_entries( $log, 'delete_transient' ) ) );
chk( 'single: updater cache transients deleted', array( array( 'delete_transient', 1, $jht_upd ), array( 'delete_transient', 1, $jht_upd . '_fail' ), array( 'delete_transient', 1, $jht_upd . '_lock' ) ) === $jht_updater_only( jht_entries( $log, 'delete_transient' ) ) );
chk( 'single: no site switching', array() === jht_entries( $log, 'switch_to_blog' ) && array() === jht_entries( $log, 'get_sites' ) );

// Multisite.

$run = jht_uninstall( 'multisite' );
$log = $run['log'] ?? array();
chk( 'multisite: exits 0, clean output (' . substr( string: $run['out'], offset: 0, length: 300 ) . ')', 0 === $run['exit'] && is_array( $run['log'] ) );
chk( 'multisite: every site queried, without a limit', array( array( 'get_sites', array( 'fields' => 'ids', 'number' => 0 ) ) ) === jht_entries( $log, 'get_sites' ) );
chk( 'multisite: switches to every site', array( array( 'switch_to_blog', 1 ), array( 'switch_to_blog', 2 ), array( 'switch_to_blog', 3 ) ) === jht_entries( $log, 'switch_to_blog' ) );
chk( 'multisite: restores after every switch', 3 === count( jht_entries( $log, 'restore_current_blog' ) ) );
chk(
	'multisite: option deleted on every site',
	array(
		array( 'delete_option', 1, 'jpkcom_harden_trim_settings' ),
		array( 'delete_option', 2, 'jpkcom_harden_trim_settings' ),
		array( 'delete_option', 3, 'jpkcom_harden_trim_settings' ),
	) === jht_entries( $log, 'delete_option' )
);
chk(
	'multisite: leftover transients deleted on every site',
	array(
		array( 'delete_transient', 1, 'jpkcom_harden_trim_notice_1' ),
		array( 'delete_transient', 2, 'jpkcom_harden_trim_notice_2' ),
		array( 'delete_transient', 3, 'jpkcom_harden_trim_notice_3' ),
	) === $jht_notice_only( jht_entries( $log, 'delete_transient' ) )
);
chk( 'multisite: updater cache transients deleted on every site', 9 === count( $jht_updater_only( jht_entries( $log, 'delete_transient' ) ) ) && array( 1, 1, 1, 2, 2, 2, 3, 3, 3 ) === array_map( callback: static fn( array $e ): int => (int) $e[1], array: $jht_updater_only( jht_entries( $log, 'delete_transient' ) ) ) );
$jht_order = array_map( callback: static fn( array $e ): string => $e[0], array: array_values( array: array_filter( array: $log, callback: static fn( array $e ): bool => in_array( needle: $e[0], haystack: array( 'switch_to_blog', 'delete_option', 'restore_current_blog' ), strict: true ) ) ) );
chk( 'multisite: each delete happens between switch and restore', array( 'switch_to_blog', 'delete_option', 'restore_current_blog', 'switch_to_blog', 'delete_option', 'restore_current_blog', 'switch_to_blog', 'delete_option', 'restore_current_blog' ) === $jht_order );

// Source guards.

$jht_src = (string) file_get_contents( filename: $root . '/uninstall.php' );
chk( 'source: strict types', str_contains( haystack: $jht_src, needle: 'declare(strict_types=1);' ) );
chk( 'source: WP_UNINSTALL_PLUGIN guard before any work', 1 === preg_match( '/^<\?php.*?if \( ! defined\( constant_name: \'WP_UNINSTALL_PLUGIN\' \) \) \{\s*exit;\s*\}/s', $jht_src ) && strpos( haystack: $jht_src, needle: 'WP_UNINSTALL_PLUGIN' ) < strpos( haystack: $jht_src, needle: 'delete_option' ) );

summary();
