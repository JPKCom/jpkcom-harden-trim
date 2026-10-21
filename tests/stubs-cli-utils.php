<?php
/**
 * Stand-in for WP_CLI\Utils\format_items(): records the items instead of printing them.
 *
 * Not named test-*.php: CI runs tests/test-*.php and this is not a suite.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

namespace WP_CLI\Utils;

/**
 * Record a format_items() call.
 *
 * @param string                     $format Format.
 * @param list<array<string, mixed>> $items  Rows.
 * @param string|list<string>        $fields Fields.
 * @return void
 */
function format_items( string $format, array $items, string|array $fields ): void {
	\WP_CLI::$log[] = array(
		'items',
		array(
			'format' => $format,
			'items'  => $items,
			'fields' => is_array( $fields ) ? $fields : explode( ',', $fields ),
		),
	);
}

/**
 * WP-CLI's get_flag_value(): --flag is true, --no-flag is false (WP-CLI stores false for it).
 *
 * @param array<string, mixed> $assoc_args Associative arguments.
 * @param string               $flag       Flag.
 * @param mixed                $default    Default.
 * @return mixed
 */
function get_flag_value( array $assoc_args, string $flag, mixed $default = null ): mixed {
	return array_key_exists( $flag, $assoc_args ) ? $assoc_args[ $flag ] : $default;
}
