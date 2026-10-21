<?php
/*
Plugin Name: JPKCom Harden & Trim
Plugin URI: https://github.com/JPKCom/jpkcom-harden-trim
Description: Secure, private & lean WordPress: hardening, privacy and performance tweaks with safe defaults.
Version: 1.1.3
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Security, Privacy, Performance, Hardening, Cleanup
Requires at least: 7.1
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.1.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: jpkcom-harden-trim
Domain Path: /languages
Update URI: https://github.com/JPKCom/jpkcom-harden-trim
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}


/**
 * Plugin constants.
 *
 * @since 1.0.0
 */
if ( ! defined( 'JPKCOM_HARDEN_TRIM_VERSION' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_VERSION', '1.1.3' );
}

if ( ! defined( 'JPKCOM_HARDEN_TRIM_FILE' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_FILE', __FILE__ );
}

if ( ! defined( 'JPKCOM_HARDEN_TRIM_PATH' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'JPKCOM_HARDEN_TRIM_URL' ) ) {
	define( 'JPKCOM_HARDEN_TRIM_URL', plugin_dir_url( __FILE__ ) );
}

/**
 * Snapshot of the core constants that are already defined while this file is
 * read (wp-config.php, mu-plugins, earlier plugins).
 *
 * Taken before any module can define them itself, so the settings page can tell
 * a constant set by the site from one set by this plugin.
 *
 * @since 1.0.0
 */
if ( ! defined( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' ) ) {
	$jpkcom_harden_trim_at_load = array();

	foreach ( array( 'WP_POST_REVISIONS', 'AUTOSAVE_INTERVAL', 'DISALLOW_FILE_EDIT', 'WP_AI_SUPPORT' ) as $jpkcom_harden_trim_constant ) {
		if ( defined( $jpkcom_harden_trim_constant ) ) {
			$jpkcom_harden_trim_at_load[ $jpkcom_harden_trim_constant ] = constant( $jpkcom_harden_trim_constant );
		}
	}

	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', $jpkcom_harden_trim_at_load );
	unset( $jpkcom_harden_trim_at_load, $jpkcom_harden_trim_constant );
}


/**
 * Load the plugin's translations.
 *
 * @since 1.0.0
 *
 * @return void
 */
add_action( 'init', static function (): void {
	load_plugin_textdomain( 'jpkcom-harden-trim', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}, 1 );


/**
 * Initialize Plugin Updater.
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 1.0.0
 *
 * @return void
 */
add_action( 'init', static function (): void {
	$updater_file = plugin_dir_path( __FILE__ ) . 'includes/class-plugin-updater.php';

	if ( file_exists( $updater_file ) ) {
		require_once $updater_file;

		if ( class_exists( 'JPKComHardenTrimGitUpdate\\JPKComGitPluginUpdater' ) ) {
			new \JPKComHardenTrimGitUpdate\JPKComGitPluginUpdater(
				plugin_file: __FILE__,
				current_version: JPKCOM_HARDEN_TRIM_VERSION,
				manifest_url: 'https://jpkcom.github.io/jpkcom-harden-trim/plugin_jpkcom-harden-trim.json'
			);
		}
	}
}, 5 );


/**
 * Load the plugin modules and register the features.
 *
 * Loaded on plugins_loaded so the settings store exists before anything reads
 * it. Every module and every call into a module is guarded, so the plugin
 * stays loadable while modules are missing.
 *
 * @since 1.0.0
 *
 * @return void
 */
add_action( 'plugins_loaded', static function (): void {

	// features.php holds the helpers the feature modules share, so it comes
	// first; glob() alone would sort "features-*.php" before it ('-' < '.').
	$modules = array(
		'includes/settings-schema.php',
		'includes/settings-store.php',
		'includes/features.php',
	);

	$feature_files = glob( JPKCOM_HARDEN_TRIM_PATH . 'includes/features-*.php' ) ?: array();
	sort( $feature_files );

	foreach ( $feature_files as $feature_file ) {
		$modules[] = 'includes/' . basename( $feature_file );
	}

	if ( is_admin() ) {
		$modules[] = 'includes/admin-page.php';
		$modules[] = 'includes/import-export.php';
	}

	foreach ( $modules as $module ) {
		$file = JPKCOM_HARDEN_TRIM_PATH . $module;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	// Kill switch, parsed like every boolean constant ('false' / 'off' do not kill).
	if ( function_exists( 'jpkcom_harden_trim_kill_switch' ) && jpkcom_harden_trim_kill_switch() ) {
		return;
	}

	if ( function_exists( 'jpkcom_harden_trim_register_features' ) && function_exists( 'jpkcom_harden_trim_settings' ) ) {
		jpkcom_harden_trim_register_features( jpkcom_harden_trim_settings() );
	}

}, 5 );


/**
 * Activation: store the defaults if the option does not exist yet.
 *
 * The store files are required inside the hook because plugins_loaded has not
 * run yet during activation.
 *
 * @since 1.0.0
 */
register_activation_hook( __FILE__, static function (): void {
	foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php' ) as $module ) {
		$file = JPKCOM_HARDEN_TRIM_PATH . $module;

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	if ( function_exists( 'jpkcom_harden_trim_activate' ) ) {
		jpkcom_harden_trim_activate();
	}
} );
