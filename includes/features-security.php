<?php
/**
 * Security features
 *
 * Neutral login error, response headers, fingerprint headers, JSONP, file
 * editor and application passwords. All of them
 * are global: they act on anonymous requests, headers or constants, where no
 * user context exists, so the exempt roles do not apply. Hook names were
 * checked against the WordPress 7.1.3 source.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_security_features' ) ) {

	/**
	 * Register the hooks of the active security features.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_security_features( array $settings ): void {

		if ( true === ( $settings['login_errors'] ?? false ) ) {
			add_filter( 'wp_login_errors', 'jpkcom_harden_trim_filter_login_errors', 10, 2 );
		}

		if ( true === ( $settings['fingerprint_headers'] ?? false ) ) {
			add_action( 'send_headers', 'jpkcom_harden_trim_remove_fingerprint_headers' );
			add_filter( 'x_redirect_by', '__return_false' );
		}

		foreach ( array( 'header_nosniff', 'header_referrer', 'header_frame', 'header_permissions', 'header_hsts' ) as $key ) {
			if ( true === ( $settings[ $key ] ?? false ) ) {
				add_action( 'send_headers', 'jpkcom_harden_trim_send_security_headers' );
				break;
			}
		}

		if ( true === ( $settings['rest_jsonp'] ?? false ) ) {
			add_filter( 'rest_jsonp_enabled', '__return_false' );
		}

		// A DISALLOW_FILE_EDIT from wp-config.php wins. Defined at plugins_loaded,
		// before the admin menu and the file editor capability checks run.
		if (
			true === ( $settings['file_edit'] ?? false )
			&& null === jpkcom_harden_trim_core_lock( 'file_edit' )
			&& ! defined( constant_name: 'DISALLOW_FILE_EDIT' )
		) {
			define( constant_name: 'DISALLOW_FILE_EDIT', value: true );
		}

		$app_passwords = $settings['app_passwords'] ?? 'unchanged';

		if ( 'disabled' === $app_passwords ) {
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
		} elseif ( 'roles' === $app_passwords ) {
			add_filter( 'wp_is_application_passwords_available_for_user', 'jpkcom_harden_trim_filter_app_passwords_for_user', 10, 2 );
		}
	}

}

// Login errors.

if ( ! function_exists( function: 'jpkcom_harden_trim_neutral_login_errors' ) ) {

	/**
	 * Replace the user-enumerating login errors by one neutral message.
	 *
	 * Only `invalid_username`, `invalid_email` and `incorrect_password` are
	 * replaced; codes from other plugins (two-factor, captcha, lockout) stay.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error $errors Login errors.
	 * @return WP_Error The same object with the mapped codes replaced.
	 */
	function jpkcom_harden_trim_neutral_login_errors( WP_Error $errors ): WP_Error {
		$mapped = array( 'invalid_username', 'invalid_email', 'incorrect_password' );
		$found  = false;

		foreach ( $mapped as $code ) {
			if ( in_array( needle: $code, haystack: $errors->get_error_codes(), strict: true ) ) {
				$errors->remove( $code );
				$found = true;
			}
		}

		if ( $found ) {
			$errors->add(
				'incorrect_password',
				'<strong>' . __( 'Error:', 'jpkcom-harden-trim' ) . '</strong> ' . __( 'The username, email address or password is incorrect.', 'jpkcom-harden-trim' )
			);
		}

		return $errors;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_login_errors' ) ) {

	/**
	 * `wp_login_errors` callback, limited to the login form.
	 *
	 * wp-login.php applies the filter in two places: the `checkemail` case
	 * and the `login` case (`case 'login': default:`, so every unknown action
	 * is a login request too). The lost-password flow does not use it, and
	 * `checkemail` never carries credential errors, so only that action is
	 * skipped; gating on `login` alone would let `action=foo` bypass the
	 * neutral message.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $errors       Login errors.
	 * @param mixed $redirect_to  Redirect destination (unused).
	 * @return mixed The errors, rewritten unless the action is `checkemail`.
	 */
	function jpkcom_harden_trim_filter_login_errors( mixed $errors, mixed $redirect_to = '' ): mixed {
		if ( ! $errors instanceof WP_Error ) {
			return $errors;
		}

		$action = isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ? $_REQUEST['action'] : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing check, mirrors wp-login.php.

		if ( 'checkemail' === $action ) {
			return $errors;
		}

		return jpkcom_harden_trim_neutral_login_errors( $errors );
	}

}

// Headers.

if ( ! function_exists( function: 'jpkcom_harden_trim_security_headers' ) ) {

	/**
	 * Security response headers to send for the active settings.
	 *
	 * A header whose name is already in `$already_sent` (set in PHP by core or
	 * another plugin) is never returned, so the plugin cannot override it.
	 * headers_list() only knows headers set in PHP: one added by the web
	 * server (nginx add_header, Apache mod_headers) or a CDN is invisible
	 * here and would be sent twice.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings     Effective settings.
	 * @param list<string>         $already_sent Raw `headers_list()` lines.
	 * @param bool                 $is_ssl       Whether the request is HTTPS.
	 * @param bool                 $is_embed     Whether the response is an embed.
	 * @return array<string, string> Header name => value.
	 */
	function jpkcom_harden_trim_security_headers( array $settings, array $already_sent, bool $is_ssl, bool $is_embed ): array {
		$sent = array();

		foreach ( $already_sent as $line ) {
			$sent[] = strtolower( string: trim( string: explode( separator: ':', string: (string) $line, limit: 2 )[0] ) );
		}

		$wanted = array();

		if ( true === ( $settings['header_nosniff'] ?? false ) ) {
			$wanted['X-Content-Type-Options'] = 'nosniff';
		}

		if ( true === ( $settings['header_referrer'] ?? false ) ) {
			$wanted['Referrer-Policy'] = 'strict-origin-when-cross-origin';
		}

		if ( true === ( $settings['header_frame'] ?? false ) && ! $is_embed ) {
			$wanted['X-Frame-Options'] = 'SAMEORIGIN';
		}

		if ( true === ( $settings['header_permissions'] ?? false ) ) {
			$wanted['Permissions-Policy'] = 'camera=(), microphone=(), geolocation=()';
		}

		if ( true === ( $settings['header_hsts'] ?? false ) && $is_ssl ) {
			$wanted['Strict-Transport-Security'] = 'max-age=31536000';
		}

		return array_filter(
			array: $wanted,
			callback: static fn( string $name ): bool => ! in_array( needle: strtolower( string: $name ), haystack: $sent, strict: true ),
			mode: ARRAY_FILTER_USE_KEY
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_send_security_headers' ) ) {

	/**
	 * `send_headers` callback: send the security headers on the front end.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_send_security_headers(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || headers_sent() ) {
			return;
		}

		$headers = jpkcom_harden_trim_security_headers( jpkcom_harden_trim_settings(), headers_list(), is_ssl(), is_embed() );

		foreach ( $headers as $name => $value ) {
			header( header: $name . ': ' . $value );
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_remove_fingerprint_headers' ) ) {

	/**
	 * `send_headers` callback: drop `X-Powered-By` on the front end.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_remove_fingerprint_headers(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || headers_sent() ) {
			return;
		}

		header_remove( name: 'X-Powered-By' );
	}

}

// Application passwords.

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_app_passwords_for_user' ) ) {

	/**
	 * `wp_is_application_passwords_available_for_user` callback for the `roles` mode.
	 *
	 * Registered from the booting site's settings, decided with the current
	 * site's: after switch_to_blog() a site whose mode is `unchanged` keeps
	 * core's answer and a `disabled` site denies.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $available Availability so far.
	 * @param mixed $user      The user checked.
	 * @return bool True only when already available and the user holds an allowed role.
	 */
	function jpkcom_harden_trim_filter_app_passwords_for_user( mixed $available, mixed $user = null ): bool {
		$settings = jpkcom_harden_trim_settings();

		if ( 'disabled' === $settings['app_passwords'] ) {
			return false;
		}

		if ( 'roles' !== $settings['app_passwords'] ) {
			return true === $available;
		}

		if ( true !== $available || ! $user instanceof WP_User ) {
			return false;
		}

		$allowed = $settings['app_password_roles'];

		return array() !== $allowed && array() !== array_intersect( jpkcom_harden_trim_user_roles( $user ), $allowed );
	}

}
