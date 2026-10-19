<?php
/**
 * Shared stub base for the standalone PHP tests of jpkcom-harden-trim.
 *
 * Provides WordPress stubs (hooks recorder, options, users, roles, escaping,
 * WP_Error, WP_User, WP_Post), the `chk()` / `summary()` assertion helpers and
 * `jht_run_fixture()` for per-process constant scenarios. Additive by design:
 * later tests add their own stubs guarded with function_exists().
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

if ( ! defined( constant_name: 'WPINC' ) ) {
	define( constant_name: 'WPINC', value: 'wp-includes' );
}

/**
 * Reset every recorded stub state.
 *
 * @return void
 */
function jht_reset_wp(): void {
	foreach ( array_keys( $GLOBALS ) as $key ) {
		if ( is_string( $key ) && str_starts_with( haystack: $key, needle: 'jht_' ) && 'jht_passed' !== $key && 'jht_failed' !== $key ) {
			unset( $GLOBALS[ $key ] );
		}
	}
	$GLOBALS['jht_hooks']   = array();
	$GLOBALS['jht_removed'] = array();
	$GLOBALS['jht_options'] = array();
	$GLOBALS['jht_user']    = null;
	$GLOBALS['jht_state']   = array(
		'logged_in'   => false,
		'roles'       => array(),
		'is_admin'    => false,
		'is_ssl'      => false,
		'is_embed'    => false,
		'block_theme' => false,
		'home'        => 'https://example.test',
		'site'        => 'https://example.test',
		'blogname'    => 'Example Site',
	);
}

/**
 * Seed the core default hooks the removal tests expect to find.
 *
 * @return void
 */
function jht_seed_core_hooks(): void {
	$seed = array(
		'wp_head'           => array(
			'rest_output_link_wp_head'      => array( 10 ),
			'feed_links'                    => array( 2 ),
			'feed_links_extra'              => array( 3 ),
			'rsd_link'                      => array( 10 ),
			'print_emoji_detection_script'  => array( 7 ),
			'wp_generator'                  => array( 10 ),
			'wp_shortlink_wp_head'          => array( 10 ),
			'wp_oembed_add_discovery_links' => array( 4, 10 ),
			'wp_oembed_add_host_js'         => array( 10 ),
		),
		'template_redirect' => array(
			'rest_output_link_header' => array( 11 ),
			'wp_shortlink_header'     => array( 11 ),
		),
	);
	foreach ( $seed as $hook => $callbacks ) {
		foreach ( $callbacks as $callback => $priorities ) {
			foreach ( $priorities as $priority ) {
				$GLOBALS['jht_hooks'][ $hook ][ $priority ][] = $callback;
			}
		}
	}
}

jht_reset_wp();

// Hook recorder.

if ( ! function_exists( function: 'add_action' ) ) {
	function add_action( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['jht_hooks'][ $hook_name ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( function: 'add_filter' ) ) {
	function add_filter( string $hook_name, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['jht_hooks'][ $hook_name ][ $priority ][] = $callback;
		return true;
	}
}

if ( ! function_exists( function: 'remove_filter' ) ) {
	function remove_filter( string $hook_name, $callback, int $priority = 10 ): bool {
		$GLOBALS['jht_removed'][] = array( $hook_name, $callback, $priority );
		$list                     = $GLOBALS['jht_hooks'][ $hook_name ][ $priority ] ?? array();
		$found                    = array_search( needle: $callback, haystack: $list, strict: true );
		if ( false === $found ) {
			return false;
		}
		unset( $GLOBALS['jht_hooks'][ $hook_name ][ $priority ][ $found ] );
		return true;
	}
}

if ( ! function_exists( function: 'remove_action' ) ) {
	function remove_action( string $hook_name, $callback, int $priority = 10 ): bool {
		return remove_filter( $hook_name, $callback, $priority );
	}
}

if ( ! function_exists( function: 'has_filter' ) ) {
	function has_filter( string $hook_name, $callback = false ): bool|int {
		foreach ( $GLOBALS['jht_hooks'][ $hook_name ] ?? array() as $priority => $list ) {
			if ( false === $callback && array() !== $list ) {
				return (int) $priority;
			}
			if ( false !== $callback && in_array( needle: $callback, haystack: $list, strict: true ) ) {
				return (int) $priority;
			}
		}
		return false;
	}
}

if ( ! function_exists( function: 'has_action' ) ) {
	function has_action( string $hook_name, $callback = false ): bool|int {
		return has_filter( $hook_name, $callback );
	}
}

if ( ! function_exists( function: 'apply_filters' ) ) {
	function apply_filters( string $hook_name, $value, ...$args ) {
		$hooks = $GLOBALS['jht_hooks'][ $hook_name ] ?? array();
		ksort( array: $hooks );
		foreach ( $hooks as $list ) {
			foreach ( $list as $callback ) {
				if ( is_callable( $callback ) ) {
					$value = $callback( $value, ...$args );
				}
			}
		}
		return $value;
	}
}

if ( ! function_exists( function: 'do_action' ) ) {
	function do_action( string $hook_name, ...$args ): void {
		$hooks = $GLOBALS['jht_hooks'][ $hook_name ] ?? array();
		ksort( array: $hooks );
		foreach ( $hooks as $list ) {
			foreach ( $list as $callback ) {
				if ( is_callable( $callback ) ) {
					$callback( ...$args );
				}
			}
		}
	}
}

// Options.

if ( ! function_exists( function: 'get_option' ) ) {
	function get_option( string $option, $default_value = false ) {
		return $GLOBALS['jht_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( function: 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['jht_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( function: 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( array_key_exists( key: $option, array: $GLOBALS['jht_options'] ) ) {
			return false;
		}
		$GLOBALS['jht_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( function: 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['jht_options'][ $option ] );
		return true;
	}
}

// Users and roles.

if ( ! class_exists( class: 'WP_User' ) ) {
	class WP_User {
		public int $ID = 0;
		/** @var string[] */
		public array $roles = array();

		public function __construct( int $id = 0, array $roles = array() ) {
			$this->ID    = $id;
			$this->roles = $roles;
		}
	}
}

if ( ! class_exists( class: 'WP_Post' ) ) {
	class WP_Post {
		public int $ID = 0;
		public string $post_type = 'post';

		public function __construct( int $id = 0, string $post_type = 'post' ) {
			$this->ID        = $id;
			$this->post_type = $post_type;
		}
	}
}

if ( ! class_exists( class: 'WP_Error' ) ) {
	class WP_Error {
		/** @var array<string, string[]> */
		public array $errors = array();
		/** @var array<string, mixed> */
		public array $error_data = array();

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' !== $code ) {
				$this->add( $code, $message, $data );
			}
		}

		public function add( $code, $message, $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_codes(): array {
			return array_keys( array: $this->errors );
		}

		public function get_error_message( $code = '' ): string {
			if ( '' === $code ) {
				$code = $this->get_error_codes()[0] ?? '';
			}
			return $this->errors[ $code ][0] ?? '';
		}

		public function remove( $code ): void {
			unset( $this->errors[ $code ], $this->error_data[ $code ] );
		}
	}
}

if ( ! class_exists( class: 'WP_Roles' ) ) {
	class WP_Roles {
		/** @var array<string, array{name: string}> */
		public array $roles = array(
			'administrator' => array( 'name' => 'Administrator' ),
			'editor'        => array( 'name' => 'Editor' ),
			'author'        => array( 'name' => 'Author' ),
			'contributor'   => array( 'name' => 'Contributor' ),
			'subscriber'    => array( 'name' => 'Subscriber' ),
		);

		public function get_names(): array {
			return array_map( callback: static fn( array $r ): string => $r['name'], array: $this->roles );
		}
	}
}

if ( ! function_exists( function: 'wp_roles' ) ) {
	function wp_roles(): WP_Roles {
		return new WP_Roles();
	}
}

if ( ! function_exists( function: 'is_user_logged_in' ) ) {
	function is_user_logged_in(): bool {
		return (bool) $GLOBALS['jht_state']['logged_in'];
	}
}

if ( ! function_exists( function: 'wp_get_current_user' ) ) {
	function wp_get_current_user(): WP_User {
		if ( $GLOBALS['jht_state']['logged_in'] ) {
			return new WP_User( 1, (array) $GLOBALS['jht_state']['roles'] );
		}
		return new WP_User( 0, array() );
	}
}

// Environment.

if ( ! function_exists( function: 'home_url' ) ) {
	function home_url( string $path = '' ): string {
		return $GLOBALS['jht_state']['home'] . $path;
	}
}

if ( ! function_exists( function: 'site_url' ) ) {
	function site_url( string $path = '' ): string {
		return $GLOBALS['jht_state']['site'] . $path;
	}
}

if ( ! function_exists( function: 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '' ): string {
		return match ( $show ) {
			'url'   => $GLOBALS['jht_state']['home'],
			'name'  => $GLOBALS['jht_state']['blogname'],
			default => '',
		};
	}
}

if ( ! function_exists( function: 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'jht-fixed-salt-' . $scheme;
	}
}

if ( ! function_exists( function: 'is_admin' ) ) {
	function is_admin(): bool {
		return (bool) $GLOBALS['jht_state']['is_admin'];
	}
}

if ( ! function_exists( function: 'is_ssl' ) ) {
	function is_ssl(): bool {
		return (bool) $GLOBALS['jht_state']['is_ssl'];
	}
}

if ( ! function_exists( function: 'is_embed' ) ) {
	function is_embed(): bool {
		return (bool) $GLOBALS['jht_state']['is_embed'];
	}
}

if ( ! function_exists( function: 'is_multisite' ) ) {
	function is_multisite(): bool {
		return (bool) ( $GLOBALS['jht_state']['multisite'] ?? false );
	}
}

if ( ! function_exists( function: 'is_super_admin' ) ) {
	function is_super_admin( $user_id = false ): bool {
		return in_array( needle: (int) $user_id, haystack: (array) ( $GLOBALS['jht_state']['super_admins'] ?? array() ), strict: true );
	}
}

if ( ! function_exists( function: 'get_current_blog_id' ) ) {
	function get_current_blog_id(): int {
		return (int) ( $GLOBALS['jht_state']['blog_id'] ?? 1 );
	}
}

/**
 * Emulate switch_to_blog(): per-site options and the current blog id.
 *
 * Options of every site live in $GLOBALS['jht_sites'][ id ]; the active
 * site's set is swapped into $GLOBALS['jht_options'].
 *
 * @param int $blog_id Site to switch to.
 * @return void
 */
function jht_switch_site( int $blog_id ): void {
	$current = (int) ( $GLOBALS['jht_state']['blog_id'] ?? 1 );
	$GLOBALS['jht_sites'][ $current ] = $GLOBALS['jht_options'];
	$GLOBALS['jht_options']           = $GLOBALS['jht_sites'][ $blog_id ] ?? array();
	$GLOBALS['jht_state']['blog_id']  = $blog_id;
}

if ( ! function_exists( function: 'wp_is_block_theme' ) ) {
	function wp_is_block_theme(): bool {
		return (bool) $GLOBALS['jht_state']['block_theme'];
	}
}

// i18n, escaping, sanitising, misc.

if ( ! function_exists( function: '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( function: 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( string: $text, flags: ENT_QUOTES );
	}
}

if ( ! function_exists( function: 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( string: (string) $text, flags: ENT_QUOTES );
	}
}

if ( ! function_exists( function: 'esc_attr' ) ) {
	function esc_attr( $text ): string {
		return htmlspecialchars( string: (string) $text, flags: ENT_QUOTES );
	}
}

if ( ! function_exists( function: 'esc_url' ) ) {
	function esc_url( $url ): string {
		return htmlspecialchars( string: (string) $url, flags: ENT_QUOTES );
	}
}

if ( ! function_exists( function: 'sanitize_key' ) ) {
	function sanitize_key( $key ): string {
		return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key ) );
	}
}

if ( ! function_exists( function: 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ): string {
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( string: (string) $str ) ) );
	}
}

if ( ! function_exists( function: 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( string: $value ) : $value );
	}
}

if ( ! function_exists( function: 'current_time' ) ) {
	function current_time( string $type, $gmt = 0 ): string {
		return '2026-10-19T12:00:00+00:00';
	}
}

if ( ! function_exists( function: 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $data, $flags, $depth );
	}
}

// Assertions.

// URL and argument parsing, copied from core 7.1.3 (http.php:761-863, functions.php:5012,
// formatting.php:5240): ActivityPub parses its requested URL with these, so the stubs keep
// core's exact semantics (scheme-relative and path-only placeholders, null for a missing part).

if ( ! function_exists( function: 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		$to_unset = array();
		$url      = (string) $url;

		if ( str_starts_with( $url, '//' ) ) {
			$to_unset[] = 'scheme';
			$url        = 'placeholder:' . $url;
		} elseif ( str_starts_with( $url, '/' ) ) {
			$to_unset[] = 'scheme';
			$to_unset[] = 'host';
			$url        = 'placeholder://placeholder' . $url;
		}

		$parts = parse_url( $url );

		if ( false === $parts ) {
			return $parts;
		}

		foreach ( $to_unset as $key ) {
			unset( $parts[ $key ] );
		}

		if ( -1 === $component ) {
			return $parts;
		}

		$keys = array(
			PHP_URL_SCHEME   => 'scheme',
			PHP_URL_HOST     => 'host',
			PHP_URL_PORT     => 'port',
			PHP_URL_USER     => 'user',
			PHP_URL_PASS     => 'pass',
			PHP_URL_PATH     => 'path',
			PHP_URL_QUERY    => 'query',
			PHP_URL_FRAGMENT => 'fragment',
		);
		$key  = $keys[ $component ] ?? false;

		return false !== $key && isset( $parts[ $key ] ) ? $parts[ $key ] : null;
	}
}

if ( ! function_exists( function: 'wp_parse_str' ) ) {
	function wp_parse_str( $input_string, &$result ) {
		parse_str( (string) $input_string, $result );
		$result = apply_filters( 'wp_parse_str', $result );
	}
}

if ( ! function_exists( function: 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) {
		if ( is_object( $args ) ) {
			$parsed_args = get_object_vars( $args );
		} elseif ( is_array( $args ) ) {
			$parsed_args =& $args;
		} else {
			wp_parse_str( $args, $parsed_args );
		}

		if ( is_array( $defaults ) && $defaults ) {
			return array_merge( $defaults, $parsed_args );
		}
		return $parsed_args;
	}
}

$GLOBALS['jht_passed'] = 0;
$GLOBALS['jht_failed'] = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @return void
 */
function chk( string $name, bool $ok ): void {
	if ( $ok ) {
		++$GLOBALS['jht_passed'];
		return;
	}
	++$GLOBALS['jht_failed'];
	echo "FAIL: {$name}\n";
}

/**
 * Print the totals and exit non-zero on any failure.
 *
 * @return never
 */
function summary(): never {
	echo "{$GLOBALS['jht_passed']} passed, {$GLOBALS['jht_failed']} failed\n";
	exit( $GLOBALS['jht_failed'] > 0 ? 1 : 0 );
}

/**
 * Run a fixture in a child PHP process (constants are per-process).
 *
 * @param string               $file Fixture path.
 * @param array<string,string> $env  Extra environment variables.
 * @return array{exit:int, out:string}
 */
function jht_run_fixture( string $file, array $env = array() ): array {
	$cmd  = escapeshellarg( arg: PHP_BINARY ) . ' ' . escapeshellarg( arg: $file ) . ' 2>&1';
	$proc = proc_open(
		command: $cmd,
		descriptor_spec: array( 1 => array( 'pipe', 'w' ) ),
		pipes: $pipes,
		cwd: null,
		env_vars: array_merge( getenv(), $env )
	);
	if ( ! is_resource( $proc ) ) {
		return array( 'exit' => -1, 'out' => '' );
	}
	$out  = (string) stream_get_contents( stream: $pipes[1] );
	fclose( stream: $pipes[1] );
	return array( 'exit' => proc_close( process: $proc ), 'out' => $out );
}
