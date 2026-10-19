<?php
/**
 * Settings page
 *
 * Settings → Harden & Trim: one form for every setting, grouped by section
 * (performance, head, privacy, security, roles), a presets form, and the save and preset handlers behind
 * admin-post.php. All user-facing copy for the settings (labels,
 * descriptions, choice names) lives in this file; import/export and the
 * README reuse the labels.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_page_slug' ) ) {

	/**
	 * Slug of the settings page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Menu slug.
	 */
	function jpkcom_harden_trim_page_slug(): string {
		return 'jpkcom-harden-trim';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_field_label' ) ) {

	/**
	 * Short label of a setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return string Translated label, '' for an unknown key.
	 */
	function jpkcom_harden_trim_field_label( string $key ): string {
		return match ( $key ) {
			'emoji'               => __( 'Remove emoji scripts', 'jpkcom-harden-trim' ),
			'self_pingbacks'      => __( 'Disable self-pingbacks', 'jpkcom-harden-trim' ),
			'asset_version'       => __( 'Asset version strings', 'jpkcom-harden-trim' ),
			'post_embeds'         => __( 'Disable WordPress post embeds', 'jpkcom-harden-trim' ),
			'comment_reply'       => __( 'Do not load the comment reply script', 'jpkcom-harden-trim' ),
			'dashicons'           => __( 'Do not load Dashicons for visitors', 'jpkcom-harden-trim' ),
			'block_library_css'   => __( 'Do not load block library CSS', 'jpkcom-harden-trim' ),
			'global_styles'       => __( 'Do not load global styles', 'jpkcom-harden-trim' ),
			'heartbeat'           => __( 'Heartbeat', 'jpkcom-harden-trim' ),
			'post_revisions'      => __( 'Post revisions to keep', 'jpkcom-harden-trim' ),
			'autosave_interval'   => __( 'Autosave interval', 'jpkcom-harden-trim' ),
			'head_shortlink'      => __( 'Remove shortlink', 'jpkcom-harden-trim' ),
			'head_rsd'            => __( 'Remove RSD link', 'jpkcom-harden-trim' ),
			'head_rest_links'     => __( 'Remove REST API links', 'jpkcom-harden-trim' ),
			'head_feed_links'     => __( 'Remove feed links', 'jpkcom-harden-trim' ),
			'head_generator'      => __( 'Remove generator tag', 'jpkcom-harden-trim' ),
			'block_author_enum'   => __( 'Block author enumeration', 'jpkcom-harden-trim' ),
			'hide_rest_users'     => __( 'Hide the REST API user list', 'jpkcom-harden-trim' ),
			'oembed_author'       => __( 'Hide the author in oEmbed data', 'jpkcom-harden-trim' ),
			'sitemap_users'       => __( 'Remove users from the sitemap', 'jpkcom-harden-trim' ),
			'browse_happy'        => __( 'Stop the browser version check', 'jpkcom-harden-trim' ),
			'dashboard_events'    => __( 'Remove the Events and News widget', 'jpkcom-harden-trim' ),
			'local_avatars'       => __( 'Local avatars instead of Gravatar', 'jpkcom-harden-trim' ),
			'comment_ip'          => __( 'Anonymise commenter IP addresses', 'jpkcom-harden-trim' ),
			'embed_privacy'       => __( 'Privacy-friendly video embeds', 'jpkcom-harden-trim' ),
			'editor_remote'       => __( 'Editor without remote content', 'jpkcom-harden-trim' ),
			'ai_features'         => __( 'Disable core AI features', 'jpkcom-harden-trim' ),
			'login_errors'        => __( 'Neutral login errors', 'jpkcom-harden-trim' ),
			'fingerprint_headers' => __( 'Remove fingerprint headers', 'jpkcom-harden-trim' ),
			'header_nosniff'      => __( 'X-Content-Type-Options: nosniff', 'jpkcom-harden-trim' ),
			'header_referrer'     => __( 'Referrer-Policy: strict-origin-when-cross-origin', 'jpkcom-harden-trim' ),
			'header_frame'        => __( 'X-Frame-Options: SAMEORIGIN', 'jpkcom-harden-trim' ),
			'header_permissions'  => __( 'Permissions-Policy: camera=(), microphone=(), geolocation=()', 'jpkcom-harden-trim' ),
			'header_hsts'         => __( 'Strict-Transport-Security: max-age=31536000', 'jpkcom-harden-trim' ),
			'rest_jsonp'          => __( 'Disable REST API JSONP', 'jpkcom-harden-trim' ),
			'file_edit'           => __( 'Disable the file editors', 'jpkcom-harden-trim' ),
			'app_passwords'       => __( 'Application passwords', 'jpkcom-harden-trim' ),
			'app_password_roles'  => __( 'Roles allowed to use application passwords', 'jpkcom-harden-trim' ),
			'exempt_roles'        => __( 'Roles exempt from the role-scoped features', 'jpkcom-harden-trim' ),
			default               => '',
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_field_description' ) ) {

	/**
	 * Description of a setting, with a warning wherever switching it on can break something.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Setting key.
	 * @return string Translated description, '' for an unknown key.
	 */
	function jpkcom_harden_trim_field_description( string $key ): string {
		return match ( $key ) {
			'emoji'               => __( 'Removes the emoji detection script and styles from the front end, embeds and admin, and stops emoji from being turned into images in feeds and e-mails. Emoji still show as the visitor\'s own characters.', 'jpkcom-harden-trim' ),
			'self_pingbacks'      => __( 'Links from one of your posts to another no longer send a pingback to your own site.', 'jpkcom-harden-trim' ),
			'asset_version'       => __( 'Front end only: the ver= value on script and style URLs reveals plugin and WordPress versions. A hash hides them and keeps cache busting after updates. Warning: with Remove, visitors may keep stale CSS and JavaScript from their browser cache after updates.', 'jpkcom-harden-trim' ),
			'post_embeds'         => __( 'Your posts can no longer be embedded on other sites, and links to other WordPress sites are no longer turned into embedded frames; providers such as YouTube keep working. Warning: existing embeds of other WordPress sites show as a plain quote with a link.', 'jpkcom-harden-trim' ),
			'comment_reply'       => __( 'Removes the comment reply script from the front end. Warning: the reply link then reloads the page instead of moving the comment form below the comment.', 'jpkcom-harden-trim' ),
			'dashicons'           => __( 'Removes the Dashicons icon font for logged-out visitors; logged-in users keep it for the toolbar. Warning: themes or plugins that use Dashicons on the front end lose their icons.', 'jpkcom-harden-trim' ),
			'block_library_css'   => __( 'Removes the core block styles on classic themes. Warning: blocks in posts and pages lose their styling unless the theme styles them itself.', 'jpkcom-harden-trim' ),
			'global_styles'       => __( 'Removes the global styles CSS (colour, font size and spacing presets) and the block supports CSS on classic themes. Warning: colours, font sizes and spacing chosen in the block editor no longer show, and block layouts such as columns, rows and groups lose their arrangement.', 'jpkcom-harden-trim' ),
			'heartbeat'           => __( 'Heartbeat is the regular check-in of an open admin screen with the server. Optimized keeps every Heartbeat feature at the longest safe interval. Warning: Disabled stops post locking, the login dialog when the session expires, classic editor autosave and nonce refreshes on screens left open for long.', 'jpkcom-harden-trim' ),
			'post_revisions'      => __( 'How many revisions WordPress keeps per post; Site Editor templates, template parts, global styles and navigation keep their full history. Warning: with a limit, older revisions are deleted the next time a post is saved with changes; 0 stops new revisions but keeps existing ones.', 'jpkcom-harden-trim' ),
			'autosave_interval'   => __( 'How often the editor autosaves a post; WordPress autosaves every 60 seconds. A longer interval means more unsaved work can be lost if the browser crashes.', 'jpkcom-harden-trim' ),
			'head_shortlink'      => __( 'Removes the ?p=123 shortlink from the page head and the Link HTTP header.', 'jpkcom-harden-trim' ),
			'head_rsd'            => __( 'Removes the Really Simple Discovery link to xmlrpc.php, which only old blogging clients use.', 'jpkcom-harden-trim' ),
			'head_rest_links'     => __( 'Removes the REST API discovery links from the page head and the Link HTTP header; the REST API itself keeps working.', 'jpkcom-harden-trim' ),
			'head_feed_links'     => __( 'Removes the feed links from the page head; the feeds themselves stay available. Warning: feed readers can no longer find your feeds from the site address alone.', 'jpkcom-harden-trim' ),
			'head_generator'      => __( 'Removes the WordPress version from the generator tag in the page head, feeds and exports.', 'jpkcom-harden-trim' ),
			'block_author_enum'   => __( 'Logged-out visitors requesting ?author=N get a 404 instead of a redirect that reveals the author\'s user name. Only with pretty permalinks.', 'jpkcom-harden-trim' ),
			'hide_rest_users'     => __( 'The REST API user list answers unauthenticated requests with 401, so user names cannot be listed anonymously.', 'jpkcom-harden-trim' ),
			'oembed_author'       => __( 'Embeds of your posts show the site name and address instead of the author\'s name and profile address.', 'jpkcom-harden-trim' ),
			'sitemap_users'       => __( 'Removes the user sitemap from the WordPress sitemaps.', 'jpkcom-harden-trim' ),
			'browse_happy'        => __( 'Stops the dashboard from sending your browser\'s user agent and the site address to WordPress.org to check whether the browser is outdated. The PHP version check stays.', 'jpkcom-harden-trim' ),
			'dashboard_events'    => __( 'Removes the WordPress Events and News dashboard widget, which sends an anonymised IP address, locale and timezone to WordPress.org.', 'jpkcom-harden-trim' ),
			'local_avatars'       => __( 'Shows a neutral avatar from this plugin instead of loading Gravatar, so no e-mail hash goes to a third party. Warning: pictures your users and commenters set up on Gravatar no longer show.', 'jpkcom-harden-trim' ),
			'comment_ip'          => __( 'Stores new comments with a shortened IP address (IPv4 /24, IPv6 /48) and without the browser user agent; spam plugins still see the full address. Warning: this cannot be undone for comments stored while it is on, and the comment flood check then works per network, so guests sharing one may be slowed down together.', 'jpkcom-harden-trim' ),
			'embed_privacy'       => __( 'YouTube embeds use youtube-nocookie.com and Vimeo embeds get dnt=1. This is not a consent solution.', 'jpkcom-harden-trim' ),
			'editor_remote'       => __( 'The block editor no longer loads the block directory, remote core patterns, the Openverse media category or the Google Fonts collection. Warning: patterns your theme takes from the WordPress.org pattern directory disappear.', 'jpkcom-harden-trim' ),
			'ai_features'         => __( 'Turns off the AI client built into WordPress, and with it every feature that relies on it.', 'jpkcom-harden-trim' ),
			'login_errors'        => __( 'Unknown user name, unknown e-mail address and wrong password all show the same message, so the login form does not reveal which accounts exist. Messages from other plugins stay.', 'jpkcom-harden-trim' ),
			'fingerprint_headers' => __( 'Removes the X-Powered-By header (PHP version) on the front end and the X-Redirect-By header from redirects.', 'jpkcom-harden-trim' ),
			'header_nosniff'      => __( 'Stops browsers from treating a file as a different type than the server declares.', 'jpkcom-harden-trim' ),
			'header_referrer'     => __( 'Other sites only see your domain, not the full page address, when visitors follow a link. This is what current browsers do anyway.', 'jpkcom-harden-trim' ),
			'header_frame'        => __( 'Other sites can no longer show your pages in a frame, which protects against clickjacking; WordPress embeds keep working. Warning: tools on other domains that show your site in a frame stop working.', 'jpkcom-harden-trim' ),
			'header_permissions'  => __( 'Your pages and the frames in them can no longer ask for camera, microphone or location access. Warning: embedded maps, video calls or forms that need one of these stop working.', 'jpkcom-harden-trim' ),
			'header_hsts'         => __( 'Tells browsers to reach this domain only over HTTPS for one year; sent only on HTTPS requests. Warning: a browser that has seen it refuses plain HTTP for a year, even after you switch it off — only use it when HTTPS works for good on this domain.', 'jpkcom-harden-trim' ),
			'rest_jsonp'          => __( 'Turns off the _jsonp parameter of the REST API, an old cross-domain technique.', 'jpkcom-harden-trim' ),
			'file_edit'           => __( 'Removes the theme and plugin file editors, so a taken-over admin account cannot change PHP code there. Warning: theme and plugin files then have to be edited another way, for example over SFTP.', 'jpkcom-harden-trim' ),
			'app_passwords'       => __( 'Application passwords let external tools sign in through the REST API. Warning: MCP and Abilities API clients, the WordPress mobile app and integrations that use them stop working for users who lose access.', 'jpkcom-harden-trim' ),
			'app_password_roles'  => __( 'Only used when application passwords are limited to selected roles. With no role selected, nobody can use them, administrators included.', 'jpkcom-harden-trim' ),
			'exempt_roles'        => __( 'Logged-in users with one of these roles get the unchanged site for the features listed below. By default no role is exempt.', 'jpkcom-harden-trim' ),
			default               => '',
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_choice_label' ) ) {

	/**
	 * Display name of one choice of an enum or int setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string               $key   Setting key.
	 * @param string|int|bool|null $value Choice; null is the "WordPress default" option.
	 * @return string Translated name.
	 */
	function jpkcom_harden_trim_choice_label( string $key, string|int|bool|null $value ): string {
		if ( null === $value ) {
			return __( 'WordPress default', 'jpkcom-harden-trim' );
		}

		if ( is_bool( $value ) ) {
			return $value ? __( 'On', 'jpkcom-harden-trim' ) : __( 'Off', 'jpkcom-harden-trim' );
		}

		if ( 'autosave_interval' === $key && is_int( $value ) ) {
			$minutes = intdiv( $value, 60 );

			/* translators: %d: number of minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'jpkcom-harden-trim' ), $minutes );
		}

		if ( 'post_revisions' === $key && 0 === $value ) {
			return __( '0 (no new revisions)', 'jpkcom-harden-trim' );
		}

		return match ( $key . ':' . $value ) {
			'asset_version:off'      => __( 'Keep', 'jpkcom-harden-trim' ),
			'asset_version:hash'     => __( 'Replace with a hash', 'jpkcom-harden-trim' ),
			'asset_version:remove'   => __( 'Remove', 'jpkcom-harden-trim' ),
			'heartbeat:enabled'      => __( 'Unchanged', 'jpkcom-harden-trim' ),
			'heartbeat:optimized'    => __( 'Optimized (every 120 seconds)', 'jpkcom-harden-trim' ),
			'heartbeat:disabled'     => __( 'Disabled', 'jpkcom-harden-trim' ),
			'app_passwords:unchanged' => __( 'Unchanged', 'jpkcom-harden-trim' ),
			'app_passwords:disabled' => __( 'Disabled for everyone', 'jpkcom-harden-trim' ),
			'app_passwords:roles'    => __( 'Only for the roles selected below', 'jpkcom-harden-trim' ),
			default                  => (string) $value,
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_notice_messages' ) ) {

	/**
	 * The messages the settings page shows after a redirect, by code.
	 *
	 * Only the code travels in the URL; a code that is not listed here shows
	 * nothing, so a crafted link cannot put words in front of an administrator.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Code => translated message.
	 */
	function jpkcom_harden_trim_notice_messages(): array {
		return array(
			'saved'           => __( 'Settings saved.', 'jpkcom-harden-trim' ),
			'preset_applied'  => __( 'Preset applied.', 'jpkcom-harden-trim' ),
			'imported'        => __( 'Settings imported.', 'jpkcom-harden-trim' ),
			'import_failed'   => __( 'The import failed. No settings were changed.', 'jpkcom-harden-trim' ),
			'nothing_changed' => __( 'Nothing changed.', 'jpkcom-harden-trim' ),
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_notice_transient' ) ) {

	/**
	 * Name of the current user's notice transient.
	 *
	 * Holds the key lists of the last save, preset or import for 60 seconds,
	 * so they never travel through the URL and no other user can read them.
	 *
	 * @since 1.0.0
	 *
	 * @return string Transient name.
	 */
	function jpkcom_harden_trim_notice_transient(): string {
		return 'jpkcom_harden_trim_notice_' . get_current_user_id();
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_notice_list_cap' ) ) {

	/**
	 * How many entries a notice list keeps.
	 *
	 * @since 1.0.0
	 *
	 * @return int Maximum entries per list.
	 */
	function jpkcom_harden_trim_notice_list_cap(): int {
		return 50;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_redirect_with_notice' ) ) {

	/**
	 * Redirect back to the settings page with a message code, then stop.
	 *
	 * Key lists (`changed`, `rejected`, `locked`) go to the user-scoped
	 * transient; the URL only carries the code and the number of changed keys.
	 * Each list keeps at most jpkcom_harden_trim_notice_list_cap() entries;
	 * how many were cut is kept under `more`, so a hostile import with
	 * thousands of unknown keys cannot bloat the transient or the notice.
	 *
	 * @since 1.0.0
	 *
	 * @param string                      $code  A key of jpkcom_harden_trim_notice_messages().
	 * @param array<string, list<string>> $lists Key lists for the notice.
	 * @return never
	 */
	function jpkcom_harden_trim_redirect_with_notice( string $code, array $lists = array() ): never {
		$lists   = array_filter( $lists, static fn( mixed $list ): bool => is_array( $list ) && array() !== $list );
		$changed = count( $lists['changed'] ?? array() );
		$cap     = jpkcom_harden_trim_notice_list_cap();
		$more    = array();

		foreach ( $lists as $name => $list ) {
			if ( count( $list ) > $cap ) {
				$more[ $name ]  = count( $list ) - $cap;
				$lists[ $name ] = array_slice( array_values( $list ), 0, $cap );
			}
		}

		if ( array() !== $more ) {
			$lists['more'] = $more;
		}

		if ( array() !== $lists ) {
			set_transient( jpkcom_harden_trim_notice_transient(), array_merge( array( 'code' => $code ), $lists ), 60 );
		} else {
			delete_transient( jpkcom_harden_trim_notice_transient() );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => jpkcom_harden_trim_page_slug(),
					'jht_msg'     => $code,
					'jht_changed' => $changed,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_changed_keys' ) ) {

	/**
	 * Keys whose value differs between two settings arrays.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $before Settings before.
	 * @param array<string, mixed> $after  Settings after.
	 * @return list<string> Keys in the order of `$after`.
	 */
	function jpkcom_harden_trim_changed_keys( array $before, array $after ): array {
		$changed = array();

		foreach ( $after as $key => $value ) {
			if ( ! array_key_exists( $key, $before ) || $before[ $key ] !== $value ) {
				$changed[] = (string) $key;
			}
		}

		return $changed;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_handle_save' ) ) {

	/**
	 * Store a settings form submission (admin_post_jpkcom_harden_trim_save).
	 *
	 * Fields that were disabled because the active theme is a block theme are
	 * not submitted; they keep their stored value instead of falling back to
	 * the default. Locked keys keep theirs inside the store.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'jpkcom-harden-trim' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'jpkcom_harden_trim_save', 'jpkcom_harden_trim_nonce' );

		// Every value is validated by jpkcom_harden_trim_save_settings(); unknown keys are dropped there.
		$input  = isset( $_POST['jpkcom_harden_trim'] ) && is_array( $_POST['jpkcom_harden_trim'] ) ? wp_unslash( $_POST['jpkcom_harden_trim'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$before = jpkcom_harden_trim_get_stored();

		if ( wp_is_block_theme() ) {
			foreach ( jpkcom_harden_trim_schema() as $key => $entry ) {
				if ( $entry['block_theme_unavailable'] ) {
					$input[ $key ] = $before[ $key ];
				}
			}
		}

		$result = jpkcom_harden_trim_save_settings( $input );

		jpkcom_harden_trim_redirect_with_notice(
			'saved',
			array(
				'changed'  => jpkcom_harden_trim_changed_keys( $before, $result['settings'] ),
				'rejected' => $result['rejected'],
			)
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_handle_preset' ) ) {

	/**
	 * Apply a preset (admin_post_jpkcom_harden_trim_preset).
	 *
	 * Only an exact preset name is accepted; anything else, or a preset that
	 * would change nothing, writes nothing.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_handle_preset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'jpkcom-harden-trim' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'jpkcom_harden_trim_preset', 'jpkcom_harden_trim_preset_nonce' );

		$preset = isset( $_POST['preset'] ) && is_string( $_POST['preset'] ) ? wp_unslash( $_POST['preset'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared against a fixed list.

		if ( ! in_array( $preset, array_keys( jpkcom_harden_trim_presets() ), true ) ) {
			jpkcom_harden_trim_redirect_with_notice( 'nothing_changed' );
		}

		$result = jpkcom_harden_trim_apply_preset( $preset, jpkcom_harden_trim_get_stored() );

		if ( array() === $result['changed'] ) {
			jpkcom_harden_trim_redirect_with_notice( 'nothing_changed' );
		}

		$saved = jpkcom_harden_trim_save_settings( $result['settings'] );

		jpkcom_harden_trim_redirect_with_notice(
			'preset_applied',
			array(
				'changed'  => $result['changed'],
				'rejected' => $saved['rejected'],
			)
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_admin_menu' ) ) {

	/**
	 * Add Settings → Harden & Trim and hook its assets to that screen only.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_admin_menu(): void {
		$hook = add_options_page(
			__( 'Harden & Trim', 'jpkcom-harden-trim' ),
			__( 'Harden & Trim', 'jpkcom-harden-trim' ),
			'manage_options',
			jpkcom_harden_trim_page_slug(),
			'jpkcom_harden_trim_render_page'
		);

		if ( ! is_string( $hook ) || '' === $hook ) {
			return;
		}

		add_action( 'admin_print_styles-' . $hook, 'jpkcom_harden_trim_enqueue_styles' );
		add_action( 'admin_print_scripts-' . $hook, 'jpkcom_harden_trim_enqueue_scripts' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_enqueue_styles' ) ) {

	/**
	 * Enqueue the settings page stylesheet.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_enqueue_styles(): void {
		wp_enqueue_style( 'jpkcom-harden-trim-admin', plugins_url( 'assets/admin.css', JPKCOM_HARDEN_TRIM_FILE ), array(), JPKCOM_HARDEN_TRIM_VERSION );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_enqueue_scripts' ) ) {

	/**
	 * Enqueue the settings page script (preset confirmation).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_enqueue_scripts(): void {
		wp_enqueue_script( 'jpkcom-harden-trim-admin', plugins_url( 'assets/admin.js', JPKCOM_HARDEN_TRIM_FILE ), array(), JPKCOM_HARDEN_TRIM_VERSION, array( 'in_footer' => true ) );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_plugin_action_links' ) ) {

	/**
	 * Put a "Settings" link first in the plugin's row on the Plugins screen.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string|int, string> $links Action links.
	 * @return array<string|int, string> Links with the settings link first.
	 */
	function jpkcom_harden_trim_plugin_action_links( array $links ): array {
		$url = add_query_arg( 'page', jpkcom_harden_trim_page_slug(), admin_url( 'options-general.php' ) );

		return array_merge(
			array( 'settings' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'jpkcom-harden-trim' ) . '</a>' ),
			$links
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_format_value' ) ) {

	/**
	 * A constant's value as shown next to a locked field.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Value.
	 * @return string Plain text, not escaped.
	 */
	function jpkcom_harden_trim_format_value( mixed $value ): string {
		return match ( true ) {
			true === $value  => 'true',
			false === $value => 'false',
			null === $value  => 'null',
			is_array( $value ) => array() === $value ? '[]' : implode( ', ', array_map( static fn( mixed $v ): string => is_scalar( $v ) ? (string) $v : gettype( $v ), $value ) ),
			is_scalar( $value ) => (string) $value,
			default          => gettype( $value ),
		};
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_display_value' ) ) {

	/**
	 * A setting's value as the settings page names it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value Value.
	 * @return string Plain text, not escaped.
	 */
	function jpkcom_harden_trim_display_value( string $key, mixed $value ): string {
		if ( is_array( $value ) ) {
			$names = jpkcom_harden_trim_role_options();

			return array() === $value
				? __( 'none', 'jpkcom-harden-trim' )
				: implode( ', ', array_map( static fn( mixed $slug ): string => is_string( $slug ) ? ( $names[ $slug ] ?? $slug ) : '', $value ) );
		}

		if ( null === $value || is_scalar( $value ) ) {
			return jpkcom_harden_trim_choice_label( $key, is_float( $value ) ? (string) $value : $value );
		}

		return jpkcom_harden_trim_format_value( $value );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_core_control_value' ) ) {

	/**
	 * What a field locked by a core constant shows: the core value, read the way core reads it.
	 *
	 * The control of a core-locked field must show what is in effect, which
	 * is the core constant (core honours it and the feature code then does
	 * nothing), never the stored value or an ignored plugin constant. Core
	 * reads (7.1.3):
	 * - `DISALLOW_FILE_EDIT`: truthy disables the editors (capabilities.php:609).
	 * - `WP_AI_SUPPORT`: falsy disables AI (ai-client.php:23); the field is
	 *   "Disable core AI features", so it is checked for a falsy value.
	 * - `WP_POST_REVISIONS`: `true` is unlimited, anything else is cast to int
	 *   (revision.php:813-818); a negative number is unlimited as well, which is
	 *   what "WordPress default" means for this field. 0 and the listed limits
	 *   select their option.
	 * - `AUTOSAVE_INTERVAL`: seconds handed to the editor (script-loader.php:1962);
	 *   60 is core's own default (default-constants.php:381).
	 * A value no option names is returned as `other`, the text of an extra
	 * option the disabled select shows selected.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key   Setting key with a core constant.
	 * @param mixed  $value The core constant's value.
	 * @return array{value: mixed, other: string|null} Control value; `other` is set when no option fits.
	 */
	function jpkcom_harden_trim_core_control_value( string $key, mixed $value ): array {
		$choices = jpkcom_harden_trim_schema()[ $key ]['choices'] ?? array();

		switch ( $key ) {
			case 'file_edit':
				return array( 'value' => (bool) $value, 'other' => null );

			case 'ai_features':
				return array( 'value' => ! (bool) $value, 'other' => null );

			case 'post_revisions':
				if ( true === $value ) {
					return array( 'value' => null, 'other' => null );
				}

				if ( ! is_scalar( $value ) && null !== $value ) {
					return array( 'value' => null, 'other' => jpkcom_harden_trim_format_value( $value ) );
				}

				$number = (int) $value;

				if ( $number < 0 ) {
					return array( 'value' => null, 'other' => null );
				}

				return in_array( $number, $choices, true )
					? array( 'value' => $number, 'other' => null )
					: array( 'value' => null, 'other' => (string) $number );

			case 'autosave_interval':
				if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/D', $value ) ) ) {
					$seconds = (int) $value;

					if ( 60 === $seconds ) {
						return array( 'value' => null, 'other' => null );
					}

					if ( in_array( $seconds, $choices, true ) ) {
						return array( 'value' => $seconds, 'other' => null );
					}

					/* translators: %d: number of seconds. */
					return array( 'value' => null, 'other' => sprintf( _n( '%d second', '%d seconds', $seconds, 'jpkcom-harden-trim' ), $seconds ) );
				}

				return array( 'value' => null, 'other' => jpkcom_harden_trim_format_value( $value ) );
		}

		return array( 'value' => $value, 'other' => null );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_role_options' ) ) {

	/**
	 * Roles offered as checkboxes: every role whose slug the store accepts.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, string> Slug => translated name.
	 */
	function jpkcom_harden_trim_role_options(): array {
		$options = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( ! is_string( $slug ) || sanitize_key( $slug ) !== $slug ) {
				continue;
			}

			$options[ $slug ] = translate_user_role( (string) $name );
		}

		return $options;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_notices' ) ) {

	/**
	 * Kill-switch, invalid-constant and redirect notices.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_render_notices(): void {
		if ( jpkcom_harden_trim_kill_switch() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'JPKCOM_HARDEN_TRIM_DISABLE_ALL is set in wp-config.php: no feature is active. Settings can still be changed and take effect once the constant is removed.', 'jpkcom-harden-trim' ) . '</p></div>';
		}

		$invalid = jpkcom_harden_trim_invalid_constants();

		if ( array() !== $invalid ) {
			/* translators: %s: comma-separated constant names. */
			echo '<div class="notice notice-error"><p>' . esc_html( sprintf( __( 'These constants in wp-config.php have an invalid value and are ignored: %s', 'jpkcom-harden-trim' ), implode( ', ', $invalid ) ) ) . '</p></div>';
		}

		$effective = jpkcom_harden_trim_settings();

		if ( 'roles' === $effective['app_passwords'] && array() === $effective['app_password_roles'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Application passwords are limited to selected roles, but no role is selected: nobody can use them, administrators included.', 'jpkcom-harden-trim' ) . '</p></div>';
		}

		$code     = isset( $_GET['jht_msg'] ) && is_string( $_GET['jht_msg'] ) ? sanitize_key( wp_unslash( $_GET['jht_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only, checked against a fixed list.
		$messages = jpkcom_harden_trim_notice_messages();

		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}

		$stored = get_transient( jpkcom_harden_trim_notice_transient() );
		$lists  = is_array( $stored ) && ( $stored['code'] ?? null ) === $code ? $stored : array();

		if ( false !== $stored ) {
			delete_transient( jpkcom_harden_trim_notice_transient() );
		}

		$type = match ( $code ) {
			'import_failed'   => 'error',
			'nothing_changed' => 'info',
			default           => 'success',
		};

		$lines = array( $messages[ $code ] );

		// The reason of a failed import: a code from the transient, mapped to a fixed message.
		if ( 'import_failed' === $code && isset( $lists['error'][0] ) && is_string( $lists['error'][0] ) && function_exists( function: 'jpkcom_harden_trim_import_error_message' ) ) {
			$reason = jpkcom_harden_trim_import_error_message( $lists['error'][0] );

			if ( '' !== $reason ) {
				$lines[] = $reason;
			}
		}

		// Entries cut by the cap in redirect_with_notice(), per list.
		$more = array();

		foreach ( ( isset( $lists['more'] ) && is_array( $lists['more'] ) ? $lists['more'] : array() ) as $list_key => $count ) {
			if ( is_string( $list_key ) && is_int( $count ) && $count > 0 ) {
				$more[ $list_key ] = $count;
			}
		}

		if ( 'imported' === $code && ( isset( $lists['applied'] ) || isset( $lists['rejected'] ) ) ) {
			/* translators: 1: number of imported values that were applied, 2: number of rejected entries. */
			$lines[] = sprintf( __( '%1$d applied, %2$d rejected.', 'jpkcom-harden-trim' ), count( (array) ( $lists['applied'] ?? array() ) ) + ( $more['applied'] ?? 0 ), count( (array) ( $lists['rejected'] ?? array() ) ) + ( $more['rejected'] ?? 0 ) );
		}

		$headers = array(
			'changed'  => __( 'Changed:', 'jpkcom-harden-trim' ),
			'rejected' => __( 'Rejected:', 'jpkcom-harden-trim' ),
			'locked'   => __( 'Stored, but a wp-config.php constant still wins:', 'jpkcom-harden-trim' ),
		);

		foreach ( $headers as $list_key => $header ) {
			if ( ! isset( $lists[ $list_key ] ) || ! is_array( $lists[ $list_key ] ) || array() === $lists[ $list_key ] ) {
				continue;
			}

			$names = array();

			foreach ( $lists[ $list_key ] as $entry ) {
				$entry = is_scalar( $entry ) ? (string) $entry : '';
				$parts = explode( ':', $entry, 2 );
				$label = jpkcom_harden_trim_field_label( $parts[0] );

				if ( '' === $label ) {
					$names[] = $entry;
				} elseif ( isset( $parts[1] ) ) {
					/* translators: 1: setting label, 2: rejected value such as a role slug. */
					$names[] = sprintf( __( '%1$s (%2$s)', 'jpkcom-harden-trim' ), $label, $parts[1] );
				} else {
					$names[] = $label;
				}
			}

			$line = $header . ' ' . implode( ', ', $names );

			if ( isset( $more[ $list_key ] ) ) {
				/* translators: %d: number of further entries not listed. */
				$line .= ' ' . sprintf( _n( 'and %d more', 'and %d more', $more[ $list_key ], 'jpkcom-harden-trim' ), $more[ $list_key ] );
			}

			$lines[] = $line;
		}

		if ( array() === $lists && in_array( $code, array( 'saved', 'preset_applied', 'imported' ), true ) ) {
			$count = isset( $_GET['jht_changed'] ) && is_string( $_GET['jht_changed'] ) ? absint( wp_unslash( $_GET['jht_changed'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.

			if ( $count > 0 ) {
				/* translators: %d: number of settings that changed. */
				$lines[] = sprintf( _n( '%d setting changed.', '%d settings changed.', $count, 'jpkcom-harden-trim' ), $count );
			}
		}

		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible">';

		foreach ( $lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>';
		}

		echo '</div>';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_field' ) ) {

	/**
	 * One form-table row for one setting.
	 *
	 * @since 1.0.0
	 *
	 * Unlocked fields show (and therefore submit) the stored value, so a
	 * value changed by the `jpkcom_harden_trim_settings` filter is never
	 * written into the option by a plain Save; such fields get a badge and
	 * name the value in effect.
	 *
	 * @param string                $key       Setting key.
	 * A field locked by a core constant shows the core value instead (see
	 * jpkcom_harden_trim_core_control_value()).
	 *
	 * @param array<string, mixed>  $settings  Values the inputs show: stored for unlocked keys, effective for locked ones.
	 * @param array<string, string> $locked    Key => locking constant name.
	 * @param array<string, mixed>  $effective Effective settings (filter applied); empty to skip the comparison.
	 * @return void
	 */
	function jpkcom_harden_trim_render_field( string $key, array $settings, array $locked, array $effective = array() ): void {
		$entry       = jpkcom_harden_trim_schema()[ $key ];
		$id          = 'jpkcom-harden-trim-' . str_replace( '_', '-', $key );
		$desc_id     = $id . '-description';
		$name        = 'jpkcom_harden_trim[' . $key . ']';
		$value       = $settings[ $key ];
		$label       = jpkcom_harden_trim_field_label( $key );
		$lock        = $locked[ $key ] ?? null;
		$block_theme = $entry['block_theme_unavailable'] && wp_is_block_theme();
		$disabled    = null !== $lock || $block_theme ? ' disabled="disabled"' : '';
		$filtered    = null === $lock && array_key_exists( $key, $effective ) && $effective[ $key ] !== $value;
		$core_lock   = null !== $lock ? jpkcom_harden_trim_core_lock( $key ) : null;
		$other       = null;

		// A core constant wins in behaviour: the disabled control shows its value.
		if ( null !== $core_lock ) {
			$shown = jpkcom_harden_trim_core_control_value( $key, $core_lock['value'] );
			$value = $shown['value'];
			$other = $shown['other'];
		}
		$described   = ' aria-describedby="' . esc_attr( $desc_id ) . '"';
		$grouped     = in_array( $entry['type'], array( 'enum', 'roles' ), true );

		echo '<tr class="jht-field jht-field-' . esc_attr( $key ) . '"><th scope="row">';

		if ( $grouped ) {
			echo esc_html( $label );
		} else {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		}

		echo '</th><td>';

		switch ( $entry['type'] ) {
			case 'bool':
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="0"' . $disabled . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed attribute string.
				echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1"' . ( true === $value ? ' checked="checked"' : '' ) . $disabled . $described . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
				break;

			case 'enum':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>';

				foreach ( $entry['choices'] as $choice ) {
					$choice_id = $id . '-' . $choice;
					echo '<label for="' . esc_attr( $choice_id ) . '"><input type="radio" id="' . esc_attr( $choice_id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $choice ) . '"' . ( $choice === $value ? ' checked="checked"' : '' ) . $disabled . $described . ' /> ' . esc_html( jpkcom_harden_trim_choice_label( $key, $choice ) ) . '</label><br />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
				}

				echo '</fieldset>';
				break;

			case 'int':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"' . $disabled . $described . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
				echo '<option value=""' . ( null === $value && null === $other ? ' selected="selected"' : '' ) . '>' . esc_html( jpkcom_harden_trim_choice_label( $key, null ) ) . '</option>';

				foreach ( $entry['choices'] as $choice ) {
					echo '<option value="' . esc_attr( (string) $choice ) . '"' . ( $choice === $value ? ' selected="selected"' : '' ) . '>' . esc_html( jpkcom_harden_trim_choice_label( $key, $choice ) ) . '</option>';
				}

				// Only on a disabled select (never submitted): the core value no option names.
				if ( null !== $other ) {
					echo '<option value="" selected="selected">' . esc_html( $other ) . '</option>';
				}

				echo '</select>';
				break;

			case 'roles':
				echo '<fieldset><legend class="screen-reader-text">' . esc_html( $label ) . '</legend>';

				foreach ( jpkcom_harden_trim_role_options() as $slug => $role_name ) {
					$role_id = $id . '-' . $slug;
					echo '<label for="' . esc_attr( $role_id ) . '"><input type="checkbox" id="' . esc_attr( $role_id ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $slug ) . '"' . ( in_array( $slug, (array) $value, true ) ? ' checked="checked"' : '' ) . $disabled . $described . ' /> ' . esc_html( $role_name ) . '</label><br />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes escaped above.
				}

				echo '</fieldset>';
				break;
		}

		$badges = array();

		if ( in_array( 'appearance', $entry['flags'], true ) ) {
			$badges['appearance'] = __( 'Changes appearance', 'jpkcom-harden-trim' );
		}

		if ( in_array( 'editors', $entry['flags'], true ) ) {
			$badges['editors'] = __( 'Affects editors', 'jpkcom-harden-trim' );
		}

		if ( $entry['block_theme_unavailable'] ) {
			$badges['block-theme'] = __( 'Not available with block themes', 'jpkcom-harden-trim' );
		}

		if ( null !== $lock ) {
			$badges['locked'] = __( 'Locked by wp-config.php', 'jpkcom-harden-trim' );
		}

		if ( $filtered ) {
			$badges['filtered'] = __( 'Changed by a filter', 'jpkcom-harden-trim' );
		}

		if ( array() !== $badges ) {
			echo '<p class="jht-badges">';

			foreach ( $badges as $class => $text ) {
				echo '<span class="jht-badge jht-badge-' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span> ';
			}

			echo '</p>';
		}

		echo '<div id="' . esc_attr( $desc_id ) . '" class="jht-description">';
		echo '<p class="description">' . esc_html( jpkcom_harden_trim_field_description( $key ) ) . '</p>';

		if ( null !== $lock ) {
			$core  = jpkcom_harden_trim_core_lock( $key );
			$state = jpkcom_harden_trim_constant_state( $key );
			// The core constant wins in behaviour, so its value is the one in effect.
			$effective = null !== $core ? $core['value'] : $state['value'];

			echo '<p class="jht-lock">' . esc_html__( 'Set in wp-config.php:', 'jpkcom-harden-trim' ) . ' <code>' . esc_html( $lock ) . '</code> = <code>' . esc_html( jpkcom_harden_trim_format_value( $effective ) ) . '</code></p>';

			if ( null !== $core && $state['defined'] ) {
				echo '<p class="jht-lock">' . sprintf(
					/* translators: 1: this plugin's constant, such as JPKCOM_HARDEN_TRIM_FILE_EDIT, 2: core constant, such as DISALLOW_FILE_EDIT. */
					esc_html__( '%1$s is ignored: %2$s is set as well, and WordPress follows it.', 'jpkcom-harden-trim' ),
					'<code>' . esc_html( $state['name'] ) . '</code>',
					'<code>' . esc_html( $core['name'] ) . '</code>'
				) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- translation escaped, placeholders escaped.
			}
		}

		if ( $filtered ) {
			echo '<p class="jht-lock jht-filtered-note">' . sprintf(
				/* translators: %s: the value in effect, such as On, Off or a list of roles. */
				esc_html__( 'The jpkcom_harden_trim_settings filter changes this setting; in effect: %s. The form shows and saves the stored value.', 'jpkcom-harden-trim' ),
				'<code>' . esc_html( jpkcom_harden_trim_display_value( $key, $effective[ $key ] ) ) . '</code>'
			) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- translation escaped, placeholder escaped.
		}

		if ( $block_theme ) {
			echo '<p class="jht-lock">' . esc_html__( 'The active theme is a block theme, so this setting has no effect and cannot be changed.', 'jpkcom-harden-trim' ) . '</p>';
		}

		echo '</div></td></tr>';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_role_scope' ) ) {

	/**
	 * Which features honour the role exemption and which are global.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_render_role_scope(): void {
		$scoped = array();
		$global = array();

		foreach ( jpkcom_harden_trim_schema() as $key => $entry ) {
			if ( 'roles' === $entry['type'] ) {
				continue;
			}

			if ( $entry['role_scoped'] ) {
				$scoped[] = jpkcom_harden_trim_field_label( $key );
			} else {
				$global[] = jpkcom_harden_trim_field_label( $key );
			}
		}

		echo '<div class="jht-role-scope">';
		echo '<h3>' . esc_html__( 'The role exemption applies to', 'jpkcom-harden-trim' ) . '</h3><ul class="jht-list">';

		foreach ( $scoped as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}

		echo '</ul><h3><span class="jht-badge jht-badge-global">' . esc_html__( 'Global — role exemption does not apply', 'jpkcom-harden-trim' ) . '</span></h3>';
		echo '<p class="description">' . esc_html__( 'These features act on cron jobs, anonymous visitors, HTTP headers or constants, where a role cannot be taken into account.', 'jpkcom-harden-trim' ) . '</p><ul class="jht-list">';

		foreach ( $global as $label ) {
			echo '<li>' . esc_html( $label ) . '</li>';
		}

		echo '</ul></div>';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_presets' ) ) {

	/**
	 * The presets section: what each preset sets, and one button per preset.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_render_presets(): void {
		$presets = jpkcom_harden_trim_presets();
		$names   = array(
			'harmless'    => __( 'Harmless', 'jpkcom-harden-trim' ),
			'recommended' => __( 'Recommended', 'jpkcom-harden-trim' ),
			'strict'      => __( 'Strict', 'jpkcom-harden-trim' ),
		);
		$never   = array_values( array_diff( array_keys( jpkcom_harden_trim_schema() ), jpkcom_harden_trim_preset_managed_keys() ) );
		$untouch = implode( ', ', array_map( 'jpkcom_harden_trim_field_label', $never ) );

		$describe = static function ( array $values, array $base ): string {
			$items = array();

			foreach ( $values as $key => $value ) {
				if ( array_key_exists( $key, $base ) && $base[ $key ] === $value ) {
					continue;
				}

				$items[] = true === $value
					? jpkcom_harden_trim_field_label( $key )
					: jpkcom_harden_trim_field_label( $key ) . ': ' . jpkcom_harden_trim_choice_label( $key, $value );
			}

			return implode( ', ', $items );
		};

		$summaries = array(
			'harmless'    => __( 'The defaults: nothing that changes how the site looks or how editors work.', 'jpkcom-harden-trim' ),
			/* translators: %s: comma-separated settings. */
			'recommended' => sprintf( __( 'Harmless, plus: %s.', 'jpkcom-harden-trim' ), $describe( $presets['recommended'], $presets['harmless'] ) ),
			/* translators: %s: comma-separated settings. */
			'strict'      => sprintf( __( 'Recommended, plus: %s.', 'jpkcom-harden-trim' ), $describe( $presets['strict'], $presets['recommended'] ) ),
		);

		echo '<h2 id="jht-presets">' . esc_html__( 'Presets', 'jpkcom-harden-trim' ) . '</h2>';
		echo '<p>' . esc_html__( 'A preset sets every setting it manages at once and is saved immediately. Settings locked by wp-config.php stay as they are.', 'jpkcom-harden-trim' ) . '</p>';
		/* translators: %s: comma-separated settings. */
		echo '<p>' . esc_html( sprintf( __( 'No preset ever touches: %s.', 'jpkcom-harden-trim' ), $untouch ) ) . '</p>';
		echo '<ul class="jht-list">';

		foreach ( $names as $preset => $preset_name ) {
			echo '<li><strong>' . esc_html( $preset_name ) . '</strong> — ' . esc_html( $summaries[ $preset ] ) . '</li>';
		}

		echo '</ul>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="jht-presets">';
		wp_nonce_field( 'jpkcom_harden_trim_preset', 'jpkcom_harden_trim_preset_nonce' );
		echo '<input type="hidden" name="action" value="jpkcom_harden_trim_preset" /><p class="jht-preset-buttons">';

		foreach ( $names as $preset => $preset_name ) {
			/* translators: 1: preset name, 2: comma-separated settings. */
			$confirm = sprintf( __( 'Apply the preset "%1$s"? Every setting the presets manage is overwritten, except settings locked by wp-config.php. No preset ever touches: %2$s.', 'jpkcom-harden-trim' ), $preset_name, $untouch );

			/* translators: %s: preset name. */
			echo '<button type="submit" class="button" name="preset" value="' . esc_attr( $preset ) . '" data-jht-confirm="' . esc_attr( $confirm ) . '">' . esc_html( sprintf( __( 'Apply %s', 'jpkcom-harden-trim' ), $preset_name ) ) . '</button> ';
		}

		echo '</p></form>';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_render_page' ) ) {

	/**
	 * Render Settings → Harden & Trim.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_render_page(): void {
		// The menu already requires manage_options; refuse loudly if the callback is reached anyway.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'jpkcom-harden-trim' ), '', array( 'response' => 403 ) );
		}

		// Unlocked fields show the stored value, locked ones the value in effect.
		$effective = jpkcom_harden_trim_settings();
		$locked    = jpkcom_harden_trim_locked_keys();
		$settings  = jpkcom_harden_trim_get_stored();

		foreach ( array_keys( $locked ) as $locked_key ) {
			$settings[ $locked_key ] = $effective[ $locked_key ];
		}

		$sections = array(
			'performance' => array(
				__( 'Performance', 'jpkcom-harden-trim' ),
				__( 'Load less on every page.', 'jpkcom-harden-trim' ),
			),
			'head'        => array(
				__( 'Clean up <head>', 'jpkcom-harden-trim' ),
				__( 'Remove links and tags from the page head that mainly reveal information. These apply to everyone.', 'jpkcom-harden-trim' ),
			),
			'privacy'     => array(
				__( 'Privacy', 'jpkcom-harden-trim' ),
				__( 'Send less data to third parties and reveal less about the site and its users.', 'jpkcom-harden-trim' ),
			),
			'security'    => array(
				__( 'Security', 'jpkcom-harden-trim' ),
				__( 'Security headers are sent by PHP on front-end pages. A header that WordPress or another plugin already set in PHP is left alone, but headers added by the web server, a proxy or a CDN cannot be seen from PHP and would be sent twice: if the server sends one of these headers, switch it off here. Pages delivered from a page cache may not carry the headers from PHP at all.', 'jpkcom-harden-trim' ),
			),
			'roles'       => array(
				__( 'Roles', 'jpkcom-harden-trim' ),
				__( 'Exempt roles see the site without the role-scoped features, for example to compare or to debug.', 'jpkcom-harden-trim' ),
			),
		);

		echo '<div class="wrap jpkcom-harden-trim">';
		echo '<h1>' . esc_html__( 'Harden & Trim', 'jpkcom-harden-trim' ) . '</h1>';

		jpkcom_harden_trim_render_notices();

		echo '<p>' . esc_html__( 'Hardening, privacy and performance switches. The defaults change nothing visible; everything with a known side effect is off and says so. A constant in wp-config.php (JPKCOM_HARDEN_TRIM_ followed by the setting key in capitals) overrides a setting and locks it here.', 'jpkcom-harden-trim' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'jpkcom_harden_trim_save', 'jpkcom_harden_trim_nonce' );
		echo '<input type="hidden" name="action" value="jpkcom_harden_trim_save" />';

		foreach ( $sections as $group => $section ) {
			echo '<h2 id="jht-' . esc_attr( $group ) . '">' . esc_html( $section[0] ) . '</h2>';
			echo '<p>' . esc_html( $section[1] ) . '</p>';
			echo '<table class="form-table" role="presentation"><tbody>';

			foreach ( jpkcom_harden_trim_schema() as $key => $entry ) {
				if ( $group === $entry['group'] ) {
					jpkcom_harden_trim_render_field( $key, $settings, $locked, $effective );
				}
			}

			echo '</tbody></table>';

			if ( 'roles' === $group ) {
				jpkcom_harden_trim_render_role_scope();
			}
		}

		submit_button( __( 'Save Changes', 'jpkcom-harden-trim' ) );
		echo '</form>';

		jpkcom_harden_trim_render_presets();

		if ( function_exists( function: 'jpkcom_harden_trim_render_import_export' ) ) {
			jpkcom_harden_trim_render_import_export();
		}

		echo '</div>';
	}

}

add_action( 'admin_menu', 'jpkcom_harden_trim_admin_menu' );
add_action( 'admin_post_jpkcom_harden_trim_save', 'jpkcom_harden_trim_handle_save' );
add_action( 'admin_post_jpkcom_harden_trim_preset', 'jpkcom_harden_trim_handle_preset' );

if ( defined( constant_name: 'JPKCOM_HARDEN_TRIM_FILE' ) ) {
	add_filter( 'plugin_action_links_' . plugin_basename( JPKCOM_HARDEN_TRIM_FILE ), 'jpkcom_harden_trim_plugin_action_links' );
}
