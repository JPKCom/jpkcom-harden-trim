<?php
/**
 * Import and export
 *
 * Export downloads the stored settings as JSON; import takes an uploaded file
 * or pasted JSON, validates every value through the settings store and writes
 * through it. The export carries no site address and no user data, so a file
 * can move between sites as it is.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES' ) ) {
	/**
	 * Largest import accepted, in bytes (64 KB).
	 *
	 * An export of every setting is about 1.5 KB.
	 *
	 * @since 1.0.0
	 */
	define( constant_name: 'JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES', value: 65536 );
}

if ( ! function_exists( function: 'jpkcom_harden_trim_import_max_size' ) ) {

	/**
	 * The import limit as the settings page names it ("64 KB").
	 *
	 * Follows JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES, which wp-config.php may set.
	 *
	 * @since 1.0.0
	 *
	 * @return string Formatted size.
	 */
	function jpkcom_harden_trim_import_max_size(): string {
		$size = size_format( (int) JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES );

		return is_string( $size ) ? $size : (string) JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_export_payload' ) ) {

	/**
	 * The export document.
	 *
	 * Stored values only: no constant, no filter, no site URL, no user data.
	 *
	 * @since 1.0.0
	 *
	 * @return array{plugin: string, schema: int, version: string, settings: array<string, mixed>}
	 */
	function jpkcom_harden_trim_export_payload(): array {
		return array(
			'plugin'   => 'jpkcom-harden-trim',
			'schema'   => 1,
			'version'  => JPKCOM_HARDEN_TRIM_VERSION,
			'settings' => jpkcom_harden_trim_get_stored(),
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_export_headers' ) ) {

	/**
	 * HTTP headers of the export download (besides nocache_headers()).
	 *
	 * The date in the filename is UTC, as in the other family plugins.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Header name => value.
	 */
	function jpkcom_harden_trim_export_headers(): array {
		return array(
			'Content-Type'           => 'application/json; charset=utf-8',
			'Content-Disposition'    => 'attachment; filename="jpkcom-harden-trim-settings-' . gmdate( 'Y-m-d' ) . '.json"',
			'X-Content-Type-Options' => 'nosniff',
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_handle_export' ) ) {

	/**
	 * Send the export download (admin_post_jpkcom_harden_trim_export).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to export these settings.', 'jpkcom-harden-trim' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'jpkcom_harden_trim_export', 'jpkcom_harden_trim_export_nonce' );

		$json = wp_json_encode( jpkcom_harden_trim_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		nocache_headers();

		foreach ( jpkcom_harden_trim_export_headers() as $name => $value ) {
			header( $name . ': ' . $value );
		}

		echo (string) $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download sent as application/json with nosniff, not HTML.
		exit;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_import_error_message' ) ) {

	/**
	 * The message for an import error code.
	 *
	 * Only codes listed here produce text, so a code that reaches the notice
	 * from anywhere else shows nothing.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Error code.
	 * @return string Translated message, '' for an unknown code.
	 */
	function jpkcom_harden_trim_import_error_message( string $code ): string {
		return match ( $code ) {
			'no_input'           => __( 'Choose an export file or paste its content.', 'jpkcom-harden-trim' ),
			'upload_failed'      => __( 'The file could not be uploaded. Try again or paste its content instead.', 'jpkcom-harden-trim' ),
			/* translators: %s: largest accepted import, such as 64 KB. */
			'too_large'          => sprintf( __( 'The import is larger than %s, so it is not a settings export of this plugin.', 'jpkcom-harden-trim' ), jpkcom_harden_trim_import_max_size() ),
			'invalid_json'       => __( 'The import is not valid JSON.', 'jpkcom-harden-trim' ),
			'wrong_plugin'       => __( 'The import is not a Harden & Trim settings export.', 'jpkcom-harden-trim' ),
			'unsupported_schema' => __( 'The export was made with a format this version of the plugin does not know. Update the plugin and try again.', 'jpkcom-harden-trim' ),
			'no_settings'        => __( 'The export contains no settings.', 'jpkcom-harden-trim' ),
			default              => '',
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_parse_import' ) ) {

	/**
	 * Validate an import and merge it over the stored settings (writes nothing).
	 *
	 * Every key in the file goes through the settings sanitiser on its own:
	 * a valid value is applied, an unknown key or an invalid value is rejected
	 * and the stored value stays, role slugs that do not exist on this site are
	 * dropped and reported as `<key>:<slug>`. Keys missing from the file keep
	 * their stored value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw File or pasted content.
	 * @return array{settings: array<string, mixed>, applied: list<string>, rejected: list<string>}|WP_Error
	 *         Complete settings to store, or an error with code too_large, invalid_json,
	 *         wrong_plugin, unsupported_schema or no_settings.
	 */
	function jpkcom_harden_trim_parse_import( string $raw ): array|WP_Error {
		$fail = static fn( string $code ): WP_Error => new WP_Error( $code, jpkcom_harden_trim_import_error_message( $code ) );

		if ( strlen( string: $raw ) > JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES ) {
			return $fail( 'too_large' );
		}

		if ( str_starts_with( haystack: $raw, needle: "\xEF\xBB\xBF" ) ) {
			$raw = substr( string: $raw, offset: 3 );
		}

		// Depth 8 is more than an export needs (object, settings, role list, slug).
		$decoded = json_decode( $raw, true, 8, JSON_BIGINT_AS_STRING );

		if ( ! is_array( $decoded ) ) {
			return $fail( 'invalid_json' );
		}

		if ( 'jpkcom-harden-trim' !== ( $decoded['plugin'] ?? null ) ) {
			return $fail( 'wrong_plugin' );
		}

		if ( 1 !== ( $decoded['schema'] ?? null ) ) {
			return $fail( 'unsupported_schema' );
		}

		$incoming = $decoded['settings'] ?? null;

		if ( ! is_array( $incoming ) || array() === $incoming || array_is_list( array: $incoming ) ) {
			return $fail( 'no_settings' );
		}

		$settings = jpkcom_harden_trim_get_stored();
		$applied  = array();
		$rejected = array();

		foreach ( $incoming as $key => $value ) {
			if ( ! is_string( $key ) || ! array_key_exists( key: $key, array: $settings ) ) {
				$rejected[] = (string) $key;
				continue;
			}

			$result = jpkcom_harden_trim_sanitize_value( $key, $value );

			if ( ! $result['valid'] ) {
				$rejected[] = $key;
				continue;
			}

			$settings[ $key ] = $result['value'];
			$applied[]        = $key;

			foreach ( $result['dropped'] as $slug ) {
				$rejected[] = $key . ':' . $slug;
			}
		}

		return array(
			'settings' => $settings,
			'applied'  => $applied,
			'rejected' => $rejected,
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_import_source' ) ) {

	/**
	 * The raw import from the request: an uploaded file, else pasted JSON.
	 *
	 * A file wins over pasted text. The upload is only read when PHP itself
	 * received it (`is_uploaded_file()`); the client's file name and MIME type
	 * are never trusted.
	 *
	 * @since 1.0.0
	 *
	 * @return string|WP_Error Raw content, or an error with code no_input, upload_failed or too_large.
	 */
	function jpkcom_harden_trim_import_source(): string|WP_Error {
		$fail = static fn( string $code ): WP_Error => new WP_Error( $code, jpkcom_harden_trim_import_error_message( $code ) );
		$file = $_FILES['jpkcom_harden_trim_file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- checked below, content validated by the parser.

		if ( is_array( $file ) && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'] ) {
			$error = (int) $file['error'];

			if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
				return $fail( 'too_large' );
			}

			$tmp = $file['tmp_name'] ?? null;

			if ( UPLOAD_ERR_OK !== $error || ! is_string( $tmp ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
				return $fail( 'upload_failed' );
			}

			$size = filesize( $tmp );

			if ( false === $size ) {
				return $fail( 'upload_failed' );
			}

			if ( $size > JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES ) {
				return $fail( 'too_large' );
			}

			$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.

			return false === $raw ? $fail( 'upload_failed' ) : $raw;
		}

		$pasted = isset( $_POST['jpkcom_harden_trim_json'] ) && is_string( $_POST['jpkcom_harden_trim_json'] ) ? wp_unslash( $_POST['jpkcom_harden_trim_json'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked by the handler, content validated by the parser.

		if ( '' === trim( string: $pasted ) ) {
			return $fail( 'no_input' );
		}

		return $pasted;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_handle_import' ) ) {

	/**
	 * Import settings (admin_post_jpkcom_harden_trim_import).
	 *
	 * A failed import writes nothing and names the reason. A successful one
	 * stores every applied value, locked keys included; the notice counts the
	 * applied and rejected entries, lists the keys that changed, the rejected
	 * ones and the locked ones whose constant still wins.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'jpkcom-harden-trim' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'jpkcom_harden_trim_import', 'jpkcom_harden_trim_import_nonce' );

		$raw    = jpkcom_harden_trim_import_source();
		$parsed = is_wp_error( $raw ) ? $raw : jpkcom_harden_trim_parse_import( $raw );

		if ( is_wp_error( $parsed ) ) {
			jpkcom_harden_trim_redirect_with_notice( 'import_failed', array( 'error' => array( (string) $parsed->get_error_codes()[0] ) ) );
		}

		$before = jpkcom_harden_trim_get_stored();
		$locked = jpkcom_harden_trim_locked_keys();
		$result = jpkcom_harden_trim_save_settings( $parsed['settings'], false );

		jpkcom_harden_trim_redirect_with_notice(
			'imported',
			array(
				'applied'  => $parsed['applied'],
				'changed'  => jpkcom_harden_trim_changed_keys( $before, $result['settings'] ),
				'rejected' => array_values( array_unique( array_merge( $parsed['rejected'], $result['rejected'] ) ) ),
				'locked'   => array_values( array_filter( $parsed['applied'], static fn( string $key ): bool => isset( $locked[ $key ] ) ) ),
			)
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_import_export' ) ) {

	/**
	 * The import / export section of the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_render_import_export(): void {
		$action = admin_url( 'admin-post.php' );

		echo '<h2 id="jht-import-export">' . esc_html__( 'Import / Export', 'jpkcom-harden-trim' ) . '</h2>';

		echo '<h3>' . esc_html__( 'Export', 'jpkcom-harden-trim' ) . '</h3>';
		echo '<p>' . esc_html__( 'Downloads the stored settings as a JSON file, to keep as a backup or to import on another site. The file contains no site address and no user data; constants in wp-config.php are not part of it.', 'jpkcom-harden-trim' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '" class="jht-export">';
		wp_nonce_field( 'jpkcom_harden_trim_export', 'jpkcom_harden_trim_export_nonce' );
		echo '<input type="hidden" name="action" value="jpkcom_harden_trim_export" />';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Download export', 'jpkcom-harden-trim' ) . '</button></p>';
		echo '</form>';

		echo '<h3>' . esc_html__( 'Import', 'jpkcom-harden-trim' ) . '</h3>';
		/* translators: %s: largest accepted import, such as 64 KB. */
		echo '<p id="jht-import-description">' . esc_html( sprintf( __( 'Choose an export file or paste its content (up to %s); the import is saved immediately. Every value is checked: unknown settings and invalid values are rejected and listed, settings missing from the import keep their current value. Settings locked by wp-config.php are stored, but the constant still wins.', 'jpkcom-harden-trim' ), jpkcom_harden_trim_import_max_size() ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( $action ) . '" enctype="multipart/form-data" class="jht-import">';
		wp_nonce_field( 'jpkcom_harden_trim_import', 'jpkcom_harden_trim_import_nonce' );
		echo '<input type="hidden" name="action" value="jpkcom_harden_trim_import" />';
		echo '<p><label for="jht-import-file">' . esc_html__( 'Export file', 'jpkcom-harden-trim' ) . '</label><br />';
		echo '<input type="file" id="jht-import-file" name="jpkcom_harden_trim_file" accept=".json,application/json" aria-describedby="jht-import-description" /></p>';
		echo '<p><label for="jht-import-json">' . esc_html__( 'Or paste the content of an export file', 'jpkcom-harden-trim' ) . '</label><br />';
		echo '<textarea id="jht-import-json" name="jpkcom_harden_trim_json" rows="8" class="large-text code" aria-describedby="jht-import-description"></textarea></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Import settings', 'jpkcom-harden-trim' ) . '</button></p>';
		echo '</form>';
	}

}

add_action( 'admin_post_jpkcom_harden_trim_export', 'jpkcom_harden_trim_handle_export' );
add_action( 'admin_post_jpkcom_harden_trim_import', 'jpkcom_harden_trim_handle_import' );
