<?php
/**
 * WordPress Abilities API integration
 *
 * Registers one read-only ability, `jpkcom-harden-trim/get-status`: what every
 * setting of this plugin does on this site and why. The answer comes from
 * jpkcom_harden_trim_status(), the same function `wp harden-trim status`
 * prints, so the two cannot disagree.
 *
 * Its value is the cases nothing else shows at a glance: a switch that is on
 * and does nothing (kill switch, a core constant that wins, a block theme), a
 * value that does not come from the settings page (plugin constant, filter),
 * and a constant that is ignored because its value is invalid.
 *
 * The capability is `manage_options`: the answer describes how the site is
 * hardened, which is configuration an administrator owns.
 *
 * The labels, descriptions and messages stay English in every language, as in
 * every JPKCom plugin's abilities: they are read by MCP clients and agents,
 * and their wording is the feature.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.2.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY' ) ) {

	/**
	 * Ability category.
	 *
	 * Categories are global and registration is FIRST-WINS, so this goes
	 * through wp_has_ability_category() rather than assuming.
	 *
	 * @since 1.2.0
	 */
	define( 'JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY', 'jpkcom-site' );

}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_ABILITY_INPUT_KEYS' ) ) {

	/**
	 * Top-level input keys per ability.
	 *
	 * Cross-checked against the registered schema by tests/test-abilities.php.
	 *
	 * @since 1.2.0
	 */
	define(
		'JPKCOM_HARDEN_TRIM_ABILITY_INPUT_KEYS',
		array(
			'jpkcom-harden-trim/get-status' => array(),
		)
	);

}

if ( ! function_exists( function: 'jpkcom_harden_trim_abilities_constant' ) ) {

	/**
	 * JPKCOM_HARDEN_TRIM_ABILITIES as a boolean.
	 *
	 * @since 1.2.0
	 *
	 * @return bool|null Null when undefined or not a boolean value.
	 */
	function jpkcom_harden_trim_abilities_constant(): ?bool {
		if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_ABILITIES' ) ) {
			return null;
		}

		$value = constant( name: 'JPKCOM_HARDEN_TRIM_ABILITIES' );

		return is_scalar( $value ) ? filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) : null;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_abilities_enabled' ) ) {

	/**
	 * Whether the ability should be registered at all.
	 *
	 * `JPKCOM_HARDEN_TRIM_ABILITIES` set to false in wp-config.php suppresses
	 * it. The feature kill switch JPKCOM_HARDEN_TRIM_DISABLE_ALL does not: the
	 * ability is how to see that the kill switch is on.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True when registration should proceed.
	 */
	function jpkcom_harden_trim_abilities_enabled(): bool {
		// Read like every boolean constant of this plugin: 'false' and 'off' switch it
		// off, a value that is no boolean is ignored (and named as invalid).
		if ( false === jpkcom_harden_trim_abilities_constant() ) {
			return false;
		}

		return function_exists( function: 'wp_register_ability' )
			&& function_exists( function: 'wp_register_ability_category' )
			&& function_exists( function: 'jpkcom_harden_trim_status' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_ability_capability' ) ) {

	/**
	 * Whether the current user may run an ability.
	 *
	 * @since 1.2.0
	 *
	 * @param string $ability Ability name.
	 * @return bool True when the current user holds the capability.
	 */
	function jpkcom_harden_trim_ability_capability( string $ability ): bool {
		/**
		 * Filters the capability required to run an ability.
		 *
		 * @since 1.2.0
		 *
		 * @param string $capability Capability, default manage_options.
		 * @param string $ability    Ability name.
		 */
		$capability = apply_filters( 'jpkcom_harden_trim_ability_capability', 'manage_options', $ability );

		return current_user_can( is_string( $capability ) && '' !== $capability ? $capability : 'manage_options' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_ability_meta' ) ) {

	/**
	 * The meta array of an ability.
	 *
	 * All three annotations explicit: they default to null and the REST run
	 * controller derives the HTTP verb from them, so without readonly the run
	 * route would be POST-only.
	 *
	 * @since 1.2.0
	 *
	 * @param string $ability Ability name.
	 * @return array<string, mixed> Meta array.
	 */
	function jpkcom_harden_trim_ability_meta( string $ability ): array {
		$meta = array(
			'show_in_rest' => true,
			'public'       => true,
			'mcp'          => array( 'public' => true ),
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
		);

		/**
		 * Filters the meta array of an ability (exposure and annotations).
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, mixed> $meta    Meta array.
		 * @param string               $ability Ability name.
		 */
		$filtered = apply_filters( 'jpkcom_harden_trim_ability_meta', $meta, $ability );

		return is_array( $filtered ) ? $filtered : $meta;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_ability_permission' ) ) {

	/**
	 * Permission callback of get-status.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Validated input, unused.
	 * @return bool True when the current user may run the ability.
	 */
	function jpkcom_harden_trim_ability_permission( mixed $input = null ): bool {
		return jpkcom_harden_trim_ability_capability( 'jpkcom-harden-trim/get-status' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_ability_get_status' ) ) {

	/**
	 * Execute callback of get-status.
	 *
	 * A Throwable from reading the settings becomes a WP_Error with status
	 * 500 and a message saying that repeating the call will not help.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $input Ability input (an empty object).
	 * @return array<string, mixed>|WP_Error Status or error.
	 */
	function jpkcom_harden_trim_ability_get_status( mixed $input = null ): array|WP_Error {
		try {
			return jpkcom_harden_trim_status();
		} catch ( \Throwable $e ) {
			// No log line: the plugin promises no PHP notice in any mode.
			return new WP_Error(
				'jpkcom_harden_trim_status_failed',
				__( 'The Harden & Trim settings of this site could not be read. This is a condition on the site, not a problem with the request, so repeating the call unchanged will not help.', 'jpkcom-harden-trim' ),
				array( 'status' => 500 )
			);
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_get_ability_definitions' ) ) {

	/**
	 * The registration arguments of every ability.
	 *
	 * Reads no WordPress state and touches no registry, so the tests can
	 * assert the shape without a WordPress installation.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string, array<string, mixed>> Ability name => registration args.
	 */
	function jpkcom_harden_trim_get_ability_definitions(): array {
		$value_types = array( 'boolean', 'integer', 'string', 'array', 'null' );

		return array(
			'jpkcom-harden-trim/get-status' => array(
				'label'               => __( 'Get the hardening status', 'jpkcom-harden-trim' ),
				'description'         => __( 'Returns what every setting of the JPKCom Harden & Trim plugin does on this site and why: the effective value, where it comes from (default, stored on the settings page, a plugin constant in wp-config.php, the jpkcom_harden_trim_settings filter, or a WordPress core constant that decides instead of the plugin), the constant that locks it, and whether the feature acts. A switch can be on and still do nothing; the reason field says why. Read this before advising on the site\'s hardening, and before assuming that a value seen on the settings page is the one in effect.', 'jpkcom-harden-trim' ),
				'category'            => JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					// Top level, deliberately: normalize_input() substitutes this value when
					// the input is exactly null and nothing else does. An object rather than
					// [], because the MCP adapter publishes the schema verbatim.
					'default'              => (object) array(),
					// NO `properties` key, and additionalProperties => false. An empty
					// stdClass here is an anonymous fatal (core does an array offset on it)
					// and an empty array is invalid JSON Schema.
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'kill_switch'       => array(
							'type'        => 'object',
							'description' => __( 'The constant JPKCOM_HARDEN_TRIM_DISABLE_ALL. When active, no feature of this plugin acts, whatever the settings say.', 'jpkcom-harden-trim' ),
							'properties'  => array(
								'defined' => array( 'type' => 'boolean' ),
								'valid'   => array( 'type' => 'boolean' ),
								'active'  => array( 'type' => 'boolean' ),
							),
						),
						'invalid_constants' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Plugin constants defined in wp-config.php with a value the plugin cannot use. They are ignored, silently on the front end.', 'jpkcom-harden-trim' ),
						),
						'block_theme'       => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the active theme is a block theme. Two settings (block_library_css, global_styles) have no effect with a block theme.', 'jpkcom-harden-trim' ),
						),
						'exempt_roles'      => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => __( 'Roles exempt from the role-scoped features (role_scoped true). For users holding one of them those features do not act, even where acts is true.', 'jpkcom-harden-trim' ),
						),
						'settings'          => array(
							'type'        => 'array',
							'description' => __( 'One entry per setting, in settings-page order.', 'jpkcom-harden-trim' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'key'         => array( 'type' => 'string', 'description' => __( 'Setting key, as used by the plugin constant JPKCOM_HARDEN_TRIM_<KEY> and by wp harden-trim.', 'jpkcom-harden-trim' ) ),
									'group'       => array( 'type' => 'string', 'enum' => array( 'performance', 'head', 'privacy', 'security', 'roles' ) ),
									'type'        => array( 'type' => 'string', 'enum' => array( 'bool', 'enum', 'int', 'roles' ) ),
									'value'       => array( 'type' => $value_types, 'description' => __( 'The plugin\'s effective value. For post_revisions and autosave_interval null means the WordPress default.', 'jpkcom-harden-trim' ) ),
									'stored'      => array( 'type' => $value_types, 'description' => __( 'The value stored on the settings page.', 'jpkcom-harden-trim' ) ),
									'default'     => array( 'type' => $value_types, 'description' => __( 'The plugin default.', 'jpkcom-harden-trim' ) ),
									'source'      => array( 'type' => 'string', 'enum' => array( 'core_constant', 'constant', 'filter', 'stored', 'default' ), 'description' => __( 'Where the effective value comes from. core_constant: a WordPress core constant decides instead of the plugin; see core_value.', 'jpkcom-harden-trim' ) ),
									'locked_by'   => array( 'type' => array( 'string', 'null' ), 'description' => __( 'The constant that locks the setting on the settings page, or null.', 'jpkcom-harden-trim' ) ),
									'core_value'  => array( 'type' => array( 'boolean', 'integer', 'number', 'string', 'null' ), 'description' => __( 'The value of the core constant when source is core_constant, otherwise null.', 'jpkcom-harden-trim' ) ),
									'acts'        => array( 'type' => 'boolean', 'description' => __( 'Whether the plugin\'s feature does something on this site.', 'jpkcom-harden-trim' ) ),
									'reason'      => array( 'type' => array( 'string', 'null' ), 'enum' => array( 'kill_switch', 'core_constant', 'block_theme', 'plain_permalinks', 'unused', 'off', null ), 'description' => __( 'Why the feature does not act: kill_switch, core_constant (a WordPress core constant decides; see core_value), block_theme, plain_permalinks (the author-enumeration block needs pretty permalinks), unused (a role list nothing reads right now), off. Null when it acts.', 'jpkcom-harden-trim' ) ),
									'role_scoped' => array( 'type' => 'boolean', 'description' => __( 'Whether users holding an exempt role are spared this feature.', 'jpkcom-harden-trim' ) ),
								),
							),
						),
					),
				),
				'execute_callback'    => 'jpkcom_harden_trim_ability_get_status',
				'permission_callback' => 'jpkcom_harden_trim_ability_permission',
				'meta'                => jpkcom_harden_trim_ability_meta( 'jpkcom-harden-trim/get-status' ),
			),
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_ability_category' ) ) {

	/**
	 * Register the category, unless another plugin already did.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_register_ability_category(): void {
		if ( ! jpkcom_harden_trim_abilities_enabled() ) {
			return;
		}

		if ( function_exists( function: 'wp_has_ability_category' ) && wp_has_ability_category( JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY,
			array(
				'label'       => __( 'JPKCom Site', 'jpkcom-harden-trim' ),
				'description' => __( 'Read-only access to how the JPKCom plugins configure this site.', 'jpkcom-harden-trim' ),
			)
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_abilities' ) ) {

	/**
	 * Register the abilities.
	 *
	 * wp_register_ability() returns null on every failure path and reports
	 * only through _doing_it_wrong(), which is silent in production.
	 *
	 * @since 1.2.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_register_abilities(): void {
		if ( ! jpkcom_harden_trim_abilities_enabled() ) {
			return;
		}

		foreach ( jpkcom_harden_trim_get_ability_definitions() as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

}

add_action( 'wp_abilities_api_categories_init', 'jpkcom_harden_trim_register_ability_category' );
add_action( 'wp_abilities_api_init', 'jpkcom_harden_trim_register_abilities' );
