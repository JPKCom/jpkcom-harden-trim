<?php
/**
 * Admin-side WordPress stubs for test-admin-page.php and its fixture.
 *
 * Kept out of bootstrap.php so no earlier suite sees different semantics.
 * wp_die() and wp_safe_redirect() throw instead of ending the process, so a
 * handler's `exit` after the redirect is never reached in a test. A nonce is
 * valid when it equals `nonce-<action>`.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

/**
 * Thrown by the wp_die() stub.
 */
class JhtDie extends Exception {
	public int $status;

	public function __construct( string $message, int $status ) {
		parent::__construct( $message );
		$this->status = $status;
	}
}

/**
 * Thrown by the wp_safe_redirect() stub.
 */
class JhtRedirect extends Exception {
	public string $url;

	public function __construct( string $url ) {
		parent::__construct( 'redirect' );
		$this->url = $url;
	}
}

/**
 * Reset the admin stub state on top of jht_reset_wp().
 *
 * @return void
 */
function jht_reset_admin(): void {
	jht_reset_wp();
	$GLOBALS['jht_caps']        = array( 'manage_options' => true );
	$GLOBALS['jht_user_id']     = 7;
	$GLOBALS['jht_transients']  = array();
	$GLOBALS['jht_writes']      = 0;
	$GLOBALS['jht_menu_pages']  = array();
	$GLOBALS['jht_enqueued']    = array();
	$_GET                       = array();
	$_POST                      = array();
	$_REQUEST                   = array();
}

if ( ! function_exists( function: 'current_user_can' ) ) {
	function current_user_can( string $capability, ...$args ): bool {
		return ! empty( $GLOBALS['jht_caps'][ $capability ] );
	}
}

if ( ! function_exists( function: 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return (int) ( $GLOBALS['jht_user_id'] ?? 0 );
	}
}

if ( ! function_exists( function: 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ): void {
		$status = 500;
		if ( is_int( $args ) ) {
			$status = $args;
		} elseif ( is_array( $args ) && isset( $args['response'] ) ) {
			$status = (int) $args['response'];
		}
		throw new JhtDie( (string) $message, $status );
	}
}

if ( ! function_exists( function: 'check_admin_referer' ) ) {
	function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
		if ( ( $_REQUEST[ $query_arg ] ?? null ) !== 'nonce-' . $action ) {
			wp_die( 'The link you followed has expired.', '', array( 'response' => 403 ) );
		}
		return 1;
	}
}

if ( ! function_exists( function: 'wp_nonce_field' ) ) {
	function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ): string {
		$field = '<input type="hidden" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '" />';
		if ( $display ) {
			echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $field;
	}
}

if ( ! function_exists( function: 'wp_safe_redirect' ) ) {
	function wp_safe_redirect( string $location, int $status = 302, $x_redirect_by = 'WordPress' ): bool {
		throw new JhtRedirect( $location );
	}
}

if ( ! function_exists( function: 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'https://example.test/wp-admin/' . ltrim( string: $path, characters: '/' );
	}
}

if ( ! function_exists( function: 'add_query_arg' ) ) {
	function add_query_arg( ...$args ) {
		$params = is_array( $args[0] ) ? $args[0] : array( $args[0] => $args[1] );
		$url    = is_array( $args[0] ) ? (string) ( $args[1] ?? '' ) : (string) ( $args[2] ?? '' );
		return $url . ( str_contains( haystack: $url, needle: '?' ) ? '&' : '?' ) . http_build_query( data: $params );
	}
}

if ( ! function_exists( function: 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		$GLOBALS['jht_transients'][ $transient ] = array( 'value' => $value, 'expiration' => $expiration );
		return true;
	}
}

if ( ! function_exists( function: 'get_transient' ) ) {
	function get_transient( string $transient ) {
		return $GLOBALS['jht_transients'][ $transient ]['value'] ?? false;
	}
}

if ( ! function_exists( function: 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['jht_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( function: 'add_options_page' ) ) {
	function add_options_page( $page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null ) {
		$GLOBALS['jht_menu_pages'][] = array( $page_title, $menu_title, $capability, $menu_slug, $callback );
		return 'settings_page_' . $menu_slug;
	}
}

if ( ! function_exists( function: 'wp_enqueue_style' ) ) {
	function wp_enqueue_style( $handle, $src = '', $deps = array(), $ver = false, $media = 'all' ): void {
		$GLOBALS['jht_enqueued'][] = array( 'style', $handle, $src );
	}
}

if ( ! function_exists( function: 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $args = array() ): void {
		$GLOBALS['jht_enqueued'][] = array( 'script', $handle, $src );
	}
}

if ( ! function_exists( function: 'plugins_url' ) ) {
	function plugins_url( string $path = '', string $plugin = '' ): string {
		return 'https://example.test/wp-content/plugins/jpkcom-harden-trim/' . ltrim( string: $path, characters: '/' );
	}
}

if ( ! function_exists( function: 'plugin_basename' ) ) {
	function plugin_basename( string $file ): string {
		return 'jpkcom-harden-trim/' . basename( path: $file );
	}
}

if ( ! function_exists( function: 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = 'default' ): string {
		return htmlspecialchars( string: $text, flags: ENT_QUOTES );
	}
}

if ( ! function_exists( function: '_n' ) ) {
	function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
		return 1 === $number ? $single : $plural;
	}
}

if ( ! function_exists( function: 'absint' ) ) {
	function absint( $maybeint ): int {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( function: 'translate_user_role' ) ) {
	function translate_user_role( string $name, string $domain = 'default' ): string {
		return $name;
	}
}

if ( ! function_exists( function: 'size_format' ) ) {
	/**
	 * Core's size_format() for B, KB and MB (wp-includes/functions.php).
	 */
	function size_format( $bytes, $decimals = 0 ) {
		foreach ( array( 'MB' => 1048576, 'KB' => 1024, 'B' => 1 ) as $unit => $mag ) {
			if ( (float) $bytes >= $mag ) {
				return number_format( (float) $bytes / $mag, (int) $decimals ) . ' ' . $unit;
			}
		}
		return '0 B';
	}
}

if ( ! function_exists( function: 'submit_button' ) ) {
	function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other_attributes = null ): void {
		echo '<p class="submit"><input type="submit" name="' . esc_attr( $name ) . '" id="' . esc_attr( $name ) . '" class="button button-' . esc_attr( $type ) . '" value="' . esc_attr( $text ?? 'Save Changes' ) . '" /></p>';
	}
}

/**
 * Every input/select/textarea tag whose name is jpkcom_harden_trim[<key>] or jpkcom_harden_trim[<key>][].
 *
 * @param string $html Rendered page.
 * @param string $key  Setting key.
 * @return list<string> Opening tags.
 */
function jht_field_tags( string $html, string $key ): array {
	$pattern = '/<(?:input|select|textarea)\b[^>]*\bname="jpkcom_harden_trim\[' . preg_quote( str: $key, delimiter: '/' ) . '\](?:\[\])?"[^>]*>/';
	preg_match_all( $pattern, $html, $matches );
	return $matches[0];
}

/**
 * Whether a tag carries the disabled attribute.
 *
 * @param string $tag Opening tag.
 * @return bool
 */
function jht_tag_disabled( string $tag ): bool {
	return 1 === preg_match( '/\sdisabled(?:=|\s|\/|>)/', $tag );
}

/**
 * Whether the checkbox of a bool setting is checked.
 *
 * @param string $html Rendered page.
 * @param string $key  Setting key.
 * @return bool|null Null when the page has no such checkbox.
 */
function jht_checkbox_checked( string $html, string $key ): ?bool {
	foreach ( jht_field_tags( $html, $key ) as $tag ) {
		if ( str_contains( haystack: $tag, needle: 'type="checkbox"' ) ) {
			return 1 === preg_match( '/\schecked(?:=|\s|\/|>)/', $tag );
		}
	}
	return null;
}

/**
 * The texts of the selected options of a setting's select.
 *
 * @param string $html Rendered page.
 * @param string $key  Setting key.
 * @return list<string> Option texts (entity-decoded), in document order.
 */
function jht_selected_options( string $html, string $key ): array {
	if ( 1 !== preg_match( '/<select\b[^>]*\bname="jpkcom_harden_trim\[' . preg_quote( str: $key, delimiter: '/' ) . '\]"[^>]*>(.*?)<\/select>/s', $html, $m ) ) {
		return array();
	}
	preg_match_all( '/<option\b([^>]*)>(.*?)<\/option>/s', $m[1], $options, PREG_SET_ORDER );
	$selected = array();
	foreach ( $options as $option ) {
		if ( 1 === preg_match( '/\sselected(?:=|\s|\/|$)/', $option[1] ) ) {
			$selected[] = html_entity_decode( string: $option[2], flags: ENT_QUOTES | ENT_HTML5 );
		}
	}
	return $selected;
}
