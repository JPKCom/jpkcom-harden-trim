<?php
/**
 * Settings store
 *
 * Reads, validates and writes the single option this plugin owns, resolves
 * constants and core locks, applies presets and decides the role exemption.
 * Every other module goes through here, so validation cannot be bypassed by
 * adding a caller.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_option_name' ) ) {

	/**
	 * Name of the option holding all settings.
	 *
	 * Stored structure: `['schema' => 1, 'updated' => string, 'settings' => array]`.
	 *
	 * @since 1.0.0
	 *
	 * @return string Option name.
	 */
	function jpkcom_harden_trim_option_name(): string {
		return 'jpkcom_harden_trim_settings';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_sanitize_value' ) ) {

	/**
	 * Validate one value against its schema entry.
	 *
	 * Role lists are valid as soon as the type is right (array or comma
	 * string); entries that are no existing role slug are dropped and listed
	 * in `dropped`. Never throws.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Raw value (form, import, constant, database).
	 * @return array{valid: bool, value: mixed, dropped: list<string>} `value` is null when invalid.
	 */
	function jpkcom_harden_trim_sanitize_value( string $key, mixed $value ): array {
		$invalid = array( 'valid' => false, 'value' => null, 'dropped' => array() );
		$schema  = jpkcom_harden_trim_schema();

		if ( ! isset( $schema[ $key ] ) ) {
			return $invalid;
		}

		$entry = $schema[ $key ];

		switch ( $entry['type'] ) {
			case 'bool':
				if ( ! is_scalar( $value ) ) {
					return $invalid;
				}

				$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );

				return null === $bool ? $invalid : array( 'valid' => true, 'value' => $bool, 'dropped' => array() );

			case 'enum':
				if ( ! is_string( $value ) || ! in_array( needle: $value, haystack: $entry['choices'], strict: true ) ) {
					return $invalid;
				}

				return array( 'valid' => true, 'value' => $value, 'dropped' => array() );

			case 'int':
				if ( null === $value || '' === $value ) {
					return $entry['nullable'] ? array( 'valid' => true, 'value' => null, 'dropped' => array() ) : $invalid;
				}

				if ( is_string( $value ) && 1 === preg_match( '/^\d{1,9}$/', trim( string: $value ) ) ) {
					$value = (int) trim( string: $value );
				}

				if ( ! is_int( $value ) || ! in_array( needle: $value, haystack: $entry['choices'], strict: true ) ) {
					return $invalid;
				}

				return array( 'valid' => true, 'value' => $value, 'dropped' => array() );

			case 'roles':
				if ( is_string( $value ) ) {
					$value = explode( separator: ',', string: $value );
				}

				if ( ! is_array( $value ) ) {
					return $invalid;
				}

				$roles   = array();
				$dropped = array();
				$known   = array() === $value ? array() : wp_roles()->roles;

				foreach ( $value as $candidate ) {
					if ( ! is_string( $candidate ) ) {
						$dropped[] = is_scalar( $candidate ) ? (string) $candidate : gettype( $candidate );
						continue;
					}

					$slug = trim( string: $candidate );

					if ( '' === $slug ) {
						continue;
					}

					if ( sanitize_key( $slug ) !== $slug || ! array_key_exists( key: $slug, array: $known ) ) {
						$dropped[] = $slug;
						continue;
					}

					if ( ! in_array( needle: $slug, haystack: $roles, strict: true ) ) {
						$roles[] = $slug;
					}
				}

				return array( 'valid' => true, 'value' => $roles, 'dropped' => $dropped );
		}

		return $invalid;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_sanitize_settings' ) ) {

	/**
	 * Coerce any input into a complete, valid settings array.
	 *
	 * Missing keys get their default, invalid values fall back to the default,
	 * unknown keys are dropped. Rejected keys and dropped role slugs
	 * (`<key>:<slug>`) are reported. Never throws.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Value from the database, an import, a form or a filter.
	 * @return array{settings: array<string, mixed>, rejected: list<string>}
	 */
	function jpkcom_harden_trim_sanitize_settings( mixed $input ): array {
		$settings = jpkcom_harden_trim_defaults();
		$rejected = array();

		if ( ! is_array( $input ) ) {
			return array( 'settings' => $settings, 'rejected' => $rejected );
		}

		foreach ( $input as $key => $value ) {
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

			foreach ( $result['dropped'] as $slug ) {
				$rejected[] = $key . ':' . $slug;
			}
		}

		return array( 'settings' => $settings, 'rejected' => $rejected );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_get_stored' ) ) {

	/**
	 * The stored settings, sanitised and merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Key => stored value.
	 */
	function jpkcom_harden_trim_get_stored(): array {
		$option = get_option( jpkcom_harden_trim_option_name(), array() );
		$raw    = is_array( $option ) && isset( $option['settings'] ) && is_array( $option['settings'] ) ? $option['settings'] : array();

		return jpkcom_harden_trim_sanitize_settings( $raw )['settings'];
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_constant_state' ) ) {

	/**
	 * State of the plugin constant for one key (`JPKCOM_HARDEN_TRIM_<KEY>`).
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return array{name: string, defined: bool, valid: bool, value: mixed} `value` is null unless valid.
	 */
	function jpkcom_harden_trim_constant_state( string $key ): array {
		$name  = 'JPKCOM_HARDEN_TRIM_' . strtoupper( string: $key );
		$state = array( 'name' => $name, 'defined' => false, 'valid' => false, 'value' => null );

		if ( ! defined( constant_name: $name ) ) {
			return $state;
		}

		$result           = jpkcom_harden_trim_sanitize_value( $key, constant( name: $name ) );
		$state['defined'] = true;
		$state['valid']   = $result['valid'];
		$state['value']   = $result['value'];

		return $state;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_core_lock' ) ) {

	/**
	 * The core constant that locks a key, if it was defined before the plugin loaded.
	 *
	 * Reads the snapshot taken by the main file, so a constant this plugin
	 * defines itself never counts as a lock.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return array{name: string, value: mixed}|null Null when not locked.
	 */
	function jpkcom_harden_trim_core_lock( string $key ): ?array {
		$constant = jpkcom_harden_trim_schema()[ $key ]['core_constant'] ?? null;

		if ( null === $constant || ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD' ) ) {
			return null;
		}

		$at_load = JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD;

		if ( ! is_array( $at_load ) || ! array_key_exists( key: $constant, array: $at_load ) ) {
			return null;
		}

		return array( 'name' => $constant, 'value' => $at_load[ $constant ] );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_locked_keys' ) ) {

	/**
	 * Every key whose value the settings page cannot change.
	 *
	 * A core constant defined before the plugin loaded names the lock: core
	 * honours it and the feature code then does nothing, so it is what is in
	 * effect even when a plugin constant for the same key exists. Otherwise a
	 * valid plugin constant names the lock.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Key => constant name.
	 */
	function jpkcom_harden_trim_locked_keys(): array {
		$locked = array();

		foreach ( array_keys( array: jpkcom_harden_trim_schema() ) as $key ) {
			$core = jpkcom_harden_trim_core_lock( $key );

			if ( null !== $core ) {
				$locked[ $key ] = $core['name'];
				continue;
			}

			$state = jpkcom_harden_trim_constant_state( $key );

			if ( $state['valid'] ) {
				$locked[ $key ] = $state['name'];
			}
		}

		return $locked;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_kill_switch_state' ) ) {

	/**
	 * State of the kill switch `JPKCOM_HARDEN_TRIM_DISABLE_ALL`.
	 *
	 * Parsed like every boolean setting constant, with
	 * filter_var( FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ): the
	 * strings 'false', 'off', 'no' and '0' do not switch everything off. A
	 * value that is no boolean is ignored and named in the invalid-constants
	 * notice.
	 *
	 * @since 1.0.0
	 *
	 * @return array{defined: bool, valid: bool, active: bool}
	 */
	function jpkcom_harden_trim_kill_switch_state(): array {
		if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' ) ) {
			return array( 'defined' => false, 'valid' => true, 'active' => false );
		}

		$value = constant( name: 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' );
		$bool  = is_scalar( $value ) ? filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;

		return array( 'defined' => true, 'valid' => null !== $bool, 'active' => true === $bool );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_kill_switch' ) ) {

	/**
	 * Whether the kill switch is on (no feature hooks at all).
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when JPKCOM_HARDEN_TRIM_DISABLE_ALL holds a true boolean value.
	 */
	function jpkcom_harden_trim_kill_switch(): bool {
		return jpkcom_harden_trim_kill_switch_state()['active'];
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_invalid_constants' ) ) {

	/**
	 * Names of plugin constants that are defined but hold an invalid value.
	 *
	 * Such constants are ignored; the settings page names them in a notice.
	 * Covers the setting constants and the kill switch.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Constant names.
	 */
	function jpkcom_harden_trim_invalid_constants(): array {
		$invalid = array();

		foreach ( array_keys( array: jpkcom_harden_trim_schema() ) as $key ) {
			$state = jpkcom_harden_trim_constant_state( $key );

			if ( $state['defined'] && ! $state['valid'] ) {
				$invalid[] = $state['name'];
			}
		}

		$kill = jpkcom_harden_trim_kill_switch_state();

		if ( $kill['defined'] && ! $kill['valid'] ) {
			$invalid[] = 'JPKCOM_HARDEN_TRIM_DISABLE_ALL';
		}

		return $invalid;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_settings_cache' ) ) {

	/**
	 * Holder of the effective-settings cache, one entry per site.
	 *
	 * Settings are per site (there is no network settings screen). Keyed by
	 * get_current_blog_id(), so after switch_to_blog() every reader gets the
	 * switched site's settings instead of the booting site's. `reset` forgets
	 * every site.
	 *
	 * @since 1.0.0
	 *
	 * @param string                    $op    `get`, `set` or `reset`.
	 * @param array<string, mixed>|null $value Value for `set`.
	 * @return array<string, mixed>|null The current site's cached settings, null when empty.
	 */
	function jpkcom_harden_trim_settings_cache( string $op = 'get', ?array $value = null ): ?array {
		static $cache = array();

		$site = get_current_blog_id();

		if ( 'reset' === $op ) {
			$cache = array();
		} elseif ( 'set' === $op ) {
			$cache[ $site ] = $value;
		}

		return $cache[ $site ] ?? null;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_settings_reset_cache' ) ) {

	/**
	 * Forget the cached effective settings.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_settings_reset_cache(): void {
		jpkcom_harden_trim_settings_cache( 'reset' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_settings' ) ) {

	/**
	 * The effective settings: valid plugin constant, else stored value, else default.
	 *
	 * The whole array then passes the `jpkcom_harden_trim_settings` filter and
	 * the sanitiser again. Core locks do not change the value here; the feature
	 * code skips acting when a core lock exists, because the core constant
	 * already wins inside core.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Key => effective value.
	 */
	function jpkcom_harden_trim_settings(): array {
		$cached = jpkcom_harden_trim_settings_cache();

		if ( null !== $cached ) {
			return $cached;
		}

		$settings = jpkcom_harden_trim_get_stored();

		foreach ( array_keys( array: $settings ) as $key ) {
			$state = jpkcom_harden_trim_constant_state( $key );

			if ( $state['valid'] ) {
				$settings[ $key ] = $state['value'];
			}
		}

		// A filter callback that reads the settings gets the unfiltered values instead of recursing.
		jpkcom_harden_trim_settings_cache( 'set', $settings );

		/**
		 * Filters the effective settings.
		 *
		 * Returned values pass the sanitiser again; invalid ones fall back to
		 * the default, a non-array return value is ignored. Runs once per site
		 * and request, when the main file first reads the settings on
		 * plugins_loaded priority 5; the result is cached. Add callbacks from
		 * a plugin or mu-plugin file body: a theme or a later hook is too late.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, mixed> $settings Key => effective value.
		 */
		$filtered = apply_filters( 'jpkcom_harden_trim_settings', $settings );

		if ( is_array( $filtered ) ) {
			$settings = jpkcom_harden_trim_sanitize_settings( array_merge( $settings, $filtered ) )['settings'];
		}

		jpkcom_harden_trim_settings_cache( 'set', $settings );

		return $settings;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_save_settings' ) ) {

	/**
	 * Sanitise and store a complete settings array.
	 *
	 * Keys missing from `$input` are stored with their default (a form sends
	 * every field; an unchecked role list sends nothing). Locked keys keep their
	 * stored value, whatever the input says, unless `$keep_locked` is false:
	 * an import stores what the file says for them too, and the
	 * constant still wins at runtime.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $input       Raw settings.
	 * @param bool                 $keep_locked Whether locked keys keep their stored value.
	 * @return array{settings: array<string, mixed>, rejected: list<string>} What was stored, and what was rejected.
	 */
	function jpkcom_harden_trim_save_settings( array $input, bool $keep_locked = true ): array {
		$result = jpkcom_harden_trim_sanitize_settings( $input );
		$locked = $keep_locked ? jpkcom_harden_trim_locked_keys() : array();

		if ( array() !== $locked ) {
			$stored = jpkcom_harden_trim_get_stored();

			foreach ( array_keys( array: $locked ) as $key ) {
				$result['settings'][ $key ] = $stored[ $key ];
			}
		}

		update_option(
			jpkcom_harden_trim_option_name(),
			array(
				'schema'   => 1,
				'updated'  => (string) current_time( 'c' ),
				'settings' => $result['settings'],
			),
			true
		);

		jpkcom_harden_trim_settings_reset_cache();

		return $result;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_apply_preset' ) ) {

	/**
	 * Apply a preset to a settings array (does not store it).
	 *
	 * Every unlocked preset-managed key gets the preset's value or, if the
	 * preset does not list it, its default. All other keys stay as they are.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $preset  harmless, recommended or strict.
	 * @param array<string, mixed> $current Settings to start from.
	 * @return array{settings: array<string, mixed>, changed: list<string>} New settings and the keys whose value changed.
	 */
	function jpkcom_harden_trim_apply_preset( string $preset, array $current ): array {
		$presets = jpkcom_harden_trim_presets();

		if ( ! array_key_exists( key: $preset, array: $presets ) ) {
			return array( 'settings' => $current, 'changed' => array() );
		}

		$values   = $presets[ $preset ];
		$defaults = jpkcom_harden_trim_defaults();
		$locked   = jpkcom_harden_trim_locked_keys();
		$settings = $current;
		$changed  = array();

		foreach ( jpkcom_harden_trim_preset_managed_keys() as $key ) {
			if ( isset( $locked[ $key ] ) ) {
				continue;
			}

			$new = array_key_exists( key: $key, array: $values ) ? $values[ $key ] : $defaults[ $key ];

			if ( ! array_key_exists( key: $key, array: $settings ) || $settings[ $key ] !== $new ) {
				$changed[] = $key;
			}

			$settings[ $key ] = $new;
		}

		return array( 'settings' => $settings, 'changed' => $changed );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_user_roles' ) ) {

	/**
	 * The roles a user holds for the role-based settings.
	 *
	 * On multisite WP_User::roles only lists the current site's roles, so a
	 * super admin without a role on a site has none there. Policy: a super
	 * admin counts as `administrator` on every site of the network, both for
	 * the exempt roles and for the application-password roles. On a single
	 * site is_super_admin() is not consulted.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User $user User.
	 * @return list<string> Role slugs.
	 */
	function jpkcom_harden_trim_user_roles( WP_User $user ): array {
		$roles = array_values( array: array_filter( array: (array) $user->roles, callback: 'is_string' ) );

		if ( $user->ID > 0 && is_multisite() && is_super_admin( $user->ID ) && ! in_array( needle: 'administrator', haystack: $roles, strict: true ) ) {
			$roles[] = 'administrator';
		}

		return $roles;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_is_exempt' ) ) {

	/**
	 * Whether a user is exempt from the role-scoped features.
	 *
	 * With no exempt roles and no filter callback, the current user is never
	 * looked up, so the default settings cannot trigger an early user lookup.
	 * Never cached: the answer depends on the user and is decided per call.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_User|null $user User to check; null means the current user.
	 * @return bool True when the user holds an exempt role.
	 */
	function jpkcom_harden_trim_is_exempt( ?WP_User $user = null ): bool {
		$roles = jpkcom_harden_trim_settings()['exempt_roles'];

		if ( array() === $roles && ! has_filter( 'jpkcom_harden_trim_is_exempt' ) ) {
			return false;
		}

		if ( null === $user && function_exists( function: 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
		}

		$exempt = $user instanceof WP_User
			&& $user->ID > 0
			&& array() !== $roles
			&& array() !== array_intersect( jpkcom_harden_trim_user_roles( $user ), $roles );

		/**
		 * Filters whether a user is exempt from the role-scoped features.
		 *
		 * @since 1.0.0
		 *
		 * @param bool         $exempt Whether the user holds an exempt role.
		 * @param WP_User|null $user   The user checked.
		 */
		return (bool) apply_filters( 'jpkcom_harden_trim_is_exempt', $exempt, $user );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_is_active_value' ) ) {

	/**
	 * Whether a value means "the feature does something".
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value to judge.
	 * @return bool True when the value switches the feature on.
	 */
	function jpkcom_harden_trim_is_active_value( string $key, mixed $value ): bool {
		$entry = jpkcom_harden_trim_schema()[ $key ] ?? null;

		if ( null === $entry ) {
			return false;
		}

		$inactive_enum = array(
			'asset_version' => 'off',
			'heartbeat'     => 'enabled',
			'app_passwords' => 'unchanged',
		);

		return match ( $entry['type'] ) {
			'bool'  => true === $value,
			'int'   => null !== $value,
			'roles' => is_array( $value ) && array() !== $value,
			'enum'  => is_string( $value ) && ( $inactive_enum[ $key ] ?? null ) !== $value,
			default => false,
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_feature_applies' ) ) {

	/**
	 * Whether a feature acts for the current request.
	 *
	 * Active value, and for role-scoped keys a user who is not exempt. Feature
	 * modules call this inside their callbacks, where the user is known.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return bool True when the feature should act.
	 */
	function jpkcom_harden_trim_feature_applies( string $key ): bool {
		$settings = jpkcom_harden_trim_settings();

		if ( ! array_key_exists( key: $key, array: $settings ) || ! jpkcom_harden_trim_is_active_value( $key, $settings[ $key ] ) ) {
			return false;
		}

		if ( ! jpkcom_harden_trim_schema()[ $key ]['role_scoped'] ) {
			return true;
		}

		return ! jpkcom_harden_trim_is_exempt();
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_activate' ) ) {

	/**
	 * Store the defaults on activation; never overwrites an existing option.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_activate(): void {
		add_option(
			jpkcom_harden_trim_option_name(),
			array(
				'schema'   => 1,
				'updated'  => '',
				'settings' => jpkcom_harden_trim_defaults(),
			),
			'',
			true
		);
	}

}
