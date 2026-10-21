<?php
/**
 * Tests for the Abilities API integration: registration shape (the input
 * schema pitfalls), annotations, permission, the answer being exactly
 * jpkcom_harden_trim_status() and valid against the declared output schema,
 * the Throwable boundary, and the registration gate.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.2.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';
require_once __DIR__ . '/stubs-cli.php';

foreach ( array( 'settings-schema', 'settings-store', 'status', 'abilities' ) as $ab_module ) {
	require_once dirname( path: __DIR__ ) . '/includes/' . $ab_module . '.php';
}

/**
 * Validate a value against the JSON Schema subset the plugin declares.
 *
 * Covers type (single or list), enum, properties (every declared property
 * must be present, no undeclared one may appear) and items.
 *
 * @param mixed                $value  Value.
 * @param array<string, mixed> $schema Schema.
 * @param string               $path   Path for messages.
 * @return list<string> Violations.
 */
function ab_validate( mixed $value, array $schema, string $path = '$' ): array {
	$errors = array();
	$types  = (array) ( $schema['type'] ?? array() );
	$actual = match ( true ) {
		null === $value                                  => 'null',
		is_bool( $value )                                => 'boolean',
		is_int( $value )                                 => 'integer',
		is_float( $value )                               => 'number',
		is_string( $value )                              => 'string',
		is_array( $value ) && array_is_list( $value )    => 'array',
		is_array( $value ) || is_object( $value )        => 'object',
		default                                          => gettype( $value ),
	};

	if ( array() !== $types && ! in_array( $actual, $types, true ) && ! ( 'integer' === $actual && in_array( 'number', $types, true ) ) && ! ( 'array' === $actual && array() === $value && in_array( 'object', $types, true ) ) ) {
		$errors[] = "{$path}: {$actual} is not " . implode( '|', $types );
		return $errors;
	}

	if ( isset( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
		$errors[] = "{$path}: " . json_encode( $value ) . ' not in enum';
	}

	if ( 'object' === $actual && isset( $schema['properties'] ) ) {
		$value = (array) $value;
		foreach ( array_diff( array_keys( $schema['properties'] ), array_keys( $value ) ) as $missing ) {
			$errors[] = "{$path}.{$missing}: missing";
		}
		foreach ( array_diff( array_keys( $value ), array_keys( $schema['properties'] ) ) as $extra ) {
			$errors[] = "{$path}.{$extra}: not declared";
		}
		foreach ( $schema['properties'] as $name => $sub ) {
			if ( array_key_exists( $name, $value ) ) {
				$errors = array_merge( $errors, ab_validate( $value[ $name ], $sub, "{$path}.{$name}" ) );
			}
		}
	}

	if ( 'array' === $actual && isset( $schema['items'] ) ) {
		foreach ( $value as $i => $item ) {
			$errors = array_merge( $errors, ab_validate( $item, $schema['items'], "{$path}[{$i}]" ) );
		}
	}

	return $errors;
}

/**
 * Store settings and forget the cached effective settings.
 *
 * @param array<string, mixed> $settings Overrides of the defaults.
 * @return void
 */
function ab_store( array $settings = array() ): void {
	jht_reset_admin();
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $settings ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

ab_store();

$ab_defs = jpkcom_harden_trim_get_ability_definitions();
$ab_name = 'jpkcom-harden-trim/get-status';
$ab      = $ab_defs[ $ab_name ] ?? array();

chk( 'exactly one ability, get-status', array( $ab_name ) === array_keys( $ab_defs ) );
chk( 'ability name matches core\'s pattern', 1 === preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $ab_name ) );
chk( 'category slug matches core\'s pattern', 1 === preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY ) && JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY === ( $ab['category'] ?? null ) );
chk( 'label and description present', '' !== ( $ab['label'] ?? '' ) && strlen( (string) ( $ab['description'] ?? '' ) ) > 100 );

// The input schema pitfalls (see CLAUDE.md, Abilities API).
$ab_in = $ab['input_schema'] ?? array();
chk( 'input schema: object type', 'object' === ( $ab_in['type'] ?? null ) );
chk( 'input schema: NO properties key (stdClass is a fatal, [] is invalid JSON Schema)', ! array_key_exists( 'properties', $ab_in ) );
chk( 'input schema: additionalProperties false refuses every key', false === ( $ab_in['additionalProperties'] ?? null ) );
chk( 'input schema: top-level default is an object, encoded as {}', ( $ab_in['default'] ?? null ) instanceof stdClass && '{}' === json_encode( $ab_in['default'] ) );
chk( 'declared input keys match the schema for every ability', array_keys( JPKCOM_HARDEN_TRIM_ABILITY_INPUT_KEYS ) === array_keys( $ab_defs ) && array() === array_filter( $ab_defs, static fn( array $d, string $n ): bool => JPKCOM_HARDEN_TRIM_ABILITY_INPUT_KEYS[ $n ] !== array_keys( $d['input_schema']['properties'] ?? array() ), ARRAY_FILTER_USE_BOTH ) );

// Annotations and exposure.
$ab_meta = $ab['meta'] ?? array();
chk( 'annotations explicit: readonly, not destructive, idempotent', array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ) === ( $ab_meta['annotations'] ?? null ) );
chk( 'exposed over REST and MCP', true === ( $ab_meta['show_in_rest'] ?? null ) && array( 'public' => true ) === ( $ab_meta['mcp'] ?? null ) );
add_filter( 'jpkcom_harden_trim_ability_meta', static fn( array $m ): array => array_merge( $m, array( 'mcp' => array( 'public' => false ) ) ) );
chk( 'meta filter can narrow exposure', array( 'public' => false ) === jpkcom_harden_trim_ability_meta( $ab_name )['mcp'] );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_ability_meta'] = array();
add_filter( 'jpkcom_harden_trim_ability_meta', static fn(): string => 'nonsense' );
chk( 'a non-array meta filter result is ignored', true === jpkcom_harden_trim_ability_meta( $ab_name )['annotations']['readonly'] );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_ability_meta'] = array();

// Permission.
$GLOBALS['jht_caps'] = array( 'manage_options' => true );
chk( 'permission: manage_options may', true === jpkcom_harden_trim_ability_permission() );
$GLOBALS['jht_caps'] = array( 'edit_posts' => true, 'read' => true );
chk( 'permission: an editor may not', false === jpkcom_harden_trim_ability_permission() );
add_filter( 'jpkcom_harden_trim_ability_capability', static fn(): string => 'edit_posts' );
chk( 'capability filter can widen it', true === jpkcom_harden_trim_ability_permission() );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_ability_capability'] = array();
add_filter( 'jpkcom_harden_trim_ability_capability', static fn(): array => array( 'read' ) );
chk( 'a non-string capability falls back to manage_options', false === jpkcom_harden_trim_ability_permission() );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_ability_capability'] = array();

// Execute: exactly the status, valid against the output schema.
foreach ( array(
	'defaults'          => array(),
	'stored values'     => array( 'emoji' => false, 'heartbeat' => 'disabled', 'post_revisions' => 20, 'exempt_roles' => array( 'editor' ), 'app_password_roles' => array( 'author' ) ),
	'nullable ints set' => array( 'autosave_interval' => 300, 'asset_version' => 'hash' ),
) as $ab_case => $ab_settings ) {
	ab_store( $ab_settings );
	$ab_out = jpkcom_harden_trim_ability_get_status( (object) array() );
	chk( "execute ({$ab_case}): returns jpkcom_harden_trim_status()", jpkcom_harden_trim_status() === $ab_out );
	$ab_err = ab_validate( json_decode( (string) json_encode( $ab_out ), true ), $ab['output_schema'] );
	chk( "execute ({$ab_case}): valid against the output schema" . ( array() === $ab_err ? '' : ' (' . implode( '; ', array_slice( $ab_err, 0, 3 ) ) . ')' ), array() === $ab_err );
}
chk( 'execute with null input (core passes null before normalising)', is_array( jpkcom_harden_trim_ability_get_status( null ) ) );

// Every field the status returns is declared, and nothing declared is missing.
ab_store();
$ab_row_keys = array_keys( jpkcom_harden_trim_status()['settings'][0] );
chk( 'output schema item properties = status row fields', $ab_row_keys === array_keys( $ab['output_schema']['properties']['settings']['items']['properties'] ) );
chk( 'output schema top-level properties = status fields', array_keys( jpkcom_harden_trim_status() ) === array_keys( $ab['output_schema']['properties'] ) );
chk( 'the validator itself rejects a wrong type (self-test)', array() !== ab_validate( array( 'acts' => 'yes' ), array( 'type' => 'object', 'properties' => array( 'acts' => array( 'type' => 'boolean' ) ) ) ) );
chk( 'the validator itself rejects an undeclared field (self-test)', array() !== ab_validate( array( 'x' => 1 ), array( 'type' => 'object', 'properties' => array() ) ) );

// Throwable boundary.
ab_store();
add_filter( 'jpkcom_harden_trim_settings', static function (): never {
	throw new RuntimeException( 'corrupt' );
} );
$ab_err = jpkcom_harden_trim_ability_get_status();
chk( 'a Throwable while reading becomes a WP_Error with status 500', $ab_err instanceof WP_Error && 'jpkcom_harden_trim_status_failed' === $ab_err->get_error_codes()[0] && 500 === ( $ab_err->error_data['jpkcom_harden_trim_status_failed']['status'] ?? null ) );
chk( 'the error message does not leak the exception text', ! str_contains( $ab_err instanceof WP_Error ? $ab_err->get_error_message() : 'corrupt', 'corrupt' ) );
$GLOBALS['jht_hooks']['jpkcom_harden_trim_settings'] = array();
jpkcom_harden_trim_settings_reset_cache();

// Registration.
ab_store();
$GLOBALS['jht_abilities']          = array();
$GLOBALS['jht_ability_categories'] = array();
// The hooks themselves are asserted by test-settings-store.php (kill-switch fixture);
// ab_store() resets the recorded hooks, so the callbacks are called directly here.
jpkcom_harden_trim_register_ability_category();
jpkcom_harden_trim_register_abilities();
chk( 'registration callbacks register the category and the ability', array( JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY ) === array_keys( $GLOBALS['jht_ability_categories'] ) && array( $ab_name ) === array_keys( $GLOBALS['jht_abilities'] ) );
$GLOBALS['jht_ability_categories'][ JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY ] = array( 'label' => 'first' );
jpkcom_harden_trim_register_ability_category();
chk( 'category is first-wins: an existing one is not re-registered', array( 'label' => 'first' ) === $GLOBALS['jht_ability_categories'][ JPKCOM_HARDEN_TRIM_ABILITY_CATEGORY ] );

// The gate, per process (constants).
$ab_run = static function ( array $defines ): array {
	$run = jht_run_fixture( __DIR__ . '/fixture-cli-status.php', array( 'JHT_ACTION' => 'abilities', 'JHT_DEFINE' => (string) json_encode( $defines ) ) );
	$out = json_decode( json: $run['out'], associative: true );
	return is_array( $out ) ? $out : array( 'raw' => $run['out'] );
};
$ab_out = $ab_run( array() );
chk( 'gate baseline: registered (proves the probe sees a registration)', true === ( $ab_out['enabled'] ?? null ) && array( $ab_name ) === ( $ab_out['abilities'] ?? null ) );
$ab_out = $ab_run( array( 'JPKCOM_HARDEN_TRIM_ABILITIES' => false ) );
chk( 'JPKCOM_HARDEN_TRIM_ABILITIES false: nothing registered', false === ( $ab_out['enabled'] ?? null ) && array() === ( $ab_out['abilities'] ?? null ) && array() === ( $ab_out['categories'] ?? null ) );
$ab_out = $ab_run( array( 'JPKCOM_HARDEN_TRIM_ABILITIES' => 'false' ) );
chk( "JPKCOM_HARDEN_TRIM_ABILITIES 'false' (a string, as wp-config.php often has it): nothing registered", array() === ( $ab_out['abilities'] ?? null ) );
$ab_out = $ab_run( array( 'JPKCOM_HARDEN_TRIM_ABILITIES' => 'sometimes' ) );
chk( 'JPKCOM_HARDEN_TRIM_ABILITIES with no boolean value: ignored, registered', array( $ab_name ) === ( $ab_out['abilities'] ?? null ) );
$ab_out = $ab_run( array( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' => true ) );
chk( 'the feature kill switch does not suppress the ability (it is how to see the kill switch)', array( $ab_name ) === ( $ab_out['abilities'] ?? null ) );

summary();
