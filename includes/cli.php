<?php
/**
 * WP-CLI command `wp harden-trim`
 *
 * Reads and writes the settings through exactly the paths the settings page
 * uses: every value goes through jpkcom_harden_trim_sanitize_value(), every
 * write through jpkcom_harden_trim_save_settings(), presets through
 * jpkcom_harden_trim_apply_preset(), import and export through the same
 * functions as the import / export section. The command is stricter than the
 * form in one respect: a value it cannot store as given (invalid, locked by a
 * constant, an unknown role) is an error and nothing is written, instead of a
 * notice after the fact.
 *
 * Output is English, like WP-CLI itself. Settings are per site; on multisite
 * pick the site with WP-CLI's global --url parameter.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.2.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( class: 'JPKCom_Harden_Trim_CLI' ) ) {

	/**
	 * Inspect and change the JPKCom Harden & Trim settings of a site.
	 *
	 * ## EXAMPLES
	 *
	 *     # What every switch does on this site, and why
	 *     $ wp harden-trim status
	 *
	 *     # Switch one setting
	 *     $ wp harden-trim set heartbeat optimized
	 *
	 *     # Apply a preset, first as a dry run
	 *     $ wp harden-trim preset recommended --dry-run
	 *
	 *     # Copy the settings from one site to another
	 *     $ wp harden-trim export --file=harden-trim.json
	 *     $ wp --url=https://example.org harden-trim import harden-trim.json
	 *
	 * @since 1.2.0
	 */
	final class JPKCom_Harden_Trim_CLI {

		/**
		 * Show what every setting does on this site, and why.
		 *
		 * Lists the effective value, where it comes from (default, stored,
		 * constant, filter or a core constant), the constant that locks it, and
		 * whether the feature acts. A switch can be on and still do nothing:
		 * the reason column says why (kill_switch, core_constant, block_theme,
		 * plain_permalinks, unused, off). Role-scoped features additionally
		 * skip users holding an exempt role.
		 *
		 * ## OPTIONS
		 *
		 * [--fields=<fields>]
		 * : Limit the output to specific fields.
		 * ---
		 * default: key,value,source,locked_by,acts,reason
		 * ---
		 *
		 * [--format=<format>]
		 * : Render output in a particular format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## AVAILABLE FIELDS
		 *
		 * key, group, type, value, stored, default, source, locked_by,
		 * core_value, acts, reason, role_scoped
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim status
		 *     $ wp harden-trim status --format=json
		 *     $ wp harden-trim status --fields=key,value,stored,default
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Positional arguments (none).
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function status( array $args, array $assoc_args ): void {
			$status = jpkcom_harden_trim_status();
			$format = $assoc_args['format'] ?? 'table';

			if ( 'json' === $format || 'yaml' === $format ) {
				$items = $status['settings'];
			} else {
				$items = array_map( callback: array( $this, 'printable_row' ), array: $status['settings'] );
			}

			if ( $status['kill_switch']['active'] ) {
				WP_CLI::warning( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL is set: no feature of this plugin acts on this site.' );
			}

			if ( array() !== $status['invalid_constants'] ) {
				WP_CLI::warning( 'Ignored because their value is invalid: ' . implode( separator: ', ', array: $status['invalid_constants'] ) . '.' );
			}

			WP_CLI\Utils\format_items( $format, $items, $assoc_args['fields'] ?? 'key,value,source,locked_by,acts,reason' );
		}

		/**
		 * Print the effective value of one setting.
		 *
		 * ## OPTIONS
		 *
		 * <key>
		 * : Setting key, as listed by `wp harden-trim status`.
		 *
		 * [--format=<format>]
		 * : plain prints the value as `set` accepts it; json prints it as JSON.
		 * ---
		 * default: plain
		 * options:
		 *   - plain
		 *   - json
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim get heartbeat
		 *     optimized
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Key.
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function get( array $args, array $assoc_args ): void {
			$key   = $this->require_key( $args[0] ?? '' );
			$value = jpkcom_harden_trim_settings()[ $key ];
			$core  = jpkcom_harden_trim_core_lock( $key );

			if ( null !== $core ) {
				WP_CLI::warning( "{$core['name']} in wp-config.php decides instead of this setting (value: " . (string) wp_json_encode( $core['value'] ) . ').' );
			}

			if ( 'json' === ( $assoc_args['format'] ?? 'plain' ) ) {
				WP_CLI::line( (string) wp_json_encode( $value ) );
				return;
			}

			WP_CLI::line( $this->format_value( $value ) );
		}

		/**
		 * Change one setting.
		 *
		 * The value goes through the same validation as the settings page. A
		 * value that is not accepted, a setting locked by a constant, a role
		 * that does not exist on this site, and a setting the settings page
		 * does not offer with the active block theme are errors; nothing is
		 * written.
		 *
		 * Values: switches take true/false (also 1/0, yes/no, on/off); choices
		 * take one of the listed words; post_revisions and autosave_interval
		 * take one of the listed numbers or `default` (the WordPress default);
		 * role lists take comma-separated role slugs, or an empty string for
		 * none.
		 *
		 * ## OPTIONS
		 *
		 * <key>
		 * : Setting key, as listed by `wp harden-trim status`.
		 *
		 * <value>
		 * : New value.
		 *
		 * [--dry-run]
		 * : Validate and report, but write nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim set emoji false
		 *     $ wp harden-trim set post_revisions 20
		 *     $ wp harden-trim set post_revisions default
		 *     $ wp harden-trim set exempt_roles administrator,editor
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Key and value.
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function set( array $args, array $assoc_args ): void {
			$key = $this->require_key( $args[0] ?? '' );

			if ( ! array_key_exists( key: 1, array: $args ) ) {
				WP_CLI::error( "Missing value for {$key}. " . $this->accepted_values( $key ) );
			}

			$this->refuse_locked( $key );
			$this->refuse_block_theme( $key );

			$value = $this->parse_value( $key, (string) $args[1] );

			$this->write( array( $key => $value ), $this->flag( $assoc_args, 'dry-run' ) );
		}

		/**
		 * Set settings back to their default.
		 *
		 * Settings locked by a constant are skipped with a warning (the
		 * constant wins either way), and so are the settings the settings page
		 * does not offer with a block theme.
		 *
		 * ## OPTIONS
		 *
		 * [<key>...]
		 * : One or more setting keys.
		 *
		 * [--all]
		 * : Reset every setting that is not locked.
		 *
		 * [--yes]
		 * : Do not ask for confirmation with --all.
		 *
		 * [--dry-run]
		 * : Report, but write nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim reset heartbeat post_revisions
		 *     $ wp harden-trim reset --all --yes
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Keys.
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function reset( array $args, array $assoc_args ): void {
			$all = $this->flag( $assoc_args, 'all' );

			if ( $all && array() !== $args ) {
				WP_CLI::error( 'Pass either keys or --all, not both.' );
			}

			if ( ! $all && array() === $args ) {
				WP_CLI::error( 'Name at least one setting key, or pass --all.' );
			}

			$keys   = $all ? array_keys( array: jpkcom_harden_trim_schema() ) : array_map( callback: array( $this, 'require_key' ), array: $args );
			$locked = jpkcom_harden_trim_locked_keys();
			$values = array();

			foreach ( $keys as $key ) {
				if ( isset( $locked[ $key ] ) ) {
					WP_CLI::warning( "{$key} is locked by {$locked[ $key ]} and was left as it is." );
					continue;
				}

				if ( $this->block_theme_unavailable( $key ) ) {
					WP_CLI::warning( "{$key} is not available with a block theme and was left as it is (as on the settings page)." );
					continue;
				}

				$values[ $key ] = jpkcom_harden_trim_defaults()[ $key ];
			}

			if ( $all && ! $this->flag( $assoc_args, 'dry-run' ) ) {
				WP_CLI::confirm( 'Reset every unlocked setting to its default?', $assoc_args );
			}

			$this->write( $values, $this->flag( $assoc_args, 'dry-run' ) );
		}

		/**
		 * List the presets and what each one would change on this site.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Render output in a particular format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - csv
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Positional arguments (none).
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function presets( array $args, array $assoc_args ): void {
			$stored = jpkcom_harden_trim_get_stored();
			$items  = array();

			foreach ( array_keys( array: jpkcom_harden_trim_presets() ) as $name ) {
				$result  = jpkcom_harden_trim_apply_preset( $name, $stored );
				$items[] = array(
					'preset'  => $name,
					'changes' => count( value: $result['changed'] ),
					'keys'    => implode( separator: ',', array: $result['changed'] ),
				);
			}

			WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $items, array( 'preset', 'changes', 'keys' ) );
		}

		/**
		 * Apply a preset.
		 *
		 * Assigns every setting a preset manages (the preset's value, or the
		 * default where the preset lists none). Settings locked by a constant,
		 * role lists, application passwords, HSTS, feed discovery, comments,
		 * block library CSS and global styles are never touched.
		 *
		 * ## OPTIONS
		 *
		 * <preset>
		 * : Preset name.
		 * ---
		 * options:
		 *   - harmless
		 *   - recommended
		 *   - strict
		 * ---
		 *
		 * [--dry-run]
		 * : Report the changes, but write nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim preset recommended --dry-run
		 *     $ wp harden-trim preset recommended
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Preset name.
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function preset( array $args, array $assoc_args ): void {
			$name    = (string) ( $args[0] ?? '' );
			$presets = jpkcom_harden_trim_presets();

			if ( ! array_key_exists( key: $name, array: $presets ) ) {
				WP_CLI::error( "Unknown preset '{$name}'. Presets: " . implode( separator: ', ', array: array_keys( array: $presets ) ) . '.' );
			}

			$stored = jpkcom_harden_trim_get_stored();
			$result = jpkcom_harden_trim_apply_preset( $name, $stored );

			if ( array() === $result['changed'] ) {
				WP_CLI::success( "Preset {$name} changes nothing on this site." );
				return;
			}

			$this->write( array_intersect_key( $result['settings'], array_flip( $result['changed'] ) ), $this->flag( $assoc_args, 'dry-run' ) );
		}

		/**
		 * Export the stored settings as JSON.
		 *
		 * The same document the settings page downloads: stored values only, no
		 * constant, no filter, no site URL, no user data.
		 *
		 * ## OPTIONS
		 *
		 * [--file=<file>]
		 * : Write to this file instead of standard output.
		 *
		 * [--force]
		 * : Overwrite the file if it exists.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim export > harden-trim.json
		 *     $ wp harden-trim export --file=harden-trim.json
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       Positional arguments (none).
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function export( array $args, array $assoc_args ): void {
			$json = (string) wp_json_encode( jpkcom_harden_trim_export_payload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$file = $assoc_args['file'] ?? null;

			if ( null === $file ) {
				WP_CLI::line( $json );
				return;
			}

			if ( file_exists( filename: $file ) && ! $this->flag( $assoc_args, 'force' ) ) {
				WP_CLI::error( "{$file} exists. Pass --force to overwrite it." );
			}

			if ( false === file_put_contents( filename: $file, data: $json . "\n" ) ) {
				WP_CLI::error( "Could not write {$file}." );
			}

			WP_CLI::success( "Exported the settings to {$file}." );
		}

		/**
		 * Import settings from an export.
		 *
		 * Same rules as the settings page: every key is validated on its own,
		 * keys missing from the file keep their stored value, and settings
		 * locked by a constant are stored as the file says while the constant
		 * still wins.
		 *
		 * ## OPTIONS
		 *
		 * <file>
		 * : Export file, or `-` to read standard input.
		 *
		 * [--dry-run]
		 * : Report what would change, but write nothing.
		 *
		 * ## EXAMPLES
		 *
		 *     $ wp harden-trim import harden-trim.json --dry-run
		 *     $ cat harden-trim.json | wp harden-trim import -
		 *
		 * @since 1.2.0
		 *
		 * @param list<string>          $args       File.
		 * @param array<string, string> $assoc_args Associative arguments.
		 * @return void
		 */
		public function import( array $args, array $assoc_args ): void {
			$file = (string) ( $args[0] ?? '' );
			$max  = (int) JPKCOM_HARDEN_TRIM_IMPORT_MAX_BYTES;

			if ( '-' === $file ) {
				$raw = stream_get_contents( stream: STDIN, length: $max + 1 );
			} elseif ( '' !== $file && is_file( filename: $file ) && is_readable( filename: $file ) ) {
				$raw = file_get_contents( filename: $file, use_include_path: false, context: null, offset: 0, length: $max + 1 );
			} else {
				WP_CLI::error( "Cannot read '{$file}'." );
			}

			$parsed = jpkcom_harden_trim_parse_import( is_string( value: $raw ) ? $raw : '' );

			if ( is_wp_error( $parsed ) ) {
				WP_CLI::error( wp_strip_all_tags( $parsed->get_error_message() ) );
			}

			foreach ( $parsed['rejected'] as $rejected ) {
				if ( str_contains( haystack: $rejected, needle: ':' ) ) {
					[ $role_key, $role ] = explode( separator: ':', string: $rejected, limit: 2 );
					WP_CLI::warning( "Unknown role '{$role}' dropped from {$role_key}; the rest of {$role_key} was applied." );
					continue;
				}

				WP_CLI::warning( "Rejected and left as it was: {$rejected}." );
			}

			$locked = jpkcom_harden_trim_locked_keys();

			foreach ( $parsed['applied'] as $key ) {
				if ( isset( $locked[ $key ] ) ) {
					WP_CLI::warning( "{$key} is stored as the file says, but {$locked[ $key ]} still wins." );
				}
			}

			$this->write( $parsed['settings'], $this->flag( $assoc_args, 'dry-run' ), false );
		}

		/**
		 * Store values over the current stored settings and report the changes.
		 *
		 * @param array<string, mixed> $values      Validated key => value.
		 * @param bool                 $dry_run     Whether to write nothing.
		 * @param bool                 $keep_locked Passed to the store: whether locked keys keep their stored value.
		 * @return void
		 */
		private function write( array $values, bool $dry_run, bool $keep_locked = true ): void {
			$before  = jpkcom_harden_trim_get_stored();
			$after   = array_merge( $before, $values );
			$changed = array();

			foreach ( $after as $key => $value ) {
				if ( $before[ $key ] !== $value ) {
					$changed[] = array(
						'key'    => $key,
						'before' => $this->format_value( $before[ $key ] ),
						'after'  => $this->format_value( $value ),
					);
				}
			}

			if ( array() === $changed ) {
				WP_CLI::success( 'Nothing to change.' );
				return;
			}

			WP_CLI\Utils\format_items( 'table', $changed, array( 'key', 'before', 'after' ) );

			if ( $dry_run ) {
				WP_CLI::success( 'Dry run: ' . count( value: $changed ) . ' setting(s) would change. Nothing was written.' );
				return;
			}

			$result = jpkcom_harden_trim_save_settings( $after, $keep_locked );

			foreach ( $result['rejected'] as $rejected ) {
				WP_CLI::warning( "Rejected by the store: {$rejected}." );
			}

			// Constants first (deterministic), then the filter as it answers now. On a
			// normal request the filter runs on plugins_loaded, so a callback a theme adds
			// later counts here but not there; hence "as evaluated now".
			$constants = jpkcom_harden_trim_unfiltered_settings();
			$effective = jpkcom_harden_trim_settings();

			foreach ( $changed as $row ) {
				$key = $row['key'];

				if ( $constants[ $key ] !== $result['settings'][ $key ] ) {
					WP_CLI::warning( "{$key} is stored, but a constant in wp-config.php decides: " . $this->format_value( $constants[ $key ] ) . '.' );
				} elseif ( $effective[ $key ] !== $constants[ $key ] ) {
					WP_CLI::warning( "{$key} is stored, but the jpkcom_harden_trim_settings filter returns " . $this->format_value( $effective[ $key ] ) . ' for it (as evaluated now).' );
				}
			}

			if ( jpkcom_harden_trim_kill_switch() ) {
				WP_CLI::warning( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL is set: no feature of this plugin acts until it is removed.' );
			}

			WP_CLI::success( 'Updated ' . count( value: $changed ) . ' setting(s).' );
		}

		/**
		 * A boolean flag the WP-CLI way: --flag is true, --no-flag is false.
		 *
		 * @param array<string, mixed> $assoc_args Associative arguments.
		 * @param string               $flag       Flag name.
		 * @return bool
		 */
		private function flag( array $assoc_args, string $flag ): bool {
			return (bool) WP_CLI\Utils\get_flag_value( $assoc_args, $flag, false );
		}

		/**
		 * Whether a key is unavailable because the active theme is a block theme.
		 *
		 * The settings page disables these fields and keeps their stored value.
		 *
		 * @param string $key Setting key.
		 * @return bool
		 */
		private function block_theme_unavailable( string $key ): bool {
			return jpkcom_harden_trim_schema()[ $key ]['block_theme_unavailable'] && jpkcom_harden_trim_is_block_theme();
		}

		/**
		 * Stop with an error for a key the settings page does not offer with a block theme.
		 *
		 * @param string $key Setting key.
		 * @return void
		 */
		private function refuse_block_theme( string $key ): void {
			if ( $this->block_theme_unavailable( $key ) ) {
				WP_CLI::error( "{$key} is not available while the active theme is a block theme; its stored value is kept, as on the settings page." );
			}
		}

		/**
		 * Validate a key or stop with an error.
		 *
		 * @param string $key Candidate key.
		 * @return string The key.
		 */
		private function require_key( string $key ): string {
			if ( ! array_key_exists( key: $key, array: jpkcom_harden_trim_schema() ) ) {
				WP_CLI::error( "Unknown setting '{$key}'. Run `wp harden-trim status` for the list of keys." );
			}

			return $key;
		}

		/**
		 * Stop with an error when a key is locked by a constant.
		 *
		 * @param string $key Setting key.
		 * @return void
		 */
		private function refuse_locked( string $key ): void {
			$locked = jpkcom_harden_trim_locked_keys();

			if ( isset( $locked[ $key ] ) ) {
				WP_CLI::error( "{$key} is locked by {$locked[ $key ]} in wp-config.php; the constant wins. Change or remove the constant instead." );
			}
		}

		/**
		 * Turn a command-line value into a validated setting value, or stop.
		 *
		 * @param string $key Setting key.
		 * @param string $raw Value as typed.
		 * @return mixed Validated value.
		 */
		private function parse_value( string $key, string $raw ): mixed {
			$entry = jpkcom_harden_trim_schema()[ $key ];

			if ( 'int' === $entry['type'] && $entry['nullable'] && 'default' === strtolower( string: trim( string: $raw ) ) ) {
				$raw = '';
			}

			if ( 'bool' === $entry['type'] && '' === trim( string: $raw ) ) {
				WP_CLI::error( "'' is not a valid value for {$key}. " . $this->accepted_values( $key ) );
			}

			$result = jpkcom_harden_trim_sanitize_value( $key, $raw );

			if ( ! $result['valid'] ) {
				WP_CLI::error( "'{$raw}' is not a valid value for {$key}. " . $this->accepted_values( $key ) );
			}

			if ( array() !== $result['dropped'] ) {
				WP_CLI::error( 'Unknown role(s) on this site: ' . implode( separator: ', ', array: $result['dropped'] ) . '. Nothing was written.' );
			}

			return $result['value'];
		}

		/**
		 * Describe the values a key accepts.
		 *
		 * @param string $key Setting key.
		 * @return string Sentence.
		 */
		private function accepted_values( string $key ): string {
			$entry = jpkcom_harden_trim_schema()[ $key ];

			return match ( $entry['type'] ) {
				'bool'  => 'Accepted: true or false.',
				'enum'  => 'Accepted: ' . implode( separator: ', ', array: $entry['choices'] ) . '.',
				'int'   => 'Accepted: ' . implode( separator: ', ', array: $entry['choices'] ) . ( $entry['nullable'] ? ', or default.' : '.' ),
				'roles' => 'Accepted: comma-separated role slugs of this site (' . implode( separator: ', ', array: array_keys( array: wp_roles()->roles ) ) . '), or an empty string.',
				default => '',
			};
		}

		/**
		 * A status row with every value printable in a table.
		 *
		 * @param array<string, mixed> $row Status row.
		 * @return array<string, mixed> Row with formatted values.
		 */
		private function printable_row( array $row ): array {
			foreach ( array( 'value', 'stored', 'default', 'core_value' ) as $field ) {
				$row[ $field ] = 'core_value' === $field && null === $row[ $field ] ? '' : $this->format_value( $row[ $field ] );
			}

			$row['acts']        = $row['acts'] ? 'yes' : 'no';
			$row['role_scoped'] = $row['role_scoped'] ? 'yes' : 'no';
			$row['locked_by']   = $row['locked_by'] ?? '';
			$row['reason']      = $row['reason'] ?? '';

			return $row;
		}

		/**
		 * Format a setting value the way `set` accepts it.
		 *
		 * @param mixed $value Value.
		 * @return string Printable value.
		 */
		private function format_value( mixed $value ): string {
			return match ( true ) {
				true === $value   => 'true',
				false === $value  => 'false',
				null === $value   => 'default',
				is_array( $value ) => implode( separator: ',', array: array_map( callback: 'strval', array: $value ) ),
				default           => (string) $value,
			};
		}
	}

}

if ( defined( constant_name: 'WP_CLI' ) && WP_CLI && class_exists( class: 'WP_CLI' ) ) {
	WP_CLI::add_command( 'harden-trim', 'JPKCom_Harden_Trim_CLI' );
}
