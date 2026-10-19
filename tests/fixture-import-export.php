<?php
/**
 * Fixture for the per-process scenarios in test-import-export.php.
 *
 * JHT_MODE selects it:
 * - locked: JPKCOM_HARDEN_TRIM_EMOJI and a core lock on file_edit
 *           (DISALLOW_FILE_EDIT defined before the plugin loaded); imports
 *           emoji=false and file_edit=true and prints the stored and effective
 *           settings, the notice transient and the rendered notice as JSON.
 * - export: runs the export handler, which prints the download and exits;
 *           a shutdown line reports whether nocache_headers() ran.
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

$fixture_mode = (string) getenv( 'JHT_MODE' );

if ( 'locked' === $fixture_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_EMOJI', true );
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'DISALLOW_FILE_EDIT' => true ) );
} elseif ( 'small' === $fixture_mode ) {
	// wp-config.php may lower the import limit; the texts must follow it.
	define( 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES', 2048 );
} elseif ( 'export' !== $fixture_mode ) {
	echo 'unknown JHT_MODE';
	exit( 1 );
}

define( 'JPKCOM_HARDEN_TRIM_FILE', dirname( path: __DIR__ ) . '/jpkcom-harden-trim.php' );
define( 'JPKCOM_HARDEN_TRIM_VERSION', '1.0.0' );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/admin-page.php', 'includes/import-export.php' ) as $jht_module ) {
	require_once dirname( path: __DIR__ ) . '/' . $jht_module;
}

jht_reset_admin();

if ( 'export' === $fixture_mode ) {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), array( 'emoji' => false ) ),
	);
	$_POST    = array( 'jpkcom_harden_trim_export_nonce' => 'nonce-jpkcom_harden_trim_export' );
	$_REQUEST = $_POST;

	register_shutdown_function(
		static function (): void {
			echo "\n--jht-nocache:" . ( empty( $GLOBALS['jht_nocache'] ) ? '0' : '1' );
		}
	);

	jpkcom_harden_trim_handle_export();
	echo "\n--jht-not-exited";
	exit( 1 );
}

if ( 'small' === $fixture_mode ) {
	ob_start();
	jpkcom_harden_trim_render_import_export();
	echo json_encode(
		array(
			'message' => jpkcom_harden_trim_import_error_message( 'too_large' ),
			'html'    => (string) ob_get_clean(),
			'parse'   => jpkcom_harden_trim_parse_import( str_repeat( string: ' ', times: 3000 ) ) instanceof WP_Error,
		)
	);
	exit( 0 );
}

jpkcom_harden_trim_activate();

$_POST    = array(
	'jpkcom_harden_trim_import_nonce' => 'nonce-jpkcom_harden_trim_import',
	'jpkcom_harden_trim_json'         => '{"plugin":"jpkcom-harden-trim","schema":1,"settings":{"emoji":false,"file_edit":true,"dashicons":true}}',
);
$_REQUEST = $_POST;

try {
	jpkcom_harden_trim_handle_import();
} catch ( JhtRedirect $e ) {
	// Expected: the handler redirects back to the settings page.
}

$jht_stored = jpkcom_harden_trim_get_stored();
$jht_notice = get_transient( jpkcom_harden_trim_notice_transient() );

jpkcom_harden_trim_settings_reset_cache();
$jht_effective = jpkcom_harden_trim_settings();

$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'imported' );
ob_start();
jpkcom_harden_trim_render_notices();
$jht_html = (string) ob_get_clean();

jpkcom_harden_trim_save_settings( array_merge( $jht_stored, array( 'emoji' => true ) ) );

echo json_encode(
	array(
		'stored'     => $jht_stored,
		'notice'     => $jht_notice,
		'effective'  => $jht_effective,
		'html'       => $jht_html,
		'after_save' => jpkcom_harden_trim_get_stored(),
	)
);
exit( 0 );
