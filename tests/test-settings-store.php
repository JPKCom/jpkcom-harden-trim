<?php
/**
 * Tests for the settings schema and store: defaults, sanitiser, corrupt
 * options, constants, locks, role exemption, kill switch.
 *
 * Constant scenarios run in child processes (fixture-*.php), because a
 * constant cannot be undefined once set.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$root = dirname( path: __DIR__ );

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

$jht_api = array(
	'jpkcom_harden_trim_schema',
	'jpkcom_harden_trim_defaults',
	'jpkcom_harden_trim_option_name',
	'jpkcom_harden_trim_sanitize_value',
	'jpkcom_harden_trim_sanitize_settings',
	'jpkcom_harden_trim_get_stored',
	'jpkcom_harden_trim_constant_state',
	'jpkcom_harden_trim_core_lock',
	'jpkcom_harden_trim_locked_keys',
	'jpkcom_harden_trim_invalid_constants',
	'jpkcom_harden_trim_settings',
	'jpkcom_harden_trim_settings_reset_cache',
	'jpkcom_harden_trim_save_settings',
	'jpkcom_harden_trim_is_exempt',
	'jpkcom_harden_trim_is_active_value',
	'jpkcom_harden_trim_feature_applies',
	'jpkcom_harden_trim_activate',
);

$jht_missing = array_values( array: array_filter( array: $jht_api, callback: static fn( string $fn ): bool => ! function_exists( $fn ) ) );
chk( 'store API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

/**
 * Write a settings array into the stubbed option and clear the cache.
 *
 * @param array<string, mixed> $settings Settings to store.
 * @return void
 */
function jht_store( array $settings ): void {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

// Schema shape and order.

$schema   = jpkcom_harden_trim_schema();
$expected_order = array(
	'emoji', 'self_pingbacks', 'asset_version', 'post_embeds', 'comment_reply', 'dashicons', 'block_library_css',
	'global_styles', 'heartbeat', 'post_revisions', 'autosave_interval',
	'head_shortlink', 'head_rsd', 'head_rest_links', 'head_feed_links', 'head_generator',
	'block_author_enum', 'hide_rest_users', 'oembed_author', 'sitemap_users', 'browse_happy', 'dashboard_events',
	'local_avatars', 'comment_ip', 'embed_privacy', 'editor_remote', 'ai_features',
	'login_errors', 'fingerprint_headers', 'header_nosniff', 'header_referrer', 'header_frame', 'header_permissions',
	'header_hsts', 'rest_jsonp', 'file_edit', 'app_passwords', 'app_password_roles', 'xmlrpc', 'comments',
	'exempt_roles',
);
chk( 'schema keys in settings-page order', array_keys( array: $schema ) === $expected_order );

$jht_shape_ok = true;
foreach ( $schema as $key => $entry ) {
	foreach ( array( 'type', 'default', 'choices', 'nullable', 'group', 'role_scoped', 'core_constant', 'block_theme_unavailable', 'flags' ) as $field ) {
		if ( ! array_key_exists( key: $field, array: $entry ) ) {
			$jht_shape_ok = false;
			echo "  schema {$key} lacks {$field}\n";
		}
	}
}
chk( 'every schema entry has the full shape', $jht_shape_ok );

chk( 'post_revisions choices', array( 0, 1, 2, 3, 4, 5, 10, 15, 20, 25, 30, 50, 100 ) === $schema['post_revisions']['choices'] );
chk( 'autosave_interval choices', array( 120, 180, 240, 300, 600, 900, 1200, 1800, 3600 ) === $schema['autosave_interval']['choices'] );
chk( 'heartbeat choices', array( 'enabled', 'optimized', 'disabled' ) === $schema['heartbeat']['choices'] );
chk( 'asset_version choices', array( 'off', 'hash', 'remove' ) === $schema['asset_version']['choices'] );
chk( 'app_passwords choices', array( 'unchanged', 'disabled', 'roles' ) === $schema['app_passwords']['choices'] );
chk( 'int keys nullable', true === $schema['post_revisions']['nullable'] && true === $schema['autosave_interval']['nullable'] );

$jht_core = array();
foreach ( $schema as $key => $entry ) {
	if ( null !== $entry['core_constant'] ) {
		$jht_core[ $key ] = $entry['core_constant'];
	}
}
chk(
	'core constants mapping',
	array(
		'post_revisions'    => 'WP_POST_REVISIONS',
		'autosave_interval' => 'AUTOSAVE_INTERVAL',
		'ai_features'       => 'WP_AI_SUPPORT',
		'file_edit'         => 'DISALLOW_FILE_EDIT',
	) == $jht_core
);

$jht_scoped = array_keys( array: array_filter( array: $schema, callback: static fn( array $e ): bool => true === $e['role_scoped'] ) );
chk( 'role_scoped keys', array( 'emoji', 'asset_version', 'comment_reply', 'block_library_css', 'global_styles', 'heartbeat' ) === $jht_scoped );

$jht_block = array_keys( array: array_filter( array: $schema, callback: static fn( array $e ): bool => true === $e['block_theme_unavailable'] ) );
chk( 'block_theme_unavailable keys', array( 'block_library_css', 'global_styles' ) === $jht_block );

$jht_groups = array_count_values( array: array_column( array: $schema, column_key: 'group' ) );
chk( 'group sizes 11/5/11/13/1', array( 'performance' => 11, 'head' => 5, 'privacy' => 11, 'security' => 13, 'roles' => 1 ) === $jht_groups );

$jht_global_ok = true;
foreach ( $schema as $key => $entry ) {
	$is_global = in_array( needle: 'global', haystack: $entry['flags'], strict: true );
	if ( $entry['role_scoped'] && $is_global ) {
		$jht_global_ok = false;
	}
	if ( 'roles' !== $entry['type'] && ! $entry['role_scoped'] && ! $is_global ) {
		$jht_global_ok = false;
	}
}
chk( 'global flag exactly on the non-role-scoped features', $jht_global_ok );

// Defaults are harmless.

$defaults = jpkcom_harden_trim_defaults();
$jht_on   = array_keys( array: array_filter( array: $defaults, callback: static fn( mixed $v ): bool => true === $v ) );
chk(
	'defaults_are_harmless: ON set is exactly the harmless set',
	array( 'emoji', 'self_pingbacks', 'head_shortlink', 'head_rsd', 'head_rest_links', 'head_generator', 'block_author_enum', 'hide_rest_users', 'oembed_author', 'sitemap_users', 'browse_happy', 'login_errors', 'fingerprint_headers', 'header_nosniff', 'header_referrer', 'rest_jsonp', 'xmlrpc' ) === $jht_on
);
chk( 'defaults_are_harmless: enum defaults', 'off' === $defaults['asset_version'] && 'enabled' === $defaults['heartbeat'] && 'unchanged' === $defaults['app_passwords'] );
chk( 'defaults_are_harmless: int defaults null', null === $defaults['post_revisions'] && null === $defaults['autosave_interval'] );
chk( 'defaults_are_harmless: role lists empty', array() === $defaults['exempt_roles'] && array() === $defaults['app_password_roles'] );
chk( 'defaults: xmlrpc on (owner exception), comments off', true === $defaults['xmlrpc'] && false === $defaults['comments'] );
chk( 'schema: xmlrpc and comments sit after app_password_roles, before exempt_roles, xmlrpc first', array( 'app_password_roles', 'xmlrpc', 'comments', 'exempt_roles' ) === array_slice( array: array_keys( array: $schema ), offset: -4 ) );
chk( 'schema: xmlrpc is a global security bool', 'bool' === $schema['xmlrpc']['type'] && 'security' === $schema['xmlrpc']['group'] && array( 'global' ) === $schema['xmlrpc']['flags'] && ! $schema['xmlrpc']['role_scoped'] && null === $schema['xmlrpc']['core_constant'] );
chk( 'schema: comments is a global security bool flagged appearance and editors', 'bool' === $schema['comments']['type'] && 'security' === $schema['comments']['group'] && array( 'appearance', 'editors', 'global' ) === $schema['comments']['flags'] && ! $schema['comments']['role_scoped'] && null === $schema['comments']['core_constant'] );
chk( 'defaults equal schema defaults', array_map( callback: static fn( array $e ): mixed => $e['default'], array: $schema ) === $defaults );
chk( 'option name', 'jpkcom_harden_trim_settings' === jpkcom_harden_trim_option_name() );

// Sanitiser: bool.

foreach ( array( '1', 'on', 'true', 'yes', 1, true ) as $jht_v ) {
	$r = jpkcom_harden_trim_sanitize_value( 'emoji', $jht_v );
	chk( 'sanitize_bool true: ' . var_export( value: $jht_v, return: true ), true === $r['valid'] && true === $r['value'] );
}
foreach ( array( '0', 'off', 'false', '', 0, false ) as $jht_v ) {
	$r = jpkcom_harden_trim_sanitize_value( 'emoji', $jht_v );
	chk( 'sanitize_bool false: ' . var_export( value: $jht_v, return: true ), true === $r['valid'] && false === $r['value'] );
}
foreach ( array( 'banana', array(), 2, null, 1.5 ) as $jht_v ) {
	$r = jpkcom_harden_trim_sanitize_value( 'emoji', $jht_v );
	chk( 'sanitize_bool invalid: ' . var_export( value: $jht_v, return: true ), false === $r['valid'] );
}

// Sanitiser: enum, int, roles, unknown.

chk( 'sanitize_enum heartbeat=turbo invalid', false === jpkcom_harden_trim_sanitize_value( 'heartbeat', 'turbo' )['valid'] );
chk( 'sanitize_enum heartbeat=optimized valid', array( 'valid' => true, 'value' => 'optimized' ) === array_intersect_key( jpkcom_harden_trim_sanitize_value( 'heartbeat', 'optimized' ), array( 'valid' => 0, 'value' => 0 ) ) );
chk( 'sanitize_enum case-sensitive', false === jpkcom_harden_trim_sanitize_value( 'heartbeat', 'Optimized' )['valid'] );
chk( 'sanitize_enum rejects non-string', false === jpkcom_harden_trim_sanitize_value( 'asset_version', array( 'hash' ) )['valid'] );

$r = jpkcom_harden_trim_sanitize_value( 'post_revisions', '5' );
chk( "sanitize_int post_revisions='5' => 5", true === $r['valid'] && 5 === $r['value'] );
$r = jpkcom_harden_trim_sanitize_value( 'post_revisions', 0 );
chk( 'sanitize_int post_revisions=0 => 0', true === $r['valid'] && 0 === $r['value'] );
chk( 'sanitize_int post_revisions=6 invalid', false === jpkcom_harden_trim_sanitize_value( 'post_revisions', 6 )['valid'] );
$r = jpkcom_harden_trim_sanitize_value( 'post_revisions', '' );
chk( "sanitize_int post_revisions='' => null", true === $r['valid'] && null === $r['value'] );
$r = jpkcom_harden_trim_sanitize_value( 'post_revisions', null );
chk( 'sanitize_int post_revisions=null => null', true === $r['valid'] && null === $r['value'] );
chk( 'sanitize_int autosave_interval=60 invalid', false === jpkcom_harden_trim_sanitize_value( 'autosave_interval', 60 )['valid'] );
chk( 'sanitize_int rejects bool', false === jpkcom_harden_trim_sanitize_value( 'post_revisions', true )['valid'] );
chk( 'sanitize_int rejects float', false === jpkcom_harden_trim_sanitize_value( 'post_revisions', 5.5 )['valid'] );
chk( "sanitize_int rejects '5abc'", false === jpkcom_harden_trim_sanitize_value( 'post_revisions', '5abc' )['valid'] );
chk( 'sanitize_int rejects array', false === jpkcom_harden_trim_sanitize_value( 'post_revisions', array( 5 ) )['valid'] );

$r = jpkcom_harden_trim_sanitize_value( 'exempt_roles', array( 'editor', 'editor', 'Author', 'author' ) );
chk( 'sanitize_roles array: dedupe, sanitize_key-identical only', true === $r['valid'] && array( 'editor', 'author' ) === $r['value'] );
chk( 'sanitize_roles rejects int', false === jpkcom_harden_trim_sanitize_value( 'exempt_roles', 5 )['valid'] );
$r = jpkcom_harden_trim_sanitize_value( 'exempt_roles', '' );
chk( "sanitize_roles '' => []", true === $r['valid'] && array() === $r['value'] );
chk( 'unknown key invalid', false === jpkcom_harden_trim_sanitize_value( 'no_such_key', true )['valid'] );

$res = jpkcom_harden_trim_sanitize_settings( array( 'exempt_roles' => 'editor, ghost ,author' ) );
chk( 'sanitize_roles comma string', array( 'editor', 'author' ) === $res['settings']['exempt_roles'] );
chk( 'sanitize_roles reports exempt_roles:ghost', in_array( needle: 'exempt_roles:ghost', haystack: $res['rejected'], strict: true ) );

$res = jpkcom_harden_trim_sanitize_settings( array( 'heartbeat' => 'turbo', 'bogus' => 1, 'emoji' => 'off', 'post_revisions' => '20' ) );
chk( 'sanitize_settings invalid value => default', 'enabled' === $res['settings']['heartbeat'] );
chk( 'sanitize_settings valid values kept', false === $res['settings']['emoji'] && 20 === $res['settings']['post_revisions'] );
chk( 'sanitize_settings rejected lists heartbeat and bogus', array( 'heartbeat', 'bogus' ) === $res['rejected'] );
chk( 'sanitize_settings returns every schema key', array_keys( array: $res['settings'] ) === array_keys( array: $schema ) );

$res = jpkcom_harden_trim_sanitize_settings( 'nope' );
chk( 'sanitize_settings non-array => defaults', $defaults === $res['settings'] && array() === $res['rejected'] );

// Corrupt option.

$jht_junk = array();
for ( $i = 0; $i < 10000; $i++ ) {
	$jht_junk[ 'junk_' . $i ] = str_repeat( string: 'x', times: 3 );
}
$jht_corrupt = array(
	'garbage',
	array( 'settings' => 'x' ),
	array( 'settings' => array( 'emoji' => array( 'nested' ) ) ),
	array( 'schema' => 1, 'settings' => $jht_junk ),
	$jht_junk,
	null,
	42,
);
foreach ( $jht_corrupt as $idx => $jht_value ) {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = $jht_value;
	jpkcom_harden_trim_settings_reset_cache();
	$jht_threw = false;
	try {
		$stored    = jpkcom_harden_trim_get_stored();
		$effective = jpkcom_harden_trim_settings();
	} catch ( Throwable $e ) {
		$jht_threw = true;
		$stored    = null;
		$effective = null;
	}
	chk( "corrupt_option #{$idx}: no exception", ! $jht_threw );
	chk( "corrupt_option #{$idx}: get_stored equals defaults", $defaults === $stored );
	chk( "corrupt_option #{$idx}: effective emoji true", true === ( $effective['emoji'] ?? null ) );
}
unset( $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] );
jpkcom_harden_trim_settings_reset_cache();
chk( 'missing option => defaults', $defaults === jpkcom_harden_trim_get_stored() );

// Stored values, effective values, filter, cache.

jht_store( array( 'heartbeat' => 'optimized', 'emoji' => false ) );
$s = jpkcom_harden_trim_settings();
chk( 'stored values become effective', 'optimized' === $s['heartbeat'] && false === $s['emoji'] );

$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ]['settings']['heartbeat'] = 'disabled';
chk( 'settings are cached until reset', 'optimized' === jpkcom_harden_trim_settings()['heartbeat'] );
jpkcom_harden_trim_settings_reset_cache();
chk( 'reset_cache rereads', 'disabled' === jpkcom_harden_trim_settings()['heartbeat'] );

add_filter( 'jpkcom_harden_trim_settings', static function ( $settings ) {
	$settings['dashicons']      = true;
	$settings['heartbeat']      = 'turbo';
	$settings['post_revisions'] = 6;
	return $settings;
} );
jpkcom_harden_trim_settings_reset_cache();
$s = jpkcom_harden_trim_settings();
chk( 'settings filter can change a value', true === $s['dashicons'] );
chk( 'filtered values are re-sanitised (invalid => default)', 'enabled' === $s['heartbeat'] && null === $s['post_revisions'] );
unset( $GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] );

add_filter( 'jpkcom_harden_trim_settings', static fn( $settings ) => 'not an array' );
jpkcom_harden_trim_settings_reset_cache();
chk( 'non-array filter result keeps the unfiltered settings', 'disabled' === jpkcom_harden_trim_settings()['heartbeat'] );
unset( $GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] );

$GLOBALS['jht_nested'] = null;
add_filter( 'jpkcom_harden_trim_settings', static function ( $settings ) {
	if ( null === $GLOBALS['jht_nested'] ) {
		$GLOBALS['jht_nested'] = jpkcom_harden_trim_settings();
	}
	return $settings;
} );
jpkcom_harden_trim_settings_reset_cache();
jpkcom_harden_trim_settings();
chk( 'settings() from inside the filter does not recurse', is_array( $GLOBALS['jht_nested'] ) );
unset( $GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] );

// Save.

jht_store( array() );
$res    = jpkcom_harden_trim_save_settings( array( 'heartbeat' => 'optimized', 'emoji' => '0', 'exempt_roles' => array( 'editor', 'ghost' ) ) );
$option = $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ];
chk( 'save writes structure schema 1', 1 === $option['schema'] && is_array( $option['settings'] ) );
chk( 'save stamps updated with current_time(c)', '2026-10-19T12:00:00+00:00' === $option['updated'] );
chk( 'save stores sanitised values', 'optimized' === $option['settings']['heartbeat'] && false === $option['settings']['emoji'] && array( 'editor' ) === $option['settings']['exempt_roles'] );
chk( 'save reports dropped slugs', array( 'exempt_roles:ghost' ) === $res['rejected'] );
chk( 'save resets the cache', 'optimized' === jpkcom_harden_trim_settings()['heartbeat'] );

// Activation never overwrites.

unset( $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] );
jpkcom_harden_trim_activate();
$option = $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] ?? null;
chk( 'activate stores defaults', is_array( $option ) && 1 === $option['schema'] && '' === $option['updated'] && $defaults === $option['settings'] );
jht_store( array( 'emoji' => false ) );
jpkcom_harden_trim_activate();
chk( 'activate never overwrites', false === $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ]['settings']['emoji'] );

// No locks without constants.

chk( 'no locked keys without constants', array() === jpkcom_harden_trim_locked_keys() );
chk( 'no invalid constants without constants', array() === jpkcom_harden_trim_invalid_constants() );
chk( 'core_lock null without snapshot', null === jpkcom_harden_trim_core_lock( 'post_revisions' ) );
chk( 'core_lock null for key without core constant', null === jpkcom_harden_trim_core_lock( 'emoji' ) );
chk(
	'constant_state shape when undefined',
	array( 'name' => 'JPKCOM_HARDEN_TRIM_EMOJI', 'defined' => false, 'valid' => false, 'value' => null ) === jpkcom_harden_trim_constant_state( 'emoji' )
);

// Exemption.

jht_store( array( 'exempt_roles' => array( 'editor' ) ) );
$GLOBALS['jht_state']['logged_in'] = false;
chk( 'exemption: logged out => false', false === jpkcom_harden_trim_is_exempt() );

$GLOBALS['jht_state']['logged_in'] = true;
$GLOBALS['jht_state']['roles']     = array( 'editor' );
chk( 'exemption: editor with exempt editor => true', true === jpkcom_harden_trim_is_exempt() );

$GLOBALS['jht_state']['roles'] = array( 'subscriber' );
chk( 'exemption: subscriber => false', false === jpkcom_harden_trim_is_exempt() );

chk( 'exemption: explicit user argument is used', true === jpkcom_harden_trim_is_exempt( new WP_User( 5, array( 'subscriber', 'editor' ) ) ) );
chk( 'exemption: explicit user with ID 0 => false', false === jpkcom_harden_trim_is_exempt( new WP_User( 0, array( 'editor' ) ) ) );

$GLOBALS['jht_state']['roles'] = array( 'editor' );
jht_store( array( 'exempt_roles' => array() ) );
chk( 'exemption: empty exempt roles => false', false === jpkcom_harden_trim_is_exempt() );

$GLOBALS['jht_filter_args'] = null;
add_filter( 'jpkcom_harden_trim_is_exempt', static function ( $exempt, $user ) {
	$GLOBALS['jht_filter_args'] = array( $exempt, $user );
	return true;
}, 10, 2 );
$GLOBALS['jht_state']['roles'] = array( 'subscriber' );
chk( 'exemption: filter can flip to true', true === jpkcom_harden_trim_is_exempt() );
chk( 'exemption: filter receives bool and user', false === $GLOBALS['jht_filter_args'][0] && $GLOBALS['jht_filter_args'][1] instanceof WP_User );
unset( $GLOBALS['jht_hooks']['jpkcom_harden_trim_is_exempt'] );

jht_store( array( 'exempt_roles' => array( 'editor' ) ) );
$GLOBALS['jht_state']['roles'] = array( 'editor' );
add_filter( 'jpkcom_harden_trim_is_exempt', static fn( $exempt ) => false );
chk( 'exemption: filter can flip to false', false === jpkcom_harden_trim_is_exempt() );
unset( $GLOBALS['jht_hooks']['jpkcom_harden_trim_is_exempt'] );

$GLOBALS['jht_state']['roles'] = array( 'editor' );
$jht_a                         = jpkcom_harden_trim_is_exempt();
$GLOBALS['jht_state']['roles'] = array( 'subscriber' );
$jht_b                         = jpkcom_harden_trim_is_exempt();
chk( 'exemption: never cached across users', true === $jht_a && false === $jht_b );

// Multisite super admin without a role on this site: counts as administrator.
jht_reset_wp();
jht_store( array( 'exempt_roles' => array( 'administrator' ) ) );
$GLOBALS['jht_state']['multisite']    = true;
$GLOBALS['jht_state']['super_admins'] = array( 9 );
chk( 'exemption: super admin without a role on the site counts as administrator', true === jpkcom_harden_trim_is_exempt( new WP_User( 9, array() ) ) );
chk( 'exemption: other user without a role => false', false === jpkcom_harden_trim_is_exempt( new WP_User( 10, array() ) ) );
jht_store( array( 'exempt_roles' => array( 'editor' ) ) );
chk( 'exemption: super admin is not an editor', false === jpkcom_harden_trim_is_exempt( new WP_User( 9, array() ) ) );
$GLOBALS['jht_state']['multisite'] = false;
jht_store( array( 'exempt_roles' => array( 'administrator' ) ) );
chk( 'exemption: single site ignores is_super_admin()', false === jpkcom_harden_trim_is_exempt( new WP_User( 9, array() ) ) );
jht_reset_wp();
jpkcom_harden_trim_settings_reset_cache();

// is_active_value.

chk( 'active: bool true', true === jpkcom_harden_trim_is_active_value( 'emoji', true ) );
chk( 'active: bool false', false === jpkcom_harden_trim_is_active_value( 'emoji', false ) );
chk( 'active: int null inactive', false === jpkcom_harden_trim_is_active_value( 'post_revisions', null ) );
chk( 'active: int 0 active', true === jpkcom_harden_trim_is_active_value( 'post_revisions', 0 ) );
chk( 'active: roles empty inactive', false === jpkcom_harden_trim_is_active_value( 'exempt_roles', array() ) );
chk( 'active: roles non-empty active', true === jpkcom_harden_trim_is_active_value( 'exempt_roles', array( 'editor' ) ) );
chk( 'active: asset_version off inactive', false === jpkcom_harden_trim_is_active_value( 'asset_version', 'off' ) );
chk( 'active: asset_version hash active', true === jpkcom_harden_trim_is_active_value( 'asset_version', 'hash' ) );
chk( 'active: heartbeat enabled inactive', false === jpkcom_harden_trim_is_active_value( 'heartbeat', 'enabled' ) );
chk( 'active: heartbeat disabled active', true === jpkcom_harden_trim_is_active_value( 'heartbeat', 'disabled' ) );
chk( 'active: app_passwords unchanged inactive', false === jpkcom_harden_trim_is_active_value( 'app_passwords', 'unchanged' ) );
chk( 'active: app_passwords roles active', true === jpkcom_harden_trim_is_active_value( 'app_passwords', 'roles' ) );
chk( 'active: unknown key inactive', false === jpkcom_harden_trim_is_active_value( 'no_such_key', true ) );

// feature_applies.

jht_store( array( 'emoji' => true, 'head_rsd' => true, 'exempt_roles' => array( 'editor' ) ) );
$GLOBALS['jht_state']['logged_in'] = true;
$GLOBALS['jht_state']['roles']     = array( 'editor' );
chk( 'feature_applies: emoji + exempt editor => false', false === jpkcom_harden_trim_feature_applies( 'emoji' ) );
chk( 'feature_applies: head_rsd + exempt editor => true (global)', true === jpkcom_harden_trim_feature_applies( 'head_rsd' ) );
$GLOBALS['jht_state']['roles'] = array( 'subscriber' );
chk( 'feature_applies: emoji + non-exempt user => true', true === jpkcom_harden_trim_feature_applies( 'emoji' ) );
$GLOBALS['jht_state']['logged_in'] = false;
chk( 'feature_applies: emoji + logged out => true', true === jpkcom_harden_trim_feature_applies( 'emoji' ) );
chk( 'feature_applies: inactive key => false', false === jpkcom_harden_trim_feature_applies( 'dashicons' ) );
chk( 'feature_applies: unknown key => false', false === jpkcom_harden_trim_feature_applies( 'no_such_key' ) );

// Store files register nothing at load (checked here, where nothing else ran a loader).

$jht_src = (string) file_get_contents( filename: $root . '/includes/settings-store.php' ) . (string) file_get_contents( filename: $root . '/includes/settings-schema.php' );
chk( 'store/schema never call error_log', ! str_contains( haystack: $jht_src, needle: 'error_log' ) );

// Constant scenarios (child processes).

$run = jht_run_fixture( __DIR__ . '/fixture-constants.php' );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	chk( 'constants: emoji false from constant', false === $out['settings']['emoji'] );
	chk( 'constants: invalid heartbeat constant ignored, stored value wins', 'optimized' === $out['settings']['heartbeat'] );
	chk( 'constants: post_revisions 5 from constant', 5 === $out['settings']['post_revisions'] );
	chk( 'constants: exempt_roles from comma constant', array( 'editor', 'author' ) === $out['settings']['exempt_roles'] );
	chk( 'constants: emoji locked by its constant', 'JPKCOM_HARDEN_TRIM_EMOJI' === ( $out['locked']['emoji'] ?? null ) );
	// Behaviour follows the core constant (the feature code skips on a core lock), so the lock names it too.
	chk( 'constants: core lock names the lock when a plugin constant is set as well', 'WP_POST_REVISIONS' === ( $out['locked']['post_revisions'] ?? null ) );
	chk( 'constants: exempt_roles locked', 'JPKCOM_HARDEN_TRIM_EXEMPT_ROLES' === ( $out['locked']['exempt_roles'] ?? null ) );
	chk( 'constants: heartbeat not locked (invalid constant)', ! array_key_exists( key: 'heartbeat', array: $out['locked'] ) );
	chk( 'constants: exactly three locked keys', 3 === count( $out['locked'] ) );
	chk( 'constants: invalid list', array( 'JPKCOM_HARDEN_TRIM_HEARTBEAT' ) === $out['invalid'] );
	chk( 'constants: core_lock post_revisions', array( 'name' => 'WP_POST_REVISIONS', 'value' => 3 ) === $out['core_lock'] );
	chk( 'constants: constant_state of invalid constant', array( 'name' => 'JPKCOM_HARDEN_TRIM_HEARTBEAT', 'defined' => true, 'valid' => false, 'value' => null ) === $out['state_heartbeat'] );
}

// A stored option without the 1.1.0 keys reads back with their defaults; a constant locks xmlrpc.
$run = jht_run_fixture( __DIR__ . '/fixture-constants.php', array( 'JHT_MODE' => 'xmlrpc' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants xmlrpc: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	chk( 'xmlrpc constant false: effective xmlrpc false', false === $out['settings']['xmlrpc'] );
	chk( 'xmlrpc constant: comments (absent from the stored option) reads back false', false === $out['settings']['comments'] );
	chk( 'xmlrpc constant: xmlrpc locked by JPKCOM_HARDEN_TRIM_XMLRPC, nothing else', array( 'xmlrpc' => 'JPKCOM_HARDEN_TRIM_XMLRPC' ) === $out['locked'] );
}

// An option stored without both keys reads back xmlrpc true, comments false.
jht_reset_wp();
$jht_old = jpkcom_harden_trim_defaults();
unset( $jht_old['xmlrpc'], $jht_old['comments'] );
$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array( 'schema' => 1, 'updated' => '', 'settings' => $jht_old );
jpkcom_harden_trim_settings_reset_cache();
chk( 'upgrade: option without xmlrpc/comments reads xmlrpc true, comments false', true === jpkcom_harden_trim_settings()['xmlrpc'] && false === jpkcom_harden_trim_settings()['comments'] );
jht_reset_wp();
jpkcom_harden_trim_settings_reset_cache();

$run = jht_run_fixture( __DIR__ . '/fixture-constants.php', array( 'JHT_MODE' => 'save' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants save: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	chk( 'save_keeps_locked: emoji stays false', false === $out['stored']['emoji'] );
	chk( 'save_keeps_locked: post_revisions keeps stored 10 (neither input nor constant)', 10 === $out['stored']['post_revisions'] );
	chk( 'save_keeps_locked: exempt_roles keeps stored list', array( 'administrator' ) === $out['stored']['exempt_roles'] );
	chk( 'save_keeps_locked: unlocked key saved', 'optimized' === $out['stored']['heartbeat'] );
	chk( 'save_keeps_locked: effective emoji still from constant', false === $out['effective']['emoji'] );
}

$run = jht_run_fixture( __DIR__ . '/fixture-constants.php', array( 'JHT_MODE' => 'core' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants core: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	chk( 'core locks: locked keys name the core constants', array( 'post_revisions' => 'WP_POST_REVISIONS', 'file_edit' => 'DISALLOW_FILE_EDIT' ) === $out['locked'] );
	chk( 'core locks: effective value not changed by core lock', 10 === $out['settings']['post_revisions'] && false === $out['settings']['file_edit'] );
	chk( 'core locks: save keeps stored values', 10 === $out['stored']['post_revisions'] && false === $out['stored']['file_edit'] );
	chk( 'core locks: no invalid constants', array() === $out['invalid'] );
}

// Multisite: settings are per site, the cache must follow switch_to_blog().
jht_reset_wp();
jht_store( array( 'post_revisions' => 5, 'heartbeat' => 'optimized' ) );
chk( 'multisite: site 1 reads its own settings', 5 === jpkcom_harden_trim_settings()['post_revisions'] );
jht_switch_site( 2 );
chk( 'multisite: after switch_to_blog(2) site 2 reads its own (unset) option', null === jpkcom_harden_trim_settings()['post_revisions'] && 'enabled' === jpkcom_harden_trim_settings()['heartbeat'] );
jht_store( array( 'heartbeat' => 'disabled' ) );
chk( 'multisite: site 2 stored value', 'disabled' === jpkcom_harden_trim_settings()['heartbeat'] );
jht_switch_site( 1 );
chk( 'multisite: back on site 1, its values again', 5 === jpkcom_harden_trim_settings()['post_revisions'] && 'optimized' === jpkcom_harden_trim_settings()['heartbeat'] );
jht_reset_wp();
jpkcom_harden_trim_settings_reset_cache();

// Kill switch (child processes): baseline proves the probe can see a call.

$run = jht_run_fixture( __DIR__ . '/fixture-kill-switch.php', array( 'JHT_KILL' => '0' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'kill switch baseline: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
chk( 'kill switch baseline: register_features called', true === ( $out['called'] ?? null ) && ( $out['new_hooks'] ?? 0 ) > 0 );

$run = jht_run_fixture( __DIR__ . '/fixture-kill-switch.php', array( 'JHT_KILL' => '1' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'kill switch: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
chk( 'kill switch: register_features not called', false === ( $out['called'] ?? null ) );
chk( 'kill switch: no feature hooks registered', 0 === ( $out['new_hooks'] ?? -1 ) );
chk( 'kill switch: store still loaded', true === ( $out['store_loaded'] ?? null ) );

summary();
