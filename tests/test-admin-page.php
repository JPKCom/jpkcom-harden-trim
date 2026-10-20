<?php
/**
 * Tests for the settings page: hook glue, rendering (every key, nonce, form
 * target, sections, badges, locks, block themes, notices, escaping) and the
 * save and preset handlers (capability, nonce, write, redirect, notice).
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/stubs-admin.php';

$root = dirname( path: __DIR__ );

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_FILE' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_FILE', value: $root . '/jpkcom-harden-trim.php' );
}

if ( ! defined( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION' ) ) {
	define( constant_name: 'JPKCOM_HARDEN_TRIM_VERSION', value: '1.0.0' );
}

jht_reset_admin();

foreach ( array( 'includes/settings-schema.php', 'includes/settings-store.php', 'includes/admin-page.php' ) as $jht_module ) {
	if ( is_file( filename: $root . '/' . $jht_module ) ) {
		require_once $root . '/' . $jht_module;
	}
}

$jht_missing = array_values(
	array: array_filter(
		array: array(
			'jpkcom_harden_trim_admin_menu',
			'jpkcom_harden_trim_render_page',
			'jpkcom_harden_trim_handle_save',
			'jpkcom_harden_trim_handle_preset',
			'jpkcom_harden_trim_notice_messages',
			'jpkcom_harden_trim_field_label',
			'jpkcom_harden_trim_field_description',
			'jpkcom_harden_trim_plugin_action_links',
		),
		callback: static fn( string $fn ): bool => ! function_exists( $fn )
	)
);
chk( 'admin API exists (missing: ' . implode( separator: ', ', array: $jht_missing ) . ')', array() === $jht_missing );

if ( array() !== $jht_missing ) {
	summary();
}

// Not jht_-prefixed: jht_reset_wp() clears every jht_* global.
$option_name = jpkcom_harden_trim_option_name();
$schema = jpkcom_harden_trim_schema();

/**
 * Store a settings array as the option.
 *
 * @param array<string, mixed> $overrides Values over the defaults.
 * @return void
 */
function jht_store( array $overrides = array() ): void {
	$GLOBALS['jht_options'][ jpkcom_harden_trim_option_name() ] = array(
		'schema'   => 1,
		'updated'  => '',
		'settings' => array_merge( jpkcom_harden_trim_defaults(), $overrides ),
	);
	jpkcom_harden_trim_settings_reset_cache();
}

/**
 * Render the page and return the HTML.
 *
 * @return string
 */
function jht_render(): string {
	jpkcom_harden_trim_settings_reset_cache();
	ob_start();
	jpkcom_harden_trim_render_page();
	return (string) ob_get_clean();
}

/**
 * Run a handler and return what ended it.
 *
 * @param callable $handler Handler.
 * @return array{die: ?int, redirect: ?string}
 */
function jht_run_handler( callable $handler ): array {
	$result = array( 'die' => null, 'redirect' => null );
	try {
		$handler();
	} catch ( JhtDie $e ) {
		$result['die'] = $e->status;
	} catch ( JhtRedirect $e ) {
		$result['redirect'] = $e->url;
	}
	return $result;
}

/**
 * A complete form submission built from the stored settings, as the browser would send it.
 *
 * @param array<string, mixed> $overrides Field values to change.
 * @return array<string, mixed>
 */
function jht_form_post( array $overrides = array() ): array {
	$post = array();
	foreach ( jpkcom_harden_trim_get_stored() as $key => $value ) {
		$type = jpkcom_harden_trim_schema()[ $key ]['type'];
		if ( 'bool' === $type ) {
			$post[ $key ] = $value ? '1' : '0';
		} elseif ( 'int' === $type ) {
			$post[ $key ] = null === $value ? '' : (string) $value;
		} elseif ( 'roles' === $type ) {
			if ( array() !== $value ) {
				$post[ $key ] = $value;
			}
		} else {
			$post[ $key ] = $value;
		}
	}
	return array_merge( $post, $overrides );
}

// Hook glue.

$jht_basename = 'jpkcom-harden-trim/jpkcom-harden-trim.php';
chk( 'admin_menu hooked', false !== has_action( 'admin_menu', 'jpkcom_harden_trim_admin_menu' ) );
chk( 'save handler hooked on admin_post_jpkcom_harden_trim_save', false !== has_action( 'admin_post_jpkcom_harden_trim_save', 'jpkcom_harden_trim_handle_save' ) );
chk( 'preset handler hooked on admin_post_jpkcom_harden_trim_preset', false !== has_action( 'admin_post_jpkcom_harden_trim_preset', 'jpkcom_harden_trim_handle_preset' ) );
chk( 'no nopriv handlers', ! has_action( 'admin_post_nopriv_jpkcom_harden_trim_save' ) && ! has_action( 'admin_post_nopriv_jpkcom_harden_trim_preset' ) );
chk( 'plugin row link hooked on plugin_action_links_<basename>', false !== has_filter( 'plugin_action_links_' . $jht_basename, 'jpkcom_harden_trim_plugin_action_links' ) );

$jht_links = jpkcom_harden_trim_plugin_action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );
$jht_first = (string) reset( $jht_links );
chk( 'settings link comes first and points to the page', str_contains( haystack: $jht_first, needle: 'options-general.php?page=jpkcom-harden-trim' ) && str_contains( haystack: $jht_first, needle: '>Settings</a>' ) );
chk( 'existing links kept', 2 === count( $jht_links ) );

jpkcom_harden_trim_admin_menu();
$jht_page = $GLOBALS['jht_menu_pages'][0] ?? array();
chk( 'add_options_page: title, menu title, capability, slug, callback', array( 'Harden & Trim', 'Harden & Trim', 'manage_options', 'jpkcom-harden-trim', 'jpkcom_harden_trim_render_page' ) === $jht_page );
chk( 'styles hooked on admin_print_styles-settings_page_jpkcom-harden-trim', array() !== ( $GLOBALS['jht_hooks']['admin_print_styles-settings_page_jpkcom-harden-trim'] ?? array() ) );
chk( 'scripts hooked on admin_print_scripts-settings_page_jpkcom-harden-trim', array() !== ( $GLOBALS['jht_hooks']['admin_print_scripts-settings_page_jpkcom-harden-trim'] ?? array() ) );
chk( 'nothing enqueued before the page hooks fire', array() === $GLOBALS['jht_enqueued'] );
do_action( 'admin_print_styles-settings_page_jpkcom-harden-trim' );
do_action( 'admin_print_scripts-settings_page_jpkcom-harden-trim' );
$jht_assets = array_map( static fn( array $e ): string => $e[0] . ':' . basename( path: $e[2] ), $GLOBALS['jht_enqueued'] );
chk( 'admin.css and admin.js enqueued on the page hooks (' . implode( separator: ',', array: $jht_assets ) . ')', in_array( needle: 'style:admin.css', haystack: $jht_assets, strict: true ) && in_array( needle: 'script:admin.js', haystack: $jht_assets, strict: true ) );
chk( 'asset files exist', is_file( filename: $root . '/assets/admin.css' ) && is_file( filename: $root . '/assets/admin.js' ) );

// Copy.

$jht_copy_ok = true;
foreach ( array_keys( array: $schema ) as $jht_key ) {
	if ( '' === jpkcom_harden_trim_field_label( $jht_key ) || '' === jpkcom_harden_trim_field_description( $jht_key ) || $jht_key === jpkcom_harden_trim_field_label( $jht_key ) ) {
		$jht_copy_ok = false;
		echo "  no label/description for {$jht_key}\n";
	}
}
chk( 'every key has a label and a description', $jht_copy_ok );
chk( 'unknown key: empty label', '' === jpkcom_harden_trim_field_label( 'nope' ) );
chk( 'choice labels: default option, minutes, bool', 'WordPress default' === jpkcom_harden_trim_choice_label( 'post_revisions', null ) && '2 minutes' === jpkcom_harden_trim_choice_label( 'autosave_interval', 120 ) && 'Off' === jpkcom_harden_trim_choice_label( 'emoji', false ) );
chk( 'notice codes are exactly the five', array( 'saved', 'preset_applied', 'imported', 'import_failed', 'nothing_changed' ) === array_keys( array: jpkcom_harden_trim_notice_messages() ) );

$jht_warn_ok = true;
foreach ( array( 'asset_version', 'post_embeds', 'comment_reply', 'dashicons', 'block_library_css', 'global_styles', 'heartbeat', 'post_revisions', 'head_feed_links', 'local_avatars', 'comment_ip', 'editor_remote', 'header_frame', 'header_permissions', 'header_hsts', 'file_edit', 'app_passwords' ) as $jht_key ) {
	if ( ! str_contains( haystack: jpkcom_harden_trim_field_description( $jht_key ), needle: 'Warning:' ) ) {
		$jht_warn_ok = false;
		echo "  no warning for {$jht_key}\n";
	}
}
chk( 'every off-by-default feature with a break path carries a warning', $jht_warn_ok );
chk( 'heartbeat warning lists what stops', (bool) preg_match( '/post locking.*session.*autosave.*nonce/is', jpkcom_harden_trim_field_description( 'heartbeat' ) ) );

// Default render.

jht_reset_admin();
jht_store();
$html = jht_render();

$jht_every = true;
foreach ( array_keys( array: $schema ) as $jht_key ) {
	if ( array() === jht_field_tags( $html, $jht_key ) ) {
		$jht_every = false;
		echo "  no input for {$jht_key}\n";
	}
}
chk( 'render: every schema key has an input named jpkcom_harden_trim[<key>]', $jht_every );
chk( 'render: nonce field present', str_contains( haystack: $html, needle: 'name="jpkcom_harden_trim_nonce" value="nonce-jpkcom_harden_trim_save"' ) );
chk( 'render: form posts to admin-post.php', (bool) preg_match( '/<form[^>]*method="post"[^>]*action="https:\/\/example\.test\/wp-admin\/admin-post\.php"/', $html ) );
chk( 'render: action=jpkcom_harden_trim_save', str_contains( haystack: $html, needle: 'name="action" value="jpkcom_harden_trim_save"' ) );
chk( 'render: no field disabled by default', 0 === preg_match( '/<(?:input|select|button)\b[^>]*\sdisabled/', $html ) );
chk( 'render: no kill-switch notice by default', ! str_contains( haystack: $html, needle: 'JPKCOM_HARDEN_TRIM_DISABLE_ALL' ) );
chk( 'render: no <script> tag', ! str_contains( haystack: strtolower( string: $html ), needle: '<script' ) );

preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/s', $html, $jht_h2 );
$jht_sections = array_map( static fn( string $h ): string => trim( string: html_entity_decode( string: strip_tags( string: $h ) ) ), $jht_h2[1] );
chk( 'render: sections in settings-page order (' . implode( separator: ' | ', array: $jht_sections ) . ')', array( 'Performance', 'Clean up <head>', 'Privacy', 'Security', 'Roles', 'Presets' ) === $jht_sections );

$jht_emoji = jht_field_tags( $html, 'emoji' );
chk( 'render: bool sends 0 via a hidden input before the checkbox', 2 === count( $jht_emoji ) && str_contains( haystack: $jht_emoji[0], needle: 'type="hidden"' ) && str_contains( haystack: $jht_emoji[0], needle: 'value="0"' ) && str_contains( haystack: $jht_emoji[1], needle: 'type="checkbox"' ) && str_contains( haystack: $jht_emoji[1], needle: 'value="1"' ) );
chk( 'render: default-on bool is checked', str_contains( haystack: $jht_emoji[1] ?? '', needle: 'checked' ) );
$jht_dash = jht_field_tags( $html, 'dashicons' );
chk( 'render: default-off bool is unchecked', ! str_contains( haystack: $jht_dash[1] ?? 'checked', needle: 'checked' ) );

$jht_rev = jht_field_tags( $html, 'post_revisions' );
chk( 'render: nullable int is a select', 1 === count( $jht_rev ) && str_starts_with( haystack: $jht_rev[0], needle: '<select' ) );
chk( 'render: nullable int select has the empty "WordPress default" option selected', (bool) preg_match( '/<option value=""[^>]*selected[^>]*>WordPress default<\/option>/', $html ) );
chk( 'render: autosave choices shown in minutes', str_contains( haystack: $html, needle: '>2 minutes<' ) && str_contains( haystack: $html, needle: '>60 minutes<' ) );

$jht_hb = jht_field_tags( $html, 'heartbeat' );
chk( 'render: enum as one radio per choice', 3 === count( $jht_hb ) && 3 === count( array_filter( array: $jht_hb, callback: static fn( string $t ): bool => str_contains( haystack: $t, needle: 'type="radio"' ) ) ) );
chk( 'render: enum groups use fieldset and legend', substr_count( haystack: $html, needle: '<fieldset' ) >= 4 && substr_count( haystack: $html, needle: '<legend' ) === substr_count( haystack: $html, needle: '<fieldset' ) );

$jht_roles = jht_field_tags( $html, 'exempt_roles' );
chk( 'render: roles as checkboxes named jpkcom_harden_trim[exempt_roles][]', 5 === count( $jht_roles ) && str_contains( haystack: $jht_roles[0], needle: 'name="jpkcom_harden_trim[exempt_roles][]"' ) && str_contains( haystack: $jht_roles[0], needle: 'type="checkbox"' ) );
chk( 'render: no exempt role checked by default', 0 === count( array_filter( array: $jht_roles, callback: static fn( string $t ): bool => str_contains( haystack: $t, needle: 'checked' ) ) ) );

$jht_described = true;
preg_match_all( '/<(?:input|select)\b[^>]*name="jpkcom_harden_trim\[[^"]*"[^>]*>/', $html, $jht_all );
foreach ( $jht_all[0] as $jht_tag ) {
	if ( str_contains( haystack: $jht_tag, needle: 'type="hidden"' ) ) {
		continue;
	}
	if ( ! preg_match( '/aria-describedby="([^"]+)"/', $jht_tag, $jht_m ) || ! str_contains( haystack: $html, needle: 'id="' . $jht_m[1] . '"' ) ) {
		$jht_described = false;
		echo "  no description link: {$jht_tag}\n";
	}
}
chk( 'render: every visible input has aria-describedby pointing at an existing id', $jht_described );

chk( 'render: badge Changes appearance', str_contains( haystack: $html, needle: 'Changes appearance' ) );
chk( 'render: badge Affects editors', str_contains( haystack: $html, needle: 'Affects editors' ) );
chk( 'render: global list in the roles section', str_contains( haystack: $html, needle: 'Global — role exemption does not apply' ) );
chk( 'render: header note about page caches', str_contains( haystack: $html, needle: 'page cache' ) );

chk( 'render: preset form posts action=jpkcom_harden_trim_preset with its nonce', str_contains( haystack: $html, needle: 'name="action" value="jpkcom_harden_trim_preset"' ) && str_contains( haystack: $html, needle: 'value="nonce-jpkcom_harden_trim_preset"' ) );
preg_match_all( '/<button\b[^>]*name="preset"[^>]*>/', $html, $jht_buttons );
chk( 'render: three preset buttons', 3 === count( $jht_buttons[0] ) );
$jht_confirm_ok = true;
foreach ( $jht_buttons[0] as $jht_button ) {
	if ( ! preg_match( '/data-jht-confirm="([^"]+)"/', $jht_button, $jht_m ) ) {
		$jht_confirm_ok = false;
		continue;
	}
	$jht_text = html_entity_decode( string: $jht_m[1], flags: ENT_QUOTES );
	foreach ( array( 'block_library_css', 'global_styles', 'header_hsts', 'head_feed_links', 'app_passwords', 'app_password_roles', 'comments', 'exempt_roles' ) as $jht_key ) {
		if ( ! str_contains( haystack: $jht_text, needle: jpkcom_harden_trim_field_label( $jht_key ) ) ) {
			$jht_confirm_ok = false;
			echo "  confirm text lacks {$jht_key}\n";
		}
	}
}
chk( 'render: every preset button confirms and names the keys presets never touch', $jht_confirm_ok && 3 === count( $jht_buttons[0] ) );
chk( 'render: preset forms are not nested in the settings form', 1 === preg_match( '/<\/form>.*value="jpkcom_harden_trim_preset"/s', $html ) );

// No capability: wp_die( 403 ), nothing rendered (see "render_page without capability" below).

// Block theme.

$GLOBALS['jht_state']['block_theme'] = true;
$html                                = jht_render();
$jht_bt_ok                           = true;
foreach ( array( 'block_library_css', 'global_styles' ) as $jht_key ) {
	$tags = jht_field_tags( $html, $jht_key );
	if ( array() === $tags || array() !== array_filter( array: $tags, callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) ) {
		$jht_bt_ok = false;
	}
}
chk( 'block theme: block_library_css and global_styles inputs disabled', $jht_bt_ok );
chk( 'block theme: text Not available with block themes', str_contains( haystack: $html, needle: 'Not available with block themes' ) );
chk( 'block theme: other inputs stay enabled', array() === array_filter( array: jht_field_tags( $html, 'emoji' ), callback: 'jht_tag_disabled' ) );
$GLOBALS['jht_state']['block_theme'] = false;

// Notices.

jht_reset_admin();
jht_store();
$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => '<script>alert(1)</script>', 'jht_changed' => '<script>x</script>' );
$html = jht_render();
chk( 'unknown jht_msg renders no notice', ! str_contains( haystack: $html, needle: 'notice-' ) );
chk( 'no unescaped <script> from query args', ! str_contains( haystack: strtolower( string: $html ), needle: '<script' ) && ! str_contains( haystack: $html, needle: 'alert(1)' ) );

$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'saved', 'jht_changed' => '2' );
set_transient( 'jpkcom_harden_trim_notice_7', array( 'code' => 'saved', 'changed' => array( 'emoji', 'heartbeat' ), 'rejected' => array( '<img src=x>' ) ), 60 );
$html = jht_render();
chk( 'saved notice shown', str_contains( haystack: $html, needle: 'Settings saved.' ) && str_contains( haystack: $html, needle: 'notice-success' ) );
chk( 'saved notice lists changed keys by label', str_contains( haystack: $html, needle: jpkcom_harden_trim_field_label( 'emoji' ) ) && str_contains( haystack: $html, needle: esc_html( jpkcom_harden_trim_field_label( 'heartbeat' ) ) ) );
chk( 'rejected entries escaped', str_contains( haystack: $html, needle: '&lt;img src=x&gt;' ) && ! str_contains( haystack: $html, needle: '<img src=x>' ) );
chk( 'notice transient consumed', false === get_transient( 'jpkcom_harden_trim_notice_7' ) );

$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'nothing_changed' );
set_transient( 'jpkcom_harden_trim_notice_7', array( 'code' => 'saved', 'changed' => array( 'emoji' ) ), 60 );
$html = jht_render();
chk( 'nothing_changed notice shown', str_contains( haystack: $html, needle: 'Nothing changed.' ) );
chk( 'transient of another code is not shown', ! str_contains( haystack: $html, needle: 'Changed:' ) );

set_transient( 'jpkcom_harden_trim_notice_8', array( 'code' => 'saved', 'changed' => array( 'emoji' ) ), 60 );
$_GET = array( 'page' => 'jpkcom-harden-trim', 'jht_msg' => 'saved', 'jht_changed' => '1' );
$html = jht_render();
chk( 'another user\'s transient is not read', ! str_contains( haystack: $html, needle: 'Changed:' ) && false !== get_transient( 'jpkcom_harden_trim_notice_8' ) );
chk( 'count from the URL used when no transient', str_contains( haystack: $html, needle: '1 setting changed.' ) );

// Save handler.

jht_reset_admin();
jht_store();
$jht_before = $GLOBALS['jht_options'][ $option_name ];

$GLOBALS['jht_caps'] = array();
$_POST               = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => jht_form_post( array( 'emoji' => '0' ) ) );
$_REQUEST            = $_POST;
$r                   = jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save without capability: wp_die 403', 403 === $r['die'] );
chk( 'save without capability: option untouched', $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$GLOBALS['jht_caps'] = array( 'manage_options' => true );
$_POST['jpkcom_harden_trim_nonce'] = 'nonce-something-else';
$_REQUEST                          = $_POST;
$r                                 = jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save with bad nonce: dies', null !== $r['die'] && null === $r['redirect'] );
chk( 'save with bad nonce: option untouched', $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_POST    = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => jht_form_post( array( 'emoji' => '0', 'heartbeat' => 'optimized', 'post_revisions' => '20', 'exempt_roles' => array( 'editor' ) ) ) );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_save' );
$jht_s    = $GLOBALS['jht_options'][ $option_name ]['settings'] ?? array();
chk( 'save valid: redirect with jht_msg=saved', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=saved' ) );
chk( 'save valid: redirect to the settings page', is_string( $r['redirect'] ) && str_starts_with( haystack: $r['redirect'], needle: 'https://example.test/wp-admin/options-general.php?' ) && str_contains( haystack: $r['redirect'], needle: 'page=jpkcom-harden-trim' ) );
chk( 'save valid: redirect carries the changed count, not key names', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_changed=4' ) && ! str_contains( haystack: $r['redirect'], needle: 'emoji' ) );
chk( 'save valid: unchecked checkbox (hidden 0) stores false', false === ( $jht_s['emoji'] ?? null ) );
chk( 'save valid: enum, int and roles stored', 'optimized' === ( $jht_s['heartbeat'] ?? null ) && 20 === ( $jht_s['post_revisions'] ?? null ) && array( 'editor' ) === ( $jht_s['exempt_roles'] ?? null ) );
chk( 'save valid: untouched keys keep their value', true === ( $jht_s['head_generator'] ?? null ) && array_key_exists( key: 'autosave_interval', array: $jht_s ) && null === $jht_s['autosave_interval'] );
$jht_notice = get_transient( 'jpkcom_harden_trim_notice_7' );
chk( 'save valid: changed keys go to the user-scoped transient', is_array( $jht_notice ) && 'saved' === $jht_notice['code'] && array( 'emoji', 'heartbeat', 'post_revisions', 'exempt_roles' ) === $jht_notice['changed'] );
chk( 'save valid: transient lives 60 s', 60 === ( $GLOBALS['jht_transients']['jpkcom_harden_trim_notice_7']['expiration'] ?? null ) );

$_POST    = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => jht_form_post( array( 'exempt_roles' => null ) ) );
unset( $_POST['jpkcom_harden_trim']['exempt_roles'] );
$_REQUEST = $_POST;
jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save: no role ticked stores an empty list', array() === $GLOBALS['jht_options'][ $option_name ]['settings']['exempt_roles'] );

$_POST    = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => jht_form_post( array( 'heartbeat' => 'turbo' ) ) );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save: invalid enum falls back to the default and is reported', 'enabled' === $GLOBALS['jht_options'][ $option_name ]['settings']['heartbeat'] && in_array( needle: 'heartbeat', haystack: get_transient( 'jpkcom_harden_trim_notice_7' )['rejected'] ?? array(), strict: true ) );

$_POST    = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => 'not-an-array' );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save: non-array input does not fatal and redirects', is_string( $r['redirect'] ) );

// Block theme: the disabled fields keep their stored value.
jht_reset_admin();
jht_store( array( 'block_library_css' => true, 'global_styles' => true ) );
$GLOBALS['jht_state']['block_theme'] = true;
$jht_post                            = jht_form_post();
unset( $jht_post['block_library_css'], $jht_post['global_styles'] );
$_POST    = array( 'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save', 'jpkcom_harden_trim' => $jht_post );
$_REQUEST = $_POST;
jht_run_handler( 'jpkcom_harden_trim_handle_save' );
chk( 'save on a block theme keeps block_library_css and global_styles', true === $GLOBALS['jht_options'][ $option_name ]['settings']['block_library_css'] && true === $GLOBALS['jht_options'][ $option_name ]['settings']['global_styles'] );
$GLOBALS['jht_state']['block_theme'] = false;

// Preset handler.

jht_reset_admin();
jht_store();
$jht_before = $GLOBALS['jht_options'][ $option_name ];

$GLOBALS['jht_caps'] = array();
$_POST               = array( 'jpkcom_harden_trim_preset_nonce' => 'nonce-jpkcom_harden_trim_preset', 'preset' => 'strict' );
$_REQUEST            = $_POST;
$r                   = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
chk( 'preset without capability: wp_die 403, no write', 403 === $r['die'] && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$GLOBALS['jht_caps'] = array( 'manage_options' => true );
$_POST               = array( 'jpkcom_harden_trim_preset_nonce' => 'bad', 'preset' => 'strict' );
$_REQUEST            = $_POST;
$r                   = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
chk( 'preset with bad nonce: dies, no write', null !== $r['die'] && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_POST    = array( 'jpkcom_harden_trim_preset_nonce' => 'nonce-jpkcom_harden_trim_preset', 'preset' => '../../evil' );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
chk( 'preset ../../evil: redirect with nothing_changed', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=nothing_changed' ) );
chk( 'preset ../../evil: no write', $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_POST    = array( 'jpkcom_harden_trim_preset_nonce' => 'nonce-jpkcom_harden_trim_preset', 'preset' => array( 'strict' ) );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
chk( 'preset as array: nothing_changed, no write', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=nothing_changed' ) && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

$_POST    = array( 'jpkcom_harden_trim_preset_nonce' => 'nonce-jpkcom_harden_trim_preset', 'preset' => 'harmless' );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
chk( 'preset harmless on defaults: nothing_changed, no write', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=nothing_changed' ) && $jht_before === $GLOBALS['jht_options'][ $option_name ] );

jht_store( array( 'header_hsts' => true, 'exempt_roles' => array( 'editor' ) ) );
$_POST    = array( 'jpkcom_harden_trim_preset_nonce' => 'nonce-jpkcom_harden_trim_preset', 'preset' => 'strict' );
$_REQUEST = $_POST;
$r        = jht_run_handler( 'jpkcom_harden_trim_handle_preset' );
$jht_s    = $GLOBALS['jht_options'][ $option_name ]['settings'] ?? array();
chk( 'preset strict: stored asset_version=hash', 'hash' === ( $jht_s['asset_version'] ?? null ) );
chk( 'preset strict: redirect with preset_applied and the changed count', is_string( $r['redirect'] ) && str_contains( haystack: $r['redirect'], needle: 'jht_msg=preset_applied' ) && str_contains( haystack: $r['redirect'], needle: 'jht_changed=' . count( jpkcom_harden_trim_apply_preset( 'strict', jpkcom_harden_trim_defaults() )['changed'] ) ) );
chk( 'preset strict: never-touched keys kept', true === ( $jht_s['header_hsts'] ?? null ) && array( 'editor' ) === ( $jht_s['exempt_roles'] ?? null ) );
$jht_notice = get_transient( 'jpkcom_harden_trim_notice_7' );
chk( 'preset strict: changed keys in the transient', is_array( $jht_notice ) && 'preset_applied' === $jht_notice['code'] && in_array( needle: 'asset_version', haystack: $jht_notice['changed'], strict: true ) );

// app_passwords = roles with no role selected locks everybody out: the page says so.
jht_reset_admin();
jht_store( array( 'app_passwords' => 'roles', 'app_password_roles' => array() ) );
$html = jht_render();
chk( 'app_passwords roles without roles: warning notice', (bool) preg_match( '/notice-warning[^>]*>\s*<p>[^<]*no role is selected[^<]*administrators included/', $html ) );
jht_store( array( 'app_passwords' => 'roles', 'app_password_roles' => array( 'administrator' ) ) );
chk( 'app_passwords roles with a role: no warning', ! str_contains( haystack: jht_render(), needle: 'no role is selected' ) );
jht_store( array( 'app_passwords' => 'unchanged', 'app_password_roles' => array() ) );
chk( 'app_passwords unchanged: no warning', ! str_contains( haystack: jht_render(), needle: 'no role is selected' ) );
// Header de-duplication only sees headers_list() (PHP); the page must not promise more.
jht_reset_admin();
jht_store();
$html = jht_render();
chk( 'security section: no claim that server headers are detected', ! str_contains( haystack: $html, needle: 'a header the server or another plugin already sets is left alone' ) );
chk( 'security section: says web server and CDN headers are invisible to PHP', str_contains( haystack: $html, needle: 'cannot be seen from PHP' ) );
// global_styles also dequeues core-block-supports (layout rules of columns, rows, groups): the warning must say so.
chk( 'global_styles description warns about block layouts', str_contains( haystack: jpkcom_harden_trim_field_description( 'global_styles' ), needle: 'columns, rows and groups' ) );
chk( 'app_password_roles description states the empty case', str_contains( haystack: jpkcom_harden_trim_field_description( 'app_password_roles' ), needle: 'administrators included' ) );

// A jpkcom_harden_trim_settings filter changes effective values only: the form shows and saves stored values.
jht_reset_admin();
jht_store( array( 'emoji' => true, 'heartbeat' => 'optimized' ) );
add_filter( 'jpkcom_harden_trim_settings', static fn( array $s ): array => array_merge( $s, array( 'emoji' => false, 'header_frame' => true ) ) );
$html = jht_render();
$ap_emoji = array_values( array: array_filter( array: jht_field_tags( $html, 'emoji' ), callback: static fn( string $t ): bool => str_contains( haystack: $t, needle: 'type="checkbox"' ) ) );
$ap_frame = array_values( array: array_filter( array: jht_field_tags( $html, 'header_frame' ), callback: static fn( string $t ): bool => str_contains( haystack: $t, needle: 'type="checkbox"' ) ) );
chk( 'filter: emoji checkbox shows the stored value (checked)', isset( $ap_emoji[0] ) && str_contains( haystack: $ap_emoji[0], needle: 'checked' ) );
chk( 'filter: header_frame checkbox shows the stored value (unchecked)', isset( $ap_frame[0] ) && ! str_contains( haystack: $ap_frame[0], needle: 'checked' ) );
chk( 'filter: fields changed by the filter are marked', 2 === substr_count( haystack: $html, needle: 'jht-badge-filtered' ) );
chk( 'filter: the note names the value in effect', (bool) preg_match( '/jht-field-emoji.*?jht-filtered-note[^>]*>[^<]*<code>Off<\/code>/s', $html ) );
chk( 'filter: unaffected field not marked', ! preg_match( '/jht-field-dashicons.*?jht-badge-filtered.*?jht-field-dashboard_events/s', $html ) );
// Saving the rendered form unchanged keeps the stored values, so the filter's output never reaches the option.
$_POST    = array(
	'jpkcom_harden_trim_nonce' => 'nonce-jpkcom_harden_trim_save',
	'jpkcom_harden_trim'       => jht_form_post(
		array(
			'emoji'        => str_contains( haystack: $ap_emoji[0] ?? '', needle: 'checked' ) ? '1' : '0',
			'header_frame' => str_contains( haystack: $ap_frame[0] ?? '', needle: 'checked' ) ? '1' : '0',
		)
	),
);
$_REQUEST = $_POST;
jht_run_handler( 'jpkcom_harden_trim_handle_save' );
$ap_stored = $GLOBALS['jht_options'][ $option_name ]['settings'];
chk( 'filter: untouched save keeps stored emoji = true and header_frame = false', true === $ap_stored['emoji'] && false === $ap_stored['header_frame'] );

// The page callback itself refuses users without manage_options (the menu capability is the first gate).
jht_reset_admin();
jht_store();
$GLOBALS['jht_caps'] = array();
ob_start();
$r = jht_run_handler( 'jpkcom_harden_trim_render_page' );
$ap_out = (string) ob_get_clean();
chk( 'render_page without capability: wp_die 403', 403 === $r['die'] );
chk( 'render_page without capability: no settings markup', ! str_contains( haystack: $ap_out, needle: 'jpkcom_harden_trim_nonce' ) );
$GLOBALS['jht_caps'] = array( 'manage_options' => true );

// XML-RPC and comments (1.1.0): end of the Security section, badges from the schema flags,
// a hint only while the standalone plugin is active (constant cases in the fixture below).

/**
 * The form-table row of one setting.
 *
 * @param string $html Rendered page.
 * @param string $key  Setting key.
 * @return string Row markup, '' when absent.
 */
function jht_field_row( string $html, string $key ): string {
	return 1 === preg_match( '/<tr class="jht-field jht-field-' . preg_quote( str: $key, delimiter: '/' ) . '">.*?<\/tr>/s', $html, $m ) ? $m[0] : '';
}

$sw_hint_xmlrpc   = 'XML-RPC is already disabled by the JPKCom Disable XML-RPC plugin, so this switch is not needed while that plugin is active.';
$sw_hint_comments = 'Comments are already disabled by the JPKCom Disable Comments plugin, so this switch is not needed while that plugin is active.';

chk( 'xmlrpc label', 'Disable XML-RPC' === jpkcom_harden_trim_field_label( 'xmlrpc' ) );
chk( 'comments label', 'Disable comments' === jpkcom_harden_trim_field_label( 'comments' ) );
$sw_desc = jpkcom_harden_trim_field_description( 'xmlrpc' );
chk( 'xmlrpc description: xmlrpc.php refused with a 403, endpoint no longer advertised, trackbacks refused', str_contains( haystack: $sw_desc, needle: 'xmlrpc.php' ) && str_contains( haystack: $sw_desc, needle: '403' ) && str_contains( haystack: $sw_desc, needle: 'advertis' ) && str_contains( haystack: $sw_desc, needle: 'trackbacks are refused' ) );
chk( 'xmlrpc description warns: Jetpack, the mobile apps and incoming pingbacks stop working', (bool) preg_match( '/Warning:.*Jetpack.*mobile apps.*incoming pingbacks stop working/s', $sw_desc ) );
chk( 'xmlrpc description: every preset switches it back on, the constant keeps it off', str_contains( haystack: $sw_desc, needle: 'every preset' ) && str_contains( haystack: $sw_desc, needle: 'back on' ) && str_contains( haystack: $sw_desc, needle: 'JPKCOM_HARDEN_TRIM_XMLRPC' ) );
$sw_desc = jpkcom_harden_trim_field_description( 'comments' );
chk( 'comments description: comments, pings and trackbacks closed on every post type', str_contains( haystack: $sw_desc, needle: 'comments, pings and trackbacks on every post type' ) );
chk( 'comments description: existing comments hidden, not deleted', str_contains( haystack: $sw_desc, needle: 'hides existing comments (they are not deleted)' ) );
chk( 'comments description: screen, menu, comment counts and comment feeds disappear', str_contains( haystack: $sw_desc, needle: 'The Comments screen, menu, comment counts and comment feeds disappear' ) );
chk( 'comments description: the REST comment routes answer 404', str_contains( haystack: $sw_desc, needle: 'the REST comment routes answer 404' ) );
chk( 'comments description: block editor Notes keep working', str_ends_with( haystack: $sw_desc, needle: 'Block editor Notes keep working.' ) );
chk( 'comments description: no Notes warning any more', ! str_contains( haystack: $sw_desc, needle: 'Warning' ) && ! str_contains( haystack: $sw_desc, needle: 'stop working' ) );

// Preset summaries: xmlrpc is on in harmless (from the default), so Recommended must not list it as an addition.
jht_reset_admin();
jht_store();
ob_start();
jpkcom_harden_trim_render_presets();
$sw_presets = (string) ob_get_clean();
preg_match( '/<li><strong>Recommended<\/strong> — ([^<]*)<\/li>/', $sw_presets, $sw_m );
$sw_rec = $sw_m[1] ?? '';
chk( 'presets: Recommended summary rendered (' . $sw_rec . ')', str_starts_with( haystack: $sw_rec, needle: 'Harmless, plus: ' ) && str_contains( haystack: $sw_rec, needle: 'Heartbeat' ) );
chk( 'presets: Recommended summary does not list Disable XML-RPC as an addition', '' !== $sw_rec && ! str_contains( haystack: $sw_rec, needle: 'Disable XML-RPC' ) );
preg_match( '/<li><strong>Strict<\/strong> — ([^<]*)<\/li>/', $sw_presets, $sw_m );
$sw_strict = $sw_m[1] ?? '';
chk( 'presets: Strict summary rendered, without Disable XML-RPC (' . $sw_strict . ')', str_starts_with( haystack: $sw_strict, needle: 'Recommended, plus: ' ) && ! str_contains( haystack: $sw_strict, needle: 'Disable XML-RPC' ) );

chk( 'standalone hint API exists', function_exists( 'jpkcom_harden_trim_standalone_hint' ) );
$sw_hint = static fn( string $key ): ?string => function_exists( 'jpkcom_harden_trim_standalone_hint' ) ? jpkcom_harden_trim_standalone_hint( $key ) : null;
chk( 'hint: empty without the standalone constants', '' === $sw_hint( 'xmlrpc' ) && '' === $sw_hint( 'comments' ) );
chk( 'hint: empty for every other key', '' === $sw_hint( 'emoji' ) && '' === $sw_hint( 'nope' ) );

jht_reset_admin();
jht_store();
$html = jht_render();
$sw_security = 1 === preg_match( '/<h2 id="jht-security">.*?<h2 id="jht-roles">/s', $html, $sw_m ) ? $sw_m[0] : '';
preg_match_all( '/<tr class="jht-field jht-field-([a-z_]+)">/', $sw_security, $sw_rows );
chk( 'security section ends with xmlrpc, then comments (' . implode( separator: ',', array: array_slice( array: $sw_rows[1], offset: -3 ) ) . ')', array( 'app_password_roles', 'xmlrpc', 'comments' ) === array_slice( array: $sw_rows[1], offset: -3 ) );

$sw_x = jht_field_row( $html, 'xmlrpc' );
$sw_c = jht_field_row( $html, 'comments' );
chk( 'xmlrpc row: label for its checkbox', str_contains( haystack: $sw_x, needle: '<label for="jpkcom-harden-trim-xmlrpc">Disable XML-RPC</label>' ) );
chk( 'comments row: label for its checkbox', str_contains( haystack: $sw_c, needle: '<label for="jpkcom-harden-trim-comments">Disable comments</label>' ) );
chk( 'xmlrpc row: checkbox described by the description block', (bool) preg_match( '/<input type="checkbox" id="jpkcom-harden-trim-xmlrpc"[^>]*aria-describedby="jpkcom-harden-trim-xmlrpc-description"/', $sw_x ) && str_contains( haystack: $sw_x, needle: '<div id="jpkcom-harden-trim-xmlrpc-description" class="jht-description"><p class="description">' . esc_html( jpkcom_harden_trim_field_description( 'xmlrpc' ) ) . '</p>' ) );
chk( 'comments row: checkbox described by the description block', (bool) preg_match( '/<input type="checkbox" id="jpkcom-harden-trim-comments"[^>]*aria-describedby="jpkcom-harden-trim-comments-description"/', $sw_c ) && str_contains( haystack: $sw_c, needle: '<div id="jpkcom-harden-trim-comments-description" class="jht-description"><p class="description">' . esc_html( jpkcom_harden_trim_field_description( 'comments' ) ) . '</p>' ) );
chk( 'xmlrpc checked by default, comments not', true === jht_checkbox_checked( $html, 'xmlrpc' ) && false === jht_checkbox_checked( $html, 'comments' ) );
chk( 'xmlrpc row: no appearance or editors badge', '' !== $sw_x && ! str_contains( haystack: $sw_x, needle: 'jht-badge-' ) );
chk( 'comments row: Changes appearance and Affects editors badges', str_contains( haystack: $sw_c, needle: '<span class="jht-badge jht-badge-appearance">Changes appearance</span>' ) && str_contains( haystack: $sw_c, needle: '<span class="jht-badge jht-badge-editors">Affects editors</span>' ) && 2 === substr_count( haystack: $sw_c, needle: 'jht-badge-' ) );
$sw_global = 1 === preg_match( '/Global — role exemption does not apply.*?<\/ul>/s', $html, $sw_m ) ? $sw_m[0] : '';
chk( 'both listed as global (role exemption does not apply)', str_contains( haystack: $sw_global, needle: '<li>Disable XML-RPC</li>' ) && str_contains( haystack: $sw_global, needle: '<li>Disable comments</li>' ) );
chk( 'page intro names XML-RPC as the exception to the harmless defaults', (bool) preg_match( '/<h1>Harden &amp; Trim<\/h1>.*?<p>[^<]*The defaults change nothing visible[^<]*except XML-RPC[^<]*<\/p>/s', $html ) );
chk( 'no standalone hint without the standalone plugins', ! str_contains( haystack: $html, needle: 'is already disabled by the JPKCom' ) && ! str_contains( haystack: $html, needle: 'are already disabled by the JPKCom' ) );

$sw_presets = 1 === preg_match( '/<h2 id="jht-presets">.*?<ul class="jht-list">(.*?)<\/ul>/s', $html, $sw_m ) ? $sw_m[1] : '';
chk( 'preset summaries say every preset disables XML-RPC', str_contains( haystack: $sw_presets, needle: 'XML-RPC is disabled, as in every preset' ) );
preg_match_all( '/<button\b[^>]*name="preset"[^>]*data-jht-confirm="([^"]+)"/', $html, $sw_m );
$sw_confirm_ok = 3 === count( $sw_m[1] );
foreach ( $sw_m[1] as $sw_text ) {
	if ( ! str_contains( haystack: html_entity_decode( string: $sw_text, flags: ENT_QUOTES ), needle: 'switches XML-RPC blocking back on unless a wp-config.php constant keeps it off' ) ) {
		$sw_confirm_ok = false;
	}
}
chk( 'every preset confirm says XML-RPC blocking is switched back on unless a constant keeps it off', $sw_confirm_ok );

// Constant scenarios (one process each: constants cannot be undefined).

$run = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'locked' ) );
chk( 'fixture locked: exits 0 (' . substr( string: $run['out'], offset: 0, length: 300 ) . ')', 0 === $run['exit'] );
$html  = $run['out'];
$tags  = jht_field_tags( $html, 'emoji' );
chk( 'locked: emoji inputs all disabled', array() !== $tags && array() === array_filter( array: $tags, callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) );
chk( 'locked: constant name JPKCOM_HARDEN_TRIM_EMOJI shown', str_contains( haystack: $html, needle: 'JPKCOM_HARDEN_TRIM_EMOJI' ) );
chk( 'locked: badge Locked by wp-config.php', str_contains( haystack: $html, needle: 'Locked by wp-config.php' ) );
chk( 'locked: effective value shown', (bool) preg_match( '/JPKCOM_HARDEN_TRIM_EMOJI<\/code>\s*=\s*<code>false<\/code>/', $html ) );
chk( 'locked: core lock DISALLOW_FILE_EDIT disables file_edit', array() === array_filter( array: jht_field_tags( $html, 'file_edit' ), callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) && str_contains( haystack: $html, needle: 'DISALLOW_FILE_EDIT' ) );
chk( 'locked: unlocked fields stay enabled', array() === array_filter( array: jht_field_tags( $html, 'dashicons' ), callback: 'jht_tag_disabled' ) );
chk( 'locked: invalid constant named in a notice', (bool) preg_match( '/notice[^"]*"[^>]*>.*JPKCOM_HARDEN_TRIM_HEARTBEAT/s', $html ) );
chk( 'locked: invalid constant does not lock its field', array() === array_filter( array: jht_field_tags( $html, 'heartbeat' ), callback: 'jht_tag_disabled' ) );

// A core constant and a plugin constant for the same key: core wins in behaviour, so the page names core.
$run  = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'conflict' ) );
$html = $run['out'];
chk( 'conflict: exits 0', 0 === $run['exit'] );
chk( 'conflict: file_edit shows DISALLOW_FILE_EDIT = false (what is in effect)', (bool) preg_match( '/DISALLOW_FILE_EDIT<\/code>\s*=\s*<code>false<\/code>/', $html ) );
chk( 'conflict: file_edit does not claim JPKCOM_HARDEN_TRIM_FILE_EDIT = true', ! preg_match( '/JPKCOM_HARDEN_TRIM_FILE_EDIT<\/code>\s*=\s*<code>true<\/code>/', $html ) );
chk( 'conflict: post_revisions shows WP_POST_REVISIONS = 3', (bool) preg_match( '/WP_POST_REVISIONS<\/code>\s*=\s*<code>3<\/code>/', $html ) );
chk( 'conflict: the ignored plugin constants are named', (bool) preg_match( '/JPKCOM_HARDEN_TRIM_FILE_EDIT<\/code>[^<]*ignored/', $html ) && (bool) preg_match( '/JPKCOM_HARDEN_TRIM_POST_REVISIONS<\/code>[^<]*ignored/', $html ) );
chk( 'conflict: both fields locked', array() === array_filter( array: jht_field_tags( $html, 'file_edit' ), callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) );

chk( 'conflict: post_revisions control selects 3 (WP_POST_REVISIONS), not 5 (the ignored plugin constant)', array( '3' ) === jht_selected_options( $html, 'post_revisions' ) );
chk( 'conflict: file_edit checkbox unchecked (DISALLOW_FILE_EDIT = false), not checked (the ignored plugin constant)', false === jht_checkbox_checked( $html, 'file_edit' ) );

// A core constant locks the field: the disabled control shows the core value, mapped the way core reads it,
// never the stored value. Each row: core constants, stored values, expected control states.
$ap_core_cases = array(
	'core on, stored off' => array(
		array( 'DISALLOW_FILE_EDIT' => true, 'WP_AI_SUPPORT' => false, 'WP_POST_REVISIONS' => true, 'AUTOSAVE_INTERVAL' => 300 ),
		array( 'file_edit' => false, 'ai_features' => false, 'post_revisions' => 5, 'autosave_interval' => 120 ),
		array( 'file_edit' => true, 'ai_features' => true, 'post_revisions' => array( 'WordPress default' ), 'autosave_interval' => array( '5 minutes' ) ),
	),
	'core off, values outside the choices' => array(
		array( 'DISALLOW_FILE_EDIT' => false, 'WP_AI_SUPPORT' => true, 'WP_POST_REVISIONS' => 7, 'AUTOSAVE_INTERVAL' => 90 ),
		array( 'file_edit' => true, 'ai_features' => true, 'post_revisions' => 20, 'autosave_interval' => 600 ),
		array( 'file_edit' => false, 'ai_features' => false, 'post_revisions' => array( '7' ), 'autosave_interval' => array( '90 seconds' ) ),
	),
	'core false/default values' => array(
		array( 'DISALLOW_FILE_EDIT' => 0, 'WP_AI_SUPPORT' => 0, 'WP_POST_REVISIONS' => false, 'AUTOSAVE_INTERVAL' => 60 ),
		array( 'file_edit' => true, 'ai_features' => false, 'post_revisions' => 10, 'autosave_interval' => 120 ),
		array( 'file_edit' => false, 'ai_features' => true, 'post_revisions' => array( '0 (no new revisions)' ), 'autosave_interval' => array( 'WordPress default' ) ),
	),
	'core unlimited as -1, numeric strings' => array(
		array( 'DISALLOW_FILE_EDIT' => '1', 'WP_POST_REVISIONS' => -1, 'AUTOSAVE_INTERVAL' => '600' ),
		array( 'post_revisions' => 3, 'autosave_interval' => 120 ),
		array( 'file_edit' => true, 'post_revisions' => array( 'WordPress default' ), 'autosave_interval' => array( '10 minutes' ) ),
	),
	'core revisions as numeric string' => array(
		array( 'WP_POST_REVISIONS' => '4', 'AUTOSAVE_INTERVAL' => 'abc' ),
		array( 'post_revisions' => 3, 'autosave_interval' => 120 ),
		array( 'post_revisions' => array( '4' ), 'autosave_interval' => array( 'abc' ) ),
	),
);
foreach ( $ap_core_cases as $ap_label => list( $ap_core, $ap_stored, $ap_expect ) ) {
	$run  = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'core', 'JHT_CORE_JSON' => (string) json_encode( $ap_core ), 'JHT_STORED_JSON' => (string) json_encode( $ap_stored ) ) );
	$html = $run['out'];
	chk( "core lock ({$ap_label}): exits 0", 0 === $run['exit'] );
	foreach ( $ap_expect as $ap_key => $ap_state ) {
		chk( "core lock ({$ap_label}): {$ap_key} control disabled", array() !== jht_field_tags( $html, $ap_key ) && array() === array_filter( array: jht_field_tags( $html, $ap_key ), callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) );
		if ( is_bool( $ap_state ) ) {
			chk( "core lock ({$ap_label}): {$ap_key} checkbox " . ( $ap_state ? 'checked' : 'unchecked' ), $ap_state === jht_checkbox_checked( $html, $ap_key ) );
		} else {
			$ap_sel = jht_selected_options( $html, $ap_key );
			chk( "core lock ({$ap_label}): {$ap_key} selects exactly " . implode( separator: ',', array: $ap_state ) . ' (got ' . implode( separator: ',', array: $ap_sel ) . ')', $ap_state === $ap_sel );
		}
	}
}

// The standalone plugins' hints: plain informational text under the description, nothing else changes.
foreach ( array( 'xmlrpc' => array( 'comments', $sw_hint_xmlrpc ), 'comments' => array( 'xmlrpc', $sw_hint_comments ) ) as $sw_key => list( $sw_other, $sw_text ) ) {
	$run  = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'standalone_' . $sw_key ) );
	$html = $run['out'];
	$sw_row = jht_field_row( $html, $sw_key );
	chk( "standalone {$sw_key}: exits 0", 0 === $run['exit'] );
	chk( "standalone {$sw_key}: exact hint text as a description paragraph", str_contains( haystack: $sw_row, needle: '<p class="description">' . $sw_text . '</p>' ) );
	chk( "standalone {$sw_key}: hint below the description, inside the described block", str_contains( haystack: $sw_row, needle: esc_html( jpkcom_harden_trim_field_description( $sw_key ) ) . '</p><p class="description">' . esc_html( $sw_text ) . '</p>' ) );
	chk( "standalone {$sw_key}: hint shown once on the page, no notice, no link", 1 === substr_count( haystack: $html, needle: $sw_text ) && ! str_contains( haystack: $html, needle: 'notice-' ) && ! str_contains( haystack: $sw_row, needle: '<a ' ) );
	chk( "standalone {$sw_key}: the other field has no hint", '' !== jht_field_row( $html, $sw_other ) && ! str_contains( haystack: jht_field_row( $html, $sw_other ), needle: 'already disabled by the JPKCom' ) );
	chk( "standalone {$sw_key}: the field stays enabled", array() !== jht_field_tags( $html, $sw_key ) && array() === array_filter( array: jht_field_tags( $html, $sw_key ), callback: 'jht_tag_disabled' ) );
}

$run  = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'sitewide_locked' ) );
$html = $run['out'];
chk( 'sitewide locked: exits 0', 0 === $run['exit'] );
foreach ( array( 'xmlrpc' => array( 'JPKCOM_HARDEN_TRIM_XMLRPC', false ), 'comments' => array( 'JPKCOM_HARDEN_TRIM_COMMENTS', true ) ) as $sw_key => list( $sw_const, $sw_value ) ) {
	$sw_row = jht_field_row( $html, $sw_key );
	chk( "sitewide locked: {$sw_key} inputs all disabled", array() !== jht_field_tags( $html, $sw_key ) && array() === array_filter( array: jht_field_tags( $html, $sw_key ), callback: static fn( string $t ): bool => ! jht_tag_disabled( $t ) ) );
	chk( "sitewide locked: {$sw_key} checkbox shows the constant's value", $sw_value === jht_checkbox_checked( $html, $sw_key ) );
	chk( "sitewide locked: {$sw_key} names {$sw_const} and its value", (bool) preg_match( '/' . $sw_const . '<\/code>\s*=\s*<code>' . ( $sw_value ? 'true' : 'false' ) . '<\/code>/', $sw_row ) );
	chk( "sitewide locked: {$sw_key} badge Locked by wp-config.php", str_contains( haystack: $sw_row, needle: 'jht-badge-locked' ) );
}

$run = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'kill_json', 'JHT_KILL_JSON' => '"false"' ) );
chk( 'kill switch "false": no kill-switch notice', 0 === $run['exit'] && ! str_contains( haystack: $run['out'], needle: 'no feature is active' ) );
$run = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'kill_json', 'JHT_KILL_JSON' => '"maybe"' ) );
chk( 'kill switch "maybe": named among the invalid constants, no kill notice', (bool) preg_match( '/invalid value and are ignored: [^<]*JPKCOM_HARDEN_TRIM_DISABLE_ALL/', $run['out'] ) && ! str_contains( haystack: $run['out'], needle: 'no feature is active' ) );
$run = jht_run_fixture( __DIR__ . '/fixture-admin-page.php', array( 'JHT_MODE' => 'kill' ) );
chk( 'fixture kill: exits 0', 0 === $run['exit'] );
chk( 'kill switch: notice names JPKCOM_HARDEN_TRIM_DISABLE_ALL', (bool) preg_match( '/notice[^"]*"[^>]*>.*JPKCOM_HARDEN_TRIM_DISABLE_ALL/s', $run['out'] ) );
chk( 'kill switch: page still renders the form', array() !== jht_field_tags( $run['out'], 'emoji' ) );

summary();
