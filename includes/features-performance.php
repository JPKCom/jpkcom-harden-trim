<?php
/**
 * Performance features
 *
 * Emoji, self pingbacks, asset versions, post embeds, comment-reply,
 * dashicons, block library CSS, global styles, heartbeat, revisions and the
 * autosave interval.
 *
 * Hooks are only added for features whose value is active. Role-scoped
 * features decide inside their callbacks, where the current user is known;
 * global features act for everyone. Every hook name and priority below was
 * checked against the WordPress 7.1.3 source.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_performance_features' ) ) {

	/**
	 * Register the hooks of the active performance features.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_performance_features( array $settings ): void {

		if ( true === ( $settings['emoji'] ?? false ) ) {
			// Feeds and e-mail have no reliable user context: this part is global.
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

			// Front end and embeds: init runs before wp_head@7 and embed_head. The
			// admin hooks are only added by wp-admin/includes/admin-filters.php,
			// which loads after init, so admin_init removes them. The footer
			// passes catch a detection script queued by a direct call to
			// print_emoji_detection_script().
			add_action( 'init', 'jpkcom_harden_trim_remove_emoji' );
			add_action( 'admin_init', 'jpkcom_harden_trim_remove_emoji' );
			add_action( 'wp_print_footer_scripts', 'jpkcom_harden_trim_remove_emoji', 1 );
			add_action( 'admin_print_footer_scripts', 'jpkcom_harden_trim_remove_emoji', 1 );
			add_filter( 'tiny_mce_plugins', 'jpkcom_harden_trim_filter_tinymce_plugins' );
		}

		if ( true === ( $settings['self_pingbacks'] ?? false ) ) {
			add_action( 'pre_ping', 'jpkcom_harden_trim_filter_self_pings' );
		}

		if ( in_array( needle: $settings['asset_version'] ?? 'off', haystack: array( 'hash', 'remove' ), strict: true ) ) {
			// Priority 20: after core's wp_style_loader_src() at 10.
			add_filter( 'script_loader_src', 'jpkcom_harden_trim_filter_asset_src', 20 );
			add_filter( 'style_loader_src', 'jpkcom_harden_trim_filter_asset_src', 20 );
			add_filter( 'script_module_loader_src', 'jpkcom_harden_trim_filter_asset_src', 20 );
		}

		if ( true === ( $settings['post_embeds'] ?? false ) ) {
			// Own posts: not embeddable (also suppresses oEmbed data for them).
			add_filter( 'is_post_embeddable', '__return_false' );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			add_action( 'template_redirect', 'jpkcom_harden_trim_embed_404', 1 );

			// Other WordPress sites: no discovery, no wp-embed.js, cached iframes stripped.
			// The oEmbed REST routes stay registered (the editor proxy needs them).
			add_filter( 'embed_oembed_discover', '__return_false' );
			add_filter( 'rest_pre_dispatch', 'jpkcom_harden_trim_oembed_proxy_no_discover', 10, 3 );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			remove_filter( 'embed_oembed_html', 'wp_maybe_enqueue_oembed_host_js' );
			add_filter( 'embed_oembed_html', 'jpkcom_harden_trim_filter_embed_html', 5 );
		}

		// Front-end dequeues run right before the head is printed and again before
		// the footer is printed, so late enqueues (templates, blocks) are caught.
		$dequeues = array(
			'comment_reply'     => array( 'wp_print_scripts', 'jpkcom_harden_trim_dequeue_comment_reply' ),
			'dashicons'         => array( 'wp_print_styles', 'jpkcom_harden_trim_dequeue_dashicons' ),
			'block_library_css' => array( 'wp_print_styles', 'jpkcom_harden_trim_dequeue_block_library_css' ),
			'global_styles'     => array( 'wp_print_styles', 'jpkcom_harden_trim_dequeue_global_styles' ),
		);

		foreach ( $dequeues as $key => $dequeue ) {
			if ( true === ( $settings[ $key ] ?? false ) ) {
				add_action( $dequeue[0], $dequeue[1] );
				add_action( 'wp_print_footer_scripts', $dequeue[1], 1 );
			}
		}

		// heartbeat_settings is applied once, when wp_scripts() is built; core
		// skips it before init (script-loader.php:866). Attached now, the earliest
		// safe point; the role is decided at call time.
		if ( 'optimized' === ( $settings['heartbeat'] ?? 'enabled' ) ) {
			add_filter( 'heartbeat_settings', 'jpkcom_harden_trim_filter_heartbeat_settings', 99 );
		}

		if ( 'disabled' === ( $settings['heartbeat'] ?? 'enabled' ) ) {
			add_filter( 'script_loader_src', 'jpkcom_harden_trim_filter_heartbeat_src', PHP_INT_MAX, 2 );
			add_filter( 'wp_auth_check_load', 'jpkcom_harden_trim_filter_auth_check_load', PHP_INT_MAX );
		}

		// A WP_POST_REVISIONS / AUTOSAVE_INTERVAL from wp-config.php wins.
		if ( null !== ( $settings['post_revisions'] ?? null ) && null === jpkcom_harden_trim_core_lock( 'post_revisions' ) ) {
			add_filter( 'wp_revisions_to_keep', 'jpkcom_harden_trim_filter_revisions_to_keep', 10, 2 );
		}

		// wp_functionality_constants() defines the core default right after
		// plugins_loaded; defining it here (plugins_loaded) comes first.
		if (
			is_int( $settings['autosave_interval'] ?? null )
			&& null === jpkcom_harden_trim_core_lock( 'autosave_interval' )
			&& ! defined( constant_name: 'AUTOSAVE_INTERVAL' )
		) {
			define( constant_name: 'AUTOSAVE_INTERVAL', value: $settings['autosave_interval'] );
		}
	}

}

// Emoji.

if ( ! function_exists( function: 'jpkcom_harden_trim_remove_emoji' ) ) {

	/**
	 * Remove the emoji detection script and styles for the current request.
	 *
	 * The deprecated print_emoji_styles() fallback is removed together with
	 * wp_enqueue_emoji_styles(): the latter normally unhooks the former, and
	 * left alone it would raise a deprecation notice. Idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_remove_emoji(): void {
		if ( ! jpkcom_harden_trim_feature_applies( 'emoji' ) ) {
			return;
		}

		$removals = array(
			array( 'wp_head', 'print_emoji_detection_script', 7 ),
			array( 'embed_head', 'print_emoji_detection_script', 10 ),
			array( 'admin_print_scripts', 'print_emoji_detection_script', 10 ),
			array( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles', 10 ),
			array( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles', 10 ),
			array( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles', 10 ),
			array( 'wp_print_styles', 'print_emoji_styles', 10 ),
			array( 'admin_print_styles', 'print_emoji_styles', 10 ),
			array( 'wp_print_footer_scripts', '_print_emoji_detection_script', 10 ),
			array( 'admin_print_footer_scripts', '_print_emoji_detection_script', 10 ),
		);

		foreach ( $removals as $removal ) {
			remove_action( $removal[0], $removal[1], $removal[2] );
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_tinymce_plugins' ) ) {

	/**
	 * Drop the TinyMCE `wpemoji` plugin (classic editor and Classic block).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $plugins TinyMCE plugin names.
	 * @return mixed The list without `wpemoji`; anything else unchanged.
	 */
	function jpkcom_harden_trim_filter_tinymce_plugins( mixed $plugins ): mixed {
		if ( ! is_array( $plugins ) || ! jpkcom_harden_trim_feature_applies( 'emoji' ) ) {
			return $plugins;
		}

		return array_values( array: array_diff( $plugins, array( 'wpemoji' ) ) );
	}

}

// Self pingbacks.

if ( ! function_exists( function: 'jpkcom_harden_trim_normalized_host' ) ) {

	/**
	 * Lowercase ASCII host of a URL without a leading `www.`.
	 *
	 * An internationalised host is converted to its punycode form when the
	 * intl extension is available, so `bücher.example` and
	 * `xn--bcher-kva.example` compare equal.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Absolute or protocol-relative URL.
	 * @return string Host, or '' when the URL has none.
	 */
	function jpkcom_harden_trim_normalized_host( string $url ): string {
		$host = parse_url( url: $url, component: PHP_URL_HOST );

		if ( ! is_string( $host ) || '' === $host ) {
			return '';
		}

		if ( 1 === preg_match( '/[^\x00-\x7F]/', $host ) && function_exists( function: 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) && '' !== $ascii ? $ascii : $host;
		}

		$host = strtolower( string: $host );

		return str_starts_with( haystack: $host, needle: 'www.' ) ? substr( string: $host, offset: 4 ) : $host;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_url_path' ) ) {

	/**
	 * Lowercase path of a URL without trailing slashes ('' for the root).
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL.
	 * @return string Path such as `/site2`, or ''.
	 */
	function jpkcom_harden_trim_url_path( string $url ): string {
		$path = parse_url( url: $url, component: PHP_URL_PATH );

		return is_string( $path ) ? rtrim( string: strtolower( string: $path ), characters: '/' ) : '';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_sibling_site_paths' ) ) {

	/**
	 * Paths of the other sites of a multisite installation on a host.
	 *
	 * On the main site of a subdirectory network home_url() has no path, so
	 * host and path prefix alone would count every sibling site as "self".
	 * Every site with the same domain (with or without `www.`) except the
	 * current one is listed, in any network and any status: a path that
	 * belongs to another site is never the current site's. Cached per
	 * request, current site and host; empty on a single site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Normalised host (see jpkcom_harden_trim_normalized_host()).
	 * @return list<string> Lowercase paths without trailing slash, such as `/site2`.
	 */
	function jpkcom_harden_trim_sibling_site_paths( string $host ): array {
		static $cache = array();

		if ( '' === $host || ! is_multisite() || ! function_exists( function: 'get_sites' ) ) {
			return array();
		}

		$blog_id = get_current_blog_id();
		$key     = $blog_id . '|' . $host;

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$paths = array();
		$sites = get_sites(
			array(
				'domain__in'             => array( $host, 'www.' . $host ),
				'site__not_in'           => array( $blog_id ),
				'number'                 => 0,
				'update_site_meta_cache' => false,
			)
		);

		foreach ( is_array( $sites ) ? $sites : array() as $site ) {
			if ( $site instanceof WP_Site ) {
				$path = rtrim( string: strtolower( string: $site->path ), characters: '/' );

				if ( '' !== $path ) {
					$paths[] = $path;
				}
			}
		}

		$cache[ $key ] = $paths;

		return $paths;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_path_of_sibling' ) ) {

	/**
	 * Whether a path below this site's base belongs to another site of the network.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Normalised host of the link.
	 * @param string $path Lowercase link path without trailing slash.
	 * @param string $base Lowercase path of this site's matching URL ('' for the host root).
	 * @return bool True when a sibling site's path, deeper than `$base`, contains `$path`.
	 */
	function jpkcom_harden_trim_path_of_sibling( string $host, string $path, string $base ): bool {
		foreach ( jpkcom_harden_trim_sibling_site_paths( $host ) as $sibling ) {
			if ( strlen( string: $sibling ) > strlen( string: $base ) && ( $path === $sibling || str_starts_with( haystack: $path, needle: $sibling . '/' ) ) ) {
				return true;
			}
		}

		return false;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_is_self_link' ) ) {

	/**
	 * Whether a URL points to this site (home or WordPress address).
	 *
	 * Host and path prefix: the host is compared without scheme, `www.` and
	 * letter case, and the link's path must start with the path of
	 * home_url() or site_url() at a segment boundary. On a subdirectory
	 * multisite (or a second WordPress under a path) a link to a sibling site
	 * on the same host is therefore not a self ping. On the main site of such
	 * a network (home without a path) a link into the path of another site
	 * of the network is not a self ping either (see
	 * jpkcom_harden_trim_sibling_site_paths()); a single site is unaffected.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL to check.
	 * @return bool True for a link to this site.
	 */
	function jpkcom_harden_trim_is_self_link( string $url ): bool {
		$host = jpkcom_harden_trim_normalized_host( $url );

		if ( '' === $host ) {
			return false;
		}

		$path = jpkcom_harden_trim_url_path( $url );

		foreach ( array( home_url(), site_url() ) as $own ) {
			$own = (string) $own;

			if ( $host !== jpkcom_harden_trim_normalized_host( $own ) ) {
				continue;
			}

			$base = jpkcom_harden_trim_url_path( $own );

			if ( '' === $base || $path === $base || str_starts_with( haystack: $path, needle: $base . '/' ) ) {
				return ! jpkcom_harden_trim_path_of_sibling( $host, $path, $base );
			}
		}

		return false;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_self_pings' ) ) {

	/**
	 * Remove links to this site from the pingback list (`pre_ping`, by reference).
	 *
	 * @since 1.0.0
	 *
	 * @param array<int, mixed> $links Links about to be pinged.
	 * @return void
	 */
	function jpkcom_harden_trim_filter_self_pings( array &$links ): void {
		$links = array_values(
			array: array_filter(
				array: $links,
				callback: static fn( mixed $link ): bool => ! ( is_string( $link ) && jpkcom_harden_trim_is_self_link( $link ) )
			)
		);
	}

}

// Asset versions.

if ( ! function_exists( function: 'jpkcom_harden_trim_rewrite_asset_src' ) ) {

	/**
	 * Hash or remove the `ver` query argument of an asset URL.
	 *
	 * Only an argument named exactly `ver` is touched; every other argument
	 * and the fragment are kept byte for byte.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src  Asset URL.
	 * @param string $mode `hash`, `remove`; anything else returns `$src`.
	 * @return string Rewritten URL.
	 */
	function jpkcom_harden_trim_rewrite_asset_src( string $src, string $mode ): string {
		if ( 'hash' !== $mode && 'remove' !== $mode ) {
			return $src;
		}

		$fragment = '';
		$hash_pos = strpos( haystack: $src, needle: '#' );

		if ( false !== $hash_pos ) {
			$fragment = substr( string: $src, offset: $hash_pos );
			$src      = substr( string: $src, offset: 0, length: $hash_pos );
		}

		$query_pos = strpos( haystack: $src, needle: '?' );

		if ( false === $query_pos ) {
			return $src . $fragment;
		}

		$base    = substr( string: $src, offset: 0, length: $query_pos );
		$parts   = explode( separator: '&', string: substr( string: $src, offset: $query_pos + 1 ) );
		$kept    = array();
		$changed = false;

		foreach ( $parts as $part ) {
			$pair = explode( separator: '=', string: $part, limit: 2 );

			if ( 'ver' !== $pair[0] ) {
				$kept[] = $part;
				continue;
			}

			$changed = true;

			if ( 'hash' === $mode ) {
				$kept[] = 'ver=' . substr( string: hash_hmac( algo: 'sha256', data: rawurldecode( string: $pair[1] ?? '' ), key: wp_salt( 'nonce' ) ), offset: 0, length: 10 );
			}
		}

		if ( ! $changed ) {
			return $src . $fragment;
		}

		$query = implode( separator: '&', array: $kept );

		return $base . ( '' === $query ? '' : '?' . $query ) . $fragment;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_asset_src' ) ) {

	/**
	 * `script_loader_src` / `style_loader_src` / `script_module_loader_src` callback.
	 *
	 * Front end only; non-string values (core may pass false) are returned as is.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $src Asset URL.
	 * @return mixed Rewritten URL, or the value unchanged.
	 */
	function jpkcom_harden_trim_filter_asset_src( mixed $src ): mixed {
		if ( ! is_string( $src ) || '' === $src || ! jpkcom_harden_trim_is_front_end() || ! jpkcom_harden_trim_feature_applies( 'asset_version' ) ) {
			return $src;
		}

		return jpkcom_harden_trim_rewrite_asset_src( $src, (string) jpkcom_harden_trim_settings()['asset_version'] );
	}

}

// Post embeds.

if ( ! function_exists( function: 'jpkcom_harden_trim_strip_wp_embed_iframe' ) ) {

	/**
	 * Turn WordPress post-embed markup into its plain blockquote fallback.
	 *
	 * Removes every `<iframe>` carrying the `wp-embedded-content` class (also
	 * self-closing ones), so the hidden iframe no longer loads the remote page,
	 * and drops an inline style that hides the blockquote. Other iframes
	 * (YouTube and the like) are untouched.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Embed HTML.
	 * @return string HTML without WordPress embed iframes.
	 */
	function jpkcom_harden_trim_strip_wp_embed_iframe( string $html ): string {
		if ( ! str_contains( haystack: $html, needle: 'wp-embedded-content' ) ) {
			return $html;
		}

		// The class attribute holds `wp-embedded-content` as a whole token (not
		// `x-wp-embedded-content`), quoted or unquoted.
		$token = '(?<![\w-])wp-embedded-content(?![\w-])';
		$class = '(?=[^>]*?\sclass\s*=\s*(?:"[^"]*?' . $token . '|\'[^\']*?' . $token . '|' . $token . '))';

		// The element runs to its own closing tag only: an unclosed WP-embed
		// iframe followed by another iframe loses just its opening tag.
		$html = (string) preg_replace(
			pattern: '#<iframe\b' . $class . '[^>]*?(?:/>|>(?:(?!<iframe\b).)*?</iframe\s*>|>)#is',
			replacement: '',
			subject: $html
		);

		return (string) preg_replace_callback(
			pattern: '#<blockquote\b' . $class . '[^>]*>#i',
			callback: static function ( array $tag ): string {
				return (string) preg_replace(
					pattern: '#\sstyle\s*=\s*(["\'])(?:(?!\1).)*(?:display\s*:\s*none|visibility\s*:\s*hidden)(?:(?!\1).)*\1#is',
					replacement: '',
					subject: $tag[0]
				);
			},
			subject: $html
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_embed_html' ) ) {

	/**
	 * `embed_oembed_html` callback: strip WordPress embed iframes (cached and fresh).
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $html Embed HTML.
	 * @return mixed Stripped HTML, or the value unchanged.
	 */
	function jpkcom_harden_trim_filter_embed_html( mixed $html ): mixed {
		return is_string( $html ) ? jpkcom_harden_trim_strip_wp_embed_iframe( $html ) : $html;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_embed_404' ) ) {

	/**
	 * Answer `/embed/` requests with the theme's 404.
	 *
	 * Runs at template_redirect priority 1. jpkcom_harden_trim_force_404()
	 * also clears is_embed and the post, so the template loader picks the
	 * 404 template instead of the embed card (theme-compat/embed.php renders
	 * while have_posts()). redirect_canonical() is told not to turn the 404
	 * into a guessed redirect to the post.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_embed_404(): void {
		global $wp_query;

		if ( ! is_embed() ) {
			return;
		}

		// set_404() alone keeps is_embed and the post: the embed card would still render.
		if ( $wp_query instanceof WP_Query ) {
			jpkcom_harden_trim_force_404( $wp_query );
		}

		add_filter( 'redirect_canonical', '__return_false' );
		status_header( 404 );
		nocache_headers();
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_oembed_proxy_no_discover' ) ) {

	/**
	 * Switch off oEmbed discovery for the editor's oEmbed proxy route.
	 *
	 * Keeps the editor preview consistent with the front end, where
	 * `embed_oembed_discover` is false. Whitelisted providers still work.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result  Dispatch short-circuit value, passed through.
	 * @param mixed $server  REST server.
	 * @param mixed $request REST request.
	 * @return mixed `$result` unchanged.
	 */
	function jpkcom_harden_trim_oembed_proxy_no_discover( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( $request instanceof WP_REST_Request && '/oembed/1.0/proxy' === rtrim( string: strtolower( string: $request->get_route() ), characters: '/' ) ) {
			$request->set_param( 'discover', false );
		}

		return $result;
	}

}

// Front-end dequeues.

if ( ! function_exists( function: 'jpkcom_harden_trim_dequeue_comment_reply' ) ) {

	/**
	 * Dequeue `comment-reply`; replies fall back to the `?replytocom=` reload.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_dequeue_comment_reply(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || ! jpkcom_harden_trim_feature_applies( 'comment_reply' ) ) {
			return;
		}

		wp_dequeue_script( 'comment-reply' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_dequeue_dashicons' ) ) {

	/**
	 * Dequeue `dashicons` for logged-out visitors.
	 *
	 * Never deregistered: styles that depend on dashicons still pull it in.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_dequeue_dashicons(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || is_user_logged_in() || ! jpkcom_harden_trim_feature_applies( 'dashicons' ) ) {
			return;
		}

		wp_dequeue_style( 'dashicons' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_dequeue_block_library_css' ) ) {

	/**
	 * Dequeue the block library CSS and the core per-block styles (classic themes only).
	 *
	 * Core per-block style handles are `wp-block-<name>`. The block-theme check
	 * happens here, at print time, so a theme switch needs no re-registration.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_dequeue_block_library_css(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || wp_is_block_theme() || ! jpkcom_harden_trim_feature_applies( 'block_library_css' ) ) {
			return;
		}

		$handles = array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles' );

		foreach ( wp_styles()->queue as $queued ) {
			if ( is_string( $queued ) && str_starts_with( haystack: $queued, needle: 'wp-block-' ) && ! in_array( needle: $queued, haystack: $handles, strict: true ) ) {
				$handles[] = $queued;
			}
		}

		foreach ( $handles as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_dequeue_global_styles' ) ) {

	/**
	 * Dequeue the global styles and the core block-supports / duotone CSS (classic themes only).
	 *
	 * Covers the head output and the classic-theme on-demand path, where
	 * global styles are enqueued at wp_footer@1 and a placeholder in the head.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_dequeue_global_styles(): void {
		if ( ! jpkcom_harden_trim_is_front_end() || wp_is_block_theme() || ! jpkcom_harden_trim_feature_applies( 'global_styles' ) ) {
			return;
		}

		foreach ( array( 'global-styles', 'wp-global-styles-placeholder', 'core-block-supports', 'core-block-supports-duotone' ) as $handle ) {
			wp_dequeue_style( $handle );
		}
	}

}

// Heartbeat.

if ( ! function_exists( function: 'jpkcom_harden_trim_heartbeat_settings' ) ) {

	/**
	 * Heartbeat settings for `optimized`: 120 s interval and minimal interval.
	 *
	 * `minimalInterval` is the only value the block editor's forced
	 * `interval( 10 )` respects; 120 s is the maximum core documents as safe
	 * for post locks. Higher values set by someone else are kept (heartbeat.js
	 * ignores a minimal interval above 600 s, so that is the cap).
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Heartbeat settings.
	 * @return array<string, mixed> Adjusted settings, unchanged unless optimized and not exempt.
	 */
	function jpkcom_harden_trim_heartbeat_settings( array $settings ): array {
		if ( 'optimized' !== jpkcom_harden_trim_settings()['heartbeat'] || ! jpkcom_harden_trim_feature_applies( 'heartbeat' ) ) {
			return $settings;
		}

		$settings['interval']        = max( 120, (int) ( $settings['interval'] ?? 0 ) );
		$settings['minimalInterval'] = max( 120, min( 600, (int) ( $settings['minimalInterval'] ?? 0 ) ) );

		return $settings;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_heartbeat_settings' ) ) {

	/**
	 * `heartbeat_settings` callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Heartbeat settings.
	 * @return mixed Adjusted settings, or the value unchanged when it is no array.
	 */
	function jpkcom_harden_trim_filter_heartbeat_settings( mixed $settings ): mixed {
		return is_array( $settings ) ? jpkcom_harden_trim_heartbeat_settings( $settings ) : $settings;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_heartbeat_disabled_handles' ) ) {

	/**
	 * Script handles that print nothing while heartbeat is disabled.
	 *
	 * Mechanism (read from 7.1.3 script-loader.php, class-wp-dependencies.php,
	 * class-wp-scripts.php, edit-form-blocks.php and js/dist/editor.js):
	 *
	 * - Deregistering `heartbeat` is not an option: `autosave` and
	 *   `wp-auth-check` depend on it, and WP_Dependencies::all_deps() then
	 *   raises `_doing_it_wrong` for the missing dependency (since 6.9.1).
	 * - Instead the handles stay registered and resolvable, and
	 *   `script_loader_src` returns '' for them. WP_Scripts::do_item() then
	 *   returns before building the tag, so neither the file nor its inline
	 *   before/after scripts are printed. That drops edit-form-blocks.php's
	 *   inline `wp.heartbeat.interval( 10 )`, which would throw without
	 *   heartbeat.js. Only the localized `heartbeatSettings` variable remains.
	 * - `autosave` goes too: its triggerSave() calls wp.heartbeat.connectNow()
	 *   unguarded (Ctrl+S, slug blur in the classic editor). Every caller of
	 *   wp.autosave in core checks `wp.autosave` first, and every core caller
	 *   of wp.heartbeat outside autosave.js checks `wp.heartbeat` first.
	 * - The block editor (js/dist/editor.js) only uses `heartbeat.send` /
	 *   `heartbeat.tick` hook actions and its own REST autosave, so it runs
	 *   without heartbeat; `wp-auth-check` is also switched off via
	 *   `wp_auth_check_load`, which removes its CSS and markup as well.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Script handles.
	 */
	function jpkcom_harden_trim_heartbeat_disabled_handles(): array {
		return array( 'heartbeat', 'autosave', 'wp-auth-check' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_heartbeat_src' ) ) {

	/**
	 * `script_loader_src` callback for `heartbeat = disabled`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $src    Script URL.
	 * @param mixed $handle Script handle.
	 * @return mixed '' for the heartbeat handles, otherwise `$src` unchanged.
	 */
	function jpkcom_harden_trim_filter_heartbeat_src( mixed $src, mixed $handle = '' ): mixed {
		if (
			! in_array( needle: $handle, haystack: jpkcom_harden_trim_heartbeat_disabled_handles(), strict: true )
			|| 'disabled' !== jpkcom_harden_trim_settings()['heartbeat']
			|| ! jpkcom_harden_trim_feature_applies( 'heartbeat' )
		) {
			return $src;
		}

		return '';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_auth_check_load' ) ) {

	/**
	 * `wp_auth_check_load` callback for `heartbeat = disabled`.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $show Whether to load the authentication check.
	 * @return mixed False while heartbeat is disabled for this user, otherwise `$show`.
	 */
	function jpkcom_harden_trim_filter_auth_check_load( mixed $show ): mixed {
		if ( 'disabled' !== jpkcom_harden_trim_settings()['heartbeat'] || ! jpkcom_harden_trim_feature_applies( 'heartbeat' ) ) {
			return $show;
		}

		return false;
	}

}

// Revisions.

if ( ! function_exists( function: 'jpkcom_harden_trim_revision_exempt_post_types' ) ) {

	/**
	 * Post types whose revision count this plugin never changes.
	 *
	 * The Site Editor types, so Site Editor history survives.
	 *
	 * @since 1.0.0
	 *
	 * @return list<string> Post type slugs.
	 */
	function jpkcom_harden_trim_revision_exempt_post_types(): array {
		return array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_revisions_to_keep' ) ) {

	/**
	 * Number of revisions to keep for a post.
	 *
	 * Exempt post types and post types without revision support keep the
	 * value core computed.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $num  Value computed so far.
	 * @param WP_Post $post Post.
	 * @return int The setting, or `$num`.
	 */
	function jpkcom_harden_trim_revisions_to_keep( int $num, WP_Post $post ): int {
		$setting = jpkcom_harden_trim_settings()['post_revisions'];

		if (
			! is_int( $setting )
			|| in_array( needle: $post->post_type, haystack: jpkcom_harden_trim_revision_exempt_post_types(), strict: true )
			|| ! post_type_supports( $post->post_type, 'revisions' )
		) {
			return $num;
		}

		return $setting;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_revisions_to_keep' ) ) {

	/**
	 * `wp_revisions_to_keep` callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $num  Value computed so far.
	 * @param mixed $post Post.
	 * @return mixed The setting, or `$num` unchanged.
	 */
	function jpkcom_harden_trim_filter_revisions_to_keep( mixed $num, mixed $post = null ): mixed {
		if ( ! $post instanceof WP_Post || ! is_numeric( $num ) ) {
			return $num;
		}

		return jpkcom_harden_trim_revisions_to_keep( (int) $num, $post );
	}

}
