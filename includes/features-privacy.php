<?php
/**
 * Privacy features
 *
 * Author enumeration, REST users, oEmbed author, users sitemap, Browse Happy,
 * dashboard events, local avatars, commenter IPs, embed privacy, remote editor
 * content and the core AI client. All of them are
 * global: they protect anonymous visitors or act on data and requests where
 * no user context exists, so the exempt roles do not apply. Every hook name
 * and priority below was checked against the WordPress 7.1.3 source.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_privacy_features' ) ) {

	/**
	 * Register the hooks of the active privacy features.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_privacy_features( array $settings ): void {

		if ( true === ( $settings['block_author_enum'] ?? false ) ) {
			// Priority 0: before redirect_canonical() (10) sends ?author=N to /author/<nicename>/.
			add_action( 'template_redirect', 'jpkcom_harden_trim_block_author_enum', 0 );
		}

		if ( true === ( $settings['hide_rest_users'] ?? false ) ) {
			// rest_pre_dispatch runs for /wp-json/, ?rest_route=, batch sub-requests and
			// embeds, after authentication. Priority 99: after plugins that short-circuit
			// with a cached response, so a cached user list cannot slip through.
			add_filter( 'rest_pre_dispatch', 'jpkcom_harden_trim_filter_rest_users', 99, 3 );
		}

		if ( true === ( $settings['oembed_author'] ?? false ) ) {
			// Priority 20: after core's get_oembed_response_data_rich() (10).
			add_filter( 'oembed_response_data', 'jpkcom_harden_trim_filter_oembed_response_data', 20 );
		}

		if ( true === ( $settings['sitemap_users'] ?? false ) ) {
			add_filter( 'wp_sitemaps_add_provider', 'jpkcom_harden_trim_filter_sitemap_provider', 10, 2 );
		}

		if ( true === ( $settings['browse_happy'] ?? false ) ) {
			// pre_http_request rather than the transient: the transient key is
			// browser_<md5 of the user agent>, so pre-filling it would have to guess
			// the key per visitor. Short-circuiting the one request with a WP_Error
			// makes wp_check_browser_version() return false: no request leaves the
			// site and wp_dashboard_setup() adds no nag widget.
			add_filter( 'pre_http_request', 'jpkcom_harden_trim_filter_browse_happy', 10, 3 );
		}

		if ( true === ( $settings['dashboard_events'] ?? false ) ) {
			// wp_dashboard_setup fires after core added dashboard_primary.
			add_action( 'wp_dashboard_setup', 'jpkcom_harden_trim_remove_events_widget' );
		}

		if ( true === ( $settings['local_avatars'] ?? false ) ) {
			// Priority 99: an avatar URL another plugin set earlier (an uploaded local
			// avatar) is kept. get_avatar_url() and REST avatar_urls use the same filter.
			add_filter( 'pre_get_avatar_data', 'jpkcom_harden_trim_filter_avatar_data', 99 );
		}

		if ( true === ( $settings['comment_ip'] ?? false ) ) {
			// Applied in wp_filter_comment(), after preprocess_comment and after
			// wp_allow_comment(): spam plugins and the flood check see the real IP.
			add_filter( 'pre_comment_user_ip', 'jpkcom_harden_trim_filter_comment_ip' );
			add_filter( 'pre_comment_user_agent', 'jpkcom_harden_trim_filter_comment_agent' );
			// The stored rows then hold the shortened IP, which core's flood query
			// never compares against: repeat it with that form (priority 11, after
			// core's wp_check_comment_flood() at 10).
			add_filter( 'wp_is_comment_flood', 'jpkcom_harden_trim_filter_comment_flood', 11, 5 );
		}

		if ( true === ( $settings['embed_privacy'] ?? false ) ) {
			// WP_Embed (shortcode, autoembed, embed block), cached and fresh.
			add_filter( 'embed_oembed_html', 'jpkcom_harden_trim_filter_embed_privacy' );
			// The cover block builds its background-video iframe itself from
			// wp_oembed_get() and needs the original src to detect the provider, so
			// its final markup is rewritten instead of hooking oembed_result.
			add_filter( 'render_block_core/cover', 'jpkcom_harden_trim_filter_cover_embed', 10, 2 );
			// Block editor previews come from the oEmbed proxy route.
			add_filter( 'rest_post_dispatch', 'jpkcom_harden_trim_filter_oembed_proxy_response', 10, 3 );
		}

		if ( true === ( $settings['editor_remote'] ?? false ) ) {
			remove_action( 'enqueue_block_editor_assets', 'wp_enqueue_editor_block_directory_assets' );
			// Also stops the theme.json patterns from the pattern directory.
			add_filter( 'should_load_remote_block_patterns', '__return_false' );
			add_filter( 'block_editor_settings_all', 'jpkcom_harden_trim_filter_editor_settings' );
			// Core registers the collection in _wp_register_default_font_collections() on init@10.
			add_action( 'init', 'jpkcom_harden_trim_unregister_google_fonts', 11 );
		}

		// A WP_AI_SUPPORT from wp-config.php wins. PHP_INT_MAX: the site owner's
		// explicit choice is not overridden by a plugin forcing AI back on.
		if ( true === ( $settings['ai_features'] ?? false ) && null === jpkcom_harden_trim_core_lock( 'ai_features' ) ) {
			add_filter( 'wp_supports_ai', '__return_false', PHP_INT_MAX );
		}
	}

}

// Author enumeration.

if ( ! function_exists( function: 'jpkcom_harden_trim_is_author_enum_request' ) ) {

	/**
	 * Whether a request is an author enumeration probe.
	 *
	 * Plain permalinks are left alone (there `?author=N` is the real author
	 * link), as are logged-in users and the admin. Whether a probe is exempt
	 * (ActivityPub) is decided separately, see
	 * jpkcom_harden_trim_author_enum_exempt().
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $query               Parsed request query vars.
	 * @param bool                 $logged_in           Whether a user is logged in.
	 * @param string               $permalink_structure The permalink structure option.
	 * @param bool                 $is_admin            Whether this is an admin request.
	 * @return bool True for an anonymous `?author=N` request with pretty permalinks.
	 */
	function jpkcom_harden_trim_is_author_enum_request( array $query, bool $logged_in, string $permalink_structure, bool $is_admin ): bool {
		return ! empty( $query['author'] ) && ! $logged_in && '' !== $permalink_structure && ! $is_admin;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_requested_url' ) ) {

	/**
	 * The requested URL exactly as core's redirect_canonical() builds it.
	 *
	 * 7.1.3 canonical.php:69-73: scheme from is_ssl(), then the raw Host
	 * header and the raw REQUEST_URI, concatenated unchecked; no Host header,
	 * no URL. This is the `$requested_url` ActivityPub's
	 * Router::redirect_canonical() parses, so a Host header such as
	 * `example.com#` moves the whole REQUEST_URI into the fragment there.
	 * A non-string server value (no web SAPI sends one) gives '' as well.
	 *
	 * @since 1.0.0
	 *
	 * @return string The URL, or '' when core builds none.
	 */
	function jpkcom_harden_trim_requested_url(): string {
		$host = $_SERVER['HTTP_HOST'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw, as core builds $requested_url; only parsed.
		$uri  = $_SERVER['REQUEST_URI'] ?? ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- raw, as core builds $requested_url; only parsed.

		if ( ! is_string( $host ) || ! is_string( $uri ) ) {
			return '';
		}

		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_author_is_only_query_arg' ) ) {

	/**
	 * Whether `author` is the only argument of the requested URL's query.
	 *
	 * Mirrors ActivityPub's own check in Router::redirect_canonical()
	 * (ActivityPub 9.3.1, class-router.php:300-330), input included: it takes
	 * the query of core's `$requested_url` (see
	 * jpkcom_harden_trim_requested_url(), not REQUEST_URI alone) with
	 * wp_parse_url(), parses it with wp_parse_args(), ignores `activitypub`
	 * and `stamp`, and suppresses core's redirect to /author/<nicename>/
	 * only when exactly one argument, `author`, remains. No query (also one
	 * hidden in a fragment by the Host header) or any further argument (also
	 * one WordPress ignores) lets that redirect through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $requested_url The requested URL as core builds it.
	 * @return bool True for `?author=N`, optionally with `activitypub`/`stamp`.
	 */
	function jpkcom_harden_trim_author_is_only_query_arg( string $requested_url ): bool {
		$query = wp_parse_url( $requested_url, PHP_URL_QUERY );

		// ActivityPub: `if ( ! $query ) return $redirect_url;`.
		if ( ! is_string( $query ) || ! $query ) {
			return false;
		}

		$args = wp_parse_args( $query );
		unset( $args['activitypub'], $args['stamp'] );

		return array( 'author' ) === array_keys( $args );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_activitypub_answers_author' ) ) {

	/**
	 * Whether the ActivityPub plugin itself answers this `?author=N` request.
	 *
	 * ActivityPub (wordpress.org slug `activitypub`, read against 9.3.1)
	 * answers `?author=N` with the actor document only when all of these hold,
	 * each decided by its own public API, never by a header test of ours:
	 *
	 * - `\Activitypub\is_activitypub_request()` (functions-request.php:70):
	 *   `?activitypub`, or an Accept header whose highest-quality type is
	 *   activity+json or ld+json with the ActivityStreams profile (q-aware,
	 *   `q=0` refuses), plus its filter. Otherwise it does not suppress
	 *   core's canonical redirect (class-router.php:301).
	 * - `\Activitypub\should_negotiate_content()` (functions-request.php:79):
	 *   otherwise it renders the theme template (class-router.php:92).
	 * - `author` is the only argument in the query of the requested URL as
	 *   core builds it from the Host header and REQUEST_URI (see
	 *   jpkcom_harden_trim_author_is_only_query_arg()); otherwise core's
	 *   redirect goes through.
	 * - `\Activitypub\user_can_activitypub( N )` (functions-user.php:90): a
	 *   user who is no actor gets no transformer (transformer/class-factory.php:109)
	 *   and the theme renders the author archive.
	 * - The request reaches `template_include`, where ActivityPub renders its
	 *   document (class-router.php:24). Core's template loader answers robots,
	 *   favicon, feeds and trackbacks before that filter (template-loader.php:40-62),
	 *   and sitemaps exit on `template_redirect`; a feed path such as
	 *   `/feed/?author=N` keeps the raw query at `author` alone, yet core
	 *   serves the author's feed (display name, by default the login). Any
	 *   of their query vars (`feed`, `tb`, `robots`, `favicon`, `sitemap`,
	 *   `sitemap-stylesheet`), from path, query or POST body, refuses.
	 *
	 * The functions are looked up at call time. If any of them is missing
	 * (ActivityPub not loaded, or a version with a different API), the
	 * answer is false: an unknown ActivityPub is not trusted to answer.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $query         Parsed request query vars.
	 * @param string               $requested_url The requested URL as core builds it
	 *                                            (jpkcom_harden_trim_requested_url()).
	 * @return bool True when ActivityPub answers the request with its own document.
	 */
	function jpkcom_harden_trim_activitypub_answers_author( array $query, string $requested_url ): bool {
		foreach ( array( 'is_activitypub_request', 'should_negotiate_content', 'user_can_activitypub' ) as $function ) {
			if ( ! function_exists( function: 'Activitypub\\' . $function ) ) {
				return false;
			}
		}

		// Core answers these before template_include (template-loader.php:40-62: robots, favicon,
		// feed, trackback; sitemaps exit on template_redirect), so neither ActivityPub nor the
		// guard ever sees them. Path and POST body set them without touching the raw query.
		foreach ( array( 'feed', 'tb', 'robots', 'favicon', 'sitemap', 'sitemap-stylesheet' ) as $var ) {
			if ( array_key_exists( $var, $query ) ) {
				return false;
			}
		}

		$author = $query['author'] ?? '';

		// Core redirects only a plain number (canonical.php: '|^[0-9]+$|'); ActivityPub resolves only an ID.
		if ( ! is_string( $author ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', $author ) ) {
			return false;
		}

		if ( ! jpkcom_harden_trim_author_is_only_query_arg( $requested_url ) ) {
			return false;
		}

		// Truthy, as ActivityPub itself reads its filtered answers (class-router.php:92, :301).
		return (bool) \Activitypub\is_activitypub_request()
			&& (bool) \Activitypub\should_negotiate_content()
			&& (bool) \Activitypub\user_can_activitypub( (int) $author );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_author_enum_exempt' ) ) {

	/**
	 * Whether an author enumeration probe is let through.
	 *
	 * By default only when the ActivityPub plugin itself answers this very
	 * request with the actor document (see
	 * jpkcom_harden_trim_activitypub_answers_author()): then `?author=N` is
	 * the actor ID other servers resolve, and ActivityPub publishes the
	 * actor's name by design. Every other probe, whatever its Accept header,
	 * gets the 404, because core would redirect it to /author/<nicename>/.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $query         Parsed request query vars.
	 * @param string               $accept        The Accept request header (passed to the filter).
	 * @param string               $request_uri   The raw REQUEST_URI (passed to the filter).
	 * @param string               $requested_url The requested URL as core builds it
	 *                                            (jpkcom_harden_trim_requested_url()); '' never exempts.
	 * @return bool True when the probe must not get the 404.
	 */
	function jpkcom_harden_trim_author_enum_exempt( array $query, string $accept, string $request_uri = '', string $requested_url = '' ): bool {
		$exempt = jpkcom_harden_trim_activitypub_answers_author( $query, $requested_url );

		/**
		 * Filters whether an anonymous `?author=N` request is exempt from the 404.
		 *
		 * @since 1.0.0
		 *
		 * @param bool                 $exempt        Default: ActivityPub itself answers this request
		 *                                            with the actor document.
		 * @param array<string, mixed> $query         Parsed request query vars.
		 * @param string               $accept        The Accept request header.
		 * @param string               $request_uri   The raw REQUEST_URI.
		 * @param string               $requested_url The requested URL as core's redirect_canonical()
		 *                                            builds it (scheme, Host header, REQUEST_URI).
		 */
		return true === apply_filters( 'jpkcom_harden_trim_author_enum_exempt', $exempt, $query, $accept, $request_uri, $requested_url );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_author_enum_guard_template' ) ) {

	/**
	 * `template_include` callback (PHP_INT_MAX): the exempted probe must get
	 * ActivityPub's own document.
	 *
	 * Added only for a request that was exempted because ActivityPub answers
	 * it. ActivityPub still falls back to the theme template on some paths
	 * it decides only while rendering: under Authorized Fetch an unsigned
	 * request gets status 401 and the theme template (9.3.1
	 * class-router.php:211-215), which for `?author=N` is the author archive
	 * with the user's name and nicename. ActivityPub's own templates live in
	 * its plugin directory (ACTIVITYPUB_PLUGIN_DIR); any other template turns
	 * the response into the 404 the probe would have got.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $template Template path chosen so far.
	 * @return mixed The template, or the theme's 404 template.
	 */
	function jpkcom_harden_trim_author_enum_guard_template( mixed $template ): mixed {
		global $wp_query;

		if ( ! is_string( $template ) ) {
			return $template;
		}

		if ( defined( constant_name: 'ACTIVITYPUB_PLUGIN_DIR' ) && is_string( ACTIVITYPUB_PLUGIN_DIR ) && '' !== ACTIVITYPUB_PLUGIN_DIR ) {
			$dir = rtrim( string: wp_normalize_path( ACTIVITYPUB_PLUGIN_DIR ), characters: '/' ) . '/';

			if ( str_starts_with( haystack: wp_normalize_path( $template ), needle: $dir ) ) {
				return $template;
			}
		}

		if ( $wp_query instanceof WP_Query ) {
			jpkcom_harden_trim_force_404( $wp_query );
		}

		status_header( 404 );
		nocache_headers();

		$not_found = get_query_template( '404' );

		return '' !== $not_found ? $not_found : get_query_template( 'index' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_block_author_enum' ) ) {

	/**
	 * `template_redirect` callback: answer author enumeration probes with a 404.
	 *
	 * Reads the parsed request (`$wp->query_vars`), not `$wp_query`: WP_Query
	 * fills its own `author` from `author_name` on pretty author archives,
	 * which must keep working. The query is emptied (see
	 * jpkcom_harden_trim_force_404()), so the theme's 404 template renders
	 * and neither a feed nor a fallback index lists the author's posts;
	 * redirect_canonical() sees is_404() and has nothing to guess from.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_block_author_enum(): void {
		global $wp, $wp_query;

		$query       = is_object( $wp ) && isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? $wp->query_vars : array();
		$accept      = isset( $_SERVER['HTTP_ACCEPT'] ) && is_string( $_SERVER['HTTP_ACCEPT'] ) ? $_SERVER['HTTP_ACCEPT'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to a filter only.
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed to the filter only; the decision parses $requested.
		$requested   = jpkcom_harden_trim_requested_url();
		$structure   = get_option( 'permalink_structure' );

		if ( ! jpkcom_harden_trim_is_author_enum_request( $query, is_user_logged_in(), is_string( $structure ) ? $structure : '', is_admin() ) ) {
			return;
		}

		if ( jpkcom_harden_trim_author_enum_exempt( $query, $accept, $request_uri, $requested ) ) {
			// Exempt because ActivityPub answers: make sure it does (see the guard).
			if ( jpkcom_harden_trim_activitypub_answers_author( $query, $requested ) ) {
				add_filter( 'template_include', 'jpkcom_harden_trim_author_enum_guard_template', PHP_INT_MAX );
			}

			return;
		}

		if ( $wp_query instanceof WP_Query ) {
			// Also drops the author's posts and an author feed (?author=N&feed=rss2).
			jpkcom_harden_trim_force_404( $wp_query );
		}

		status_header( 404 );
		nocache_headers();
	}

}

// REST users.

if ( ! function_exists( function: 'jpkcom_harden_trim_is_users_route' ) ) {

	/**
	 * Whether a REST route is the users collection or anything below it.
	 *
	 * Case-insensitive like core's route matching; a trailing slash is
	 * tolerated.
	 *
	 * The anchor must keep core's semantics: WP_REST_Server::match_request_to_handler()
	 * (7.1.3 class-wp-rest-server.php:1172) matches with '@^' . $route . '$@i',
	 * no D modifier, so its `$` also matches before a trailing "\n", and
	 * rest_api_loaded() only strips slashes (untrailingslashit()). Core
	 * therefore serves `?rest_route=/wp/v2/users%0A` as the user list. Do
	 * not "tighten" this to `\z`: that would let exactly that request through.
	 *
	 * @since 1.0.0
	 *
	 * @param string $route REST route, e.g. `/wp/v2/users/1`.
	 * @return bool True for `/wp/v2/users` and `/wp/v2/users/…`.
	 */
	function jpkcom_harden_trim_is_users_route( string $route ): bool {
		return 1 === preg_match( '#^/wp/v2/users(?:/.*)?$#is', $route ); // `$`, not `\z`: see above.
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_rest_users' ) ) {

	/**
	 * `rest_pre_dispatch` callback: 401 for unauthenticated users requests.
	 *
	 * Decided at dispatch time, after cookie+nonce and application-password
	 * authentication. An error set earlier (rate limit, another plugin) is
	 * kept; any other short-circuit result is replaced.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result  Short-circuit result so far (null to continue).
	 * @param mixed $server  REST server (unused).
	 * @param mixed $request The request.
	 * @return mixed The result, or a WP_Error with status 401.
	 */
	function jpkcom_harden_trim_filter_rest_users( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if ( ! $request instanceof WP_REST_Request || ! jpkcom_harden_trim_is_users_route( $request->get_route() ) ) {
			return $result;
		}

		if ( $result instanceof WP_Error || is_user_logged_in() ) {
			return $result;
		}

		return new WP_Error(
			'rest_user_cannot_view',
			__( 'Sorry, you are not allowed to view users.', 'jpkcom-harden-trim' ),
			array( 'status' => 401 )
		);
	}

}

// oEmbed author.

if ( ! function_exists( function: 'jpkcom_harden_trim_anonymize_oembed' ) ) {

	/**
	 * Replace the author in oEmbed data by the site.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $data oEmbed response data.
	 * @return array<string, mixed> The data; only existing author keys are changed.
	 */
	function jpkcom_harden_trim_anonymize_oembed( array $data ): array {
		if ( array_key_exists( key: 'author_name', array: $data ) ) {
			$data['author_name'] = get_bloginfo( 'name' );
		}

		if ( array_key_exists( key: 'author_url', array: $data ) ) {
			$data['author_url'] = home_url( '/' );
		}

		return $data;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_oembed_response_data' ) ) {

	/**
	 * `oembed_response_data` callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data oEmbed response data.
	 * @return mixed The anonymised data, or the input when it is no array.
	 */
	function jpkcom_harden_trim_filter_oembed_response_data( mixed $data ): mixed {
		return is_array( $data ) ? jpkcom_harden_trim_anonymize_oembed( $data ) : $data;
	}

}

// Users sitemap.

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_sitemap_provider' ) ) {

	/**
	 * `wp_sitemaps_add_provider` callback: drop the users provider.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $provider Sitemap provider.
	 * @param mixed $name     Provider name.
	 * @return mixed False for `users` (core then skips it), else the provider.
	 */
	function jpkcom_harden_trim_filter_sitemap_provider( mixed $provider, mixed $name = '' ): mixed {
		return 'users' === $name ? false : $provider;
	}

}

// Browse Happy.

if ( ! function_exists( function: 'jpkcom_harden_trim_is_browse_happy_url' ) ) {

	/**
	 * Whether a URL is the Browse Happy API (http or https).
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Request URL.
	 * @return bool True for api.wordpress.org/core/browse-happy/….
	 */
	function jpkcom_harden_trim_is_browse_happy_url( string $url ): bool {
		$parts = parse_url( url: $url );

		return is_array( $parts )
			&& 'api.wordpress.org' === strtolower( string: (string) ( $parts['host'] ?? '' ) )
			&& str_starts_with( haystack: (string) ( $parts['path'] ?? '' ), needle: '/core/browse-happy/' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_browse_happy' ) ) {

	/**
	 * `pre_http_request` callback: short-circuit the Browse Happy request.
	 *
	 * The error message is never shown (core only checks is_wp_error()), so
	 * it is not translated; translating here could load the text domain early.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $pre  Short-circuit value so far (false to continue).
	 * @param mixed $args Request arguments (unused).
	 * @param mixed $url  Request URL.
	 * @return mixed A WP_Error for the Browse Happy request, else `$pre`.
	 */
	function jpkcom_harden_trim_filter_browse_happy( mixed $pre, mixed $args = array(), mixed $url = '' ): mixed {
		if ( false !== $pre || ! is_string( $url ) || ! jpkcom_harden_trim_is_browse_happy_url( $url ) ) {
			return $pre;
		}

		return new WP_Error( 'jpkcom_harden_trim_browse_happy', 'Browse Happy request blocked by JPKCom Harden & Trim.' );
	}

}

// Dashboard events.

if ( ! function_exists( function: 'jpkcom_harden_trim_remove_events_widget' ) ) {

	/**
	 * `wp_dashboard_setup` callback: remove "WordPress Events and News".
	 *
	 * Without the widget its scripts never request events or news.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_remove_events_widget(): void {
		remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
	}

}

// Local avatars.

if ( ! function_exists( function: 'jpkcom_harden_trim_local_avatar_data' ) ) {

	/**
	 * Point avatar data at the plugin's local SVG.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $args Avatar data arguments.
	 * @return array<string, mixed> The arguments with `url` and `found_avatar` set.
	 */
	function jpkcom_harden_trim_local_avatar_data( array $args ): array {
		$args['url']          = JPKCOM_HARDEN_TRIM_URL . 'assets/avatar.svg';
		$args['found_avatar'] = true;

		return $args;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_avatar_data' ) ) {

	/**
	 * `pre_get_avatar_data` callback: local avatar instead of Gravatar.
	 *
	 * Setting `url` makes get_avatar_data() return before it hashes the
	 * e-mail address. A URL another plugin already set is kept.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $args Avatar data arguments.
	 * @return mixed The arguments.
	 */
	function jpkcom_harden_trim_filter_avatar_data( mixed $args ): mixed {
		if ( ! is_array( $args ) || ( isset( $args['url'] ) && is_string( $args['url'] ) && '' !== $args['url'] ) ) {
			return $args;
		}

		return jpkcom_harden_trim_local_avatar_data( $args );
	}

}

// Commenter IPs.

if ( ! function_exists( function: 'jpkcom_harden_trim_anonymize_ip' ) ) {

	/**
	 * Anonymise an IP address: IPv4 to /24, IPv6 to /48.
	 *
	 * An IPv4-mapped IPv6 address (`::ffff:a.b.c.d`, as dual-stack listeners
	 * report IPv4 clients) is anonymised as the IPv4 address it carries;
	 * keeping its first 48 bits would store `::` for every such client. Core's
	 * sanitiser keeps commas and spaces (comment.php:2424), so a proxy list
	 * anonymises its first valid address.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip IP address, or a comma-separated list of them.
	 * @return string The anonymised address, or '' when no valid IP is found.
	 */
	function jpkcom_harden_trim_anonymize_ip( string $ip ): string {
		foreach ( explode( separator: ',', string: $ip ) as $candidate ) {
			$candidate = trim( string: $candidate );

			if ( false !== filter_var( value: $candidate, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV4 ) ) {
				return (string) preg_replace( '/\.\d+$/', '.0', $candidate );
			}

			if ( false === filter_var( value: $candidate, filter: FILTER_VALIDATE_IP, options: FILTER_FLAG_IPV6 ) ) {
				continue;
			}

			$packed = inet_pton( ip: $candidate );

			if ( false === $packed || 16 !== strlen( string: $packed ) ) {
				continue;
			}

			// ::ffff:0:0/96 carries an IPv4 address in its last four bytes.
			if ( str_repeat( string: "\0", times: 10 ) . "\xff\xff" === substr( string: $packed, offset: 0, length: 12 ) ) {
				$ipv4 = inet_ntop( ip: substr( string: $packed, offset: 12, length: 4 ) );

				return false === $ipv4 ? '' : (string) preg_replace( '/\.\d+$/', '.0', $ipv4 );
			}

			$masked = inet_ntop( ip: substr( string: $packed, offset: 0, length: 6 ) . str_repeat( string: "\0", times: 10 ) );

			return false === $masked ? '' : $masked;
		}

		return '';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_comment_ip' ) ) {

	/**
	 * `pre_comment_user_ip` callback.
	 *
	 * Re-checks the setting of the current site: after switch_to_blog() a
	 * comment stored on another site of the network follows that site's
	 * choice, not the one the request booted with.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $ip Commenter IP.
	 * @return mixed The anonymised IP ('' for anything that is no IP), or `$ip`
	 *               unchanged when the current site does not anonymise.
	 */
	function jpkcom_harden_trim_filter_comment_ip( mixed $ip ): mixed {
		if ( ! jpkcom_harden_trim_feature_applies( 'comment_ip' ) ) {
			return $ip;
		}

		return is_string( $ip ) ? jpkcom_harden_trim_anonymize_ip( $ip ) : '';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_comment_flood' ) ) {

	/**
	 * `wp_is_comment_flood` callback: flood check against the anonymised IP.
	 *
	 * wp_check_comment_flood() (7.1.3 comment.php:909) looks for a recent
	 * comment with `comment_author_IP = <live IP>` or the same e-mail address.
	 * While comment_ip is on, stored rows hold the shortened address, so for
	 * guests only the e-mail clause would still match and a guest changing
	 * the e-mail address on every comment was never throttled. This runs the
	 * same core check once more with the shortened form, so the IP clause
	 * works again, per /24 (IPv4) or /48 (IPv6) network.
	 *
	 * Skipped when core already found a flood, for logged-in users (core
	 * checks the user ID), when the IP is already in its stored form, and when
	 * the site removed core's flood check.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $is_flood  Flood verdict so far.
	 * @param mixed $ip        Live commenter IP.
	 * @param mixed $email     Commenter e-mail address.
	 * @param mixed $date      GMT date of the new comment.
	 * @param mixed $avoid_die Whether to return instead of calling wp_die().
	 * @return mixed The verdict.
	 */
	function jpkcom_harden_trim_filter_comment_flood( mixed $is_flood, mixed $ip = '', mixed $email = '', mixed $date = '', mixed $avoid_die = false ): mixed {
		if (
			true === $is_flood
			|| ! is_string( $ip )
			|| is_user_logged_in()
			|| ! function_exists( function: 'wp_check_comment_flood' )
			|| false === has_filter( 'wp_is_comment_flood', 'wp_check_comment_flood' )
			|| ! jpkcom_harden_trim_feature_applies( 'comment_ip' )
		) {
			return $is_flood;
		}

		$stored = jpkcom_harden_trim_anonymize_ip( $ip );

		if ( '' === $stored || $stored === $ip ) {
			return $is_flood;
		}

		return wp_check_comment_flood( $is_flood, $stored, $email, $date, (bool) $avoid_die );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_comment_agent' ) ) {

	/**
	 * `pre_comment_user_agent` callback: store no user agent.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $agent Commenter user agent.
	 * @return mixed '' while the current site anonymises comments, else `$agent`.
	 */
	function jpkcom_harden_trim_filter_comment_agent( mixed $agent ): mixed {
		return jpkcom_harden_trim_feature_applies( 'comment_ip' ) ? '' : $agent;
	}

}

// Embed privacy.

if ( ! function_exists( function: 'jpkcom_harden_trim_privacy_embed_src' ) ) {

	/**
	 * Rewrite one iframe src for privacy.
	 *
	 * YouTube (`www.` or bare host) moves to youtube-nocookie.com; a Vimeo
	 * player URL gets `dnt=1`, and an existing `dnt` parameter with any other
	 * value (`dnt=0` keeps tracking on) is set to 1. The
	 * separator follows the src's own escaping (`&amp;`, `&#038;` or `&`) and
	 * the parameter goes before a fragment.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src Raw attribute value.
	 * @return string The rewritten value.
	 */
	function jpkcom_harden_trim_privacy_embed_src( string $src ): string {
		$src = (string) preg_replace( '#^((?:https?:)?//)(?:www\.)?youtube\.com/embed/#i', '$1www.youtube-nocookie.com/embed/', $src );

		if ( 1 !== preg_match( '#^(?:https?:)?//player\.vimeo\.com/video/#i', $src ) ) {
			return $src;
		}

		// A "#" that does not belong to a numeric entity (&#038;) starts the fragment.
		$fragment = '';

		if ( 1 === preg_match( '/(?<!&)#/', $src, $match, PREG_OFFSET_CAPTURE ) ) {
			$fragment = substr( string: $src, offset: $match[0][1] );
			$src      = substr( string: $src, offset: 0, length: $match[0][1] );
		}

		$query = parse_url( url: html_entity_decode( string: $src, flags: ENT_QUOTES | ENT_HTML5 ), component: PHP_URL_QUERY );

		// An existing dnt parameter is forced to 1 in place (dnt=0 would keep tracking on).
		if ( is_string( $query ) && 1 === preg_match( '/(?:^|&)dnt=/i', $query ) ) {
			$query_pos = (int) strpos( haystack: $src, needle: '?' );

			return substr( string: $src, offset: 0, length: $query_pos )
				. (string) preg_replace( '/(?<=[?&;])dnt=[^&#]*/i', 'dnt=1', substr( string: $src, offset: $query_pos ) )
				. $fragment;
		}

		if ( ! str_contains( haystack: $src, needle: '?' ) ) {
			$separator = '?';
		} elseif ( str_contains( haystack: $src, needle: '&amp;' ) ) {
			$separator = '&amp;';
		} elseif ( str_contains( haystack: $src, needle: '&#038;' ) ) {
			$separator = '&#038;';
		} else {
			$separator = '&';
		}

		return $src . $separator . 'dnt=1' . $fragment;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_privacy_embed_html' ) ) {

	/**
	 * Rewrite the src of every YouTube and Vimeo iframe in a piece of HTML.
	 *
	 * Only `src` attributes of `<iframe>` tags are touched; links and text
	 * stay as they are. Idempotent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $html Embed or block HTML.
	 * @return string The rewritten HTML.
	 */
	function jpkcom_harden_trim_privacy_embed_html( string $html ): string {
		if ( false === stripos( haystack: $html, needle: '<iframe' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'/<iframe\b[^>]*>/i',
			static fn( array $tag ): string => (string) preg_replace_callback(
				'/(\ssrc\s*=\s*)(["\'])(.*?)\2/is',
				static fn( array $attr ): string => $attr[1] . $attr[2] . jpkcom_harden_trim_privacy_embed_src( $attr[3] ) . $attr[2],
				$tag[0],
				1
			),
			$html
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_embed_privacy' ) ) {

	/**
	 * `embed_oembed_html` callback.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $html Embed HTML.
	 * @return mixed The rewritten HTML, or the input when it is no string.
	 */
	function jpkcom_harden_trim_filter_embed_privacy( mixed $html ): mixed {
		return is_string( $html ) ? jpkcom_harden_trim_privacy_embed_html( $html ) : $html;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_cover_embed' ) ) {

	/**
	 * `render_block_core/cover` callback for embedded background videos.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $content Rendered block HTML.
	 * @param mixed $block   Parsed block.
	 * @return mixed The rewritten HTML for embed-video covers, else the input.
	 */
	function jpkcom_harden_trim_filter_cover_embed( mixed $content, mixed $block = array() ): mixed {
		if ( ! is_string( $content ) || ! is_array( $block ) || 'embed-video' !== ( $block['attrs']['backgroundType'] ?? '' ) ) {
			return $content;
		}

		return jpkcom_harden_trim_privacy_embed_html( $content );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_oembed_proxy_response' ) ) {

	/**
	 * `rest_post_dispatch` callback: rewrite the oEmbed proxy's preview HTML.
	 *
	 * Covers fresh and cached proxy responses; the cached copy itself is not
	 * changed.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result  REST response.
	 * @param mixed $server  REST server (unused).
	 * @param mixed $request The request.
	 * @return mixed The response.
	 */
	function jpkcom_harden_trim_filter_oembed_proxy_response( mixed $result, mixed $server = null, mixed $request = null ): mixed {
		if (
			! $result instanceof WP_REST_Response
			|| ! $request instanceof WP_REST_Request
			|| 0 !== strcasecmp( rtrim( string: $request->get_route(), characters: '/' ), '/oembed/1.0/proxy' )
		) {
			return $result;
		}

		$data = $result->get_data();

		if ( is_object( $data ) && isset( $data->html ) && is_string( $data->html ) ) {
			$data       = clone $data;
			$data->html = jpkcom_harden_trim_privacy_embed_html( $data->html );
			$result->set_data( $data );
		} elseif ( is_array( $data ) && isset( $data['html'] ) && is_string( $data['html'] ) ) {
			$data['html'] = jpkcom_harden_trim_privacy_embed_html( $data['html'] );
			$result->set_data( $data );
		}

		return $result;
	}

}

// Remote editor content.

if ( ! function_exists( function: 'jpkcom_harden_trim_filter_editor_settings' ) ) {

	/**
	 * `block_editor_settings_all` callback: hide the Openverse media category.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $settings Block editor settings.
	 * @return mixed The settings.
	 */
	function jpkcom_harden_trim_filter_editor_settings( mixed $settings ): mixed {
		if ( is_array( $settings ) ) {
			$settings['enableOpenverseMediaCategory'] = false;
		}

		return $settings;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_unregister_google_fonts' ) ) {

	/**
	 * `init` callback: unregister core's Google Fonts collection.
	 *
	 * Checked first, because unregistering a collection that is not
	 * registered (removed by another plugin) raises _doing_it_wrong. The
	 * check goes through the public get_font_collections():
	 * WP_Font_Library::is_collection_registered() is private (7.1.3,
	 * class-wp-font-library.php:96) and calling it is a fatal Error.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_unregister_google_fonts(): void {
		if ( ! class_exists( class: 'WP_Font_Library' ) || ! function_exists( function: 'wp_unregister_font_collection' ) ) {
			return;
		}

		if ( array_key_exists( key: 'google-fonts', array: WP_Font_Library::get_instance()->get_font_collections() ) ) {
			wp_unregister_font_collection( 'google-fonts' );
		}
	}

}
