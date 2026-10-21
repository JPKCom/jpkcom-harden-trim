<?php
/**
 * Effective status of every setting
 *
 * One answer to "what does this plugin do on this site right now, and why",
 * shared by the WP-CLI command and the Abilities API, so the two cannot drift
 * apart. It reports what the settings page cannot show at a glance: a switch
 * can be on and still do nothing (kill switch, a core constant that wins, a
 * block theme), and a value can come from somewhere other than the form (a
 * plugin constant, the `jpkcom_harden_trim_settings` filter).
 *
 * Everything here is read through the store's own functions; nothing is
 * re-derived from the option.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.2.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_unfiltered_settings' ) ) {

	/**
	 * Stored values with the valid plugin constants applied, before the filter.
	 *
	 * The same two steps jpkcom_harden_trim_settings() takes before it applies
	 * `jpkcom_harden_trim_settings`, so a difference to the effective settings
	 * is exactly what the filter changed.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, mixed> Key => value.
	 */
	function jpkcom_harden_trim_unfiltered_settings(): array {
		$settings = jpkcom_harden_trim_get_stored();

		foreach ( array_keys( array: $settings ) as $key ) {
			$state = jpkcom_harden_trim_constant_state( $key );

			if ( $state['valid'] ) {
				$settings[ $key ] = $state['value'];
			}
		}

		return $settings;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_is_block_theme' ) ) {

	/**
	 * Whether the active theme is a block theme, when that can be known.
	 *
	 * wp_is_block_theme() is only reliable from after_setup_theme on; before
	 * that the answer is false, as it is for the feature code.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	function jpkcom_harden_trim_is_block_theme(): bool {
		return function_exists( function: 'wp_is_block_theme' )
			&& function_exists( function: 'did_action' )
			&& did_action( 'after_setup_theme' ) > 0
			&& wp_is_block_theme();
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_status' ) ) {

	/**
	 * The effective status of every setting.
	 *
	 * Per key:
	 * - `value`: the plugin's effective value (constant, else stored, else
	 *   default, then the filter). A core constant does not change it, because
	 *   core does not change it either; see `core_value`.
	 * - `source`: where `value` comes from: `core_constant` (a core constant
	 *   defined before the plugin loaded decides instead of the plugin),
	 *   `constant`, `filter`, `stored` or `default`.
	 * - `locked_by`: the constant the settings page names as the lock, or null.
	 * - `acts` / `reason`: whether the plugin's feature does something on this
	 *   request, and if not, why: `kill_switch`, `core_constant` (also a core
	 *   constant another plugin defined before this one could),
	 *   `block_theme` (not available with a block theme), `plain_permalinks`
	 *   (the author-enumeration block needs pretty permalinks), `unused` (a
	 *   role list nothing reads right now) or `off`.
	 *   Role-scoped features (`role_scoped`) still skip users holding an
	 *   exempt role; that is decided per user and is not reflected in `acts`.
	 *
	 * @since 1.2.0
	 *
	 * @return array{
	 *     kill_switch: array{defined: bool, valid: bool, active: bool},
	 *     invalid_constants: list<string>,
	 *     block_theme: bool,
	 *     exempt_roles: list<string>,
	 *     settings: list<array<string, mixed>>
	 * }
	 */
	function jpkcom_harden_trim_status(): array {
		$schema      = jpkcom_harden_trim_schema();
		$defaults    = jpkcom_harden_trim_defaults();
		$stored      = jpkcom_harden_trim_get_stored();
		$unfiltered  = jpkcom_harden_trim_unfiltered_settings();
		$effective   = jpkcom_harden_trim_settings();
		$locked      = jpkcom_harden_trim_locked_keys();
		$kill        = jpkcom_harden_trim_kill_switch_state();
		$block_theme = jpkcom_harden_trim_is_block_theme();
		$rows        = array();

		$structure = function_exists( function: 'get_option' ) ? get_option( 'permalink_structure' ) : '';
		$plain     = '' === ( is_string( $structure ) ? $structure : '' );

		foreach ( $schema as $key => $entry ) {
			$core     = jpkcom_harden_trim_core_lock( $key );
			$constant = jpkcom_harden_trim_constant_state( $key );
			$value    = $effective[ $key ];
			$late     = jpkcom_harden_trim_status_late_constant( $key, $value, $core );

			// Same order as jpkcom_harden_trim_settings(): stored, then the plugin
			// constant, then the filter, which may replace the constant's value.
			$source = match ( true ) {
				null !== $core || null !== $late                          => 'core_constant',
				$value !== $unfiltered[ $key ]                             => 'filter',
				$constant['valid']                                         => 'constant',
				$stored[ $key ] !== $defaults[ $key ]                      => 'stored',
				default                                                    => 'default',
			};

			// Every arm mirrors a gate in the feature code; see CLAUDE.md before adding one.
			$reason = match ( true ) {
				$kill['active']                                                          => 'kill_switch',
				null !== $core || null !== $late                                         => 'core_constant',
				// features-security.php: the role list is read only in `roles` mode,
				// and there an EMPTY list acts: nobody may use application passwords.
				'app_password_roles' === $key                                            => 'roles' === $effective['app_passwords'] ? null : 'unused',
				! jpkcom_harden_trim_is_active_value( $key, $value )                     => 'off',
				$entry['block_theme_unavailable'] && $block_theme                        => 'block_theme',
				// features-privacy.php: the author-enumeration block needs pretty permalinks.
				'block_author_enum' === $key && $plain                                   => 'plain_permalinks',
				default                                                                  => null,
			};

			$rows[ $key ] = array(
				'key'         => $key,
				'group'       => $entry['group'],
				'type'        => $entry['type'],
				'value'       => $value,
				'stored'      => $stored[ $key ],
				'default'     => $defaults[ $key ],
				'source'      => $source,
				'locked_by'   => $locked[ $key ] ?? null,
				'core_value'  => null !== $core ? jpkcom_harden_trim_status_scalar( $core['value'] ) : ( null !== $late ? jpkcom_harden_trim_status_scalar( $late['value'] ) : null ),
				'acts'        => null === $reason,
				'reason'      => $reason,
				'role_scoped' => (bool) $entry['role_scoped'],
			);
		}

		// An exempt-role list only matters while a role-scoped feature acts.
		if ( true === $rows['exempt_roles']['acts'] ) {
			$scoped_acts = array_filter( array: $rows, callback: static fn( array $row ): bool => $row['role_scoped'] && $row['acts'] );

			if ( array() === $scoped_acts ) {
				$rows['exempt_roles']['acts']   = false;
				$rows['exempt_roles']['reason'] = 'unused';
			}
		}

		$rows = array_values( array: $rows );

		return array(
			'kill_switch'       => $kill,
			'invalid_constants' => jpkcom_harden_trim_invalid_constants(),
			'block_theme'       => $block_theme,
			'exempt_roles'      => array_values( array: (array) $effective['exempt_roles'] ),
			'settings'          => $rows,
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_status_late_constant' ) ) {

	/**
	 * A core constant defined after the plugin's load-time snapshot that keeps
	 * an active setting from acting.
	 *
	 * file_edit and autosave_interval work by defining DISALLOW_FILE_EDIT and
	 * AUTOSAVE_INTERVAL on plugins_loaded, and skip when the constant already
	 * exists (features-security.php, features-performance.php). A constant
	 * defined by another plugin's file body comes after the snapshot but before
	 * that, so it is no core lock and still wins. Only an active value counts:
	 * core defines AUTOSAVE_INTERVAL itself when the plugin does not, and the
	 * plugin's own definition equals the setting.
	 *
	 * @since 1.2.0
	 *
	 * @param string                           $key   Setting key.
	 * @param mixed                            $value Effective value.
	 * @param array{name: string, value: mixed}|null $core  Core lock, if any.
	 * @return array{name: string, value: mixed}|null The winning constant, or null.
	 */
	function jpkcom_harden_trim_status_late_constant( string $key, mixed $value, ?array $core ): ?array {
		$name = array( 'file_edit' => 'DISALLOW_FILE_EDIT', 'autosave_interval' => 'AUTOSAVE_INTERVAL' )[ $key ] ?? null;

		if ( null === $name || null !== $core || ! jpkcom_harden_trim_is_active_value( $key, $value ) || ! defined( constant_name: $name ) ) {
			return null;
		}

		$defined = constant( name: $name );

		return $defined === $value ? null : array( 'name' => $name, 'value' => $defined );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_status_scalar' ) ) {

	/**
	 * A core constant's value as a JSON scalar.
	 *
	 * wp-config.php may define anything; arrays and objects are reported as
	 * their type name rather than passed through.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $value Constant value.
	 * @return bool|int|float|string|null
	 */
	function jpkcom_harden_trim_status_scalar( mixed $value ): bool|int|float|string|null {
		return ( null === $value || is_scalar( $value ) ) ? $value : gettype( $value );
	}

}
