<?php
/**
 * Settings schema and presets
 *
 * The single table of every setting this plugin knows: type, default, allowed
 * values, section, whether the role exemption applies, the core constant that
 * locks it, and the badges the settings page shows. Defaults and presets live
 * here and nowhere else, so changing one of them is a one-line edit.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_schema_entry' ) ) {

	/**
	 * Build one schema entry with every field present.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $type    One of bool, enum, int, roles.
	 * @param mixed                $default Default value on activation.
	 * @param string               $group   Section: performance, head, privacy, security, roles.
	 * @param array<string, mixed> $extra   Overrides for choices, nullable, role_scoped,
	 *                                      core_constant, block_theme_unavailable, flags.
	 * @return array<string, mixed> The complete entry.
	 */
	function jpkcom_harden_trim_schema_entry( string $type, mixed $default, string $group, array $extra = array() ): array {
		return array_merge(
			array(
				'type'                    => $type,
				'default'                 => $default,
				'choices'                 => array(),
				'nullable'                => false,
				'group'                   => $group,
				'role_scoped'             => false,
				'core_constant'           => null,
				'block_theme_unavailable' => false,
				'flags'                   => array(),
			),
			$extra
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_schema' ) ) {

	/**
	 * Every setting, in settings-page order (performance, head, privacy, security, roles).
	 *
	 * Flags drive the badges on the settings page: `appearance` (changes how
	 * the site looks), `editors` (changes the editing workflow), `global` (the
	 * role exemption does not apply), `irreversible` (effects that a later
	 * switch-off does not undo).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array<string, mixed>> Key => entry.
	 */
	function jpkcom_harden_trim_schema(): array {
		static $schema = null;

		if ( null !== $schema ) {
			return $schema;
		}

		$scoped = array( 'role_scoped' => true );
		$global = array( 'flags' => array( 'global' ) );

		$schema = array(
			// 4.1 Performance.
			'emoji'              => jpkcom_harden_trim_schema_entry( 'bool', true, 'performance', $scoped ),
			'self_pingbacks'     => jpkcom_harden_trim_schema_entry( 'bool', true, 'performance', $global ),
			'asset_version'      => jpkcom_harden_trim_schema_entry( 'enum', 'off', 'performance', array(
				'choices'     => array( 'off', 'hash', 'remove' ),
				'role_scoped' => true,
			) ),
			'post_embeds'        => jpkcom_harden_trim_schema_entry( 'bool', false, 'performance', array( 'flags' => array( 'appearance', 'global' ) ) ),
			'comment_reply'      => jpkcom_harden_trim_schema_entry( 'bool', false, 'performance', $scoped ),
			'dashicons'          => jpkcom_harden_trim_schema_entry( 'bool', false, 'performance', array( 'flags' => array( 'appearance', 'global' ) ) ),
			'block_library_css'  => jpkcom_harden_trim_schema_entry( 'bool', false, 'performance', array(
				'role_scoped'             => true,
				'block_theme_unavailable' => true,
				'flags'                   => array( 'appearance' ),
			) ),
			'global_styles'      => jpkcom_harden_trim_schema_entry( 'bool', false, 'performance', array(
				'role_scoped'             => true,
				'block_theme_unavailable' => true,
				'flags'                   => array( 'appearance' ),
			) ),
			'heartbeat'          => jpkcom_harden_trim_schema_entry( 'enum', 'enabled', 'performance', array(
				'choices'     => array( 'enabled', 'optimized', 'disabled' ),
				'role_scoped' => true,
				'flags'       => array( 'editors' ),
			) ),
			'post_revisions'     => jpkcom_harden_trim_schema_entry( 'int', null, 'performance', array(
				'choices'       => array( 0, 1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 50, 100 ),
				'nullable'      => true,
				'core_constant' => 'WP_POST_REVISIONS',
				'flags'         => array( 'editors', 'global', 'irreversible' ),
			) ),
			'autosave_interval'  => jpkcom_harden_trim_schema_entry( 'int', null, 'performance', array(
				'choices'       => array( 120, 180, 240, 300, 600, 900, 1200, 1800, 3600 ),
				'nullable'      => true,
				'core_constant' => 'AUTOSAVE_INTERVAL',
				'flags'         => array( 'editors', 'global' ),
			) ),

			// 4.2 Clean up <head> (all global).
			'head_shortlink'     => jpkcom_harden_trim_schema_entry( 'bool', true, 'head', $global ),
			'head_rsd'           => jpkcom_harden_trim_schema_entry( 'bool', true, 'head', $global ),
			'head_rest_links'    => jpkcom_harden_trim_schema_entry( 'bool', true, 'head', $global ),
			'head_feed_links'    => jpkcom_harden_trim_schema_entry( 'bool', false, 'head', $global ),
			'head_generator'     => jpkcom_harden_trim_schema_entry( 'bool', true, 'head', $global ),

			// 4.3 Privacy.
			'block_author_enum'  => jpkcom_harden_trim_schema_entry( 'bool', true, 'privacy', $global ),
			'hide_rest_users'    => jpkcom_harden_trim_schema_entry( 'bool', true, 'privacy', $global ),
			'oembed_author'      => jpkcom_harden_trim_schema_entry( 'bool', true, 'privacy', $global ),
			'sitemap_users'      => jpkcom_harden_trim_schema_entry( 'bool', true, 'privacy', $global ),
			'browse_happy'       => jpkcom_harden_trim_schema_entry( 'bool', true, 'privacy', $global ),
			'dashboard_events'   => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', $global ),
			'local_avatars'      => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', array( 'flags' => array( 'appearance', 'global' ) ) ),
			'comment_ip'         => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', array( 'flags' => array( 'global', 'irreversible' ) ) ),
			'embed_privacy'      => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', $global ),
			'editor_remote'      => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', array( 'flags' => array( 'editors', 'global' ) ) ),
			'ai_features'        => jpkcom_harden_trim_schema_entry( 'bool', false, 'privacy', array(
				'core_constant' => 'WP_AI_SUPPORT',
				'flags'         => array( 'editors', 'global' ),
			) ),

			// 4.4 Security.
			'login_errors'       => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'fingerprint_headers' => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'header_nosniff'     => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'header_referrer'    => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'header_frame'       => jpkcom_harden_trim_schema_entry( 'bool', false, 'security', $global ),
			'header_permissions' => jpkcom_harden_trim_schema_entry( 'bool', false, 'security', $global ),
			'header_hsts'        => jpkcom_harden_trim_schema_entry( 'bool', false, 'security', array( 'flags' => array( 'global', 'irreversible' ) ) ),
			'rest_jsonp'         => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'file_edit'          => jpkcom_harden_trim_schema_entry( 'bool', false, 'security', array(
				'core_constant' => 'DISALLOW_FILE_EDIT',
				'flags'         => array( 'editors', 'global' ),
			) ),
			'app_passwords'      => jpkcom_harden_trim_schema_entry( 'enum', 'unchanged', 'security', array(
				'choices' => array( 'unchanged', 'disabled', 'roles' ),
				'flags'   => array( 'editors', 'global' ),
			) ),
			'app_password_roles' => jpkcom_harden_trim_schema_entry( 'roles', array(), 'security' ),
			// Owner decision 2026-10-20: the one security switch that is on by default (see CLAUDE.md).
			'xmlrpc'             => jpkcom_harden_trim_schema_entry( 'bool', true, 'security', $global ),
			'comments'           => jpkcom_harden_trim_schema_entry( 'bool', false, 'security', array( 'flags' => array( 'appearance', 'editors', 'global' ) ) ),

			// 4.5 Roles. Default: nobody is exempt (user decision).
			'exempt_roles'       => jpkcom_harden_trim_schema_entry( 'roles', array(), 'roles' ),
		);

		return $schema;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_defaults' ) ) {

	/**
	 * Default value of every setting.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, mixed> Key => default.
	 */
	function jpkcom_harden_trim_defaults(): array {
		return array_map(
			callback: static fn( array $entry ): mixed => $entry['default'],
			array: jpkcom_harden_trim_schema()
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_presets' ) ) {

	/**
	 * The three presets as overrides of the defaults.
	 *
	 * A key a preset does not list gets its default when the preset is applied
	 * (see jpkcom_harden_trim_preset_managed_keys()).
	 *
	 * @since 1.0.0
	 *
	 * @return array{harmless: array<string, mixed>, recommended: array<string, mixed>, strict: array<string, mixed>}
	 */
	function jpkcom_harden_trim_presets(): array {
		$recommended = array(
			'heartbeat'        => 'optimized',
			'header_frame'     => true,
			'dashboard_events' => true,
			'embed_privacy'    => true,
			'file_edit'        => true,
			'post_revisions'   => 20,
			'xmlrpc'           => true,
		);

		$strict = array_merge(
			$recommended,
			array(
				'asset_version'      => 'hash',
				'post_embeds'        => true,
				'comment_reply'      => true,
				'dashicons'          => true,
				'local_avatars'      => true,
				'comment_ip'         => true,
				'editor_remote'      => true,
				'ai_features'        => true,
				'header_permissions' => true,
				'post_revisions'     => 5,
				'autosave_interval'  => 120,
			)
		);

		return array(
			'harmless'    => array(),
			'recommended' => $recommended,
			'strict'      => $strict,
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_preset_managed_keys' ) ) {

	/**
	 * Keys a preset assigns a value to; every other key is never touched.
	 *
	 * The excluded keys either have a break path no preset may switch on, or
	 * hold a decision only the site owner can make (role lists, application
	 * passwords, HSTS, feed discovery, comments). `xmlrpc` is managed: it is
	 * on in every preset, so no preset can reopen XML-RPC.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Keys in schema order.
	 */
	function jpkcom_harden_trim_preset_managed_keys(): array {
		$never = array( 'block_library_css', 'global_styles', 'header_hsts', 'head_feed_links', 'app_passwords', 'app_password_roles', 'comments', 'exempt_roles' );

		return array_values( array: array_diff( array_keys( array: jpkcom_harden_trim_schema() ), $never ) );
	}

}
