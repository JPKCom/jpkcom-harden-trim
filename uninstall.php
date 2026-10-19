<?php
/**
 * Uninstall: remove everything this plugin stored.
 *
 * Runs only when WordPress deletes the plugin (WP_UNINSTALL_PLUGIN), never on
 * deactivation. Removes the settings option, leftover notice transients and
 * the bundled updater's cache transients; on multisite on every site,
 * because the settings are per site.
 *
 * Notice transients live 60 seconds. They are found by name in the options
 * table; with a persistent object cache they are not stored there and simply
 * expire.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_uninstall_site' ) ) {

	/**
	 * Remove the plugin's data from the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_uninstall_site(): void {
		global $wpdb;

		delete_option( 'jpkcom_harden_trim_settings' );

		$prefix = '_transient_';
		$names  = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix . 'jpkcom_harden_trim_notice_' ) . '%'
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-off lookup on uninstall, nothing to cache.

		foreach ( (array) $names as $name ) {
			if ( is_string( $name ) && str_starts_with( haystack: $name, needle: $prefix ) ) {
				// delete_transient() removes the value and its timeout row.
				delete_transient( substr( string: $name, offset: strlen( string: $prefix ) ) );
			}
		}

		// The family updater's manifest cache, negative cache and fetch lock
		// (class-plugin-updater.php: 'jpk_git_update_' . md5( plugin directory )).
		$updater_key = 'jpk_git_update_' . md5( string: basename( path: __DIR__ ) );

		foreach ( array( '', '_fail', '_lock' ) as $suffix ) {
			delete_transient( $updater_key . $suffix );
		}
	}

}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $jpkcom_harden_trim_site_id ) {
		switch_to_blog( (int) $jpkcom_harden_trim_site_id );
		jpkcom_harden_trim_uninstall_site();
		restore_current_blog();
	}
} else {
	jpkcom_harden_trim_uninstall_site();
}
