<?php
/**
 * Tests for the performance features: pure helpers (asset versions, self
 * pings, heartbeat, embed stripping, revisions) and the hook glue registered
 * per setting (defaults register only the harmless set, role exemption,
 * block-theme no-op, core locks).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/features.php', 'includes/features-performance.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

// Stubs this suite needs on top of bootstrap.php.

if ( ! function_exists( function: 'wp_dequeue_style' ) ) {
	function wp_dequeue_style( string $handle ): void {
		$GLOBALS['jht_dequeued_styles'][] = $handle;
	}
}

if ( ! function_exists( function: 'wp_dequeue_script' ) ) {
	function wp_dequeue_script( string $handle ): void {
		$GLOBALS['jht_dequeued_scripts'][] = $handle;
	}
}

if ( ! class_exists( class: 'JHT_Styles_Stub' ) ) {
	class JHT_Styles_Stub {
		/** @var string[] */
		public array $queue = array();
	}
}

if ( ! function_exists( function: 'wp_styles' ) ) {
	function wp_styles(): JHT_Styles_Stub {
		$styles        = new JHT_Styles_Stub();
		$styles->queue = $GLOBALS['jht_style_queue'] ?? array();
		return $styles;
	}
}

if ( ! function_exists( function: 'post_type_supports' ) ) {
	function post_type_supports( string $post_type, string $feature ): bool {
		return ! in_array( needle: $post_type, haystack: $GLOBALS['jht_no_revisions'] ?? array(), strict: true );
	}
}

if ( ! function_exists( function: 'status_header' ) ) {
	function status_header( int $code ): void {
		$GLOBALS['jht_status'] = $code;
	}
}

if ( ! function_exists( function: 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['jht_nocache'] = true;
	}
}

require_once __DIR__ . '/stubs-query.php';

if ( ! class_exists( class: 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		/** @var array<string, mixed> */
		public array $params = array();

		public function __construct( public string $route = '' ) {
		}

		public function get_route(): string {
			return $this->route;
		}

		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}
	}
}

$jht_api = array(
	'jpkcom_harden_trim_register_features',
	'jpkcom_harden_trim_register_performance_features',
	'jpkcom_harden_trim_rewrite_asset_src',
	'jpkcom_harden_trim_is_self_link',
	'jpkcom_harden_trim_filter_self_pings',
	'jpkcom_harden_trim_heartbeat_settings',
	'jpkcom_harden_trim_strip_wp_embed_iframe',
	'jpkcom_harden_trim_revisions_to_keep',
	'jpkcom_harden_trim_revision_exempt_post_types',
);

$jht_missing = array_values( array: array_filter( array: $jht_api, callback: static fn( string $fn ): bool => ! function_exists( $fn ) ) );
chk( 'performance API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

/**
 * Reset the stubs, store settings over the defaults and clear the cache.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @param array<string, mixed> $state    Overrides for $GLOBALS['jht_state'].
 * @return void
 */
function jht_perf_setup( array $settings = array(), array $state = array() ): void {
	jht_reset_wp();
	jht_seed_core_hooks();
	$GLOBALS['jht_state'] = array_merge( $GLOBALS['jht_state'], $state );
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * Store settings and run the performance registrar with them.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @param array<string, mixed> $state    Overrides for $GLOBALS['jht_state'].
 * @return void
 */
function jht_perf_register( array $settings = array(), array $state = array() ): void {
	jht_perf_setup( $settings, $state );
	jpkcom_harden_trim_register_performance_features( jpkcom_harden_trim_settings() );
}

/**
 * Whether a removal of the callback from the hook at the priority was recorded.
 *
 * @param string $hook     Hook name.
 * @param string $callback Callback name.
 * @param int    $priority Priority.
 * @return bool
 */
function jht_was_removed( string $hook, string $callback, int $priority = 10 ): bool {
	return in_array( needle: array( $hook, $callback, $priority ), haystack: $GLOBALS['jht_removed'], strict: true );
}

/**
 * Every callback recorded on a hook, flattened over priorities.
 *
 * @param string $hook Hook name.
 * @return list<mixed>
 */
function jht_callbacks( string $hook ): array {
	$all = array();
	foreach ( $GLOBALS['jht_hooks'][ $hook ] ?? array() as $list ) {
		foreach ( $list as $callback ) {
			$all[] = $callback;
		}
	}
	return $all;
}

$editor = array( 'logged_in' => true, 'roles' => array( 'editor' ) );

// asset_remove.

jht_perf_setup();
chk( 'asset_remove: only ver', 'https://ex.test/a.css' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.css?ver=7.1.3', 'remove' ) );
chk( 'asset_remove: other args kept', 'https://ex.test/a.js?foo=2' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?ver=1&foo=2', 'remove' ) );
chk( 'asset_remove: ver in the middle', 'https://ex.test/a.js?a=1&b=2' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?a=1&ver=1&b=2', 'remove' ) );
chk( 'asset_remove: fragment kept', 'https://ex.test/a.css#x' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.css?ver=1#x', 'remove' ) );
chk( 'asset_remove: no ver unchanged', 'https://fonts.googleapis.com/css2?family=Inter' === jpkcom_harden_trim_rewrite_asset_src( 'https://fonts.googleapis.com/css2?family=Inter', 'remove' ) );
chk( 'asset_remove: arg merely containing ver unchanged', 'https://ex.test/a.js?version=1&aver=2' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?version=1&aver=2', 'remove' ) );
chk( 'asset_off: unchanged', 'https://ex.test/a.css?ver=7.1.3' === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.css?ver=7.1.3', 'off' ) );

// asset_hash.

$jht_h1 = jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?ver=7.1.3&foo=2', 'hash' );
chk( 'asset_hash: ten hex chars', 1 === preg_match( '/[?&]ver=[0-9a-f]{10}(&|$)/', $jht_h1 ) );
chk( 'asset_hash: version hidden', ! str_contains( haystack: $jht_h1, needle: '7.1.3' ) );
chk( 'asset_hash: stable', $jht_h1 === jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?ver=7.1.3&foo=2', 'hash' ) );
chk(
	'asset_hash: different versions differ',
	jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?ver=1', 'hash' ) !== jpkcom_harden_trim_rewrite_asset_src( 'https://ex.test/a.js?ver=2', 'hash' )
);
chk( 'asset_hash: foo kept', str_contains( haystack: $jht_h1, needle: 'foo=2' ) );
chk( 'asset_hash: keyed with the nonce salt', str_contains( haystack: $jht_h1, needle: 'ver=' . substr( string: hash_hmac( algo: 'sha256', data: '7.1.3', key: wp_salt( 'nonce' ) ), offset: 0, length: 10 ) ) );

// Asset hooks: front end only, role-scoped, non-strings passed through.

jht_perf_register( array( 'asset_version' => 'remove' ) );
chk( 'asset hooks: script_loader_src', array() !== jht_callbacks( 'script_loader_src' ) );
chk( 'asset hooks: style_loader_src after core (priority > 10)', false !== has_filter( 'style_loader_src' ) && max( array_keys( array: $GLOBALS['jht_hooks']['style_loader_src'] ) ) > 10 );
chk( 'asset hooks: script_module_loader_src', array() !== jht_callbacks( 'script_module_loader_src' ) );
chk( 'asset hooks: front end rewritten', 'https://ex.test/a.css' === apply_filters( 'style_loader_src', 'https://ex.test/a.css?ver=1', 'a' ) );
chk( 'asset hooks: false passed through', false === apply_filters( 'style_loader_src', false, 'colors' ) );
$GLOBALS['jht_state']['is_admin'] = true;
chk( 'asset hooks: admin untouched', 'https://ex.test/a.css?ver=1' === apply_filters( 'style_loader_src', 'https://ex.test/a.css?ver=1', 'a' ) );
jht_perf_register( array( 'asset_version' => 'hash', 'exempt_roles' => array( 'editor' ) ), $editor );
chk( 'asset hooks: exempt editor untouched', 'https://ex.test/a.js?ver=1' === apply_filters( 'script_loader_src', 'https://ex.test/a.js?ver=1', 'a' ) );

// self_ping_variants.

jht_perf_setup( array(), array( 'home' => 'https://www.Site.test', 'site' => 'https://site.test/wp' ) );
$jht_links = array( 'http://site.test/a', 'https://WWW.SITE.TEST/b', 'https://other.test/c' );
jpkcom_harden_trim_filter_self_pings( $jht_links );
chk( 'self_ping_variants: only the foreign link remains', array( 'https://other.test/c' ) === $jht_links );
chk( 'self_ping: subdomain is not self', ! jpkcom_harden_trim_is_self_link( 'https://blog.site.test/x' ) );
chk( 'self_ping: look-alike host is not self', ! jpkcom_harden_trim_is_self_link( 'https://site.test.evil.test/x' ) );
chk( 'self_ping: relative URL is not self', ! jpkcom_harden_trim_is_self_link( '/a' ) );
chk( 'self_ping: protocol-relative own host is self', jpkcom_harden_trim_is_self_link( '//site.test/a' ) );

// Subdirectory installs (multisite sites, a second WordPress under a path): host and path prefix count.
jht_perf_setup( array(), array( 'home' => 'https://example.test/site2', 'site' => 'https://example.test/site2' ) );
chk( 'self_ping path: own subdirectory is self', jpkcom_harden_trim_is_self_link( 'https://example.test/site2/hello/' ) );
chk( 'self_ping path: own home exactly is self', jpkcom_harden_trim_is_self_link( 'https://www.example.test/site2' ) && jpkcom_harden_trim_is_self_link( 'http://example.test/site2?p=1' ) && jpkcom_harden_trim_is_self_link( 'https://example.test/site2#c' ) );
chk( 'self_ping path: sibling site on the same host is not self', ! jpkcom_harden_trim_is_self_link( 'https://example.test/site3/hello' ) );
chk( 'self_ping path: path boundary respected (/site20)', ! jpkcom_harden_trim_is_self_link( 'https://example.test/site20/x' ) );
chk( 'self_ping path: host root is not this subdirectory site', ! jpkcom_harden_trim_is_self_link( 'https://example.test/' ) );
$jht_links = array( 'https://example.test/site2/a', 'https://example.test/site3/b', 'https://example.test/other/c' );
jpkcom_harden_trim_filter_self_pings( $jht_links );
chk( 'self_ping path: only the own subdirectory link is dropped', array( 'https://example.test/site3/b', 'https://example.test/other/c' ) === $jht_links );
jht_perf_setup( array(), array( 'home' => 'https://example.test/blog', 'site' => 'https://example.test/blog/wp' ) );
chk( 'self_ping path: WordPress address below home is self', jpkcom_harden_trim_is_self_link( 'https://example.test/blog/wp/wp-content/x.png' ) );
// IDN: Unicode and punycode spellings of the same host.
jht_perf_setup( array(), array( 'home' => 'https://bücher.example', 'site' => 'https://bücher.example' ) );
chk( 'self_ping idn: punycode link to a Unicode home is self', ! function_exists( 'idn_to_ascii' ) || jpkcom_harden_trim_is_self_link( 'https://xn--bcher-kva.example/x' ) );
jht_perf_setup( array(), array( 'home' => 'https://xn--bcher-kva.example', 'site' => 'https://xn--bcher-kva.example' ) );
chk( 'self_ping idn: Unicode link to a punycode home is self', ! function_exists( 'idn_to_ascii' ) || jpkcom_harden_trim_is_self_link( 'https://BÜCHER.example/x' ) );

// Main site of a subdirectory network: home_url() has no path, so the host alone would claim every
// sibling site. A path that belongs to another site of the network on the same host is not self.
if ( ! class_exists( class: 'WP_Site' ) ) {
	final class WP_Site {
		public string $blog_id;
		public string $domain;
		public string $path;

		public function __construct( int $id, string $domain, string $path ) {
			$this->blog_id = (string) $id;
			$this->domain  = $domain;
			$this->path    = $path;
		}
	}
}

if ( ! function_exists( function: 'get_sites' ) ) {
	/**
	 * Mirrors the WP_Site_Query arguments the plugin uses: domain__in, site__not_in, number 0 = no limit.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return list<WP_Site>
	 */
	function get_sites( array $args = array() ): array {
		$GLOBALS['jht_get_sites_calls'][] = $args;
		$out = array();
		foreach ( $GLOBALS['jht_network_sites'] ?? array() as $site ) {
			if ( isset( $args['domain__in'] ) && ! in_array( needle: $site->domain, haystack: (array) $args['domain__in'], strict: true ) ) {
				continue;
			}
			if ( isset( $args['site__not_in'] ) && in_array( needle: (int) $site->blog_id, haystack: array_map( 'intval', (array) $args['site__not_in'] ), strict: true ) ) {
				continue;
			}
			$out[] = $site;
		}
		return $out;
	}
}

$pf_network = array(
	new WP_Site( 1, 'example.test', '/' ),
	new WP_Site( 2, 'example.test', '/site2/' ),
	new WP_Site( 3, 'example.test', '/Site3/' ),
	new WP_Site( 4, 'other.test', '/' ),
	new WP_Site( 5, 'other.test', '/news/' ),
);

jht_perf_setup( array(), array( 'home' => 'https://example.test', 'site' => 'https://example.test', 'multisite' => true, 'blog_id' => 1 ) );
$GLOBALS['jht_network_sites'] = $pf_network;
chk( 'self_ping network main: link into sibling /site2/ is not self', ! jpkcom_harden_trim_is_self_link( 'https://example.test/site2/hello/' ) );
chk( 'self_ping network main: sibling home exactly is not self', ! jpkcom_harden_trim_is_self_link( 'https://www.example.test/site2' ) && ! jpkcom_harden_trim_is_self_link( 'https://example.test/site2/?p=1' ) );
chk( 'self_ping network main: sibling path compared case-insensitively', ! jpkcom_harden_trim_is_self_link( 'https://example.test/site3/x' ) );
chk( 'self_ping network main: /site20 is a main-site page (segment boundary)', jpkcom_harden_trim_is_self_link( 'https://example.test/site20/x' ) );
chk( 'self_ping network main: own post is self', jpkcom_harden_trim_is_self_link( 'https://example.test/hello-world/' ) && jpkcom_harden_trim_is_self_link( 'https://example.test/' ) );
chk( 'self_ping network main: /news/ belongs to another host only, so it is self here', jpkcom_harden_trim_is_self_link( 'https://example.test/news/x' ) );
$jht_links = array( 'https://example.test/a', 'https://example.test/site2/b', 'https://example.test/site3/c', 'https://other.test/news/d' );
jpkcom_harden_trim_filter_self_pings( $jht_links );
chk( 'self_ping network main: only the own link is dropped', array( 'https://example.test/site2/b', 'https://example.test/site3/c', 'https://other.test/news/d' ) === $jht_links );
$jht_calls = $GLOBALS['jht_get_sites_calls'] ?? array();
chk( 'self_ping network main: get_sites queried once per request and host (cached)', 1 === count( $jht_calls ) );
chk( 'self_ping network main: query by domain, without the own site, without a limit', isset( $jht_calls[0] ) && array( 'example.test', 'www.example.test' ) === ( $jht_calls[0]['domain__in'] ?? null ) && array( 1 ) === ( $jht_calls[0]['site__not_in'] ?? null ) && 0 === ( $jht_calls[0]['number'] ?? null ) );

// A subdirectory site keeps its own path rule; the main site's posts stay foreign to it.
jht_perf_setup( array(), array( 'home' => 'https://example.test/site2', 'site' => 'https://example.test/site2', 'multisite' => true, 'blog_id' => 2 ) );
$GLOBALS['jht_network_sites'] = $pf_network;
chk( 'self_ping network site2: own link is self', jpkcom_harden_trim_is_self_link( 'https://example.test/site2/x' ) );
chk( 'self_ping network site2: main-site link is not self', ! jpkcom_harden_trim_is_self_link( 'https://example.test/hello/' ) );

// Single site: unchanged, no network query.
jht_perf_setup( array(), array( 'home' => 'https://example.test', 'site' => 'https://example.test', 'multisite' => false ) );
$GLOBALS['jht_network_sites'] = $pf_network;
chk( 'self_ping single site: every same-host path is self', jpkcom_harden_trim_is_self_link( 'https://example.test/site2/hello/' ) );
chk( 'self_ping single site: no get_sites call', array() === ( $GLOBALS['jht_get_sites_calls'] ?? array() ) );

// heartbeat.

jht_perf_setup( array( 'heartbeat' => 'optimized' ) );
$jht_hb = jpkcom_harden_trim_heartbeat_settings( array( 'nonce' => 'n' ) );
chk( 'heartbeat: optimized interval 120', 120 === ( $jht_hb['interval'] ?? null ) );
chk( 'heartbeat: optimized minimalInterval 120', 120 === ( $jht_hb['minimalInterval'] ?? null ) );
chk( 'heartbeat: other keys kept', 'n' === ( $jht_hb['nonce'] ?? null ) );
$jht_hb = jpkcom_harden_trim_heartbeat_settings( array( 'interval' => 300, 'minimalInterval' => 900 ) );
chk( 'heartbeat: higher interval kept, minimal capped at 600', 300 === $jht_hb['interval'] && 600 === $jht_hb['minimalInterval'] );
jht_perf_setup( array( 'heartbeat' => 'enabled' ) );
chk( 'heartbeat: enabled unchanged', array( 'nonce' => 'n' ) === jpkcom_harden_trim_heartbeat_settings( array( 'nonce' => 'n' ) ) );
jht_perf_setup( array( 'heartbeat' => 'optimized', 'exempt_roles' => array( 'editor' ) ), $editor );
chk( 'heartbeat: optimized + exempt editor unchanged', array( 'nonce' => 'n' ) === jpkcom_harden_trim_heartbeat_settings( array( 'nonce' => 'n' ) ) );

jht_perf_register( array( 'heartbeat' => 'optimized' ) );
chk( 'heartbeat: optimized registers heartbeat_settings', array() !== jht_callbacks( 'heartbeat_settings' ) );
chk( 'heartbeat: optimized filter applies', 120 === ( apply_filters( 'heartbeat_settings', array() )['minimalInterval'] ?? null ) );
chk( 'heartbeat: optimized leaves scripts alone', array() === jht_callbacks( 'script_loader_src' ) );

// heartbeat=disabled: heartbeat and its dependents print nothing, auth check off; no deregistration.

jht_perf_register( array( 'heartbeat' => 'disabled' ) );
chk( 'heartbeat disabled: heartbeat src emptied', '' === apply_filters( 'script_loader_src', '/wp-includes/js/heartbeat.min.js?ver=7.1.3', 'heartbeat' ) );
chk( 'heartbeat disabled: autosave src emptied', '' === apply_filters( 'script_loader_src', '/wp-includes/js/autosave.min.js', 'autosave' ) );
chk( 'heartbeat disabled: wp-auth-check src emptied', '' === apply_filters( 'script_loader_src', '/wp-includes/js/wp-auth-check.min.js', 'wp-auth-check' ) );
chk( 'heartbeat disabled: other script untouched', '/wp-includes/js/jquery.js' === apply_filters( 'script_loader_src', '/wp-includes/js/jquery.js', 'jquery' ) );
chk( 'heartbeat disabled: auth check not loaded', false === apply_filters( 'wp_auth_check_load', true, null ) );
chk( 'heartbeat disabled: no heartbeat_settings filter', array() === jht_callbacks( 'heartbeat_settings' ) );
jht_perf_register( array( 'heartbeat' => 'disabled', 'exempt_roles' => array( 'editor' ) ), $editor );
chk( 'heartbeat disabled + exempt editor: heartbeat kept', '/h.js' === apply_filters( 'script_loader_src', '/h.js', 'heartbeat' ) );
chk( 'heartbeat disabled + exempt editor: auth check kept', true === apply_filters( 'wp_auth_check_load', true, null ) );

// embed_strip.

$core_embed_html = '<blockquote class="wp-embedded-content" data-secret="x"><a href="https://other.test/p/">T</a></blockquote>'
	. '<iframe class="wp-embedded-content" sandbox="allow-scripts" security="restricted" style="position: absolute; visibility: hidden;" src="https://other.test/p/embed/#?secret=x" data-secret="x" width="500" height="282" title="T" frameborder="0" marginwidth="0" marginheight="0" scrolling="no"></iframe>';
$jht_stripped    = jpkcom_harden_trim_strip_wp_embed_iframe( $core_embed_html );
chk( 'embed_strip: iframe removed', ! str_contains( haystack: $jht_stripped, needle: '<iframe' ) );
chk( 'embed_strip: blockquote kept', str_contains( haystack: $jht_stripped, needle: '<blockquote' ) && str_contains( haystack: $jht_stripped, needle: '>T</a>' ) );
chk( 'embed_strip: self-closing iframe removed', '<p>a</p><p>b</p>' === jpkcom_harden_trim_strip_wp_embed_iframe( '<p>a</p><iframe src="https://o.test/e/" class="wp-embedded-content" /><p>b</p>' ) );
$jht_yt = '<iframe title="YouTube" width="500" height="281" src="https://www.youtube.com/embed/abc?feature=oembed" frameborder="0" allowfullscreen></iframe>';
chk( 'embed_strip: YouTube untouched', $jht_yt === jpkcom_harden_trim_strip_wp_embed_iframe( $jht_yt ) );
chk(
	'embed_strip: self-closing does not eat a following iframe',
	str_contains( haystack: jpkcom_harden_trim_strip_wp_embed_iframe( '<iframe class="wp-embedded-content" src="a" />' . $jht_yt ), needle: $jht_yt )
);
// An unclosed WP-embed iframe must not swallow a following iframe up to its closing tag.
chk(
	'embed_strip: unclosed WP-embed iframe does not eat a following YouTube iframe',
	str_contains( haystack: jpkcom_harden_trim_strip_wp_embed_iframe( '<iframe class="wp-embedded-content" src="https://o.test/e/"><p>x</p>' . $jht_yt ), needle: $jht_yt )
);
chk( 'embed_strip: unclosed WP-embed iframe itself removed', ! str_contains( haystack: jpkcom_harden_trim_strip_wp_embed_iframe( '<iframe class="wp-embedded-content" src="https://o.test/e/"><p>x</p>' ), needle: 'o.test/e/' ) );
// The class is matched as a whole token.
foreach ( array( 'x-wp-embedded-content', 'wp-embedded-content-x', 'my_wp-embedded-content' ) as $jht_cls ) {
	$jht_html = '<iframe class="' . $jht_cls . '" src="https://v.test/e/"></iframe>';
	chk( "embed_strip: class token {$jht_cls} is not the WP embed class", $jht_html === jpkcom_harden_trim_strip_wp_embed_iframe( $jht_html ) );
}
chk( 'embed_strip: class among other tokens stripped', '' === jpkcom_harden_trim_strip_wp_embed_iframe( '<iframe class="a wp-embedded-content b" src="u"></iframe>' ) );
chk( 'embed_strip: unquoted class stripped', '' === jpkcom_harden_trim_strip_wp_embed_iframe( '<iframe class=wp-embedded-content src=u></iframe>' ) );
$jht_bq = '<blockquote class="x-wp-embedded-content" style="display: none;"><a href="u">T</a></blockquote>';
chk( 'embed_strip: blockquote with a look-alike class untouched', $jht_bq === jpkcom_harden_trim_strip_wp_embed_iframe( $jht_bq . '<iframe class="wp-embedded-content" src="u"></iframe>' ) );
$jht_hidden_bq = jpkcom_harden_trim_strip_wp_embed_iframe( '<blockquote class="wp-embedded-content" style="display: none;" data-secret="x"><a href="u">T</a></blockquote>' );
chk( 'embed_strip: hidden blockquote made visible', ! str_contains( haystack: $jht_hidden_bq, needle: 'display' ) && str_contains( haystack: $jht_hidden_bq, needle: 'data-secret="x"' ) );

// revisions.

jht_perf_setup( array( 'post_revisions' => 5 ) );
chk( 'revisions: post gets the setting', 5 === jpkcom_harden_trim_revisions_to_keep( -1, new WP_Post( 1, 'post' ) ) );
chk( 'revisions: wp_template keeps core value', -1 === jpkcom_harden_trim_revisions_to_keep( -1, new WP_Post( 2, 'wp_template' ) ) );
chk(
	'revisions: exempt types are the Site Editor types',
	array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ) === jpkcom_harden_trim_revision_exempt_post_types()
);
$GLOBALS['jht_no_revisions'] = array( 'book' );
chk( 'revisions: type without revision support keeps 0', 0 === jpkcom_harden_trim_revisions_to_keep( 0, new WP_Post( 3, 'book' ) ) );
jht_perf_setup( array( 'post_revisions' => 0 ) );
chk( 'revisions: setting 0 applies', 0 === jpkcom_harden_trim_revisions_to_keep( -1, new WP_Post( 1, 'post' ) ) );

jht_perf_register( array( 'post_revisions' => 5 ) );
chk( 'revisions: filter registered when set', array() !== jht_callbacks( 'wp_revisions_to_keep' ) );
chk( 'revisions: filter applies', 5 === apply_filters( 'wp_revisions_to_keep', -1, new WP_Post( 1, 'post' ) ) );
chk( 'revisions: filter passes a foreign value through for non-posts', 'x' === apply_filters( 'wp_revisions_to_keep', 'x', null ) );

// Multisite: hooks are registered from the booting site; the revisions filter re-reads the current site.
jht_perf_register( array( 'post_revisions' => 5 ) );
jht_switch_site( 2 );
chk( 'revisions multisite: switched to a site without a limit, core value kept', -1 === apply_filters( 'wp_revisions_to_keep', -1, new WP_Post( 9, 'post' ) ) );
jht_switch_site( 1 );
chk( 'revisions multisite: back on the booting site, its limit applies', 5 === apply_filters( 'wp_revisions_to_keep', -1, new WP_Post( 9, 'post' ) ) );

// registration_defaults.

jht_perf_register();
ob_start();
do_action( 'init' );
$jht_init_output = ob_get_clean();
chk( 'defaults: init callback silent', '' === $jht_init_output );
chk( 'defaults: emoji script removed from wp_head@7', jht_was_removed( 'wp_head', 'print_emoji_detection_script', 7 ) );
chk( 'defaults: emoji script removed from embed_head', jht_was_removed( 'embed_head', 'print_emoji_detection_script' ) );
chk( 'defaults: emoji styles removed', jht_was_removed( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' ) );
chk( 'defaults: deprecated emoji styles fallback removed too', jht_was_removed( 'wp_print_styles', 'print_emoji_styles' ) );
chk( 'defaults: footer emoji script removed', jht_was_removed( 'wp_print_footer_scripts', '_print_emoji_detection_script' ) );
chk( 'defaults: feed staticize removed', jht_was_removed( 'the_content_feed', 'wp_staticize_emoji' ) );
chk( 'defaults: comment feed staticize removed', jht_was_removed( 'comment_text_rss', 'wp_staticize_emoji' ) );
chk( 'defaults: mail staticize removed', jht_was_removed( 'wp_mail', 'wp_staticize_emoji_for_email' ) );
chk( 'defaults: emoji gone from wp_head', false === has_action( 'wp_head', 'print_emoji_detection_script' ) );
chk( 'defaults: pre_ping callback added', array() !== jht_callbacks( 'pre_ping' ) );
chk( 'defaults: no script_loader_src callback', array() === jht_callbacks( 'script_loader_src' ) );
chk( 'defaults: no style_loader_src callback', array() === jht_callbacks( 'style_loader_src' ) );
chk( 'defaults: no script_module_loader_src callback', array() === jht_callbacks( 'script_module_loader_src' ) );
chk( 'defaults: no heartbeat_settings callback', array() === jht_callbacks( 'heartbeat_settings' ) );
chk( 'defaults: heartbeat settings unchanged', array( 'interval' => 60 ) === apply_filters( 'heartbeat_settings', array( 'interval' => 60 ) ) );
chk( 'defaults: no wp_revisions_to_keep filter', array() === jht_callbacks( 'wp_revisions_to_keep' ) );
chk( 'defaults: no wp_auth_check_load filter', array() === jht_callbacks( 'wp_auth_check_load' ) );
chk( 'defaults: no embed changes', array() === jht_callbacks( 'is_post_embeddable' ) && array() === jht_callbacks( 'embed_oembed_html' ) );
chk( 'defaults: oEmbed discovery links untouched', false !== has_action( 'wp_head', 'wp_oembed_add_discovery_links' ) );
chk( 'defaults: no front-end dequeues', array() === jht_callbacks( 'wp_print_styles' ) && array() === jht_callbacks( 'wp_print_scripts' ) );
chk( 'defaults: AUTOSAVE_INTERVAL not defined', ! defined( constant_name: 'AUTOSAVE_INTERVAL' ) );

// Emoji in the admin: admin hooks exist only from admin_init on.

do_action( 'admin_init' );
chk( 'emoji admin: detection script removed', jht_was_removed( 'admin_print_scripts', 'print_emoji_detection_script' ) );
chk( 'emoji admin: styles removed', jht_was_removed( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' ) );
chk( 'emoji admin: deprecated styles fallback removed', jht_was_removed( 'admin_print_styles', 'print_emoji_styles' ) );
chk( 'emoji TinyMCE: wpemoji dropped', array( 'lists', 'wplink' ) === apply_filters( 'tiny_mce_plugins', array( 'lists', 'wpemoji', 'wplink' ), 'content' ) );

// Emoji with an exempt user: role-scoped parts stay, feed/mail parts are global.

jht_perf_register( array( 'exempt_roles' => array( 'editor' ) ), $editor );
do_action( 'init' );
chk( 'emoji exempt: wp_head script stays', ! jht_was_removed( 'wp_head', 'print_emoji_detection_script', 7 ) );
chk( 'emoji exempt: TinyMCE plugin stays', array( 'wpemoji' ) === apply_filters( 'tiny_mce_plugins', array( 'wpemoji' ), 'content' ) );
chk( 'emoji exempt: mail staticize still removed (global)', jht_was_removed( 'wp_mail', 'wp_staticize_emoji_for_email' ) );

jht_perf_register( array( 'emoji' => false ) );
do_action( 'init' );
chk( 'emoji off: nothing removed', array() === $GLOBALS['jht_removed'] );

// block_theme_noop and the classic-theme counterpart.

jht_perf_register( array( 'block_library_css' => true, 'global_styles' => true ), array( 'block_theme' => true ) );
$GLOBALS['jht_style_queue'] = array( 'wp-block-library', 'global-styles', 'wp-block-paragraph' );
jpkcom_harden_trim_dequeue_block_library_css();
jpkcom_harden_trim_dequeue_global_styles();
chk( 'block_theme_noop: nothing dequeued', array() === ( $GLOBALS['jht_dequeued_styles'] ?? array() ) );
chk( 'block CSS: dequeue registered on print points', in_array( needle: 'jpkcom_harden_trim_dequeue_block_library_css', haystack: jht_callbacks( 'wp_print_styles' ), strict: true ) && in_array( needle: 'jpkcom_harden_trim_dequeue_block_library_css', haystack: jht_callbacks( 'wp_print_footer_scripts' ), strict: true ) );

jht_perf_register( array( 'block_library_css' => true ) );
$GLOBALS['jht_style_queue'] = array( 'wp-block-library', 'wp-block-paragraph', 'my-plugin-style', 'global-styles' );
jpkcom_harden_trim_dequeue_block_library_css();
$jht_deq = $GLOBALS['jht_dequeued_styles'] ?? array();
chk( 'block CSS classic: library dequeued', in_array( needle: 'wp-block-library', haystack: $jht_deq, strict: true ) );
chk( 'block CSS classic: per-block style dequeued', in_array( needle: 'wp-block-paragraph', haystack: $jht_deq, strict: true ) );
chk( 'block CSS classic: classic-theme-styles dequeued', in_array( needle: 'classic-theme-styles', haystack: $jht_deq, strict: true ) );
chk( 'block CSS classic: foreign style kept', ! in_array( needle: 'my-plugin-style', haystack: $jht_deq, strict: true ) );
chk( 'block CSS classic: global styles kept', ! in_array( needle: 'global-styles', haystack: $jht_deq, strict: true ) );
$GLOBALS['jht_dequeued_styles'] = array();
$GLOBALS['jht_state']['is_admin'] = true;
jpkcom_harden_trim_dequeue_block_library_css();
chk( 'block CSS: admin untouched', array() === $GLOBALS['jht_dequeued_styles'] );

jht_perf_register( array( 'global_styles' => true ) );
jpkcom_harden_trim_dequeue_global_styles();
chk( 'global styles classic: dequeued', in_array( needle: 'global-styles', haystack: $GLOBALS['jht_dequeued_styles'] ?? array(), strict: true ) );
jht_perf_register( array( 'global_styles' => true, 'exempt_roles' => array( 'editor' ) ), $editor );
jpkcom_harden_trim_dequeue_global_styles();
chk( 'global styles: exempt editor untouched', array() === ( $GLOBALS['jht_dequeued_styles'] ?? array() ) );

// comment_reply and dashicons.

jht_perf_register( array( 'comment_reply' => true ) );
do_action( 'wp_print_scripts' );
chk( 'comment_reply: dequeued', array( 'comment-reply' ) === ( $GLOBALS['jht_dequeued_scripts'] ?? array() ) );
chk( 'comment_reply: also before footer printing', in_array( needle: 1, haystack: array_keys( array: $GLOBALS['jht_hooks']['wp_print_footer_scripts'] ?? array() ), strict: true ) );

jht_perf_register( array( 'dashicons' => true ) );
do_action( 'wp_print_styles' );
chk( 'dashicons: dequeued for visitors', array( 'dashicons' ) === ( $GLOBALS['jht_dequeued_styles'] ?? array() ) );
jht_perf_register( array( 'dashicons' => true ), array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) );
do_action( 'wp_print_styles' );
chk( 'dashicons: kept for logged-in users', array() === ( $GLOBALS['jht_dequeued_styles'] ?? array() ) );

// post_embeds.

jht_perf_register( array( 'post_embeds' => true ) );
chk( 'post_embeds: posts not embeddable', in_array( needle: '__return_false', haystack: jht_callbacks( 'is_post_embeddable' ), strict: true ) );
chk( 'post_embeds: discovery links removed @4', jht_was_removed( 'wp_head', 'wp_oembed_add_discovery_links', 4 ) );
chk( 'post_embeds: discovery links removed @10', jht_was_removed( 'wp_head', 'wp_oembed_add_discovery_links', 10 ) );
chk( 'post_embeds: host JS trigger removed', jht_was_removed( 'embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js' ) && jht_was_removed( 'wp_head', 'wp_oembed_add_host_js' ) );
chk( 'post_embeds: discovery of other sites off', in_array( needle: '__return_false', haystack: jht_callbacks( 'embed_oembed_discover' ), strict: true ) );
$jht_filtered = (string) apply_filters( 'embed_oembed_html', $core_embed_html, 'https://other.test/p/', array(), 1 );
chk( 'post_embeds: cached embed iframe stripped', str_contains( haystack: $core_embed_html, needle: '<iframe' ) && ! str_contains( haystack: $jht_filtered, needle: '<iframe' ) && str_contains( haystack: $jht_filtered, needle: '<blockquote' ) );
chk( 'post_embeds: oEmbed REST route kept', ! jht_was_removed( 'rest_api_init', 'wp_oembed_register_route' ) );
$jht_proxy = new WP_REST_Request( '/oembed/1.0/proxy' );
apply_filters( 'rest_pre_dispatch', null, null, $jht_proxy );
chk( 'post_embeds: editor proxy discovery off', false === ( $jht_proxy->params['discover'] ?? null ) );
$jht_other = new WP_REST_Request( '/wp/v2/posts' );
apply_filters( 'rest_pre_dispatch', null, null, $jht_other );
chk( 'post_embeds: other routes untouched', array() === $jht_other->params );
$GLOBALS['wp_query'] = jht_query_with_posts( array( 'is_single' => true, 'is_singular' => true ) );
do_action( 'template_redirect' );
chk( 'post_embeds: normal request not 404', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) && 1 === $GLOBALS['wp_query']->post_count );
$GLOBALS['wp_query']              = jht_query_with_posts( array( 'is_single' => true, 'is_singular' => true, 'is_embed' => true ) );
$GLOBALS['jht_state']['is_embed'] = true;
do_action( 'template_redirect' );
chk( 'post_embeds: /embed/ answers 404', true === $GLOBALS['wp_query']->is_404 && 404 === ( $GLOBALS['jht_status'] ?? null ) && true === ( $GLOBALS['jht_nocache'] ?? null ) );
// template-loader.php checks is_embed before is_404, and theme-compat/embed.php renders while have_posts().
chk( 'post_embeds: is_embed cleared, so the 404 template (not the embed card) renders', false === $GLOBALS['wp_query']->is_embed );
chk( 'post_embeds: queried posts cleared', array() === $GLOBALS['wp_query']->posts && 0 === $GLOBALS['wp_query']->post_count && null === $GLOBALS['wp_query']->post && null === $GLOBALS['wp_query']->queried_object );
chk( 'post_embeds: global $post and $posts cleared', null === ( $GLOBALS['post'] ?? null ) && array() === $GLOBALS['posts'] );
chk( 'post_embeds: no guessed canonical redirect for the 404', in_array( needle: '__return_false', haystack: jht_callbacks( 'redirect_canonical' ), strict: true ) );
unset( $GLOBALS['wp_query'], $GLOBALS['post'], $GLOBALS['posts'] );

// register_features: calls the performance registrar, tolerates missing group registrars.

jht_perf_setup();
jpkcom_harden_trim_register_features( jpkcom_harden_trim_settings() );
chk( 'register_features: performance registrar ran', array() !== jht_callbacks( 'pre_ping' ) );

// core_lock_respected (defines the snapshot constant: keep below every case that needs no lock).

define( constant_name: 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', value: array( 'WP_POST_REVISIONS' => 3 ) );
jht_perf_register( array( 'post_revisions' => 5 ) );
chk( 'core_lock_respected: no wp_revisions_to_keep filter', array() === jht_callbacks( 'wp_revisions_to_keep' ) );

// autosave_interval (defines AUTOSAVE_INTERVAL: keep last).

jht_perf_register( array( 'autosave_interval' => 300 ) );
chk( 'autosave_interval: constant defined from the setting', defined( constant_name: 'AUTOSAVE_INTERVAL' ) && 300 === constant( name: 'AUTOSAVE_INTERVAL' ) );
jht_perf_register( array( 'autosave_interval' => 600 ) );
chk( 'autosave_interval: an existing constant is never redefined', 300 === constant( name: 'AUTOSAVE_INTERVAL' ) );

// REST requests are not the front end (defines REST_REQUEST: keep last).

define( constant_name: 'REST_REQUEST', value: true );
jht_perf_register( array( 'block_library_css' => true, 'dashicons' => true, 'asset_version' => 'remove' ) );
$GLOBALS['jht_style_queue'] = array( 'wp-block-library' );
do_action( 'wp_print_styles' );
chk( 'REST: no front-end dequeues', array() === ( $GLOBALS['jht_dequeued_styles'] ?? array() ) );
chk( 'REST: asset versions untouched', 'https://ex.test/a.css?ver=1' === apply_filters( 'style_loader_src', 'https://ex.test/a.css?ver=1', 'a' ) );

summary();
