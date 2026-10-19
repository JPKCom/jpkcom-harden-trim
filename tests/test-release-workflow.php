<?php
/**
 * Guard: the release workflow never runs a downloaded tool unverified.
 *
 * release.yml runs with `contents: write` and builds the ZIP and the checksum
 * the auto-updater trusts. A tool fetched from a moving URL (the former
 * `https://phpdoc.org/phpDocumentor.phar`) would execute whatever the host
 * serves that day. phpDocumentor must therefore come from a fixed GitHub
 * release and pass `sha256sum -c` against a hard-coded digest before it runs.
 *
 * JHT_RELEASE_YML may point at another copy of the workflow (used to show the
 * guard failing on the unpinned version).
 *
 * @package JPKCom_Harden_Trim
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$jht_yml_path = getenv( 'JHT_RELEASE_YML' ) ?: dirname( path: __DIR__ ) . '/.github/workflows/release.yml';
$jht_yml      = (string) file_get_contents( filename: $jht_yml_path );

chk( 'release.yml readable', '' !== $jht_yml );
chk( 'no download from phpdoc.org (moving target)', ! str_contains( haystack: $jht_yml, needle: 'phpdoc.org/phpDocumentor.phar' ) );

$jht_version = preg_match( "/PHPDOC_VERSION:\\s*'(\\d+\\.\\d+\\.\\d+)'/", $jht_yml, $jht_m ) ? $jht_m[1] : '';
$jht_sha     = preg_match( "/PHPDOC_SHA256:\\s*'([0-9a-f]{64})'/", $jht_yml, $jht_m ) ? $jht_m[1] : '';
chk( 'PHPDOC_VERSION is an exact x.y.z release', '' !== $jht_version );
chk( 'PHPDOC_SHA256 is a 64-character hex digest', '' !== $jht_sha );
chk(
	'phar comes from the pinned GitHub release',
	str_contains( haystack: $jht_yml, needle: 'https://github.com/phpDocumentor/phpDocumentor/releases/download/v${PHPDOC_VERSION}/phpDocumentor.phar' )
);

// The digest check must run after the download and before the first execution.
$jht_get  = strpos( haystack: $jht_yml, needle: '-O phpDocumentor.phar' );
$jht_sum  = strpos( haystack: $jht_yml, needle: 'echo "${PHPDOC_SHA256}  phpDocumentor.phar" | sha256sum -c -' );
$jht_exec = strpos( haystack: $jht_yml, needle: './phpDocumentor.phar run' );
chk( 'download, then sha256sum -c, then run', false !== $jht_get && false !== $jht_sum && false !== $jht_exec && $jht_get < $jht_sum && $jht_sum < $jht_exec );
chk( 'the step fails on the first error (set -euo pipefail)', 1 === preg_match( '/PHPDOC_SHA256:[^\n]*\n\s*run: \|\n\s*set -euo pipefail\n/', $jht_yml ) );

summary();
