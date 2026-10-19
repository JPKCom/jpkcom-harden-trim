<?php
/**
 * Tests for the privacy features: the pure helpers (author enumeration,
 * users route, oEmbed author, IP anonymising, embed privacy, local avatar),
 * the hook callbacks (author 404, REST users, sitemap, Browse Happy, cover
 * block, oEmbed proxy) and the registration glue (defaults register exactly
 * the default-on privacy hooks; every opt-in adds its own).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_URL' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_URL', value: 'https://example.test/wp-content/plugins/jpkcom-harden-trim/' );
}

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/features.php', 'includes/features-privacy.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

// Stubs this suite needs on top of the bootstrap.

if ( ! function_exists( function: '__return_false' ) ) {
	function __return_false(): bool {
		return false;
	}
}

if ( ! function_exists( function: '__return_empty_string' ) ) {
	function __return_empty_string(): string {
		return '';
	}
}

if ( ! function_exists( function: 'status_header' ) ) {
	function status_header( int $code, string $description = '' ): void {
		$GLOBALS['jht_status'] = $code;
	}
}

if ( ! function_exists( function: 'nocache_headers' ) ) {
	function nocache_headers(): void {
		$GLOBALS['jht_nocache'] = true;
	}
}

if ( ! function_exists( function: 'wp_redirect' ) ) {
	function wp_redirect( string $location, int $status = 302, string $x_redirect_by = 'WordPress' ): bool {
		$GLOBALS['jht_redirect'] = $location;
		return true;
	}
}

if ( ! function_exists( function: 'remove_meta_box' ) ) {
	function remove_meta_box( string $id, $screen, string $context ): void {
		$GLOBALS['jht_meta_box_removed'][] = array( $id, $screen, $context );
	}
}

require_once __DIR__ . '/stubs-query.php';

if ( ! class_exists( class: 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private string $route;

		public function __construct( string $method = '', string $route = '' ) {
			$this->route = $route;
		}

		public function get_route(): string {
			return $this->route;
		}
	}
}

if ( ! class_exists( class: 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		public mixed $data;

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

if ( ! class_exists( class: 'WP_Font_Library' ) ) {
	/**
	 * Mirrors WP 7.1.3 wp-includes/fonts/class-wp-font-library.php: the
	 * collection map and is_collection_registered() are PRIVATE; only
	 * unregister_font_collection(), get_font_collections() and
	 * get_font_collection() are public. unregister_font_collection() raises
	 * _doing_it_wrong for a slug that is not registered.
	 */
	class WP_Font_Library {
		/** @var array<string, bool> */
		private array $collections = array();

		private static ?WP_Font_Library $instance = null;

		public static function get_instance(): WP_Font_Library {
			if ( null === self::$instance ) {
				self::$instance = new WP_Font_Library();
			}
			return self::$instance;
		}

		public function unregister_font_collection( string $slug ): bool {
			if ( ! $this->is_collection_registered( $slug ) ) {
				$GLOBALS['jht_doing_it_wrong'][] = __METHOD__ . ': ' . $slug;
				return false;
			}
			unset( $this->collections[ $slug ] );
			return true;
		}

		private function is_collection_registered( string $slug ): bool {
			return array_key_exists( key: $slug, array: $this->collections );
		}

		/** @return array<string, bool> */
		public function get_font_collections(): array {
			return $this->collections;
		}

		/**
		 * Test-only: stands in for core's register_font_collection(), which
		 * runs in _wp_register_default_font_collections() on init@10.
		 */
		public function jht_set_collections( array $collections ): void {
			$this->collections = $collections;
		}
	}
}

if ( ! function_exists( function: 'wp_unregister_font_collection' ) ) {
	function wp_unregister_font_collection( string $slug ): bool {
		return WP_Font_Library::get_instance()->unregister_font_collection( $slug );
	}
}

$jht_api = array(
	'jpkcom_harden_trim_register_privacy_features',
	'jpkcom_harden_trim_is_author_enum_request',
	'jpkcom_harden_trim_is_users_route',
	'jpkcom_harden_trim_anonymize_oembed',
	'jpkcom_harden_trim_anonymize_ip',
	'jpkcom_harden_trim_privacy_embed_html',
	'jpkcom_harden_trim_local_avatar_data',
);

$jht_missing = array_values( array: array_filter( array: $jht_api, callback: static fn( string $fn ): bool => ! function_exists( $fn ) ) );
chk( 'privacy API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

/**
 * Reset the stubs and store settings over the defaults.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @param array<string, mixed> $state    Overrides for $GLOBALS['jht_state'].
 * @return void
 */
function jht_pv_setup( array $settings = array(), array $state = array() ): void {
	jht_reset_wp();
	$GLOBALS['jht_state'] = array_merge( $GLOBALS['jht_state'], $state );
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	$GLOBALS['jht_hooks']['enqueue_block_editor_assets'][10][] = 'wp_enqueue_editor_block_directory_assets';
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * Priority a callback is registered at, or false.
 *
 * @param string $hook     Hook name.
 * @param string $callback Callback name.
 * @return int|false
 */
function jht_pv_priority( string $hook, string $callback ): int|false {
	foreach ( $GLOBALS['jht_hooks'][ $hook ] ?? array() as $priority => $list ) {
		if ( in_array( needle: $callback, haystack: $list, strict: true ) ) {
			return (int) $priority;
		}
	}
	return false;
}

/**
 * Whether anything is registered on a hook.
 *
 * @param string $hook Hook name.
 * @return bool
 */
function jht_pv_hooked( string $hook ): bool {
	foreach ( $GLOBALS['jht_hooks'][ $hook ] ?? array() as $list ) {
		if ( array() !== $list ) {
			return true;
		}
	}
	return false;
}

// author_enum.
$ae = 'jpkcom_harden_trim_is_author_enum_request';
chk( 'author_enum: pretty permalinks, logged out => true', true === $ae( array( 'author' => '1' ), false, '/%postname%/', false ) );
chk( 'author_enum: logged in => false', false === $ae( array( 'author' => '1' ), true, '/%postname%/', false ) );
chk( 'author_enum: plain permalinks => false', false === $ae( array( 'author' => '1' ), false, '', false ) );
chk( 'author_enum: admin => false', false === $ae( array( 'author' => '1' ), false, '/%postname%/', true ) );
chk( 'author_enum: empty author => false', false === $ae( array( 'author' => '' ), false, '/%postname%/', false ) );
chk( 'author_enum: author_name only => false', false === $ae( array( 'author_name' => 'x' ), false, '/%postname%/', false ) );
chk( 'author_enum: no query vars => false', false === $ae( array(), false, '/%postname%/', false ) );
chk( 'author_enum_exempt: no ActivityPub, AP Accept => not exempt', false === jpkcom_harden_trim_author_enum_exempt( array( 'author' => '1' ), 'application/activity+json', '/?author=1' ) );

// users_route.
foreach ( array( '/wp/v2/users', '/wp/v2/users/', '/wp/v2/users/1', '/wp/v2/users/me', '/WP/V2/USERS', '/wp/v2/users/me/application-passwords' ) as $r ) {
	chk( "users_route: {$r} => true", true === jpkcom_harden_trim_is_users_route( $r ) );
}
// Core matches routes with '@^' . $route . '$@i' (no D modifier): its `$` also matches before a
// trailing newline, so ?rest_route=/wp/v2/users%0A reaches the user list. The guard must match it too.
foreach ( array( "/wp/v2/users\n", "/wp/v2/users/1\n", "/WP/V2/USERS/me\n" ) as $r ) {
	chk( 'users_route: ' . json_encode( $r ) . ' (core serves it) => true', true === jpkcom_harden_trim_is_users_route( $r ) );
	chk( 'users_route: core matches ' . json_encode( $r ) . ' too', 1 === preg_match( '@^/wp/v2/users(?:/(?:[\d]+|me))?$@i', $r ) );
}
foreach ( array( '/wp/v2/usersx', '/wp/v2/posts', '/wp/v2/posts/users', '', '/' ) as $r ) {
	chk( "users_route: '{$r}' => false", false === jpkcom_harden_trim_is_users_route( $r ) );
}

// rest_users_dispatch.
jht_pv_setup();
$res = jpkcom_harden_trim_filter_rest_users( null, null, new WP_REST_Request( 'GET', '/wp/v2/users/1' ) );
chk( 'rest_users_dispatch: logged out => WP_Error', $res instanceof WP_Error );
chk( 'rest_users_dispatch: code rest_user_cannot_view', $res instanceof WP_Error && array( 'rest_user_cannot_view' ) === $res->get_error_codes() );
chk( 'rest_users_dispatch: status 401', $res instanceof WP_Error && array( 'status' => 401 ) === ( $res->error_data['rest_user_cannot_view'] ?? null ) );

$res = jpkcom_harden_trim_filter_rest_users( null, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
chk( 'rest_users_dispatch: other route => unchanged', null === $res );

$own = new WP_Error( 'rest_rate_limited', 'Slow down.', array( 'status' => 429 ) );
chk( 'rest_users_dispatch: earlier WP_Error kept', $own === jpkcom_harden_trim_filter_rest_users( $own, null, new WP_REST_Request( 'GET', '/wp/v2/users' ) ) );

$cached = new WP_REST_Response( array( array( 'id' => 1, 'name' => 'admin' ) ) );
chk( 'rest_users_dispatch: earlier non-error result replaced for logged out', jpkcom_harden_trim_filter_rest_users( $cached, null, new WP_REST_Request( 'GET', '/wp/v2/users' ) ) instanceof WP_Error );
chk( 'rest_users_dispatch: no request object => unchanged', 'x' === jpkcom_harden_trim_filter_rest_users( 'x', null, null ) );

jht_pv_setup( array(), array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) );
$marker = new WP_REST_Response( 'marker' );
chk( 'rest_users_dispatch: logged in => result unchanged', $marker === jpkcom_harden_trim_filter_rest_users( $marker, null, new WP_REST_Request( 'GET', '/wp/v2/users/1' ) ) );
chk( 'rest_users_dispatch: logged in null => null', null === jpkcom_harden_trim_filter_rest_users( null, null, new WP_REST_Request( 'GET', '/wp/v2/users/me' ) ) );

// oembed.
jht_pv_setup();
$o = jpkcom_harden_trim_anonymize_oembed( array( 'author_name' => 'Jane', 'author_url' => 'https://site.test/author/jane/', 'title' => 'Hello' ) );
chk( 'oembed: author_name => blog name', 'Example Site' === $o['author_name'] );
chk( 'oembed: author_url => home_url(/)', 'https://example.test/' === $o['author_url'] );
chk( 'oembed: other keys kept', 'Hello' === $o['title'] );
chk( 'oembed: missing keys not added', array( 'title' => 'x' ) === jpkcom_harden_trim_anonymize_oembed( array( 'title' => 'x' ) ) );
chk( 'oembed glue: non-array passthrough', false === jpkcom_harden_trim_filter_oembed_response_data( false ) );

// ip.
chk( 'ip: IPv4 last octet zeroed', '203.0.113.0' === jpkcom_harden_trim_anonymize_ip( '203.0.113.77' ) );
chk( 'ip: IPv6 /48', '2001:db8:1234::' === jpkcom_harden_trim_anonymize_ip( '2001:db8:1234:5678::1' ) );
chk( 'ip: IPv6 full form /48', '2001:db8:abcd::' === jpkcom_harden_trim_anonymize_ip( '2001:0db8:abcd:0012:0000:0000:0000:0001' ) );
chk( 'ip: invalid => empty', '' === jpkcom_harden_trim_anonymize_ip( 'not-an-ip' ) );
chk( 'ip: empty => empty', '' === jpkcom_harden_trim_anonymize_ip( '' ) );
chk( 'ip: IPv4 with port is invalid => empty', '' === jpkcom_harden_trim_anonymize_ip( '203.0.113.77:8080' ) );
// IPv4-mapped IPv6 (dual-stack listeners): the embedded IPv4 is anonymised to its /24.
chk( 'ip: IPv4-mapped IPv6 => IPv4 /24', '203.0.113.0' === jpkcom_harden_trim_anonymize_ip( '::ffff:203.0.113.77' ) );
chk( 'ip: IPv4-mapped IPv6, upper case => IPv4 /24', '203.0.113.0' === jpkcom_harden_trim_anonymize_ip( '::FFFF:203.0.113.77' ) );
chk( 'ip: IPv4-mapped IPv6 in hex form => IPv4 /24', '203.0.113.0' === jpkcom_harden_trim_anonymize_ip( '::ffff:cb00:714d' ) );
chk( 'ip: plain IPv6 still /48', '2001:db8:1234::' === jpkcom_harden_trim_anonymize_ip( '2001:db8:1234:ffff::1' ) );
// Core's sanitiser (comment.php:2424) keeps commas and spaces: a proxy list anonymises its first address.
chk( 'ip: comma-separated list => first address anonymised', '1.2.3.0' === jpkcom_harden_trim_anonymize_ip( '1.2.3.4, 5.6.7.8' ) );
chk( 'ip: list starting with IPv6 => /48 of the first', '2001:db8:1234::' === jpkcom_harden_trim_anonymize_ip( '2001:db8:1234:5678::1,1.2.3.4' ) );
chk( 'ip: list with an invalid first entry => first valid one', '5.6.7.0' === jpkcom_harden_trim_anonymize_ip( 'unknown, 5.6.7.8' ) );
chk( 'ip: list without a valid address => empty', '' === jpkcom_harden_trim_anonymize_ip( 'a, b' ) );

// embed_privacy.
$yt  = '<iframe title="Video" width="500" height="281" src="https://www.youtube.com/embed/abc123?feature=oembed" frameborder="0" allowfullscreen></iframe>';
$out = jpkcom_harden_trim_privacy_embed_html( $yt );
chk( 'embed_privacy: www.youtube.com rewritten', str_contains( haystack: $out, needle: 'src="https://www.youtube-nocookie.com/embed/abc123?feature=oembed"' ) );
chk( 'embed_privacy: rest of tag kept', str_replace( search: 'www.youtube-nocookie.com', replace: 'www.youtube.com', subject: $out ) === $yt );

$out = jpkcom_harden_trim_privacy_embed_html( '<iframe src="https://youtube.com/embed/abc123"></iframe>' );
chk( 'embed_privacy: bare youtube.com rewritten', '<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>' === $out );

$out = jpkcom_harden_trim_privacy_embed_html( "<IFRAME SRC='//www.youtube.com/embed/abc'></IFRAME>" );
chk( 'embed_privacy: protocol-relative, single quotes, upper case', "<IFRAME SRC='//www.youtube-nocookie.com/embed/abc'></IFRAME>" === $out );

$m = '<iframe src="https://m.youtube.com/embed/abc"></iframe>';
chk( 'embed_privacy: other youtube subdomain untouched', $m === jpkcom_harden_trim_privacy_embed_html( $m ) );

$nc = '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>';
chk( 'embed_privacy: already nocookie => idempotent', $nc === jpkcom_harden_trim_privacy_embed_html( $nc ) );

$link = '<p><a href="https://www.youtube.com/embed/abc">link</a></p>';
chk( 'embed_privacy: links outside iframes untouched', $link === jpkcom_harden_trim_privacy_embed_html( $link ) );

$out = jpkcom_harden_trim_privacy_embed_html( '<iframe src="https://player.vimeo.com/video/76979871?h=abc" width="640"></iframe>' );
chk( 'embed_privacy: vimeo ?h=abc => &dnt=1', '<iframe src="https://player.vimeo.com/video/76979871?h=abc&dnt=1" width="640"></iframe>' === $out );

$out = jpkcom_harden_trim_privacy_embed_html( '<iframe src="https://player.vimeo.com/video/76979871"></iframe>' );
chk( 'embed_privacy: vimeo without query => ?dnt=1', '<iframe src="https://player.vimeo.com/video/76979871?dnt=1"></iframe>' === $out );

$out = jpkcom_harden_trim_privacy_embed_html( '<iframe src="https://player.vimeo.com/video/1?h=a&amp;app_id=1"></iframe>' );
chk( 'embed_privacy: vimeo with &amp; => &amp;dnt=1', '<iframe src="https://player.vimeo.com/video/1?h=a&amp;app_id=1&amp;dnt=1"></iframe>' === $out );

$out = jpkcom_harden_trim_privacy_embed_html( '<iframe src="https://player.vimeo.com/video/1?h=a#t=30s"></iframe>' );
chk( 'embed_privacy: vimeo fragment stays last', '<iframe src="https://player.vimeo.com/video/1?h=a&dnt=1#t=30s"></iframe>' === $out );

foreach ( array(
	'<iframe src="https://player.vimeo.com/video/1?h=abc&dnt=1"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?dnt=1"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?h=a&#038;dnt=1"></iframe>',
) as $i => $v ) {
	chk( "embed_privacy: vimeo with dnt=1 unchanged #{$i}", $v === jpkcom_harden_trim_privacy_embed_html( $v ) );
}
// A dnt value other than 1 (tracking on) is forced to dnt=1, in the src's own escaping.
foreach ( array(
	'<iframe src="https://player.vimeo.com/video/1?h=a&amp;dnt=0"></iframe>'    => '<iframe src="https://player.vimeo.com/video/1?h=a&amp;dnt=1"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?dnt=0&h=a"></iframe>'        => '<iframe src="https://player.vimeo.com/video/1?dnt=1&h=a"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?h=a&#038;DNT=false"></iframe>' => '<iframe src="https://player.vimeo.com/video/1?h=a&#038;dnt=1"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?dnt=&h=a#t=3"></iframe>'     => '<iframe src="https://player.vimeo.com/video/1?dnt=1&h=a#t=3"></iframe>',
	'<iframe src="https://player.vimeo.com/video/1?h=a&adnt=0"></iframe>'       => '<iframe src="https://player.vimeo.com/video/1?h=a&adnt=0&dnt=1"></iframe>',
) as $v => $want ) {
	chk( 'embed_privacy: vimeo ' . $v . ' => dnt=1', $want === jpkcom_harden_trim_privacy_embed_html( $v ) );
	chk( 'embed_privacy: vimeo dnt rewrite is idempotent for ' . $v, $want === jpkcom_harden_trim_privacy_embed_html( $want ) );
}

$other = '<iframe src="https://example.org/embed/x?y=1"></iframe><iframe src="https://www.dailymotion.com/embed/video/x"></iframe>';
chk( 'embed_privacy: unrelated iframe unchanged', $other === jpkcom_harden_trim_privacy_embed_html( $other ) );
chk( 'embed_privacy: no iframe unchanged', 'plain text' === jpkcom_harden_trim_privacy_embed_html( 'plain text' ) );
chk( 'embed_privacy: second pass is a no-op', jpkcom_harden_trim_privacy_embed_html( $yt . $out ) === jpkcom_harden_trim_privacy_embed_html( jpkcom_harden_trim_privacy_embed_html( $yt . $out ) ) );
chk( 'embed_privacy glue: non-string passthrough', false === jpkcom_harden_trim_filter_embed_privacy( false ) );

// Cover block with an embedded background video (core builds its own iframe from wp_oembed_get()).
$cover = '<div class="wp-block-cover"><div class="wp-block-cover__video-background wp-block-cover__embed-background"><iframe src="https://www.youtube.com/embed/abc?autoplay=1&#038;mute=1" title="Background video"></iframe></div></div>';
$out   = jpkcom_harden_trim_filter_cover_embed( $cover, array( 'blockName' => 'core/cover', 'attrs' => array( 'backgroundType' => 'embed-video' ) ) );
chk( 'cover: embed-video background rewritten', str_contains( haystack: $out, needle: 'https://www.youtube-nocookie.com/embed/abc?autoplay=1&#038;mute=1' ) );
chk( 'cover: image background untouched', $cover === jpkcom_harden_trim_filter_cover_embed( $cover, array( 'blockName' => 'core/cover', 'attrs' => array( 'backgroundType' => 'image' ) ) ) );

// oEmbed proxy (block editor previews).
$proxy = new WP_REST_Response( (object) array( 'html' => $yt, 'provider_name' => 'YouTube' ) );
$back  = jpkcom_harden_trim_filter_oembed_proxy_response( $proxy, null, new WP_REST_Request( 'GET', '/oembed/1.0/proxy' ) );
chk( 'proxy: html rewritten', $back instanceof WP_REST_Response && str_contains( haystack: (string) $back->get_data()->html, needle: 'youtube-nocookie.com/embed/abc123' ) );
$proxy = new WP_REST_Response( array( 'html' => $yt ) );
$back  = jpkcom_harden_trim_filter_oembed_proxy_response( $proxy, null, new WP_REST_Request( 'GET', '/oembed/1.0/proxy' ) );
chk( 'proxy: array data rewritten', str_contains( haystack: (string) $back->get_data()['html'], needle: 'youtube-nocookie.com' ) );
$other_route = new WP_REST_Response( array( 'html' => $yt ) );
jpkcom_harden_trim_filter_oembed_proxy_response( $other_route, null, new WP_REST_Request( 'GET', '/wp/v2/posts' ) );
chk( 'proxy: other route untouched', $yt === $other_route->get_data()['html'] );
chk( 'proxy: non-response passthrough', 'x' === jpkcom_harden_trim_filter_oembed_proxy_response( 'x', null, new WP_REST_Request( 'GET', '/oembed/1.0/proxy' ) ) );

// local avatar.
$a = jpkcom_harden_trim_local_avatar_data( array( 'size' => 96, 'default' => 'mystery' ) );
chk( 'avatar: url is the plugin svg', JPKCOM_HARDEN_TRIM_URL . 'assets/avatar.svg' === $a['url'] );
chk( 'avatar: found_avatar true', true === $a['found_avatar'] );
chk( 'avatar: other args kept', 96 === $a['size'] );
chk( 'avatar glue: url from an earlier filter kept', array( 'url' => 'https://example.test/me.png' ) === jpkcom_harden_trim_filter_avatar_data( array( 'url' => 'https://example.test/me.png' ) ) );
chk( 'avatar glue: gravatar replaced', JPKCOM_HARDEN_TRIM_URL . 'assets/avatar.svg' === jpkcom_harden_trim_filter_avatar_data( array( 'size' => 32 ) )['url'] );

$svg = (string) file_get_contents( filename: $root . '/assets/avatar.svg' );
chk( 'avatar svg: exists and is svg', str_starts_with( haystack: ltrim( string: $svg ), needle: '<svg' ) );
chk( 'avatar svg: viewBox 0 0 96 96', str_contains( haystack: $svg, needle: 'viewBox="0 0 96 96"' ) );
chk( 'avatar svg: colours', str_contains( haystack: $svg, needle: '#c3c4c7' ) && str_contains( haystack: $svg, needle: '#f0f0f1' ) );
chk( 'avatar svg: no external references', 0 === preg_match( '/(?:href|src)\s*=|url\(|<script|@import/i', $svg ) && 1 === substr_count( haystack: $svg, needle: 'http' ) && str_contains( haystack: $svg, needle: 'xmlns="http://www.w3.org/2000/svg"' ) );

// Author 404 glue.
jht_pv_setup( array(), array() );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']       = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query'] = new WP_Query();
$_SERVER['HTTP_ACCEPT'] = 'text/html';
jpkcom_harden_trim_block_author_enum();
chk( 'author_404: wp_query set to 404', true === $GLOBALS['wp_query']->is_404 );
chk( 'author_404: status 404', 404 === ( $GLOBALS['jht_status'] ?? null ) );
chk( 'author_404: no-cache headers', true === ( $GLOBALS['jht_nocache'] ?? null ) );
chk( 'author_404: no redirect', ! isset( $GLOBALS['jht_redirect'] ) );

// The probe's query is emptied: no author posts in a fallback index template, no author feed.
foreach ( array( 'html' => array(), 'rss2 feed' => array( 'feed' => 'rss2' ), 'atom feed' => array( 'feed' => 'atom' ) ) as $pv_case => $pv_extra ) {
	jht_pv_setup();
	$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
	$GLOBALS['wp']          = (object) array( 'query_vars' => array_merge( array( 'author' => '1' ), $pv_extra ) );
	$GLOBALS['wp_query']    = jht_query_with_posts( array( 'is_author' => true, 'is_archive' => true, 'is_feed' => array() !== $pv_extra ) );
	$GLOBALS['authordata']  = new WP_User( 1, array( 'administrator' ) );
	$_SERVER['HTTP_ACCEPT'] = 'text/html';
	jpkcom_harden_trim_block_author_enum();
	$pv_q = $GLOBALS['wp_query'];
	chk( "author_404 ({$pv_case}): 404 and status", true === $pv_q->is_404 && 404 === ( $GLOBALS['jht_status'] ?? null ) );
	// template-loader.php runs do_feed() while is_feed() is true, before any template, and set_404() keeps is_feed.
	chk( "author_404 ({$pv_case}): is_feed cleared, no feed is rendered", false === $pv_q->is_feed && false === $pv_q->is_comment_feed );
	chk( "author_404 ({$pv_case}): author posts cleared", array() === $pv_q->posts && 0 === $pv_q->post_count && null === $pv_q->post && 0 === $pv_q->found_posts );
	chk( "author_404 ({$pv_case}): queried object (the author) cleared", null === $pv_q->queried_object && null === $pv_q->queried_object_id );
	chk( "author_404 ({$pv_case}): global post and authordata cleared", null === ( $GLOBALS['post'] ?? null ) && ! isset( $GLOBALS['authordata'] ) );
}
unset( $GLOBALS['wp'], $GLOBALS['wp_query'], $GLOBALS['post'], $GLOBALS['posts'], $_SERVER['HTTP_ACCEPT'] );

// Pretty author archive: WP_Query fills its own `author` from author_name, the parsed request does not.
jht_pv_setup();
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']       = (object) array( 'query_vars' => array( 'author_name' => 'jane' ) );
$GLOBALS['wp_query'] = new WP_Query();
jpkcom_harden_trim_block_author_enum();
chk( 'author_404: pretty author archive untouched', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) );

jht_pv_setup( array(), array( 'logged_in' => true, 'roles' => array( 'subscriber' ) ) );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']       = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query'] = new WP_Query();
jpkcom_harden_trim_block_author_enum();
chk( 'author_404: logged in untouched', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) );
unset( $GLOBALS['wp'], $GLOBALS['wp_query'], $_SERVER['HTTP_ACCEPT'] );
// Accept header bypass: without the ActivityPub plugin an ActivityPub Accept header changes nothing.
foreach ( array( 'application/activity+json', 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"' ) as $pv_accept ) {
	jht_pv_setup();
	$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
	$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'author' => '1' ) );
	$GLOBALS['wp_query']    = new WP_Query();
	$_SERVER['HTTP_ACCEPT'] = $pv_accept;
	jpkcom_harden_trim_block_author_enum();
	chk( "author_404: no ActivityPub plugin, Accept {$pv_accept} => still 404", true === $GLOBALS['wp_query']->is_404 && 404 === ( $GLOBALS['jht_status'] ?? null ) );
}
unset( $GLOBALS['wp'], $GLOBALS['wp_query'], $_SERVER['HTTP_ACCEPT'] );

// A forced 404 on a feed request: WP::send_headers() already sent the feed Content-Type, so the
// HTML 404 template would go out as application/rss+xml. Real headers need a real SAPI: the CLI
// keeps no header list, so the fixture runs under the built-in web server.

/**
 * Serve tests/fixture-feed-404.php with `php -S` and fetch it once per query.
 *
 * @param list<string> $queries Query strings.
 * @return array<string, array{status: string, type: string, body: string}> Per query; empty when the server did not start.
 */
function jht_pv_serve_feed_404( array $queries ): array {
	$probe = stream_socket_server( 'tcp://127.0.0.1:0', $errno, $errstr );
	if ( false === $probe ) {
		return array();
	}
	$port = (int) substr( string: (string) strrchr( haystack: (string) stream_socket_get_name( socket: $probe, remote: false ), needle: ':' ), offset: 1 );
	fclose( stream: $probe );

	// An argument list, not a string: no shell in between, so proc_terminate() reaches the server itself.
	$cmd  = array( PHP_BINARY, '-d', 'error_reporting=-1', '-d', 'display_errors=1', '-d', 'output_buffering=0', '-S', '127.0.0.1:' . $port, __DIR__ . '/fixture-feed-404.php' );
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
			foreach ( $headers as $line ) {
				if ( 0 === stripos( haystack: (string) $line, needle: 'content-type:' ) ) {
					$type = trim( string: substr( string: (string) $line, offset: 13 ) );
				}
			}
			$out[ $query ] = array( 'status' => (string) ( $headers[0] ?? '' ), 'type' => $type, 'body' => (string) $body );
		}
	} finally {
		proc_terminate( process: $proc );
		proc_close( process: $proc );
	}
	return $out;
}

$pv_served = jht_pv_serve_feed_404( array( 'feed=1', 'feed=1&charset=ISO-8859-1', 'feed=0', 'feed=1&early=1' ) );
chk( 'feed 404: built-in server answered', 4 === count( $pv_served ) );
$pv_r = $pv_served['feed=1'] ?? array( 'status' => '', 'type' => '', 'body' => '' );
chk( 'feed 404: status 404 (' . $pv_r['status'] . ')', str_contains( haystack: $pv_r['status'], needle: ' 404' ) );
chk( 'feed 404: Content-Type text/html; charset=UTF-8, not the feed type (got ' . $pv_r['type'] . ')', 'text/html; charset=UTF-8' === $pv_r['type'] );
chk( 'feed 404: HTML 404 body, no PHP notice', str_contains( haystack: $pv_r['body'], needle: 'is_404=1' ) && ! preg_match( '/(Warning|Notice|Deprecated|Fatal)/', $pv_r['body'] ) );
$pv_r = $pv_served['feed=1&charset=ISO-8859-1'] ?? array( 'type' => '' );
chk( 'feed 404: blog charset used (got ' . $pv_r['type'] . ')', 'text/html; charset=ISO-8859-1' === $pv_r['type'] );
$pv_r = $pv_served['feed=0'] ?? array( 'type' => '', 'body' => '' );
chk( 'feed 404: non-feed request keeps its Content-Type', 'text/html; charset=UTF-8' === $pv_r['type'] && str_contains( haystack: $pv_r['body'], needle: 'is_404=1' ) );
$pv_r = $pv_served['feed=1&early=1'] ?? array( 'type' => '', 'body' => '' );
chk( 'feed 404: headers already sent => no header() call, no warning (got ' . $pv_r['type'] . ')', 'application/rss+xml; charset=UTF-8' === $pv_r['type'] && ! preg_match( '/(Warning|Notice|Cannot modify)/', $pv_r['body'] ) );

// Sitemap, Browse Happy, events, comment IP glue.
chk( 'sitemap: users provider dropped', false === jpkcom_harden_trim_filter_sitemap_provider( 'provider', 'users' ) );
chk( 'sitemap: posts provider kept', 'provider' === jpkcom_harden_trim_filter_sitemap_provider( 'provider', 'posts' ) );

chk( 'browse_happy: https url matched', jpkcom_harden_trim_filter_browse_happy( false, array(), 'https://api.wordpress.org/core/browse-happy/1.1/' ) instanceof WP_Error );
chk( 'browse_happy: http url matched', jpkcom_harden_trim_filter_browse_happy( false, array(), 'http://api.wordpress.org/core/browse-happy/1.1/' ) instanceof WP_Error );
chk( 'browse_happy: serve happy untouched', false === jpkcom_harden_trim_filter_browse_happy( false, array(), 'https://api.wordpress.org/core/serve-happy/1.0/' ) );
chk( 'browse_happy: other host untouched', false === jpkcom_harden_trim_filter_browse_happy( false, array(), 'https://evil.test/api.wordpress.org/core/browse-happy/' ) );
chk( 'browse_happy: earlier short-circuit kept', 'pre' === jpkcom_harden_trim_filter_browse_happy( 'pre', array(), 'https://api.wordpress.org/core/browse-happy/1.1/' ) );

jht_pv_setup();
jpkcom_harden_trim_remove_events_widget();
chk( 'events: dashboard_primary removed from dashboard side', array( array( 'dashboard_primary', 'dashboard', 'side' ) ) === ( $GLOBALS['jht_meta_box_removed'] ?? null ) );

jht_pv_setup( array( 'comment_ip' => true ) );
chk( 'comment_ip glue: non-string => empty', '' === jpkcom_harden_trim_filter_comment_ip( null ) );
chk( 'comment_ip glue: anonymises', '198.51.100.0' === jpkcom_harden_trim_filter_comment_ip( '198.51.100.23' ) );
jht_pv_setup();
chk( 'comment_ip glue: setting off on this site => IP unchanged', '198.51.100.23' === jpkcom_harden_trim_filter_comment_ip( '198.51.100.23' ) );

// Editor remote glue.
$es = jpkcom_harden_trim_filter_editor_settings( array( 'foo' => 1 ) );
chk( 'editor_remote: openverse off', false === $es['enableOpenverseMediaCategory'] && 1 === $es['foo'] );
chk( 'editor_remote: settings non-array passthrough', 'x' === jpkcom_harden_trim_filter_editor_settings( 'x' ) );
jht_pv_setup();
unset( $GLOBALS['jht_doing_it_wrong'] );
WP_Font_Library::get_instance()->jht_set_collections( array( 'google-fonts' => true, 'other' => true ) );
$jht_font_error = null;
try {
	jpkcom_harden_trim_unregister_google_fonts();
} catch ( Error $e ) {
	$jht_font_error = $e->getMessage();
}
chk( 'editor_remote: unregister does not fatal (private core API)', null === $jht_font_error );
chk( 'editor_remote: google-fonts unregistered, other kept', array( 'other' => true ) === WP_Font_Library::get_instance()->get_font_collections() );
chk( 'editor_remote: registered path no _doing_it_wrong', ! isset( $GLOBALS['jht_doing_it_wrong'] ) );
jpkcom_harden_trim_unregister_google_fonts();
chk( 'editor_remote: second call no _doing_it_wrong', ! isset( $GLOBALS['jht_doing_it_wrong'] ) );
WP_Font_Library::get_instance()->jht_set_collections( array() );
jpkcom_harden_trim_unregister_google_fonts();
chk( 'editor_remote: removed by another plugin => no _doing_it_wrong', ! isset( $GLOBALS['jht_doing_it_wrong'] ) );

// defaults_registration.
jht_pv_setup();
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'defaults: author enum on template_redirect@0', 0 === jht_pv_priority( 'template_redirect', 'jpkcom_harden_trim_block_author_enum' ) );
chk( 'defaults: rest users on rest_pre_dispatch', false !== jht_pv_priority( 'rest_pre_dispatch', 'jpkcom_harden_trim_filter_rest_users' ) );
chk( 'defaults: oembed_response_data@20', 20 === jht_pv_priority( 'oembed_response_data', 'jpkcom_harden_trim_filter_oembed_response_data' ) );
chk( 'defaults: sitemap provider filter', false !== jht_pv_priority( 'wp_sitemaps_add_provider', 'jpkcom_harden_trim_filter_sitemap_provider' ) );
chk( 'defaults: browse happy on pre_http_request', false !== jht_pv_priority( 'pre_http_request', 'jpkcom_harden_trim_filter_browse_happy' ) );
chk( 'defaults: no avatar hook', ! jht_pv_hooked( 'pre_get_avatar_data' ) );
chk( 'defaults: no comment ip hook', ! jht_pv_hooked( 'pre_comment_user_ip' ) );
chk( 'defaults: no comment agent hook', ! jht_pv_hooked( 'pre_comment_user_agent' ) );
chk( 'defaults: no events hook', ! jht_pv_hooked( 'wp_dashboard_setup' ) );
chk( 'defaults: no embed privacy hooks', ! jht_pv_hooked( 'embed_oembed_html' ) && ! jht_pv_hooked( 'render_block_core/cover' ) && ! jht_pv_hooked( 'rest_post_dispatch' ) );
chk( 'defaults: no editor remote hooks', ! jht_pv_hooked( 'should_load_remote_block_patterns' ) && ! jht_pv_hooked( 'block_editor_settings_all' ) && ! jht_pv_hooked( 'init' ) );
chk( 'defaults: block directory assets kept', false !== jht_pv_priority( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' ) );
chk( 'defaults: no AI hook', ! jht_pv_hooked( 'wp_supports_ai' ) );

// All default-on privacy switches off: nothing hooked.
jht_pv_setup( array( 'block_author_enum' => false, 'hide_rest_users' => false, 'oembed_author' => false, 'sitemap_users' => false, 'browse_happy' => false ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
$hooked = array_keys( array: array_filter( array: $GLOBALS['jht_hooks'], callback: static fn( array $p ): bool => array() !== array_filter( array: $p ) ) );
chk( 'privacy_off: no hooks at all (got: ' . implode( separator: ', ', array: $hooked ) . ')', array( 'enqueue_block_editor_assets' ) === $hooked );

// Every opt-in on.
jht_pv_setup( array( 'dashboard_events' => true, 'local_avatars' => true, 'comment_ip' => true, 'embed_privacy' => true, 'editor_remote' => true, 'ai_features' => true ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'opt_in: events on wp_dashboard_setup', false !== jht_pv_priority( 'wp_dashboard_setup', 'jpkcom_harden_trim_remove_events_widget' ) );
chk( 'opt_in: avatar on pre_get_avatar_data', false !== jht_pv_priority( 'pre_get_avatar_data', 'jpkcom_harden_trim_filter_avatar_data' ) );
chk( 'opt_in: comment ip', false !== jht_pv_priority( 'pre_comment_user_ip', 'jpkcom_harden_trim_filter_comment_ip' ) );
chk( 'opt_in: comment agent emptied', '' === apply_filters( 'pre_comment_user_agent', 'Mozilla/5.0' ) );
chk( 'opt_in: embed_oembed_html', false !== jht_pv_priority( 'embed_oembed_html', 'jpkcom_harden_trim_filter_embed_privacy' ) );
chk( 'opt_in: cover block', false !== jht_pv_priority( 'render_block_core/cover', 'jpkcom_harden_trim_filter_cover_embed' ) );
chk( 'opt_in: oembed proxy', false !== jht_pv_priority( 'rest_post_dispatch', 'jpkcom_harden_trim_filter_oembed_proxy_response' ) );
chk( 'opt_in: block directory assets removed', false === jht_pv_priority( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' ) );
chk( 'opt_in: remote patterns off', false === apply_filters( 'should_load_remote_block_patterns', true ) );
chk( 'opt_in: openverse off', false === apply_filters( 'block_editor_settings_all', array() )['enableOpenverseMediaCategory'] );
chk( 'opt_in: google fonts after core (init > 10)', ( jht_pv_priority( 'init', 'jpkcom_harden_trim_unregister_google_fonts' ) ?: 0 ) > 10 );
chk( 'opt_in: AI off', false === apply_filters( 'wp_supports_ai', true ) );

// comment_ip and the flood check: core compares stored rows with the live IP (comment.php:925-936),
// so with anonymised rows the IP clause never matches. The plugin repeats the check with the stored form.
if ( ! function_exists( function: 'wp_check_comment_flood' ) ) {
	function wp_check_comment_flood( $is_flood, $ip, $email, $date, $avoid_die = false ) {
		$GLOBALS['jht_flood_calls'][] = array( $ip, $email, $avoid_die );
		return in_array( needle: $ip, haystack: $GLOBALS['jht_flood_rows'] ?? array(), strict: true );
	}
}
jht_pv_setup( array( 'comment_ip' => true ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'comment flood: callback on wp_is_comment_flood after core (priority > 10)', ( jht_pv_priority( 'wp_is_comment_flood', 'jpkcom_harden_trim_filter_comment_flood' ) ?: 0 ) > 10 );
$GLOBALS['jht_hooks']['wp_is_comment_flood'][10][] = 'wp_check_comment_flood';
$GLOBALS['jht_flood_rows'] = array( '203.0.113.0' );
chk( 'comment flood: guest from the stored /24 within the interval is a flood', true === apply_filters( 'wp_is_comment_flood', false, '203.0.113.77', 'new@example.test', '2026-10-19 12:00:00', true ) );
chk( 'comment flood: the repeat check used the anonymised IP', array( '203.0.113.0', 'new@example.test', true ) === ( $GLOBALS['jht_flood_calls'][1] ?? null ) );
$GLOBALS['jht_flood_calls'] = array();
$GLOBALS['jht_flood_rows']  = array();
chk( 'comment flood: no stored row => no flood', false === apply_filters( 'wp_is_comment_flood', false, '198.51.100.9', 'x@example.test', '2026-10-19 12:00:00', true ) );
$GLOBALS['jht_flood_calls'] = array();
chk( 'comment flood: earlier flood verdict kept, no extra query', true === jpkcom_harden_trim_filter_comment_flood( true, '203.0.113.77', 'a@example.test', '2026-10-19 12:00:00', true ) && array() === ( $GLOBALS['jht_flood_calls'] ?? array() ) );
unset( $GLOBALS['jht_hooks']['wp_is_comment_flood'][10] );
chk( 'comment flood: core check removed by the site => nothing re-added', false === jpkcom_harden_trim_filter_comment_flood( false, '203.0.113.77', 'a@example.test', '2026-10-19 12:00:00', true ) && array() === ( $GLOBALS['jht_flood_calls'] ?? array() ) );
$GLOBALS['jht_hooks']['wp_is_comment_flood'][10][] = 'wp_check_comment_flood';
$GLOBALS['jht_state']['logged_in'] = true;
chk( 'comment flood: logged-in users are checked by user ID in core, no repeat', false === jpkcom_harden_trim_filter_comment_flood( false, '203.0.113.77', 'a@example.test', '2026-10-19 12:00:00', true ) && array() === ( $GLOBALS['jht_flood_calls'] ?? array() ) );
$GLOBALS['jht_state']['logged_in'] = false;
chk( 'comment flood: already anonymised IP is not checked twice', false === jpkcom_harden_trim_filter_comment_flood( false, '203.0.113.0', 'a@example.test', '2026-10-19 12:00:00', true ) && array() === ( $GLOBALS['jht_flood_calls'] ?? array() ) );
jht_pv_setup();
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'comment flood: not hooked while comment_ip is off', false === jht_pv_priority( 'wp_is_comment_flood', 'jpkcom_harden_trim_filter_comment_flood' ) );

// Multisite: comment_ip stores data, so it follows the current site after switch_to_blog().
jht_pv_setup( array( 'comment_ip' => true ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'comment_ip multisite: booting site anonymises', '203.0.113.0' === apply_filters( 'pre_comment_user_ip', '203.0.113.77' ) && '' === apply_filters( 'pre_comment_user_agent', 'Mozilla/5.0' ) );
jht_switch_site( 2 );
chk( 'comment_ip multisite: switched site without the setting keeps the IP', '203.0.113.77' === apply_filters( 'pre_comment_user_ip', '203.0.113.77' ) );
chk( 'comment_ip multisite: switched site keeps the user agent', 'Mozilla/5.0' === apply_filters( 'pre_comment_user_agent', 'Mozilla/5.0' ) );
jht_switch_site( 1 );

// Global: an exempt role changes nothing (privacy features are not role-scoped).
jht_pv_setup( array( 'exempt_roles' => array( 'editor' ) ), array( 'logged_in' => true, 'roles' => array( 'editor' ) ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'global: exempt editor still gets rest users hook', false !== jht_pv_priority( 'rest_pre_dispatch', 'jpkcom_harden_trim_filter_rest_users' ) );

// ai_features with WP_AI_SUPPORT from wp-config.php: core wins (defined last, constants are per process).
define( constant_name: 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', value: array( 'WP_AI_SUPPORT' => true ) );
jht_pv_setup( array( 'ai_features' => true ) );
jpkcom_harden_trim_register_privacy_features( jpkcom_harden_trim_settings() );
chk( 'ai_locked: no wp_supports_ai filter', ! jht_pv_hooked( 'wp_supports_ai' ) );

// Filter: a site may exempt probes itself (default stays the ActivityPub detection).
jht_pv_setup();
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query']    = new WP_Query();
$_SERVER['HTTP_ACCEPT'] = 'text/html';
add_filter( 'jpkcom_harden_trim_author_enum_exempt', static fn( bool $exempt, array $query, string $accept ): bool => true, 10, 3 );
jpkcom_harden_trim_block_author_enum();
chk( 'author_enum_exempt filter: true lets the probe through', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) );
jht_pv_setup();
add_filter( 'jpkcom_harden_trim_author_enum_exempt', static fn(): string => 'yes' );
chk( 'author_enum_exempt filter: only a real true exempts', false === jpkcom_harden_trim_author_enum_exempt( array( 'author' => '1' ), 'text/html' ) );

// ActivityPub: the probe is exempt only when ActivityPub itself answers THIS request with the
// actor document. Its own API decides (stubs-activitypub.php mirrors 9.3.1); anything it would
// not answer gets the 404, because core would otherwise redirect to /author/<nicename>/ or the
// theme would render the author archive. Loaded last: functions and constants are per process.

if ( ! function_exists( function: 'wp_normalize_path' ) ) {
	function wp_normalize_path( string $path ): string {
		return (string) preg_replace( '|(?<=.)/+|', '/', str_replace( search: '\\', replace: '/', subject: $path ) );
	}
}

if ( ! function_exists( function: 'get_query_template' ) ) {
	function get_query_template( string $type, array $templates = array() ): string {
		return '/var/www/html/wp-content/themes/t/' . $type . '.php';
	}
}

/**
 * Run the author probe with ActivityPub inputs.
 *
 * @param string               $uri    REQUEST_URI.
 * @param string               $accept Accept header.
 * @param array<string, mixed> $vars   Parsed query vars ($wp->query_vars).
 * @param string|null          $host   Host header (HTTP_HOST); null: none sent.
 * @param bool                 $ssl    Whether is_ssl() is true.
 * @return array{blocked: bool, exempt: bool, guard: int|false}
 */
function jht_pv_ap_probe( string $uri, string $accept, array $vars = array( 'author' => '1' ), ?string $host = 'example.com', bool $ssl = false ): array {
	jht_pv_setup( array(), array( 'is_ssl' => $ssl ) );
	$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
	if ( null === $host ) {
		unset( $_SERVER['HTTP_HOST'] );
	} else {
		$_SERVER['HTTP_HOST'] = $host;
	}
	$GLOBALS['wp']             = (object) array( 'query_vars' => $vars );
	$GLOBALS['wp_query']       = new WP_Query();
	$_SERVER['REQUEST_URI']    = $uri;
	$_SERVER['HTTP_ACCEPT']    = $accept;
	$query_string              = (string) parse_url( url: $uri, component: PHP_URL_QUERY );
	parse_str( string: $query_string, result: $get );
	$_GET                      = $get;
	jpkcom_harden_trim_block_author_enum();
	$blocked = true === $GLOBALS['wp_query']->is_404 && 404 === ( $GLOBALS['jht_status'] ?? null ) && true === ( $GLOBALS['jht_nocache'] ?? null );
	$exempt  = false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] );
	return array(
		'blocked' => $blocked,
		'exempt'  => $exempt,
		'guard'   => jht_pv_priority( 'template_include', 'jpkcom_harden_trim_author_enum_guard_template' ),
	);
}

$pv_as2      = 'application/ld+json; profile="https://www.w3.org/ns/activitystreams"';
$pv_mastodon = $pv_as2 . ', application/activity+json, text/html;q=0.1';

// Stage 1: ActivityPub loaded without user_can_activitypub() (older/newer API): never exempt.
$GLOBALS['apstub_parts']  = array( 'is_activitypub_request', 'should_negotiate_content' );
$GLOBALS['apstub_actors'] = array( 1 );
require __DIR__ . '/stubs-activitypub.php';
$pv_r = jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
chk( 'ap partial API (no user_can_activitypub): genuine request => 404', $pv_r['blocked'] );

// Stage 2: the full API.
$GLOBALS['apstub_parts'] = array( 'is_activitypub_request', 'should_negotiate_content', 'user_can_activitypub' );
require __DIR__ . '/stubs-activitypub.php';

$pv_cases = array(
	// label => array( REQUEST_URI, Accept, query vars, exempt? ).
	'genuine activity+json for an actor'              => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), true ),
	'genuine ld+json with AS2 profile'                => array( '/?author=1', $pv_as2, array( 'author' => '1' ), true ),
	'Mastodon Accept (AP preferred, html q=0.1)'      => array( '/?author=1', $pv_mastodon, array( 'author' => '1' ), true ),
	'?activitypub query var, html Accept'             => array( '/?author=1&activitypub', 'text/html', array( 'author' => '1', 'activitypub' => '' ), true ),
	'bare ld+json (no AS2 profile)'                   => array( '/?author=1', 'application/ld+json', array( 'author' => '1' ), false ),
	'low-q activity+json behind text/html'            => array( '/?author=1', 'text/html, application/activity+json;q=0.5', array( 'author' => '1' ), false ),
	'activity+json;q=0 (refused)'                     => array( '/?author=1', 'application/activity+json;q=0', array( 'author' => '1' ), false ),
	'Accept merely containing activity+json at q=0.1' => array( '/?author=1', 'text/html,application/xhtml+xml,application/activity+json;q=0.1', array( 'author' => '1' ), false ),
	'text/html'                                       => array( '/?author=1', 'text/html', array( 'author' => '1' ), false ),
	'no Accept'                                       => array( '/?author=1', '', array( 'author' => '1' ), false ),
	'extra raw query arg (WP ignores it)'             => array( '/?author=1&foo=bar', 'application/activity+json', array( 'author' => '1' ), false ),
	'extra query var paged'                           => array( '/?author=1&paged=2', 'application/activity+json', array( 'author' => '1', 'paged' => '2' ), false ),
	'author feed'                                     => array( '/?author=1&feed=rss2', 'application/activity+json', array( 'author' => '1', 'feed' => 'rss2' ), false ),
	'non-actor user'                                  => array( '/?author=2', 'application/activity+json', array( 'author' => '2' ), false ),
	'author list 1,2'                                 => array( '/?author=1,2', 'application/activity+json', array( 'author' => '1,2' ), false ),
	'negative author'                                 => array( '/?author=-1', 'application/activity+json', array( 'author' => '-1' ), false ),
	// Requests core's template loader answers BEFORE template_include (template-loader.php:40-62):
	// ActivityPub never answers them and the guard is never reached, so the raw query being only
	// `author` (the feed etc. comes from the path or the POST body) must not exempt them.
	'feed from the path'                              => array( '/feed/?author=1', 'application/activity+json', array( 'author' => '1', 'feed' => 'feed' ), false ),
	'feed from the path, ?activitypub'                => array( '/feed/?author=1&activitypub', 'text/html', array( 'author' => '1', 'feed' => 'feed', 'activitypub' => '' ), false ),
	'atom feed from the path'                         => array( '/feed/atom/?author=1', 'application/activity+json', array( 'author' => '1', 'feed' => 'atom' ), false ),
	'comments feed from the path'                     => array( '/comments/feed/?author=1', 'application/activity+json', array( 'author' => '1', 'feed' => 'feed', 'withcomments' => '1' ), false ),
	'feed from the POST body'                         => array( '/?author=1', 'application/activity+json', array( 'author' => '1', 'feed' => 'rss2' ), false ),
	'trackback from the path'                         => array( '/hello/trackback/?author=1', 'application/activity+json', array( 'author' => '1', 'name' => 'hello', 'tb' => '1' ), false ),
	'robots.txt'                                      => array( '/robots.txt?author=1', 'application/activity+json', array( 'author' => '1', 'robots' => '1' ), false ),
	'favicon.ico'                                     => array( '/favicon.ico?author=1', 'application/activity+json', array( 'author' => '1', 'favicon' => '1' ), false ),
	'sitemap (rendered on template_redirect)'         => array( '/wp-sitemap.xml?author=1', 'application/activity+json', array( 'author' => '1', 'sitemap' => 'index' ), false ),
	'sitemap stylesheet'                              => array( '/wp-sitemap-index.xsl?author=1', 'application/activity+json', array( 'author' => '1', 'sitemap-stylesheet' => 'index' ), false ),
	'path vars that reach template_include stay exempt (guard armed)' => array( '/page/2/?author=1', 'application/activity+json', array( 'author' => '1', 'paged' => '2' ), true ),
);
foreach ( $pv_cases as $pv_label => $pv_case ) {
	$pv_r = jht_pv_ap_probe( $pv_case[0], $pv_case[1], $pv_case[2] );
	chk( "ap: {$pv_label} => " . ( $pv_case[3] ? 'exempt, guard armed' : '404' ), $pv_case[3] ? ( $pv_r['exempt'] && PHP_INT_MAX === $pv_r['guard'] ) : ( $pv_r['blocked'] && false === $pv_r['guard'] ) );
}

// The argument count is decided on the URL ActivityPub parses, not on REQUEST_URI alone: core's
// redirect_canonical() builds $requested_url = scheme . HTTP_HOST . REQUEST_URI (canonical.php:69-73)
// and Router::redirect_canonical() takes the query from that (class-router.php:305). A Host header
// ending in '#' turns the whole REQUEST_URI into the fragment: no query, ActivityPub lets core's 301
// to /author/<nicename>/ through. No Host header: core builds no URL, same result.
$pv_host_cases = array(
	// label => array( REQUEST_URI, Accept, query vars, HTTP_HOST, is_ssl, exempt? ).
	"Host 'example.com#'"                     => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), 'example.com#', false, false ),
	"Host 'example.com#', ?activitypub"       => array( '/?author=1&activitypub', 'text/html', array( 'author' => '1', 'activitypub' => '' ), 'example.com#', false, false ),
	"Host 'example.com#x', https"             => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), 'example.com#x', true, false ),
	'no Host header'                          => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), null, false, false ),
	"Host 'example.com?x=1' (extra argument)" => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), 'example.com?x=1', false, false ),
	'Host with port'                          => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), 'example.com:8080', false, true ),
	'https'                                   => array( '/?author=1', 'application/activity+json', array( 'author' => '1' ), 'example.com', true, true ),
);
foreach ( $pv_host_cases as $pv_label => $pv_case ) {
	$pv_r = jht_pv_ap_probe( $pv_case[0], $pv_case[1], $pv_case[2], $pv_case[3], $pv_case[4] );
	chk( "ap: {$pv_label} => " . ( $pv_case[5] ? 'exempt, guard armed' : '404' ), $pv_case[5] ? ( $pv_r['exempt'] && PHP_INT_MAX === $pv_r['guard'] ) : ( $pv_r['blocked'] && false === $pv_r['guard'] ) );
}
$_SERVER['HTTP_HOST'] = 'example.com';

// ActivityPub reads its own filtered answers as truthy: a filter returning 1 still makes an ActivityPub request.
jht_pv_setup();
add_filter( 'activitypub_is_activitypub_request', static fn(): int => 1 );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query']    = new WP_Query();
$_SERVER['REQUEST_URI'] = '/?author=1';
$_SERVER['HTTP_HOST']   = 'example.com';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
$_GET                   = array( 'author' => '1' );
jpkcom_harden_trim_block_author_enum();
chk( 'ap: classifier filter answering 1 (truthy, as ActivityPub reads it) => exempt', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) );

// ActivityPub's content negotiation switched off: it serves the theme template, so no exemption.
$GLOBALS['apstub_negotiate'] = false;
$pv_r                        = jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
chk( 'ap: should_negotiate_content() false => 404', $pv_r['blocked'] );
unset( $GLOBALS['apstub_negotiate'] );

// The guard: when the exemption rests on ActivityPub's decision, the response must be ActivityPub's
// own template. An unsigned fetch under Authorized Fetch gets status 401 and the THEME template
// (class-router.php:211-215), i.e. the author archive; that becomes the 404.
chk( 'ap guard: function exists', function_exists( function: 'jpkcom_harden_trim_author_enum_guard_template' ) );
if ( function_exists( function: 'jpkcom_harden_trim_author_enum_guard_template' ) ) {
	foreach ( array( 'templates/activitypub-json.php', '/templates/post-preview.php', 'templates/tombstone-json.php' ) as $pv_tpl ) {
		jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
		$pv_path = ACTIVITYPUB_PLUGIN_DIR . $pv_tpl;
		chk( "ap guard: ActivityPub template {$pv_tpl} kept", $pv_path === jpkcom_harden_trim_author_enum_guard_template( $pv_path ) && false === $GLOBALS['wp_query']->is_404 );
	}
	jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
	$GLOBALS['jht_status'] = 401;
	$pv_tpl                = jpkcom_harden_trim_author_enum_guard_template( '/var/www/html/wp-content/themes/t/author.php' );
	chk( 'ap guard: theme template (unsigned Authorized Fetch) => 404 template', '/var/www/html/wp-content/themes/t/404.php' === $pv_tpl );
	chk( 'ap guard: theme template => query 404, status 404, no-cache', true === $GLOBALS['wp_query']->is_404 && 404 === ( $GLOBALS['jht_status'] ?? null ) && true === ( $GLOBALS['jht_nocache'] ?? null ) );
	jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
	chk( 'ap guard: a sibling directory named activitypub-evil is no ActivityPub template', '/var/www/html/wp-content/themes/t/404.php' === jpkcom_harden_trim_author_enum_guard_template( rtrim( string: ACTIVITYPUB_PLUGIN_DIR, characters: '/' ) . '-evil/author.php' ) );
	jht_pv_ap_probe( '/?author=1', 'application/activity+json' );
	chk( 'ap guard: non-string template passed through', 42 === jpkcom_harden_trim_author_enum_guard_template( 42 ) );
}

// The filter keeps the final say on the exemption.
jht_pv_setup();
add_filter( 'jpkcom_harden_trim_author_enum_exempt', '__return_false' );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query']    = new WP_Query();
$_SERVER['REQUEST_URI'] = '/?author=1';
$_SERVER['HTTP_ACCEPT'] = 'application/activity+json';
$_GET                   = array( 'author' => '1' );
jpkcom_harden_trim_block_author_enum();
chk( 'author_enum_exempt filter: false blocks a genuine ActivityPub request', true === $GLOBALS['wp_query']->is_404 );
jht_pv_setup();
add_filter( 'jpkcom_harden_trim_author_enum_exempt', static fn( bool $exempt, array $query, string $accept, string $uri = '' ): bool => '/?author=1&x=y' === $uri, 10, 4 );
$GLOBALS['jht_options']['permalink_structure'] = '/%postname%/';
$GLOBALS['wp']          = (object) array( 'query_vars' => array( 'author' => '1' ) );
$GLOBALS['wp_query']    = new WP_Query();
$_SERVER['REQUEST_URI'] = '/?author=1&x=y';
$_SERVER['HTTP_ACCEPT'] = 'text/html';
jpkcom_harden_trim_block_author_enum();
chk( 'author_enum_exempt filter: true exempts what ActivityPub would not answer (gets the request URI)', false === $GLOBALS['wp_query']->is_404 && ! isset( $GLOBALS['jht_status'] ) );
unset( $GLOBALS['wp'], $GLOBALS['wp_query'], $_SERVER['HTTP_ACCEPT'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'] );
$_GET = array();

summary();
