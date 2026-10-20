<?php
/**
 * Router script for the built-in web server (`php -S`), used by
 * test-features-sitewide.php to check the real response of the XML-RPC init
 * guard: status, Content-Type, no-cache headers and the body up to `exit`
 * (the CLI SAPI keeps no header list, and the guard ends the process).
 *
 * Emulates `xmlrpc.php`, which defines XMLRPC_REQUEST before wp-load.php
 * (7.1.3 xmlrpc.php:13), and then `init` priority 0. Query arguments:
 * - xmlrpc=1|0     define XMLRPC_REQUEST (default 0)
 * - charset=...    the blog_charset option (default UTF-8)
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.1.0
 */

declare(strict_types=1);

if ( '1' === ( $_GET['xmlrpc'] ?? '0' ) ) {
	define( 'XMLRPC_REQUEST', true );
}

require_once __DIR__ . '/bootstrap.php';
require_once dirname( path: __DIR__ ) . '/includes/features-sitewide.php';

// Both guard on headers_sent() like core (7.1.3 functions.php:1489, :1544).
function status_header( int $code, string $description = '' ): void {
	if ( ! headers_sent() ) {
		http_response_code( $code );
	}
}

function nocache_headers(): void {
	if ( ! headers_sent() ) {
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private' );
	}
}

$GLOBALS['jht_options']['blog_charset'] = (string) ( $_GET['charset'] ?? 'UTF-8' );

// init priority 0.
jpkcom_harden_trim_xmlrpc_refuse();

echo 'continued';
