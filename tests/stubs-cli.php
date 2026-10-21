<?php
/**
 * Stubs for the WP-CLI command and the Abilities API tests.
 *
 * WP_CLI records every call; error() and halt() throw JHT_CLI_Halt so a test
 * can run a command to its first error and inspect what happened before it,
 * exactly as WP-CLI stops at WP_CLI::error(). confirm() throws unless --yes
 * was passed. The Abilities API stubs record registrations.
 *
 * Not named test-*.php: CI runs tests/test-*.php and this is not a suite.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'WP_CLI' ) ) {
	define( constant_name: 'WP_CLI', value: true );
}

if ( ! class_exists( class: 'JHT_CLI_Halt' ) ) {
	/**
	 * Thrown where WP-CLI would stop the process.
	 */
	class JHT_CLI_Halt extends RuntimeException {}
}

if ( ! class_exists( class: 'WP_CLI' ) ) {
	/**
	 * Recording stand-in for WP_CLI.
	 */
	class WP_CLI {
		/** @var list<array{0: string, 1: mixed}> */
		public static array $log = array();

		public static function add_command( string $name, mixed $callable ): void {
			$GLOBALS['jht_cli_commands'][ $name ] = $callable;
		}

		public static function line( string $message = '' ): void {
			self::$log[] = array( 'line', $message );
		}

		public static function log( string $message ): void {
			self::$log[] = array( 'log', $message );
		}

		public static function success( string $message ): void {
			self::$log[] = array( 'success', $message );
		}

		public static function warning( string $message ): void {
			self::$log[] = array( 'warning', $message );
		}

		public static function error( string $message ): never {
			self::$log[] = array( 'error', $message );
			throw new JHT_CLI_Halt( $message );
		}

		public static function confirm( string $question, array $assoc_args = array() ): void {
			self::$log[] = array( 'confirm', $question );
			if ( ! isset( $assoc_args['yes'] ) ) {
				throw new JHT_CLI_Halt( 'confirm declined' );
			}
		}

		/**
		 * Messages of one kind.
		 *
		 * @param string $kind line, success, warning, error, confirm, items.
		 * @return list<mixed>
		 */
		public static function of( string $kind ): array {
			return array_values( array: array_map( callback: static fn( array $e ): mixed => $e[1], array: array_filter( array: self::$log, callback: static fn( array $e ): bool => $kind === $e[0] ) ) );
		}
	}
}

if ( ! function_exists( function: 'WP_CLI\Utils\format_items' ) ) {
	require __DIR__ . '/stubs-cli-utils.php';
}

if ( ! function_exists( function: 'did_action' ) ) {
	function did_action( string $hook_name ): int {
		return (int) ( $GLOBALS['jht_did'][ $hook_name ] ?? 0 );
	}
}

if ( ! function_exists( function: 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( function: 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $text ): string {
		return trim( string: strip_tags( $text ) );
	}
}

// Abilities API.

if ( ! function_exists( function: 'wp_register_ability' ) ) {
	function wp_register_ability( string $name, array $args ): ?object {
		$GLOBALS['jht_abilities'][ $name ] = $args;
		return (object) array( 'name' => $name );
	}
}

if ( ! function_exists( function: 'wp_register_ability_category' ) ) {
	function wp_register_ability_category( string $slug, array $args ): ?object {
		$GLOBALS['jht_ability_categories'][ $slug ] = $args;
		return (object) array( 'slug' => $slug );
	}
}

if ( ! function_exists( function: 'wp_has_ability_category' ) ) {
	function wp_has_ability_category( string $slug ): bool {
		return isset( $GLOBALS['jht_ability_categories'][ $slug ] );
	}
}

/**
 * Run one subcommand of the registered command class.
 *
 * @param string                $subcommand Method name.
 * @param list<string>          $args       Positional arguments.
 * @param array<string, string> $assoc_args Associative arguments.
 * @return string|null The halt message when the command stopped, null when it ran through.
 */
function jht_cli( string $subcommand, array $args = array(), array $assoc_args = array() ): ?string {
	WP_CLI::$log = array();
	$class       = $GLOBALS['jht_cli_commands']['harden-trim'] ?? 'JPKCom_Harden_Trim_CLI';
	$command     = new $class();

	try {
		$command->$subcommand( $args, $assoc_args );
	} catch ( JHT_CLI_Halt $halt ) {
		return $halt->getMessage();
	}

	return null;
}
