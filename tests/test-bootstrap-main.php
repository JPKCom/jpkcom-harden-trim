<?php
/**
 * Tests for the main plugin file: constants, bootstrap hooks, header.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

// Defined before the main file is read, as a wp-config.php would.
define( constant_name: 'WP_POST_REVISIONS', value: 7 );

if ( ! function_exists( function: 'plugin_dir_path' ) ) {
	function plugin_dir_path( string $file ): string {
		return trailingslashit( dirname( path: $file ) );
	}
}
if ( ! function_exists( function: 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( string: $value, characters: '/\\' ) . '/';
	}
}
if ( ! function_exists( function: 'plugin_dir_url' ) ) {
	function plugin_dir_url( string $file ): string {
		return 'https://example.test/wp-content/plugins/jpkcom-harden-trim/';
	}
}
if ( ! function_exists( function: 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return 'jpkcom-harden-trim/' . basename( path: $file );
	}
}
if ( ! function_exists( function: 'register_activation_hook' ) ) {
	function register_activation_hook( string $file, $callback ): void {
		$GLOBALS['jht_activation'][] = $callback;
	}
}

$main = $root . '/jpkcom-harden-trim.php';
chk( 'main file exists', is_file( filename: $main ) );

if ( is_file( filename: $main ) ) {
	require_once $main;

	// The header is the source of truth; the constant and the Stable tag follow it.
	$bm_header_version = preg_match( '/^Version:\s*(\d+\.\d+\.\d+)\s*$/m', (string) file_get_contents( filename: $main ), $bm_m ) ? $bm_m[1] : '';
	chk( 'version constant matches the header Version (' . $bm_header_version . ')', '' !== $bm_header_version && defined( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION' ) && $bm_header_version === JPKCOM_HARDEN_TRIM_VERSION );
	chk( 'file constant', defined( constant_name: 'JPKCOM_HARDEN_TRIM_FILE' ) && JPKCOM_HARDEN_TRIM_FILE === $main );
	chk( 'path constant', defined( constant_name: 'JPKCOM_HARDEN_TRIM_PATH' ) && str_ends_with( haystack: JPKCOM_HARDEN_TRIM_PATH, needle: '/' ) );
	chk( 'url constant', defined( constant_name: 'JPKCOM_HARDEN_TRIM_URL' ) );
	chk(
		'core constants snapshot only has what was defined at load',
		defined( constant_name: 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' ) && array( 'WP_POST_REVISIONS' => 7 ) === JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD
	);
	chk( 'plugins_loaded priority 5 registered', isset( $GLOBALS['jht_hooks']['plugins_loaded'][5] ) );
	chk( 'init priority 5 registered (updater)', isset( $GLOBALS['jht_hooks']['init'][5] ) );
	chk( 'init priority 1 registered (textdomain)', isset( $GLOBALS['jht_hooks']['init'][1] ) );
	chk( 'activation hook registered', ! empty( $GLOBALS['jht_activation'] ) );

	// Running the loader without any later-task module must be silent.
	ob_start();
	foreach ( $GLOBALS['jht_hooks']['plugins_loaded'][5] ?? array() as $cb ) {
		$cb();
	}
	chk( 'plugins_loaded loader is silent without later modules', '' === ob_get_clean() );

	// Activation callback: requires the store and adds the defaults, silently, never overwriting.
	$bm_activate = $GLOBALS['jht_activation'][0] ?? null;
	chk( 'activation callback is callable', is_callable( $bm_activate ) );
	unset( $GLOBALS['jht_options']['jpkcom_harden_trim_settings'] );
	ob_start();
	$bm_activate();
	chk( 'activation: silent', '' === ob_get_clean() );
	$bm_option = $GLOBALS['jht_options']['jpkcom_harden_trim_settings'] ?? null;
	chk( 'activation: option added with schema 1 and the defaults', is_array( $bm_option ) && 1 === $bm_option['schema'] && jpkcom_harden_trim_defaults() === $bm_option['settings'] );
	$GLOBALS['jht_options']['jpkcom_harden_trim_settings']['settings']['emoji'] = false;
	$bm_activate();
	chk( 'activation: an existing option is never overwritten', false === $GLOBALS['jht_options']['jpkcom_harden_trim_settings']['settings']['emoji'] );
}

// Module order: features.php (shared helpers) before every features-*.php, whatever glob() sorts.
$run = jht_run_fixture( __DIR__ . '/fixture-load-order.php' );
$out = json_decode( json: $run['out'], associative: true );
chk( 'load order: features.php is loaded before features-*.php (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && 'shared' === ( $out['result'] ?? null ) );
chk( 'load order: every features-*.php still loaded', array( 'zzz' ) === ( $out['zzz'] ?? null ) );

// Kill switch: parsed like every other boolean constant (filter_var), an unparseable value is ignored and named.
foreach ( array(
	'true'    => array( 'killed' => true, 'invalid' => false ),
	'"true"'  => array( 'killed' => true, 'invalid' => false ),
	'"1"'     => array( 'killed' => true, 'invalid' => false ),
	'1'       => array( 'killed' => true, 'invalid' => false ),
	'"on"'    => array( 'killed' => true, 'invalid' => false ),
	'false'   => array( 'killed' => false, 'invalid' => false ),
	'"false"' => array( 'killed' => false, 'invalid' => false ),
	'"off"'   => array( 'killed' => false, 'invalid' => false ),
	'"no"'    => array( 'killed' => false, 'invalid' => false ),
	'"0"'     => array( 'killed' => false, 'invalid' => false ),
	'0'       => array( 'killed' => false, 'invalid' => false ),
	'"maybe"' => array( 'killed' => false, 'invalid' => true ),
	'[1]'     => array( 'killed' => false, 'invalid' => true ),
) as $bm_json => $bm_expect ) {
	$run = jht_run_fixture( __DIR__ . '/fixture-kill-switch.php', array( 'JHT_KILL_JSON' => $bm_json ) );
	$out = json_decode( json: $run['out'], associative: true );
	chk( "kill switch {$bm_json}: " . ( $bm_expect['killed'] ? 'no feature registered' : 'features registered' ) . ' (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) && ! $bm_expect['killed'] === $out['called'] );
	chk( "kill switch {$bm_json}: " . ( $bm_expect['invalid'] ? 'named as invalid' : 'not named as invalid' ), is_array( $out ) && $bm_expect['invalid'] === in_array( needle: 'JPKCOM_HARDEN_TRIM_DISABLE_ALL', haystack: (array) ( $out['invalid'] ?? array() ), strict: true ) );
}

// Kill switch with the real registrar and every feature module (JHT_REAL=1): the site-wide
// switches register nothing either. The unkilled runs prove the probe sees their hooks.
foreach ( array(
	'defaults'          => array( 'env' => array(), 'xmlrpc' => true, 'comments' => false ),
	'comments on'       => array( 'env' => array( 'JHT_COMMENTS' => '1' ), 'xmlrpc' => true, 'comments' => true ),
	'killed, both on'   => array( 'env' => array( 'JHT_COMMENTS' => '1', 'JHT_KILL' => '1' ), 'xmlrpc' => false, 'comments' => false ),
	'killed, defaults'  => array( 'env' => array( 'JHT_KILL' => '1' ), 'xmlrpc' => false, 'comments' => false ),
) as $bm_case => $bm_expect ) {
	$run = jht_run_fixture( __DIR__ . '/fixture-kill-switch.php', array_merge( array( 'JHT_REAL' => '1' ), $bm_expect['env'] ) );
	$out = json_decode( json: $run['out'], associative: true );
	chk( "kill switch, real registrar, {$bm_case}: silent run (" . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
	chk( "kill switch, real registrar, {$bm_case}: XML-RPC hooks " . ( $bm_expect['xmlrpc'] ? 'registered' : 'absent' ), is_array( $out ) && $bm_expect['xmlrpc'] === ( $out['xmlrpc'] ?? null ) );
	chk( "kill switch, real registrar, {$bm_case}: comment hooks " . ( $bm_expect['comments'] ? 'registered' : 'absent' ), is_array( $out ) && $bm_expect['comments'] === ( $out['comments'] ?? null ) );
}

$text = (string) file_get_contents( filename: $main );
chk( 'header Requires at least 7.1', str_contains( haystack: $text, needle: 'Requires at least: 7.1' ) );
chk( 'header Requires PHP 8.3', str_contains( haystack: $text, needle: 'Requires PHP: 8.3' ) );
chk( 'header Tested up to 7.1', str_contains( haystack: $text, needle: 'Tested up to: 7.1' ) );
chk( 'header Stable tag matches Version', 1 === preg_match( '/^Version:\s*(\S+)\s*$/m', $text, $bm_v ) && str_contains( haystack: $text, needle: 'Stable tag: ' . $bm_v[1] . "\n" ) );
chk( 'header has no Network line', ! str_contains( haystack: $text, needle: 'Network:' ) );
// Update URI: read the header exactly as core's get_file_data() does (wp-includes/functions.php).
$jht_update_uri = preg_match( '/^(?:[ \t]*<?php)?[ \t\/*#@]*' . preg_quote( 'Update URI', '/' ) . ':(.*)$/mi', $text, $jht_m ) ? trim( string: $jht_m[1] ) : '';
chk( 'header Update URI points at the GitHub repository', 'https://github.com/JPKCom/jpkcom-harden-trim' === $jht_update_uri );
// Core (update.php, wp_update_plugins) derives update_plugins_{host} from it; a w.org host would hand the slug back to wordpress.org.
$jht_update_host = (string) parse_url( url: $jht_update_uri, component: PHP_URL_HOST );
chk( 'Update URI host is not wordpress.org / w.org', '' !== $jht_update_host && ! in_array( needle: $jht_update_host, haystack: array( 'wordpress.org', 'w.org', 'downloads.wordpress.org' ), strict: true ) );
// The family updater injects through site_transient_update_plugins and never reads the header, so the header cannot switch it off.
$jht_updater_src = (string) file_get_contents( filename: $root . '/includes/class-plugin-updater.php' );
chk( 'updater injects via site_transient_update_plugins', str_contains( haystack: $jht_updater_src, needle: "add_filter( 'site_transient_update_plugins', [\$this, 'check_update'] );" ) );
chk( 'updater does not depend on the Update URI header', ! str_contains( haystack: $jht_updater_src, needle: 'UpdateURI' ) && ! str_contains( haystack: $jht_updater_src, needle: 'update_plugins_' ) );

// Unregistered hooks: no-op, value returned, no null entry left behind.
$jht_threw = false;
try {
	do_action( 'jht_nothing_registered' );
	$jht_val = apply_filters( 'jht_nothing_registered_filter', 5 );
} catch ( Throwable $e ) {
	$jht_threw = true;
	$jht_val   = null;
}
chk( 'do_action/apply_filters on unregistered hook do not throw', ! $jht_threw );
chk( 'apply_filters on unregistered hook returns the value', 5 === $jht_val );
chk( 'unregistered hook leaves no entry in jht_hooks', ! array_key_exists( key: 'jht_nothing_registered', array: $GLOBALS['jht_hooks'] ) && ! array_key_exists( key: 'jht_nothing_registered_filter', array: $GLOBALS['jht_hooks'] ) );

// Release metadata: README and phpdoc.xml follow the header version, and the changelog block for it leads with the XML-RPC consequence.
$jht_ver    = $bm_v[1] ?? '';
$jht_readme = (string) file_get_contents( filename: $root . '/README.md' );
$jht_phpdoc = (string) file_get_contents( filename: $root . '/phpdoc.xml' );
chk( 'README **Version:** matches the header', str_contains( haystack: $jht_readme, needle: '**Version:** ' . $jht_ver . "  \n" ) );
chk( 'README **Stable tag:** matches the header', str_contains( haystack: $jht_readme, needle: '**Stable tag:** ' . $jht_ver . "  \n" ) );
chk( 'phpdoc.xml version matches the header', str_contains( haystack: $jht_phpdoc, needle: '<version number="' . $jht_ver . '">' ) );
$jht_block = preg_match( '/^### ' . preg_quote( $jht_ver, '/' ) . '\R(.*?)(?:^### |\z)/ms', $jht_readme, $jht_cl ) ? trim( string: $jht_cl[1] ) : '';
$jht_first = strtok( $jht_block, "\n" );
chk( 'changelog has a block for the header version', '' !== $jht_block );
chk( 'changelog first entry states that XML-RPC is blocked after the update', false !== $jht_first && str_contains( haystack: $jht_first, needle: 'XML-RPC' ) && str_contains( haystack: $jht_first, needle: "define( 'JPKCOM_HARDEN_TRIM_XMLRPC', false );" ) );

summary();
