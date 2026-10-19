<?php
/**
 * Tests for the head cleanup and security features: removals recorded per
 * setting (always global), the neutral login error, the security header map
 * and the registration glue (defaults, file editor, application passwords).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/features.php', 'includes/features-head.php', 'includes/features-security.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

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

$jht_api = array(
	'jpkcom_harden_trim_register_head_features',
	'jpkcom_harden_trim_register_security_features',
	'jpkcom_harden_trim_neutral_login_errors',
	'jpkcom_harden_trim_filter_login_errors',
	'jpkcom_harden_trim_security_headers',
);

$jht_missing = array_values( array: array_filter( array: $jht_api, callback: static fn( string $fn ): bool => ! function_exists( $fn ) ) );
chk( 'head/security API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

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
function jht_hs_setup( array $settings = array(), array $state = array() ): void {
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
 * Whether a removal was recorded.
 *
 * @param string $hook     Hook.
 * @param string $callback Callback.
 * @param int    $priority Priority.
 * @return bool
 */
function jht_removed( string $hook, string $callback, int $priority ): bool {
	return in_array( needle: array( $hook, $callback, $priority ), haystack: $GLOBALS['jht_removed'], strict: true );
}

$expected_removals = array(
	array( 'wp_head', 'wp_shortlink_wp_head', 10 ),
	array( 'template_redirect', 'wp_shortlink_header', 11 ),
	array( 'wp_head', 'rsd_link', 10 ),
	array( 'wp_head', 'rest_output_link_wp_head', 10 ),
	array( 'template_redirect', 'rest_output_link_header', 11 ),
	array( 'wp_head', 'wp_generator', 10 ),
);

// head_defaults.
jht_hs_setup();
jpkcom_harden_trim_register_head_features( jpkcom_harden_trim_settings() );
foreach ( $expected_removals as $r ) {
	chk( "head_defaults: {$r[0]} {$r[1]}@{$r[2]} removed", jht_removed( $r[0], $r[1], $r[2] ) );
}
chk( 'head_defaults: the_generator returns empty', '' === apply_filters( 'the_generator', '<generator>', 'html' ) );
chk( 'head_defaults: feed_links kept', ! jht_removed( 'wp_head', 'feed_links', 2 ) );
chk( 'head_defaults: feed_links_extra kept', ! jht_removed( 'wp_head', 'feed_links_extra', 3 ) );

// head_feed_links on.
jht_hs_setup( array( 'head_feed_links' => true ) );
jpkcom_harden_trim_register_head_features( jpkcom_harden_trim_settings() );
chk( 'head_feed_links: feed_links removed', jht_removed( 'wp_head', 'feed_links', 2 ) );
chk( 'head_feed_links: feed_links_extra removed', jht_removed( 'wp_head', 'feed_links_extra', 3 ) );

// head all off.
jht_hs_setup( array( 'head_shortlink' => false, 'head_rsd' => false, 'head_rest_links' => false, 'head_generator' => false ) );
jpkcom_harden_trim_register_head_features( jpkcom_harden_trim_settings() );
chk( 'head_off: nothing removed', array() === $GLOBALS['jht_removed'] );
chk( 'head_off: the_generator untouched', '<g>' === apply_filters( 'the_generator', '<g>', 'html' ) );

// head_global: exempt role does not matter.
jht_hs_setup( array( 'exempt_roles' => array( 'editor' ) ), array( 'logged_in' => true, 'roles' => array( 'editor' ) ) );
jpkcom_harden_trim_register_head_features( jpkcom_harden_trim_settings() );
foreach ( $expected_removals as $r ) {
	chk( "head_global: {$r[1]}@{$r[2]} removed for exempt editor", jht_removed( $r[0], $r[1], $r[2] ) );
}

// login_errors.
$e = jpkcom_harden_trim_neutral_login_errors( new WP_Error( 'incorrect_password', 'Wrong.' ) );
chk( 'login_errors: incorrect_password codes', array( 'incorrect_password' ) === $e->get_error_codes() );
chk( 'login_errors: neutral message', str_contains( haystack: $e->get_error_message(), needle: 'The username, email address or password is incorrect.' ) );
chk( 'login_errors: strong prefix', str_starts_with( haystack: $e->get_error_message(), needle: '<strong>Error:</strong> ' ) );

$e = jpkcom_harden_trim_neutral_login_errors( new WP_Error( 'invalid_username', 'Unknown user.' ) );
chk( 'login_errors: invalid_username rewritten', array( 'incorrect_password' ) === $e->get_error_codes() && ! str_contains( haystack: $e->get_error_message(), needle: 'Unknown' ) );

$e = jpkcom_harden_trim_neutral_login_errors( new WP_Error( 'jpkcom_hide_login_locked', 'Locked out.' ) );
chk( 'login_errors: foreign code untouched', array( 'jpkcom_hide_login_locked' ) === $e->get_error_codes() && 'Locked out.' === $e->get_error_message() );

$mixed = new WP_Error( 'invalid_email', 'No such mail.' );
$mixed->add( 'two_factor_invalid', 'Code needed.' );
$e = jpkcom_harden_trim_neutral_login_errors( $mixed );
chk( 'login_errors: mixed keeps two_factor message', 'Code needed.' === $e->get_error_message( 'two_factor_invalid' ) && in_array( needle: 'incorrect_password', haystack: $e->get_error_codes(), strict: true ) && ! in_array( needle: 'invalid_email', haystack: $e->get_error_codes(), strict: true ) );

$both = new WP_Error( 'invalid_username', 'a' );
$both->add( 'incorrect_password', 'b' );
$e = jpkcom_harden_trim_neutral_login_errors( $both );
chk( 'login_errors: several mapped codes collapse to one message', 1 === count( $e->errors['incorrect_password'] ) );

// Glue: everything except checkemail is rewritten.
jht_hs_setup();
unset( $_REQUEST['action'] );
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: default action (login) rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
$_REQUEST['action'] = 'checkemail';
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: checkemail untouched', array( 'invalid_username' ) === $e->get_error_codes() );
$_REQUEST['action'] = 'login';
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: explicit login rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
$_REQUEST['action'] = 'bogus';
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: unknown action (handled as login by core) rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
chk( 'login_glue: non-WP_Error passthrough', 'x' === jpkcom_harden_trim_filter_login_errors( 'x', '' ) );
// wp-login.php:476 reads action as a string only and falls back to login; :503 turns every
// unknown action into login. Only a raw 'checkemail' reaches the checkemail case (:1207).
$_REQUEST['action'] = array( 'checkemail' );
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: action as array (core: login) rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
$_REQUEST['action'] = array( 'action' => array( 'checkemail' ) );
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'incorrect_password', 'x' ), '' );
chk( 'login_glue: nested action array rewritten', 1 === count( $e->errors['incorrect_password'] ) && str_contains( haystack: $e->get_error_message(), needle: 'The username, email address or password is incorrect.' ) );
$_REQUEST['action'] = 'CheckEmail';
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_email', 'x' ), '' );
chk( 'login_glue: checkemail in other case (core: login) rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
$_REQUEST['action'] = 'checkemail ';
$e = jpkcom_harden_trim_filter_login_errors( new WP_Error( 'invalid_username', 'x' ), '' );
chk( 'login_glue: checkemail with trailing space (core: login) rewritten', array( 'incorrect_password' ) === $e->get_error_codes() );
$_REQUEST['action'] = 'checkemail';
$hs_ce = new WP_Error( 'registered', 'Registration complete.', 'message' );
$e      = jpkcom_harden_trim_filter_login_errors( $hs_ce, '' );
chk( 'login_glue: checkemail message untouched', array( 'registered' ) === $e->get_error_codes() && 'Registration complete.' === $e->get_error_message() );
unset( $_REQUEST['action'] );

// Security headers.
$d = jpkcom_harden_trim_defaults();
chk( 'headers_defaults', array( 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'strict-origin-when-cross-origin' ) === jpkcom_harden_trim_security_headers( $d, array(), true, false ) );
chk( 'headers_skip_existing', array( 'X-Content-Type-Options' => 'nosniff' ) === jpkcom_harden_trim_security_headers( $d, array( 'referrer-policy: no-referrer' ), true, false ) );
chk( 'headers_skip_existing: spaces and case', array( 'Referrer-Policy' => 'strict-origin-when-cross-origin' ) === jpkcom_harden_trim_security_headers( $d, array( 'X-CONTENT-TYPE-OPTIONS : nosniff' ), true, false ) );

$f = array_merge( $d, array( 'header_frame' => true ) );
chk( 'headers_frame', 'SAMEORIGIN' === ( jpkcom_harden_trim_security_headers( $f, array(), true, false )['X-Frame-Options'] ?? null ) );
chk( 'headers_frame: absent on embed', ! isset( jpkcom_harden_trim_security_headers( $f, array(), true, true )['X-Frame-Options'] ) );

$h = array_merge( $d, array( 'header_hsts' => true ) );
chk( 'headers_hsts: absent without ssl', ! isset( jpkcom_harden_trim_security_headers( $h, array(), false, false )['Strict-Transport-Security'] ) );
chk( 'headers_hsts: exact value', 'max-age=31536000' === ( jpkcom_harden_trim_security_headers( $h, array(), true, false )['Strict-Transport-Security'] ?? null ) );

$p = array_merge( $d, array( 'header_permissions' => true ) );
chk( 'headers_permissions', 'camera=(), microphone=(), geolocation=()' === ( jpkcom_harden_trim_security_headers( $p, array(), true, false )['Permissions-Policy'] ?? null ) );

$none = array_merge( $d, array( 'header_nosniff' => false, 'header_referrer' => false ) );
chk( 'headers_all_off', array() === jpkcom_harden_trim_security_headers( $none, array(), true, false ) );

// The header callbacks need only features.php and this module (features-performance.php is not loaded here).
jht_hs_setup( array( 'header_frame' => true ) );
$hs_error = null;
try {
	jpkcom_harden_trim_send_security_headers();
	jpkcom_harden_trim_remove_fingerprint_headers();
} catch ( Error $e ) {
	$hs_error = $e->getMessage();
}
chk( 'send_headers callbacks run without features-performance.php (' . ( $hs_error ?? 'ok' ) . ')', null === $hs_error );
chk( 'is_front_end lives in features.php', function_exists( 'jpkcom_harden_trim_is_front_end' ) && str_contains( haystack: (string) file_get_contents( filename: $root . '/includes/features.php' ), needle: "function jpkcom_harden_trim_is_front_end()" ) );

// defaults_registration.
jht_hs_setup();
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'defaults_registration: rest_jsonp_enabled filter', false !== has_filter( 'rest_jsonp_enabled' ) && false === apply_filters( 'rest_jsonp_enabled', true ) );
chk( 'defaults_registration: DISALLOW_FILE_EDIT not defined', ! defined( constant_name: 'DISALLOW_FILE_EDIT' ) );
chk( 'defaults_registration: no app-password filter', false === has_filter( 'wp_is_application_passwords_available' ) && false === has_filter( 'wp_is_application_passwords_available_for_user' ) );
chk( 'defaults_registration: wp_login_errors hooked', false !== has_filter( 'wp_login_errors' ) );
chk( 'defaults_registration: send_headers hooked', false !== has_filter( 'send_headers' ) );
chk( 'defaults_registration: x_redirect_by false', false === apply_filters( 'x_redirect_by', 'WordPress', 302, '/' ) );

// Everything off: nothing hooked.
jht_hs_setup( array( 'login_errors' => false, 'fingerprint_headers' => false, 'header_nosniff' => false, 'header_referrer' => false, 'rest_jsonp' => false ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
foreach ( array( 'wp_login_errors', 'send_headers', 'x_redirect_by', 'rest_jsonp_enabled', 'wp_is_application_passwords_available', 'wp_is_application_passwords_available_for_user' ) as $hs_hook ) {
	chk( "security_off: nothing on {$hs_hook}", false === has_filter( $hs_hook ) );
}
chk( 'security_off: DISALLOW_FILE_EDIT not defined', ! defined( constant_name: 'DISALLOW_FILE_EDIT' ) );

// Each header key alone hooks send_headers (the registrar's key list must not drift from the schema).
foreach ( array( 'header_nosniff', 'header_referrer', 'header_frame', 'header_permissions', 'header_hsts' ) as $hs_key ) {
	jht_hs_setup( array( 'header_nosniff' => false, 'header_referrer' => false, $hs_key => true ) );
	jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
	chk( "headers: {$hs_key} alone hooks send_headers", false !== has_filter( 'send_headers', 'jpkcom_harden_trim_send_security_headers' ) );
}
chk( 'headers: registrar list covers every header_* schema key', array( 'header_frame', 'header_hsts', 'header_nosniff', 'header_permissions', 'header_referrer' ) === ( static function (): array { $k = array_values( array: array_filter( array: array_keys( array: jpkcom_harden_trim_schema() ), callback: static fn( string $key ): bool => str_starts_with( haystack: $key, needle: 'header_' ) ) ); sort( $k ); return $k; } )() );

// app passwords.
jht_hs_setup( array( 'app_passwords' => 'disabled' ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'app_passwords disabled: unavailable', false === apply_filters( 'wp_is_application_passwords_available', true ) );

jht_hs_setup( array( 'app_passwords' => 'roles', 'app_password_roles' => array( 'administrator' ) ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'app_passwords roles: stays available globally', false === has_filter( 'wp_is_application_passwords_available' ) );
chk( 'app_passwords roles: admin allowed', true === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 1, array( 'administrator' ) ) ) );
chk( 'app_passwords roles: editor denied', false === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 2, array( 'editor' ) ) ) );
chk( 'app_passwords roles: earlier false stays false', false === apply_filters( 'wp_is_application_passwords_available_for_user', false, new WP_User( 1, array( 'administrator' ) ) ) );

jht_hs_setup( array( 'app_passwords' => 'roles', 'app_password_roles' => array() ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'app_passwords roles: empty list denies everyone', false === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 1, array( 'administrator' ) ) ) );

// Multisite super admin without a role on this site: counts as administrator.
jht_hs_setup( array( 'app_passwords' => 'roles', 'app_password_roles' => array( 'administrator' ) ), array( 'multisite' => true, 'super_admins' => array( 9 ) ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'app_passwords roles: super admin without a site role allowed as administrator', true === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 9, array() ) ) );
chk( 'app_passwords roles: other user without a role denied', false === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 10, array() ) ) );

// Multisite: the roles callback follows the current site's mode after switch_to_blog().
jht_hs_setup( array( 'app_passwords' => 'roles', 'app_password_roles' => array( 'administrator' ) ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
jht_switch_site( 2 );
chk( 'app_passwords multisite: switched site with mode unchanged keeps availability', true === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 3, array( 'editor' ) ) ) );
jht_switch_site( 1 );
chk( 'app_passwords multisite: booting site still limits', false === apply_filters( 'wp_is_application_passwords_available_for_user', true, new WP_User( 3, array( 'editor' ) ) ) );

// file_edit is defined last (constants are per process).
jht_hs_setup( array( 'file_edit' => true ) );
jpkcom_harden_trim_register_security_features( jpkcom_harden_trim_settings() );
chk( 'file_edit: DISALLOW_FILE_EDIT defined true', defined( constant_name: 'DISALLOW_FILE_EDIT' ) && true === DISALLOW_FILE_EDIT );

summary();
