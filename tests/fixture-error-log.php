<?php
/**
 * Fixture for test-debug-gate.php: every spelling of a call to the global
 * error_log() the guard must count. Never executed, only tokenised; each
 * marked line below is one call. Keep JHT_ERROR_LOG_SPELLINGS in
 * test-debug-gate.php equal to the number of marked lines.
 *
 * Not named test-*.php on purpose: CI runs tests/test-*.php and this is not a
 * suite, it is the subject of one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

namespace JPKComHardenTrimFixture;

use function error_log as jht_log; // call 1: imported under another name

function jht_fixture_error_log_spellings(): void {
	error_log( 'plain' ); // call 2: T_STRING
	\error_log( 'fully qualified' ); // call 3: T_NAME_FULLY_QUALIFIED
	\ERROR_LOG( 'any case' ); // call 4: function names are case-insensitive
	namespace\error_log( 'relative' ); // call 5: T_NAME_RELATIVE
	Sub\error_log( 'qualified' ); // call 6: T_NAME_QUALIFIED
	call_user_func( 'error_log', 'callable string' ); // call 7: callable string
	$f = "error_log"; // call 8: callable string, double quotes
	$f( 'variable' );
	jht_log( 'alias' );
}
