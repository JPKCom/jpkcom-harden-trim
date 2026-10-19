<?php
/**
 * Fixture for test-uninstall.php: loads uninstall.php in its own process.
 *
 * WP_UNINSTALL_PLUGIN cannot be undefined again, and uninstall.php exits when
 * it is missing, so every scenario runs here and a shutdown function prints
 * what was deleted as JSON. JHT_MODE selects it:
 * - direct:    WP_UNINSTALL_PLUGIN is not defined (file requested directly).
 * - single:    single site uninstall.
 * - multisite: three sites.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

function delete_option( string $option ): bool {
	$GLOBALS['jht_log'][] = array( 'delete_option', $GLOBALS['jht_ms']['blog'], $option );
	return true;
}

function delete_transient( string $transient ): bool {
	$GLOBALS['jht_log'][] = array( 'delete_transient', $GLOBALS['jht_ms']['blog'], $transient );
	return true;
}

function delete_site_transient( string $transient ): bool {
	$GLOBALS['jht_log'][] = array( 'delete_site_transient', $GLOBALS['jht_ms']['blog'], $transient );
	return true;
}


function get_sites( $args = array() ): array {
	$GLOBALS['jht_log'][] = array( 'get_sites', $args );
	return array( 1, '2', 3 );
}

function switch_to_blog( $new_blog_id, $deprecated = null ): bool {
	$GLOBALS['jht_ms']['stack'][] = $GLOBALS['jht_ms']['blog'];
	$GLOBALS['jht_ms']['blog']    = $new_blog_id;
	$GLOBALS['jht_log'][]         = array( 'switch_to_blog', $new_blog_id );
	return true;
}

function restore_current_blog(): bool {
	$GLOBALS['jht_ms']['blog'] = array_pop( array: $GLOBALS['jht_ms']['stack'] ) ?? 1;
	$GLOBALS['jht_log'][]      = array( 'restore_current_blog' );
	return true;
}

/**
 * Minimal $wpdb: records the query, answers with one leftover notice transient per site.
 */
class JhtWpdb {
	public string $options = 'wp_options';

	public function esc_like( string $text ): string {
		return addcslashes( string: $text, characters: '_%\\' );
	}

	public function prepare( string $query, ...$args ): string {
		return vsprintf( str_replace( search: '%s', replace: "'%s'", subject: $query ), $args );
	}

	public function get_col( string $query ): array {
		$GLOBALS['jht_log'][] = array( 'get_col', $GLOBALS['jht_ms']['blog'], $query );
		return array( '_transient_jpkcom_harden_trim_notice_' . $GLOBALS['jht_ms']['blog'] );
	}
}

$GLOBALS['wpdb'] = new JhtWpdb();

require_once __DIR__ . '/bootstrap.php';

// is_multisite() comes from bootstrap.php and reads this state.
$GLOBALS['jht_state']['multisite'] = 'multisite' === getenv( 'JHT_MODE' );

// After the bootstrap: its reset clears every jht_* global.
$GLOBALS['jht_log'] = array();
$GLOBALS['jht_ms']  = array( 'blog' => 1, 'stack' => array() );

$jht_mode = (string) getenv( 'JHT_MODE' );

if ( 'direct' !== $jht_mode ) {
	define( 'WP_UNINSTALL_PLUGIN', 'jpkcom-harden-trim/jpkcom-harden-trim.php' );
}

register_shutdown_function(
	static function (): void {
		echo json_encode( $GLOBALS['jht_log'] );
	}
);

// Core includes uninstall.php from inside a function, so its top-level variables are not globals.
( static function (): void {
	include dirname( path: __DIR__ ) . '/uninstall.php';
} )();
