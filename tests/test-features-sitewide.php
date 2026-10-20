<?php
/**
 * Tests for the site-wide switches `xmlrpc` and `comments`
 * (includes/features-sitewide.php).
 *
 * Both switches mirror a standalone plugin: JPKCom Disable XML-RPC 1.0.10 and
 * JPKCom Disable Comments 1.0.10. Every applicable case of their
 * tests/test-hooks.php is ported here; each ported case names its source
 * ("xmlrpc:" or "comments:" followed by the original case name). On top of
 * them: hook priorities and accepted args of every registration, the
 * registration matrix (defaults, each switch alone, both off), the English
 * fault literal against a translating gettext stub, the last-resort and
 * comment-feed wp_die() calls, the screen redirect, and the real response of
 * the init guard under the built-in web server (fixture-xmlrpc-refuse.php).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.1.0
 */

declare(strict_types=1);

// Recorder that also keeps accepted_args (bootstrap.php's recorder drops it).
// Defined before bootstrap.php, whose stubs are function_exists()-guarded.

if ( ! function_exists( function: 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['jht_hooks'][ $hook_name ][ $priority ][] = $callback;
		$GLOBALS['jht_args'][ $hook_name ][ $priority ][]  = $accepted_args;
		return true;
	}
}

if ( ! function_exists( function: 'add_action' ) ) {
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		return add_filter( $hook_name, $callback, $priority, $accepted_args );
	}
}

// Gettext stubs that can translate: with $GLOBALS['jht_translate'] set, every
// string of this plugin's text domain comes back prefixed with "DE:".

if ( ! function_exists( function: '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return ! empty( $GLOBALS['jht_translate'] ) && 'jpkcom-harden-trim' === $domain ? 'DE:' . $text : $text;
	}
}

if ( ! function_exists( function: 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( string: __( $text, $domain ), flags: ENT_QUOTES );
	}
}

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/features.php', 'includes/features-sitewide.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

// Stubs this suite needs on top of bootstrap.php (shapes as in the standalone suites).

if ( ! function_exists( function: '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( function: '__return_empty_array' ) ) {
	function __return_empty_array(): array {
		return array();
	}
}

if ( ! function_exists( function: 'get_post_types' ) ) {
	function get_post_types(): array {
		return array_keys( $GLOBALS['jht_supports'] ?? array() );
	}
}

if ( ! function_exists( function: 'post_type_supports' ) ) {
	function post_type_supports( string $post_type, string $feature ): bool {
		return ! empty( $GLOBALS['jht_supports'][ $post_type ][ $feature ] );
	}
}

if ( ! function_exists( function: 'remove_post_type_support' ) ) {
	function remove_post_type_support( string $post_type, string $feature ): void {
		unset( $GLOBALS['jht_supports'][ $post_type ][ $feature ] );
	}
}

if ( ! function_exists( function: 'status_header' ) ) {
	function status_header( int $code, string $description = '' ): void {
		$GLOBALS['jht_status'][] = $code;
	}
}

if ( ! function_exists( function: 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['jht_nocache'] = true;
	}
}

if ( ! function_exists( function: 'is_comment_feed' ) ) {
	function is_comment_feed(): bool {
		return (bool) ( $GLOBALS['jht_state']['is_comment_feed'] ?? false );
	}
}

if ( ! function_exists( function: 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}

if ( ! function_exists( function: 'remove_menu_page' ) ) {
	function remove_menu_page( string $menu_slug ) {
		$GLOBALS['jht_menu_removed'][] = $menu_slug;
		return false;
	}
}

/** Thrown by the wp_die() stub, so a `never` callback can be observed. */
class JhtSitewideDie extends Exception {
	/** @var mixed */
	public mixed $die_message;
	/** @var mixed */
	public mixed $die_title;
	/** @var mixed */
	public mixed $die_args;

	public function __construct( mixed $message, mixed $title, mixed $args ) {
		parent::__construct( 'wp_die' );
		$this->die_message = $message;
		$this->die_title   = $title;
		$this->die_args    = $args;
	}
}

/** Thrown by the wp_safe_redirect() stub, so the `exit` after it is never reached. */
class JhtSitewideRedirect extends Exception {
	public string $location;

	public function __construct( string $location ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
	}
}

if ( ! function_exists( function: 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {
		throw new JhtSitewideDie( $message, $title, $args );
	}
}

if ( ! function_exists( function: 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302, $x_redirect_by = 'WordPress' ): bool {
		throw new JhtSitewideRedirect( $location );
	}
}

if ( ! class_exists( class: 'WP_Comment_Query' ) ) {
	class WP_Comment_Query {
		/** @var array<string,mixed> */
		public array $query_vars = array();
	}
}

if ( ! class_exists( class: 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		private mixed $data;

		public function __construct( mixed $data = null ) {
			$this->data = $data;
		}

		public function get_data(): mixed {
			return $this->data;
		}

		public function set_data( mixed $data ): void {
			$this->data = $data;
		}
	}
}

if ( ! class_exists( class: 'WP_Admin_Bar' ) ) {
	class WP_Admin_Bar {
		/** @var string[] */
		public array $removed = array();

		public function remove_node( string $id ): void {
			$this->removed[] = $id;
		}
	}
}

$jht_api = array(
	'jpkcom_harden_trim_register_sitewide_features',
	'jpkcom_harden_trim_is_xmlrpc_request',
	'jpkcom_harden_trim_xmlrpc_fault_xml',
	'jpkcom_harden_trim_xmlrpc_refuse',
	'jpkcom_harden_trim_xmlrpc_server_class',
	'jpkcom_harden_trim_comments_close_rest_status',
	'jpkcom_harden_trim_comments_remove_support',
);

$jht_missing = array_values( array: array_filter( array: $jht_api, callback: static fn( string $fn ): bool => ! function_exists( $fn ) ) );
chk( 'sitewide API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

/**
 * Reset the stubs, store settings over the defaults and run the sitewide registrar.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @return void
 */
function jht_sw_register( array $settings = array() ): void {
	jht_reset_wp();
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
	jpkcom_harden_trim_register_sitewide_features( jpkcom_harden_trim_settings() );
}

/**
 * Every registration on a hook: callback, priority and accepted args.
 *
 * @param string $hook Hook name.
 * @return list<array{0: mixed, 1: int, 2: int}>
 */
function jht_sw_entries( string $hook ): array {
	$out   = array();
	$hooks = $GLOBALS['jht_hooks'][ $hook ] ?? array();
	ksort( array: $hooks );
	foreach ( $hooks as $priority => $list ) {
		foreach ( array_values( array: $list ) as $i => $callback ) {
			$out[] = array( $callback, (int) $priority, (int) ( $GLOBALS['jht_args'][ $hook ][ $priority ][ $i ] ?? 1 ) );
		}
	}
	return $out;
}

/**
 * Whether every registration on a hook uses the given priority and accepted args.
 *
 * @param string $hook     Hook name.
 * @param int    $priority Expected priority.
 * @param int    $args     Expected accepted args.
 * @return bool False when nothing is registered.
 */
function jht_sw_is( string $hook, int $priority, int $args ): bool {
	$entries = jht_sw_entries( $hook );
	if ( array() === $entries ) {
		return false;
	}
	foreach ( $entries as $entry ) {
		if ( $priority !== $entry[1] || $args !== $entry[2] ) {
			return false;
		}
	}
	return true;
}

/**
 * The first callback registered on a hook.
 *
 * @param string $hook Hook name.
 * @return callable|null
 */
function jht_sw_cb( string $hook ): ?callable {
	$entries = jht_sw_entries( $hook );
	return isset( $entries[0] ) && is_callable( $entries[0][0] ) ? $entries[0][0] : null;
}

$sw_xmlrpc_hooks   = array( 'init', 'xmlrpc_enabled', 'xmlrpc_methods', 'wp_xmlrpc_server_class' );
$sw_comments_hooks = array( 'rest_api_init', 'wp_loaded', 'admin_init', 'comments_open', 'comments_array', 'wp_count_comments', 'comments_pre_query', 'feed_links_show_comments_feed', 'template_redirect', 'admin_menu', 'admin_bar_menu', 'rest_endpoints' );

// Registration matrix.

jht_sw_register();
foreach ( $sw_xmlrpc_hooks as $hook ) {
	chk( "defaults: XML-RPC hook {$hook} registered", array() !== jht_sw_entries( $hook ) );
}
chk( 'defaults: pings_open registered (by the XML-RPC switch)', array() !== jht_sw_entries( 'pings_open' ) );
foreach ( $sw_comments_hooks as $hook ) {
	chk( "defaults: no comment hook on {$hook}", array() === jht_sw_entries( $hook ) );
}

jht_sw_register( array( 'xmlrpc' => false, 'comments' => false ) );
chk( 'both off: no hook registered at all', array() === $GLOBALS['jht_hooks'] );

jht_sw_register( array( 'xmlrpc' => false, 'comments' => true ) );
foreach ( $sw_xmlrpc_hooks as $hook ) {
	chk( "comments only: no XML-RPC hook on {$hook}", array() === jht_sw_entries( $hook ) );
}
foreach ( $sw_comments_hooks as $hook ) {
	chk( "comments only: comment hook {$hook} registered", array() !== jht_sw_entries( $hook ) );
}
chk( 'comments only: pings_open registered (by the comments switch)', array() !== jht_sw_entries( 'pings_open' ) );

// The feature registrar hands the settings to this module ('sitewide' in its loop).
jht_reset_wp();
$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array( 'schema' => 1, 'updated' => '', 'settings' => jpkcom_harden_trim_defaults() );
jpkcom_harden_trim_settings_reset_cache();
jpkcom_harden_trim_register_features( jpkcom_harden_trim_settings() );
chk( 'register_features: calls the sitewide registrar (xmlrpc_enabled hooked with defaults)', array() !== jht_sw_entries( 'xmlrpc_enabled' ) );

// ---------------------------------------------------------------------------
// XML-RPC, ported from jpkcom-disable-xmlrpc 1.0.10 tests/test-hooks.php.
// ---------------------------------------------------------------------------

jht_sw_register( array( 'xmlrpc' => true, 'comments' => false ) );

// Source: xmlrpc "%s is filtered at PHP_INT_MAX" (1.0.8 used priority 1).
foreach ( array( 'xmlrpc_enabled', 'xmlrpc_methods' ) as $hook ) {
	chk( "xmlrpc: {$hook} is filtered at PHP_INT_MAX (1 arg)", jht_sw_is( $hook, PHP_INT_MAX, 1 ) );
}
chk( 'xmlrpc: xmlrpc_enabled returns false', false === apply_filters( 'xmlrpc_enabled', true ) );
chk( 'xmlrpc: xmlrpc_methods returns an empty array', array() === apply_filters( 'xmlrpc_methods', array( 'system.listMethods' => 'this:listMethods' ) ) );

// Source: xmlrpc "pings_open is filtered so the endpoint is no longer advertised".
chk( 'xmlrpc: pings_open is filtered at PHP_INT_MAX with 2 args', jht_sw_is( 'pings_open', PHP_INT_MAX, 2 ) );

// Source: xmlrpc "pings_open returns false".
$jht_cb = jht_sw_cb( 'pings_open' );
chk( 'xmlrpc: pings_open returns false', null !== $jht_cb && false === $jht_cb( true, 1 ) );

// Source: xmlrpc "the reject guard runs at priority 0, ahead of the updater bootstrap".
chk( 'xmlrpc: the reject guard runs on init at priority 0 (1 arg)', jht_sw_is( 'init', 0, 1 ) );
chk( 'xmlrpc: the init guard is jpkcom_harden_trim_xmlrpc_refuse', 'jpkcom_harden_trim_xmlrpc_refuse' === ( jht_sw_entries( 'init' )[0][0] ?? null ) );

// Source: xmlrpc "wp_xmlrpc_server_class is still blocked" (belt and braces).
chk( 'xmlrpc: wp_xmlrpc_server_class is still blocked (priority 10, 1 arg)', jht_sw_is( 'wp_xmlrpc_server_class', 10, 1 ) );
chk( 'xmlrpc: the last resort is jpkcom_harden_trim_xmlrpc_server_class', 'jpkcom_harden_trim_xmlrpc_server_class' === ( jht_sw_entries( 'wp_xmlrpc_server_class' )[0][0] ?? null ) );

// The guard leaves a normal request alone (XMLRPC_REQUEST is not defined yet in this process).
$GLOBALS['jht_status'] = array();
ob_start();
jpkcom_harden_trim_xmlrpc_refuse();
$jht_out = ob_get_clean();
chk( 'xmlrpc: the init guard returns silently on a normal request', '' === $jht_out && array() === $GLOBALS['jht_status'] && empty( $GLOBALS['jht_nocache'] ) );

// Last resort: status 403 + wp_die() with the translated message, response 403.
$GLOBALS['jht_status']    = array();
$GLOBALS['jht_translate'] = true;
$jht_die                  = null;
try {
	jpkcom_harden_trim_xmlrpc_server_class( 'wp_xmlrpc_server' );
} catch ( JhtSitewideDie $e ) {
	$jht_die = $e;
}
$GLOBALS['jht_translate'] = false;
chk( 'xmlrpc last resort: status_header( 403 ) before wp_die()', array( 403 ) === $GLOBALS['jht_status'] );
chk( 'xmlrpc last resort: wp_die() with the translated "XML-RPC is disabled."', null !== $jht_die && 'DE:XML-RPC is disabled.' === $jht_die->die_message );
chk( 'xmlrpc last resort: wp_die() title is the translated "Error"', null !== $jht_die && 'DE:Error' === $jht_die->die_title );
chk( 'xmlrpc last resort: wp_die() response 403', null !== $jht_die && array( 'response' => 403 ) === $jht_die->die_args );

// Source: xmlrpc "a testable request-detection helper exists" (API check above) and
// "SCRIPT_FILENAME alone does not mark a request as XML-RPC".
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/xmlrpc.php';
chk( 'xmlrpc: SCRIPT_FILENAME alone does not mark a request as XML-RPC', false === jpkcom_harden_trim_is_xmlrpc_request() );

// Source: xmlrpc "a fault body builder exists" (API check above), "the fault body is not empty",
// "the fault body is well-formed XML", "it is an XML-RPC methodResponse fault", "faultCode is 403",
// "faultString explains the refusal". Built with translation switched on: the fault stays English
// (it runs at init 0, before the text domain loads at init 1).
$GLOBALS['jht_translate'] = true;
$jht_xml                  = jpkcom_harden_trim_xmlrpc_fault_xml();
$GLOBALS['jht_translate'] = false;

chk( 'xmlrpc: the fault body is not empty', '' !== $jht_xml );

$jht_prev   = libxml_use_internal_errors( use_errors: true );
$jht_parsed = simplexml_load_string( data: $jht_xml );
libxml_clear_errors();
libxml_use_internal_errors( use_errors: $jht_prev );

chk( 'xmlrpc: the fault body is well-formed XML', false !== $jht_parsed );

if ( false !== $jht_parsed ) {
	$jht_members = array();
	foreach ( $jht_parsed->fault->value->struct->member as $jht_member ) {
		$jht_members[ (string) $jht_member->name ] = trim( string: (string) $jht_member->value->children()[0] );
	}
	chk( 'xmlrpc: it is an XML-RPC methodResponse fault', 'methodResponse' === $jht_parsed->getName() && isset( $jht_parsed->fault ) );
	chk( 'xmlrpc: faultCode is 403', '403' === ( $jht_members['faultCode'] ?? '' ) );
	chk( 'xmlrpc: faultString is the English literal "XML-RPC is disabled." even when translating', 'XML-RPC is disabled.' === ( $jht_members['faultString'] ?? '' ) );
}

chk(
	'xmlrpc: the fault document is the standalone one, byte for byte',
	'<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><int>403</int></value></member><member><name>faultString</name><value><string>XML-RPC is disabled.</string></value></member></struct></value></fault></methodResponse>' === $jht_xml
);

// ---------------------------------------------------------------------------
// Comments, ported from jpkcom-disable-comments 1.0.10 tests/test-hooks.php.
// ---------------------------------------------------------------------------

jht_sw_register( array( 'xmlrpc' => false, 'comments' => true ) );

// Source: comments "comment support is removed on wp_loaded, not admin_init".
chk( 'comments: comment support is removed on wp_loaded, not admin_init', 'jpkcom_harden_trim_comments_remove_support' === ( jht_sw_entries( 'wp_loaded' )[0][0] ?? null ) && jht_sw_is( 'wp_loaded', 10, 1 ) );

// Source: comments "admin_init carries only the comments-screen redirect".
chk( 'comments: admin_init carries only the comments-screen redirect', 1 === count( jht_sw_entries( 'admin_init' ) ) );

// Source: comments "removes comments support", "removes trackbacks support alongside comments",
// "removes trackbacks from a post type that has no comments support", "leaves unrelated supports alone".
$GLOBALS['jht_supports'] = array(
	'post'      => array( 'comments' => true, 'trackbacks' => true, 'editor' => true ),
	'pingsonly' => array( 'trackbacks' => true ),
	'page'      => array( 'editor' => true ),
);
do_action( 'wp_loaded' );
chk( 'comments: removes comments support', ! post_type_supports( 'post', 'comments' ) );
chk( 'comments: removes trackbacks support alongside comments', ! post_type_supports( 'post', 'trackbacks' ) );
chk( 'comments: removes trackbacks from a post type that has no comments support', ! post_type_supports( 'pingsonly', 'trackbacks' ) );
chk( 'comments: leaves unrelated supports alone', post_type_supports( 'post', 'editor' ) && post_type_supports( 'page', 'editor' ) );

// Source: comments "%s is filtered at PHP_INT_MAX" (1.0.8 used priority 20).
foreach ( array( 'comments_open', 'pings_open', 'comments_array' ) as $hook ) {
	chk( "comments: {$hook} is filtered at PHP_INT_MAX with 2 args", jht_sw_is( $hook, PHP_INT_MAX, 2 ) );
}
chk( 'comments: comments_open returns false', false === apply_filters( 'comments_open', true, 1 ) );
chk( 'comments: pings_open returns false', false === apply_filters( 'pings_open', true, 1 ) );
chk( 'comments: comments_array returns an empty array', array() === apply_filters( 'comments_array', array( 'c1', 'c2' ), 1 ) );

// Source: comments "wp_count_comments is filtered".
chk( 'comments: wp_count_comments is filtered at PHP_INT_MAX with 2 args', jht_sw_is( 'wp_count_comments', PHP_INT_MAX, 2 ) );

// Source: comments "wp_count_comments reports every bucket as zero" and
// "the count object is truthy so core does not fall through to its own query".
$jht_cb = jht_sw_cb( 'wp_count_comments' );
if ( null !== $jht_cb ) {
	$jht_counts = $jht_cb( array(), 0 );
	$jht_zeroed = is_object( $jht_counts );
	foreach ( array( 'approved', 'moderated', 'spam', 'trash', 'post-trashed', 'total_comments' ) as $jht_field ) {
		$jht_zeroed = $jht_zeroed && isset( $jht_counts->$jht_field ) && 0 === $jht_counts->$jht_field;
	}
	chk( 'comments: wp_count_comments reports every bucket as zero', $jht_zeroed );
	chk( 'comments: wp_count_comments has exactly the six buckets core expects', is_object( $jht_counts ) && 6 === count( get_object_vars( $jht_counts ) ) );
	chk( 'comments: the count object is truthy so core does not fall through to its own query', ! empty( $jht_counts ) );
}

// Source: comments "comments_pre_query is short-circuited".
chk( 'comments: comments_pre_query is short-circuited at PHP_INT_MAX with 2 args', jht_sw_is( 'comments_pre_query', PHP_INT_MAX, 2 ) );

// Source: comments "comments_pre_query returns an empty array for listing queries" and
// "comments_pre_query returns 0 for counting queries".
$jht_cb = jht_sw_cb( 'comments_pre_query' );
if ( null !== $jht_cb ) {
	$jht_listing             = new WP_Comment_Query();
	$jht_listing->query_vars = array( 'count' => false );
	$jht_counting            = new WP_Comment_Query();
	$jht_counting->query_vars = array( 'count' => true );
	chk( 'comments: comments_pre_query returns an empty array for listing queries', array() === $jht_cb( null, $jht_listing ) );
	chk( 'comments: comments_pre_query returns 0 for counting queries', 0 === $jht_cb( null, $jht_counting ) );
}

// Source: comments "comment feed discovery links are removed".
chk( 'comments: comment feed discovery links are removed', false === apply_filters( 'feed_links_show_comments_feed', true ) );

// Source: comments "a template_redirect guard is registered for the comment feed".
chk( 'comments: a template_redirect guard is registered for the comment feed at priority 0', jht_sw_is( 'template_redirect', 0, 1 ) );

// The guard: wp_die() 404 with the translated texts on a comment feed (kept as wp_die(): core
// routes it to _xml_wp_die_handler while is_comment_feed() is true), nothing elsewhere.
$jht_cb  = jht_sw_cb( 'template_redirect' );
$jht_die = null;
try {
	$GLOBALS['jht_state']['is_comment_feed'] = false;
	null !== $jht_cb && $jht_cb();
} catch ( JhtSitewideDie $e ) {
	$jht_die = $e;
}
chk( 'comments: the feed guard leaves other requests (e.g. /feed/) alone', null === $jht_die );

$GLOBALS['jht_translate'] = true;
try {
	$GLOBALS['jht_state']['is_comment_feed'] = true;
	null !== $jht_cb && $jht_cb();
} catch ( JhtSitewideDie $e ) {
	$jht_die = $e;
}
$GLOBALS['jht_translate']                = false;
$GLOBALS['jht_state']['is_comment_feed'] = false;
chk( 'comments: a comment feed dies with response 404', null !== $jht_die && array( 'response' => 404 ) === $jht_die->die_args );
chk( 'comments: the 404 message is the translated "Comments are disabled."', null !== $jht_die && 'DE:Comments are disabled.' === $jht_die->die_message );
chk( 'comments: the 404 title is the translated "Not Found"', null !== $jht_die && 'DE:Not Found' === $jht_die->die_title );

// Source: comments "a REST status rewrite is registered on rest_api_init".
chk( 'comments: a REST status rewrite is registered on rest_api_init', jht_sw_is( 'rest_api_init', 10, 1 ) );

// rest_api_init attaches the rewrite to rest_prepare_<type> for every type, a custom one included.
$GLOBALS['jht_supports'] = array( 'post' => array(), 'page' => array(), 'attachment' => array(), 'jht_event' => array() );
do_action( 'rest_api_init' );
foreach ( array( 'post', 'page', 'attachment', 'jht_event' ) as $jht_type ) {
	chk(
		"comments: rest_prepare_{$jht_type} carries the status rewrite at PHP_INT_MAX",
		jht_sw_is( 'rest_prepare_' . $jht_type, PHP_INT_MAX, 1 ) && 'jpkcom_harden_trim_comments_close_rest_status' === ( jht_sw_entries( 'rest_prepare_' . $jht_type )[0][0] ?? null )
	);
}
$jht_r = apply_filters( 'rest_prepare_jht_event', new WP_REST_Response( array( 'id' => 3, 'comment_status' => 'open', 'ping_status' => 'open' ) ), null, null );
chk( 'comments: a custom post type response reports both statuses closed', 'closed' === ( $jht_r->get_data()['comment_status'] ?? '' ) && 'closed' === ( $jht_r->get_data()['ping_status'] ?? '' ) );

// Source: comments "the REST rewrite is a named, reusable function" (API check above),
// "comment_status is reported as closed", "ping_status is reported as closed",
// "other fields are untouched", "absent status fields are not invented",
// "a non-response value passes through unchanged".
$jht_response = jpkcom_harden_trim_comments_close_rest_status(
	new WP_REST_Response(
		array(
			'id'             => 7,
			'comment_status' => 'open',
			'ping_status'    => 'open',
			'title'          => 'kept',
		)
	)
);
$jht_data = $jht_response->get_data();
chk( 'comments: comment_status is reported as closed', 'closed' === ( $jht_data['comment_status'] ?? '' ) );
chk( 'comments: ping_status is reported as closed', 'closed' === ( $jht_data['ping_status'] ?? '' ) );
chk( 'comments: other fields are untouched', 'kept' === ( $jht_data['title'] ?? '' ) && 7 === ( $jht_data['id'] ?? 0 ) );

$jht_without = jpkcom_harden_trim_comments_close_rest_status( new WP_REST_Response( array( 'id' => 8 ) ) );
chk( 'comments: absent status fields are not invented', ! array_key_exists( 'comment_status', $jht_without->get_data() ) && ! array_key_exists( 'ping_status', $jht_without->get_data() ) );
chk( 'comments: a non-response value passes through unchanged', 'passthrough' === jpkcom_harden_trim_comments_close_rest_status( 'passthrough' ) );

$jht_scalar = new WP_REST_Response( 'not an array' );
chk( 'comments: a response with non-array data passes through unchanged', $jht_scalar === jpkcom_harden_trim_comments_close_rest_status( $jht_scalar ) && 'not an array' === $jht_scalar->get_data() );

// Source: comments "admin menu entry is removed".
chk( 'comments: admin menu entry is removed', jht_sw_is( 'admin_menu', 10, 1 ) );
do_action( 'admin_menu' );
chk( 'comments: admin_menu removes edit-comments.php', array( 'edit-comments.php' ) === ( $GLOBALS['jht_menu_removed'] ?? null ) );

// Source: comments "admin bar node is removed".
chk( 'comments: admin bar node is removed at priority 999', jht_sw_is( 'admin_bar_menu', 999, 1 ) );
$jht_bar = new WP_Admin_Bar();
do_action( 'admin_bar_menu', $jht_bar );
chk( 'comments: admin_bar_menu removes the comments node', array( 'comments' ) === $jht_bar->removed );

// The comments screen redirects to the dashboard; other screens are left alone.
$jht_cb       = jht_sw_cb( 'admin_init' );
$jht_redirect = null;
try {
	$GLOBALS['pagenow'] = 'index.php';
	null !== $jht_cb && $jht_cb();
} catch ( JhtSitewideRedirect $e ) {
	$jht_redirect = $e;
}
chk( 'comments: admin_init leaves other screens alone', null === $jht_redirect );
try {
	$GLOBALS['pagenow'] = 'edit-comments.php';
	null !== $jht_cb && $jht_cb();
} catch ( JhtSitewideRedirect $e ) {
	$jht_redirect = $e;
}
unset( $GLOBALS['pagenow'] );
chk( 'comments: edit-comments.php is redirected to admin_url()', null !== $jht_redirect && 'https://example.test/wp-admin/' === $jht_redirect->location );

// Source: comments "rest_endpoints is filtered", "removes the comments collection route",
// "removes comment sub-routes", "keeps unrelated routes".
chk( 'comments: rest_endpoints is filtered', jht_sw_is( 'rest_endpoints', 10, 1 ) );
$jht_endpoints = apply_filters(
	'rest_endpoints',
	array(
		'/wp/v2/comments'          => 'a',
		'/wp/v2/comments/(?P<id>)' => 'b',
		'/wp/v2/posts'             => 'c',
		'/wp/v2/commentsfoo'       => 'd',
	)
);
chk( 'comments: removes the comments collection route', ! isset( $jht_endpoints['/wp/v2/comments'] ) );
chk( 'comments: removes comment sub-routes', ! isset( $jht_endpoints['/wp/v2/comments/(?P<id>)'] ) );
chk( 'comments: keeps unrelated routes', isset( $jht_endpoints['/wp/v2/posts'] ) && isset( $jht_endpoints['/wp/v2/commentsfoo'] ) );

// ---------------------------------------------------------------------------
// The init guard, end to end: real status, headers and body need a web SAPI
// (the CLI keeps no header list), so fixture-xmlrpc-refuse.php runs under
// `php -S`. It runs last in the source plugin's order: XMLRPC_REQUEST decides.
// ---------------------------------------------------------------------------

/**
 * Serve tests/fixture-xmlrpc-refuse.php with `php -S` and fetch it once per query.
 *
 * @param list<string> $queries Query strings.
 * @return array<string, array{status: string, type: string, cache: string, body: string}> Per query; empty when the server did not start.
 */
function jht_sw_serve_refuse( array $queries ): array {
	$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( false === $probe ) {
		return array();
	}
	$port = (int) substr( string: (string) strrchr( haystack: (string) stream_socket_get_name( socket: $probe, remote: false ), needle: ':' ), offset: 1 );
	fclose( stream: $probe );

	// An argument list, not a string: no shell in between, so proc_terminate() reaches the server itself.
	$cmd  = array( PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'output_buffering=0', '-S', '127.0.0.1:' . $port, __DIR__ . '/fixture-xmlrpc-refuse.php' );
	$proc = proc_open( command: $cmd, descriptor_spec: array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), pipes: $pipes, cwd: dirname( path: __DIR__ ) );
	if ( ! is_resource( $proc ) ) {
		return array();
	}

	$out = array();
	try {
		$deadline = microtime( as_float: true ) + 10.0;
		while ( microtime( as_float: true ) < $deadline ) {
			$conn = @fsockopen( hostname: '127.0.0.1', port: $port, timeout: 0.2 );
			if ( false !== $conn ) {
				fclose( stream: $conn );
				break;
			}
			usleep( microseconds: 50000 );
		}
		foreach ( $queries as $query ) {
			$context = stream_context_create( array( 'http' => array( 'ignore_errors' => true, 'timeout' => 10 ) ) );
			$body    = @file_get_contents( filename: 'http://127.0.0.1:' . $port . '/?' . $query, use_include_path: false, context: $context );
			$headers = function_exists( function: 'http_get_last_response_headers' ) ? (array) http_get_last_response_headers() : ( $http_response_header ?? array() );
			$type    = '';
			$cache   = '';
			foreach ( $headers as $line ) {
				if ( 0 === stripos( haystack: (string) $line, needle: 'content-type:' ) ) {
					$type = trim( string: substr( string: (string) $line, offset: 13 ) );
				}
				if ( 0 === stripos( haystack: (string) $line, needle: 'cache-control:' ) ) {
					$cache = trim( string: substr( string: (string) $line, offset: 14 ) );
				}
			}
			$out[ $query ] = array( 'status' => (string) ( $headers[0] ?? '' ), 'type' => $type, 'cache' => $cache, 'body' => (string) $body );
		}
	} finally {
		proc_terminate( process: $proc );
		proc_close( process: $proc );
	}
	return $out;
}

$jht_served = jht_sw_serve_refuse( array( 'xmlrpc=1', 'xmlrpc=1&charset=ISO-8859-1', 'xmlrpc=0' ) );
chk( 'refuse: built-in server answered', 3 === count( $jht_served ) );
$jht_r = $jht_served['xmlrpc=1'] ?? array( 'status' => '', 'type' => '', 'cache' => '', 'body' => '' );
chk( 'refuse: XML-RPC request answers 403 (' . $jht_r['status'] . ')', str_contains( haystack: $jht_r['status'], needle: ' 403' ) );
chk( 'refuse: Content-Type text/xml with the blog charset (got ' . $jht_r['type'] . ')', 'text/xml; charset=UTF-8' === $jht_r['type'] );
chk( 'refuse: no-cache headers sent (got ' . $jht_r['cache'] . ')', str_contains( haystack: $jht_r['cache'], needle: 'no-cache' ) );
chk( 'refuse: body is exactly the fault document, then exit', jpkcom_harden_trim_xmlrpc_fault_xml() === $jht_r['body'] );
$jht_r = $jht_served['xmlrpc=1&charset=ISO-8859-1'] ?? array( 'type' => '' );
chk( 'refuse: blog_charset option used (got ' . $jht_r['type'] . ')', 'text/xml; charset=ISO-8859-1' === $jht_r['type'] );
$jht_r = $jht_served['xmlrpc=0'] ?? array( 'status' => '', 'body' => '' );
chk( 'refuse: a normal request continues (200, no fault)', str_contains( haystack: $jht_r['status'], needle: ' 200' ) && 'continued' === $jht_r['body'] );

// Source: xmlrpc "XMLRPC_REQUEST marks a request as XML-RPC regardless of SCRIPT_FILENAME".
// Defined last: constants are per process.
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/index.php';
define( constant_name: 'XMLRPC_REQUEST', value: true );
chk( 'xmlrpc: XMLRPC_REQUEST marks a request as XML-RPC regardless of SCRIPT_FILENAME', true === jpkcom_harden_trim_is_xmlrpc_request() );

summary();
