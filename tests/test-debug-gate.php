<?php
/**
 * Guard: the bundled updater is the unmodified family copy, and the plugin's
 * own code never writes to the error log.
 *
 * The updater (includes/class-plugin-updater.php) is shared by every JPKCom
 * plugin and must stay identical to the family copy modulo namespace and slug;
 * it is never patched per plugin. It logs under WP_DEBUG like every sibling.
 * The plugin's own code has no logging at all.
 *
 * The family reference is jpkcom-allow-blocks. Its normalised SHA256 is pinned
 * below so the check also runs in CI, where the sibling repository is absent;
 * when the sibling is checked out next to this one, it is compared directly.
 *
 * @package JPKCom_Harden_Trim
 */
declare(strict_types=1);

$root = dirname( path: __DIR__ );
$fail = 0;
function chk( string $label, bool $ok ): void {
    global $fail;
    echo ( $ok ? 'ok   ' : 'FAIL ' ) . $label . "\n";
    if ( ! $ok ) { $fail++; }
}

/**
 * Replace a copy's namespace and slug by neutral placeholders.
 *
 * @param string $source    Updater source.
 * @param string $namespace Namespace of that copy.
 * @param string $slug      Plugin slug of that copy.
 * @return string Normalised source.
 */
function jht_normalise_updater( string $source, string $namespace, string $slug ): string {
    return str_replace( search: array( $namespace, $slug ), replace: array( 'FAMILYNS', 'FAMILYSLUG' ), subject: $source );
}

// Normalised SHA256 of jpkcom-allow-blocks/includes/class-plugin-updater.php
// (updater 1.5.0: family updates for inactive plugins; identical in
// jpkcom-disable-xmlrpc). Bump it only together with a family-wide updater
// release.
const JHT_FAMILY_UPDATER_SHA256 = 'b9f592fb774b7e07c0500ccc438f8dfea12297960d389065f41e20fd7de43190';

$updater_file = $root . '/includes/class-plugin-updater.php';
$updater      = (string) file_get_contents( filename: $updater_file );
$normalised   = jht_normalise_updater( $updater, 'JPKComHardenTrimGitUpdate', 'jpkcom-harden-trim' );

chk( 'updater uses this plugin\'s namespace', str_contains( haystack: $updater, needle: 'namespace JPKComHardenTrimGitUpdate;' ) );
chk( 'updater equals the pinned family copy (normalised SHA256)', JHT_FAMILY_UPDATER_SHA256 === hash( algo: 'sha256', data: $normalised ) );

$sibling = dirname( path: $root ) . '/jpkcom-allow-blocks/includes/class-plugin-updater.php';
if ( is_readable( filename: $sibling ) ) {
    $family = jht_normalise_updater( (string) file_get_contents( filename: $sibling ), 'JPKComAllowBlocksGitUpdate', 'jpkcom-allow-blocks' );
    chk( 'updater equals the jpkcom-allow-blocks checkout (normalised)', $family === $normalised );
} else {
    echo "skip updater comparison with the jpkcom-allow-blocks checkout (not present)\n";
}

// The plugin's own PHP: no error_log() anywhere.
$files = array_merge(
    [ $root . '/jpkcom-harden-trim.php', $root . '/uninstall.php' ],
    glob( pattern: $root . '/includes/*.php' ) ?: [],
    glob( pattern: $root . '/includes/*/*.php' ) ?: []
);
$own = array_values( array: array_filter( array: $files, callback: static fn( string $f ): bool => $f !== $updater_file ) );
chk( 'own PHP files found', count( $own ) > 5 );

/**
 * Count the references to error_log() in PHP source.
 *
 * A name token counts when its last segment is `error_log` (any case):
 * `error_log` (T_STRING, also in `use function error_log as x`),
 * `\error_log` (T_NAME_FULLY_QUALIFIED), `namespace\error_log`
 * (T_NAME_RELATIVE) and `A\error_log` (T_NAME_QUALIFIED; strictly a
 * different function, counted anyway: no function of that name belongs in
 * this plugin). A string literal that is exactly `error_log` or
 * `\error_log` counts as a callable (`call_user_func( 'error_log', … )`).
 *
 * @param string $source PHP source.
 * @return int Number of references.
 */
function jht_error_log_refs( string $source ): int {
    $names = [ T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ];
    $logs  = 0;
    foreach ( token_get_all( $source ) as $token ) {
        if ( ! is_array( $token ) ) {
            continue;
        }
        if ( in_array( needle: $token[0], haystack: $names, strict: true ) ) {
            $segments = explode( separator: '\\', string: $token[1] );
            $name     = end( array: $segments );
        } elseif ( T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
            $name = ltrim( string: substr( string: $token[1], offset: 1, length: -1 ), characters: '\\' );
        } else {
            continue;
        }
        if ( 'error_log' === strtolower( string: (string) $name ) ) {
            $logs++;
        }
    }
    return $logs;
}

// The detector itself: every spelling in the fixture must count (one per marked line).
const JHT_ERROR_LOG_SPELLINGS = 8;
$fixture_refs = jht_error_log_refs( (string) file_get_contents( filename: __DIR__ . '/fixture-error-log.php' ) );
chk( 'detector counts every error_log spelling in fixture-error-log.php (' . $fixture_refs . ' of ' . JHT_ERROR_LOG_SPELLINGS . ')', JHT_ERROR_LOG_SPELLINGS === $fixture_refs );
chk( 'detector ignores comments and other names', 0 === jht_error_log_refs( "<?php\n// error_log( 'x' );\n/* \\error_log() */\nmy_error_log( 1 );\n\$o->error_logger();\n\$s = 'no error_log here';\n" ) );

foreach ( $own as $file ) {
    chk( basename( path: $file ) . ' has no error_log() call', 0 === jht_error_log_refs( (string) file_get_contents( filename: $file ) ) );
}

// Docs must not document a debug constant no code reads.
foreach ( [ 'CLAUDE.md', 'README.md' ] as $doc ) {
    $text = (string) file_get_contents( filename: $root . '/' . $doc );
    chk( $doc . ' does not document JPKCOM_HARDEN_TRIM_DEBUG', ! str_contains( haystack: $text, needle: 'JPKCOM_HARDEN_TRIM_DEBUG' ) );
}
exit( $fail > 0 ? 1 : 0 );
