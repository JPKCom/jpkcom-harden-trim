<?php
/**
 * Router script for the built-in web server (`php -S`), used by
 * test-features-privacy.php to check the real response headers of a forced
 * 404 (the CLI SAPI keeps no header list, so headers_list() cannot show them).
 *
 * Emulates core's order for `?author=N&feed=rss2`: WP::send_headers() (7.1.3
 * class-wp.php:471-476) has already sent the feed Content-Type when
 * template_redirect runs, then jpkcom_harden_trim_block_author_enum() forces
 * the 404 and the theme's HTML 404 template is printed. Query arguments:
 * - feed=1|0       the request is a feed (default 1)
 * - charset=...    the blog_charset option (default UTF-8)
 * - early=1        print output before template_redirect, so headers are sent
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-query.php';

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/features.php', 'includes/features-privacy.php' ) as $jht_module ) {
	require_once dirname( path: __DIR__ ) . '/' . $jht_module;
}

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

$fx_feed    = '0' !== ( $_GET['feed'] ?? '1' );
$fx_charset = (string) ( $_GET['charset'] ?? 'UTF-8' );

jht_reset_wp();
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['jht_options']['html_type']           = 'text/html';
$GLOBALS['jht_options']['blog_charset']        = $fx_charset;
$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
	'schema'   => 1,
	'updated'  => '',
	'settings' => jpkcom_harden_trim_defaults(),
);
jpkcom_harden_trim_settings_reset_cache();

$GLOBALS['wp']       = (object) array( 'query_vars' => $fx_feed ? array( 'author' => '1', 'feed' => 'rss2' ) : array( 'author' => '1' ) );
$GLOBALS['wp_query'] = jht_query_with_posts( array( 'is_author' => true, 'is_archive' => true, 'is_feed' => $fx_feed ) );

// WP::send_headers().
header( 'Content-Type: ' . ( $fx_feed ? 'application/rss+xml' : 'text/html' ) . '; charset=' . $fx_charset );

if ( '1' === ( $_GET['early'] ?? '0' ) ) {
	echo "early output\n";
	flush();
}

// template_redirect.
jpkcom_harden_trim_block_author_enum();

echo '<!DOCTYPE html><html><body>404 is_404=' . ( $GLOBALS['wp_query']->is_404 ? '1' : '0' ) . '</body></html>';
