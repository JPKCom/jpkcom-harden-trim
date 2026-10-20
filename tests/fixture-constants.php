<?php
/**
 * Fixture for the constant scenarios in test-settings-store.php.
 *
 * Constants cannot be undefined, so every scenario runs in its own process.
 * JHT_MODE selects it:
 * - (unset)  resolve: plugin constants (one invalid) + a core lock; prints the
 *            effective settings, locked keys and invalid constants.
 * - save:    same constants; saves input that differs from both the stored
 *            values and the constants, prints what ends up stored.
 * - core:    only core constants (snapshot at load); prints locks, effective
 *            values and what a save stores.
 * - preset:  one plugin constant and one core lock; prints the result of
 *            applying the strict preset (used by test-presets.php).
 * - xmlrpc:  JPKCOM_HARDEN_TRIM_XMLRPC = false with a stored option that has
 *            no xmlrpc/comments key; prints the effective settings, the locks
 *            and what every preset leaves in the locked key.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname( path: __DIR__ ) . '/includes/settings-schema.php';
require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';

$jht_mode = (string) getenv( 'JHT_MODE' );

if ( 'core' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'WP_POST_REVISIONS' => 3, 'DISALLOW_FILE_EDIT' => true ) );

	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), array( 'post_revisions' => 10 ) ),
	);

	$jht_settings = jpkcom_harden_trim_settings();
	jpkcom_harden_trim_save_settings( array( 'post_revisions' => '20', 'file_edit' => '1' ) );

	echo json_encode(
		array(
			'settings' => $jht_settings,
			'locked'   => jpkcom_harden_trim_locked_keys(),
			'invalid'  => jpkcom_harden_trim_invalid_constants(),
			'stored'   => $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ]['settings'],
		)
	);
	exit( 0 );
}

if ( 'preset' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_POST_REVISIONS', '5' );
	define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'DISALLOW_FILE_EDIT' => true ) );

	echo json_encode(
		jpkcom_harden_trim_apply_preset( 'strict', array_merge( jpkcom_harden_trim_defaults(), array( 'post_revisions' => 10 ) ) )
	);
	exit( 0 );
}

if ( 'xmlrpc' === $jht_mode ) {
	define( 'JPKCOM_HARDEN_TRIM_XMLRPC', false );

	$jht_stored = jpkcom_harden_trim_defaults();
	unset( $jht_stored['xmlrpc'], $jht_stored['comments'] );
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => $jht_stored,
	);

	$jht_effective = jpkcom_harden_trim_settings();
	$jht_presets   = array();
	foreach ( array( 'harmless', 'recommended', 'strict' ) as $jht_name ) {
		$jht_presets[ $jht_name ] = jpkcom_harden_trim_apply_preset( $jht_name, $jht_effective );
	}

	echo json_encode(
		array(
			'settings' => $jht_effective,
			'locked'   => jpkcom_harden_trim_locked_keys(),
			'presets'  => $jht_presets,
		)
	);
	exit( 0 );
}

define( 'JPKCOM_HARDEN_TRIM_EMOJI', false );
define( 'JPKCOM_HARDEN_TRIM_HEARTBEAT', 'off' );
define( 'JPKCOM_HARDEN_TRIM_POST_REVISIONS', '5' );
define( 'JPKCOM_HARDEN_TRIM_EXEMPT_ROLES', 'editor, author' );
define( 'JPKCOM_HARDEN_TRIM_CORE_CONSTANTS_AT_LOAD', array( 'WP_POST_REVISIONS' => 3 ) );

if ( 'save' === $jht_mode ) {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge(
			jpkcom_harden_trim_defaults(),
			array(
				'emoji'          => false,
				'post_revisions' => 10,
				'exempt_roles'   => array( 'administrator' ),
			)
		),
	);

	jpkcom_harden_trim_save_settings(
		array(
			'emoji'          => true,
			'post_revisions' => '20',
			'exempt_roles'   => array( 'subscriber' ),
			'heartbeat'      => 'optimized',
		)
	);

	echo json_encode(
		array(
			'stored'    => $GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ]['settings'],
			'effective' => jpkcom_harden_trim_settings(),
		)
	);
	exit( 0 );
}

$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
	'schema'   => 1,
	'updated'  => '',
	'settings' => array_merge( jpkcom_harden_trim_defaults(), array( 'heartbeat' => 'optimized' ) ),
);

echo json_encode(
	array(
		'settings'        => jpkcom_harden_trim_settings(),
		'locked'          => jpkcom_harden_trim_locked_keys(),
		'invalid'         => jpkcom_harden_trim_invalid_constants(),
		'core_lock'       => jpkcom_harden_trim_core_lock( 'post_revisions' ),
		'state_heartbeat' => jpkcom_harden_trim_constant_state( 'heartbeat' ),
	)
);
exit( 0 );
