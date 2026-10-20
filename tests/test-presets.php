<?php
/**
 * Tests for the presets: managed keys, preset values, untouched keys, locks.
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

$jht_missing = array_values(
	array: array_filter(
		array: array( 'jpkcom_harden_trim_presets', 'jpkcom_harden_trim_preset_managed_keys', 'jpkcom_harden_trim_apply_preset', 'jpkcom_harden_trim_defaults', 'jpkcom_harden_trim_schema' ),
		callback: static fn( string $fn ): bool => ! function_exists( $fn )
	)
);
chk( 'preset API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

$schema   = jpkcom_harden_trim_schema();
$defaults = jpkcom_harden_trim_defaults();
$presets  = jpkcom_harden_trim_presets();
$managed  = jpkcom_harden_trim_preset_managed_keys();
$never    = array( 'block_library_css', 'global_styles', 'header_hsts', 'head_feed_links', 'app_passwords', 'app_password_roles', 'comments', 'exempt_roles' );

chk( 'presets are exactly harmless, recommended, strict', array( 'harmless', 'recommended', 'strict' ) === array_keys( array: $presets ) );
chk( 'harmless preset is empty (= defaults)', array() === $presets['harmless'] );
chk( 'managed keys exclude the eight never-keys', array() === array_intersect( $never, $managed ) );
chk( 'managed keys = all other schema keys, in schema order', array_values( array: array_diff( array_keys( array: $schema ), $never ) ) === $managed );

$jht_valid = true;
foreach ( $presets as $name => $values ) {
	foreach ( $values as $key => $value ) {
		if ( ! in_array( needle: $key, haystack: $managed, strict: true ) ) {
			$jht_valid = false;
			echo "  preset {$name} sets unmanaged key {$key}\n";
		}
		$r = jpkcom_harden_trim_sanitize_value( $key, $value );
		if ( ! $r['valid'] || $r['value'] !== $value ) {
			$jht_valid = false;
			echo "  preset {$name} has invalid value for {$key}\n";
		}
	}
}
chk( 'every preset value is a managed key with a canonical valid value', $jht_valid );

// Recommended.

$res = jpkcom_harden_trim_apply_preset( 'recommended', $defaults );
$s   = $res['settings'];
chk( 'recommended: heartbeat optimized', 'optimized' === $s['heartbeat'] );
chk( 'recommended: header_frame, dashboard_events, embed_privacy, file_edit on', true === $s['header_frame'] && true === $s['dashboard_events'] && true === $s['embed_privacy'] && true === $s['file_edit'] );
chk( 'recommended: post_revisions 20', 20 === $s['post_revisions'] );
$jht_rec_keys = array( 'heartbeat', 'header_frame', 'dashboard_events', 'embed_privacy', 'file_edit', 'post_revisions' );
chk( 'recommended: everything else = defaults', array_diff_key( $s, array_flip( $jht_rec_keys ) ) === array_diff_key( $defaults, array_flip( $jht_rec_keys ) ) );
chk( 'recommended: changed lists exactly the six keys, in schema order', array( 'heartbeat', 'post_revisions', 'dashboard_events', 'embed_privacy', 'header_frame', 'file_edit' ) === $res['changed'] );

// Strict.

$res = jpkcom_harden_trim_apply_preset( 'strict', $defaults );
$s   = $res['settings'];
$jht_strict_on = array( 'header_frame', 'dashboard_events', 'embed_privacy', 'file_edit', 'post_embeds', 'comment_reply', 'dashicons', 'local_avatars', 'comment_ip', 'editor_remote', 'ai_features', 'header_permissions' );
$jht_all_on    = true;
foreach ( $jht_strict_on as $key ) {
	$jht_all_on = $jht_all_on && true === $s[ $key ];
}
chk( 'strict: recommended + strict bools on', $jht_all_on );
chk( 'strict: heartbeat optimized, asset_version hash', 'optimized' === $s['heartbeat'] && 'hash' === $s['asset_version'] );
chk( 'strict: post_revisions 5, autosave_interval 120', 5 === $s['post_revisions'] && 120 === $s['autosave_interval'] );
$jht_strict_keys = array_merge( $jht_strict_on, array( 'heartbeat', 'asset_version', 'post_revisions', 'autosave_interval' ) );
chk( 'strict: everything else = defaults', array_diff_key( $s, array_flip( $jht_strict_keys ) ) === array_diff_key( $defaults, array_flip( $jht_strict_keys ) ) );

// Never-keys survive every preset.

$jht_start = array_merge(
	$defaults,
	array(
		'app_passwords'     => 'disabled',
		'header_hsts'       => true,
		'exempt_roles'      => array( 'editor' ),
		'block_library_css' => true,
		'head_feed_links'   => true,
	)
);
foreach ( array_keys( array: $presets ) as $name ) {
	$s = jpkcom_harden_trim_apply_preset( $name, $jht_start )['settings'];
	chk( "{$name}: app_passwords, header_hsts, exempt_roles untouched", 'disabled' === $s['app_passwords'] && true === $s['header_hsts'] && array( 'editor' ) === $s['exempt_roles'] );
	chk( "{$name}: block_library_css, head_feed_links untouched", true === $s['block_library_css'] && true === $s['head_feed_links'] );
}

// xmlrpc is on under every preset; comments is never preset-managed.

chk( 'xmlrpc is preset-managed, comments is not', in_array( needle: 'xmlrpc', haystack: $managed, strict: true ) && ! in_array( needle: 'comments', haystack: $managed, strict: true ) );
chk( 'recommended and strict list xmlrpc => true, harmless stays empty', true === ( $presets['recommended']['xmlrpc'] ?? null ) && true === ( $presets['strict']['xmlrpc'] ?? null ) && array() === $presets['harmless'] );
foreach ( array_keys( array: $presets ) as $name ) {
	$jht_off = array_merge( $defaults, array( 'xmlrpc' => false, 'comments' => true ) );
	$s       = jpkcom_harden_trim_apply_preset( $name, $jht_off )['settings'];
	chk( "{$name}: xmlrpc switched on again from off", true === $s['xmlrpc'] );
	chk( "{$name}: comments keeps its stored value (on)", true === $s['comments'] );
	$jht_on_off = array_merge( $defaults, array( 'comments' => false ) );
	chk( "{$name}: comments keeps its stored value (off)", false === jpkcom_harden_trim_apply_preset( $name, $jht_on_off )['settings']['comments'] );
}

// Harmless restores defaults from strict.

$strict = jpkcom_harden_trim_apply_preset( 'strict', $defaults )['settings'];
$strict['header_hsts'] = true;
$res    = jpkcom_harden_trim_apply_preset( 'harmless', $strict );
$jht_ok = true;
foreach ( $managed as $key ) {
	$jht_ok = $jht_ok && $defaults[ $key ] === $res['settings'][ $key ];
}
chk( 'harmless from strict: managed keys back to defaults', $jht_ok );
chk( 'harmless from strict: header_hsts untouched', true === $res['settings']['header_hsts'] );
sort( $jht_strict_keys );
$jht_changed = $res['changed'];
sort( $jht_changed );
chk( 'harmless from strict: changed lists exactly the strict keys', $jht_strict_keys === $jht_changed );
chk( 'harmless on defaults: nothing changed', array() === jpkcom_harden_trim_apply_preset( 'harmless', $defaults )['changed'] );

// Unknown preset.

foreach ( array( 'turbo', '../../evil', '', 'Harmless' ) as $jht_name ) {
	$res = jpkcom_harden_trim_apply_preset( $jht_name, $strict );
	chk( "unknown preset '{$jht_name}': settings unchanged, nothing changed", $strict === $res['settings'] && array() === $res['changed'] );
}

// Forbidden values never appear in any preset.

$jht_forbidden = false;
foreach ( $presets as $values ) {
	if ( 'disabled' === ( $values['heartbeat'] ?? null ) || 'remove' === ( $values['asset_version'] ?? null ) ) {
		$jht_forbidden = true;
	}
}
chk( 'no preset sets heartbeat=disabled or asset_version=remove', ! $jht_forbidden );

// Locked keys are skipped (child process: needs a constant).

$run = jht_run_fixture( __DIR__ . '/fixture-constants.php', array( 'JHT_MODE' => 'xmlrpc' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants xmlrpc (presets): exits 0 with JSON', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	foreach ( array( 'harmless', 'recommended', 'strict' ) as $name ) {
		chk( "locked xmlrpc (constant false): {$name} leaves it false and out of changed", false === $out['presets'][ $name ]['settings']['xmlrpc'] && ! in_array( needle: 'xmlrpc', haystack: $out['presets'][ $name ]['changed'], strict: true ) );
	}
}

$run = jht_run_fixture( __DIR__ . '/fixture-constants.php', array( 'JHT_MODE' => 'preset' ) );
$out = json_decode( json: $run['out'], associative: true );
chk( 'fixture-constants preset: exits 0 with JSON (' . trim( string: $run['out'] ) . ')', 0 === $run['exit'] && is_array( $out ) );
if ( is_array( $out ) ) {
	chk( 'locked: strict leaves plugin-locked post_revisions at its current value', 10 === $out['settings']['post_revisions'] );
	chk( 'locked: strict leaves core-locked file_edit at its current value', false === $out['settings']['file_edit'] );
	chk( 'locked: locked keys not in changed', ! in_array( needle: 'post_revisions', haystack: $out['changed'], strict: true ) && ! in_array( needle: 'file_edit', haystack: $out['changed'], strict: true ) );
	chk( 'locked: unlocked keys still applied', 'hash' === $out['settings']['asset_version'] && in_array( needle: 'asset_version', haystack: $out['changed'], strict: true ) );
}

summary();
