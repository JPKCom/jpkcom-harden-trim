<?php
/**
 * Fixture for the module load order check in test-bootstrap-main.php.
 *
 * Points JPKCOM_HARDEN_TRIM_PATH at a temporary plugin tree whose
 * features-aaa.php calls a helper from features.php while it is being read,
 * then runs the real main file's plugins_loaded loader. glob() sorts
 * "features-aaa.php" before "features.php" ('-' < '.'), so the helper only
 * exists in time when the loader requires features.php first.
 *
 * Not named test-*.php on purpose: it is the subject of a suite.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$jht_tmp = sys_get_temp_dir() . '/jht-load-order-' . getmypid();
@mkdir( $jht_tmp . '/includes', 0777, true );
file_put_contents( $jht_tmp . '/includes/features.php', "<?php\nfunction jht_shared_helper(): string { return 'shared'; }\nfunction jpkcom_harden_trim_register_features( array \$s ): void {}\n" );
file_put_contents( $jht_tmp . '/includes/features-aaa.php', "<?php\n\$GLOBALS['jht_order_result'] = jht_shared_helper();\n" );
file_put_contents( $jht_tmp . '/includes/features-zzz.php', "<?php\n\$GLOBALS['jht_order_list'][] = 'zzz';\n" );

define( 'JPKCOM_HARDEN_TRIM_PATH', $jht_tmp . '/' );

function plugin_dir_path( string $file ): string {
	return rtrim( string: dirname( path: $file ), characters: '/\\' ) . '/';
}

function plugin_dir_url( string $file ): string {
	return 'https://example.test/wp-content/plugins/jpkcom-harden-trim/';
}

function plugin_basename( string $file ): string {
	return 'jpkcom-harden-trim/' . basename( path: $file );
}

function register_activation_hook( string $file, $callback ): void {
}

register_shutdown_function(
	static function () use ( $jht_tmp ): void {
		foreach ( (array) glob( $jht_tmp . '/includes/*.php' ) as $f ) {
			@unlink( (string) $f );
		}
		@rmdir( $jht_tmp . '/includes' );
		@rmdir( $jht_tmp );
	}
);

require_once dirname( path: __DIR__ ) . '/jpkcom-harden-trim.php';

foreach ( $GLOBALS['jht_hooks']['plugins_loaded'][5] ?? array() as $jht_cb ) {
	$jht_cb();
}

echo json_encode(
	array(
		'result' => $GLOBALS['jht_order_result'] ?? null,
		'zzz'    => $GLOBALS['jht_order_list'] ?? array(),
	)
);
