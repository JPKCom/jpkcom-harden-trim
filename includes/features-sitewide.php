<?php
/**
 * Site-wide features
 *
 * Two switches that mirror standalone JPKCom plugins hook for hook:
 * `xmlrpc` (JPKCom Disable XML-RPC 1.0.10) and `comments` (JPKCom Disable
 * Comments 1.0.11). Same hooks, priorities, accepted args and return values;
 * only the callback names and the text domain differ, plus two documented
 * exceptions: the XML-RPC fault text is an English literal, and the comment
 * feed refusal stays a wp_die() 404 instead of going through
 * jpkcom_harden_trim_force_404(). Both are global (no role exemption). The
 * standalone plugins' findings up to their 1.0.10 were measured against
 * WordPress 7.0.2; the Notes gate, the note-query pass-through and the comment
 * count filter (Disable Comments 1.0.11) were read in the 7.1.3 source and not
 * yet measured on a running site. The line references below are 7.1.3.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.1.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_sitewide_features' ) ) {

	/**
	 * Register the hooks of the active site-wide features.
	 *
	 * Nothing is registered for a switch that is off. Registered at plugin
	 * load (plugins_loaded priority 5), so the XML-RPC guard is in place
	 * before `init`.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_sitewide_features( array $settings ): void {

		if ( true === ( $settings['xmlrpc'] ?? false ) ) {
			// PHP_INT_MAX so that a theme or plugin hooking in later cannot re-enable them.
			add_filter( 'xmlrpc_enabled', '__return_false', PHP_INT_MAX );
			add_filter( 'xmlrpc_methods', '__return_empty_array', PHP_INT_MAX );

			// Stop advertising an endpoint that is refused: X-Pingback (7.1.3
			// class-wp.php:544-546) and the themes' <link rel="pingback"> are
			// both gated on pings_open(). Pingbacks arrive through XML-RPC's
			// pingback.ping, so they cannot be received anyway. Side effect,
			// deliberate as in the standalone plugin: pings_open() also governs
			// wp-trackback.php, so trackbacks are refused too.
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX, 2 );

			add_action( 'init', 'jpkcom_harden_trim_xmlrpc_refuse', 0 );
			add_filter( 'wp_xmlrpc_server_class', 'jpkcom_harden_trim_xmlrpc_server_class' );
		}

		if ( true === ( $settings['comments'] ?? false ) ) {
			add_action( 'rest_api_init', 'jpkcom_harden_trim_comments_attach_rest_status' );
			add_action( 'wp_loaded', 'jpkcom_harden_trim_comments_remove_support' );
			add_action( 'admin_init', 'jpkcom_harden_trim_comments_redirect_screen' );

			// PHP_INT_MAX so that a theme or plugin registering a later filter
			// cannot re-open them (the standalone plugin raised it from 20 in 1.0.9).
			add_filter( 'comments_open', '__return_false', PHP_INT_MAX, 2 );
			add_filter( 'pings_open', '__return_false', PHP_INT_MAX, 2 );
			add_filter( 'comments_array', '__return_empty_array', PHP_INT_MAX, 2 );

			add_filter( 'wp_count_comments', 'jpkcom_harden_trim_comments_zero_counts', PHP_INT_MAX, 2 );
			add_filter( 'comments_pre_query', 'jpkcom_harden_trim_comments_short_circuit_query', PHP_INT_MAX, 2 );
			add_filter( 'get_comments_number', '__return_zero', PHP_INT_MAX );

			add_filter( 'feed_links_show_comments_feed', '__return_false' );
			add_action( 'template_redirect', 'jpkcom_harden_trim_comments_block_feed', 0 );

			add_action( 'admin_menu', 'jpkcom_harden_trim_comments_remove_menu' );
			add_action( 'admin_bar_menu', 'jpkcom_harden_trim_comments_remove_admin_bar_node', 999 );
			add_filter( 'rest_pre_dispatch', 'jpkcom_harden_trim_comments_rest_gate', PHP_INT_MAX, 3 );
		}
	}

}

// XML-RPC.

if ( ! function_exists( function: 'jpkcom_harden_trim_is_xmlrpc_request' ) ) {

	/**
	 * Whether the current request is being served by xmlrpc.php.
	 *
	 * `XMLRPC_REQUEST` is defined at the top of `xmlrpc.php` (7.1.3
	 * xmlrpc.php:13), before `wp-load.php` runs, so it is set for every
	 * request that reaches the endpoint and cannot be spoofed by the request.
	 * The standalone plugin derived it from `$_SERVER['SCRIPT_FILENAME']` up to
	 * 1.0.8, which depends on how a SAPI fills that variable.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-xmlrpc/jpkcom-disable-xmlrpc.php jpkcom_disable_xmlrpc_is_xmlrpc_request()
	 *
	 * @return bool True when serving an XML-RPC request.
	 */
	function jpkcom_harden_trim_is_xmlrpc_request(): bool {
		return defined( constant_name: 'XMLRPC_REQUEST' ) && (bool) constant( name: 'XMLRPC_REQUEST' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_xmlrpc_fault_xml' ) ) {

	/**
	 * Build the XML-RPC fault sent in place of a served request.
	 *
	 * A fault response keeps the refusal machine-readable for XML-RPC clients,
	 * which cannot parse an HTML error page. The fault string is the English
	 * literal, not a translation: the guard runs at `init` priority 0, before
	 * this plugin loads its text domain at `init` priority 1, and the body is
	 * read by machines. It is escaped like the standalone plugin's string.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-xmlrpc/jpkcom-disable-xmlrpc.php jpkcom_disable_xmlrpc_fault_xml()
	 *
	 * @return string A complete XML-RPC `methodResponse` fault document (faultCode 403).
	 */
	function jpkcom_harden_trim_xmlrpc_fault_xml(): string {
		return '<?xml version="1.0"?>'
			. '<methodResponse><fault><value><struct>'
			. '<member><name>faultCode</name><value><int>403</int></value></member>'
			. '<member><name>faultString</name><value><string>'
			. esc_html( 'XML-RPC is disabled.' )
			. '</string></value></member>'
			. '</struct></value></fault></methodResponse>';
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_xmlrpc_refuse' ) ) {

	/**
	 * `init` priority 0: reject XML-RPC requests with a 403 and an XML-RPC fault.
	 *
	 * The response is written here instead of being left to `wp_die()`. With
	 * `XMLRPC_REQUEST` defined, `wp_die()` dispatches to
	 * `_xmlrpc_wp_die_handler()`, which only emits anything when the global
	 * `$wp_xmlrpc_server` already exists (7.1.3 functions.php:4254), and
	 * `xmlrpc.php` creates that object after `init` (xmlrpc.php:82). That
	 * handler also never calls `status_header()`. Measured by the standalone
	 * plugin against 7.0.2: up to its 1.0.8 the endpoint answered HTTP 200 with
	 * an empty body.
	 *
	 * Priority 0 puts it ahead of the text domain (1) and the updater (5), so
	 * a refused request does no further work.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-xmlrpc/jpkcom-disable-xmlrpc.php the init priority 0 closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_xmlrpc_refuse(): void {
		if ( ! jpkcom_harden_trim_is_xmlrpc_request() ) {
			return;
		}

		nocache_headers();
		status_header( 403 );

		if ( ! headers_sent() ) {
			header( 'Content-Type: text/xml; charset=' . get_option( 'blog_charset' ) );
		}

		echo jpkcom_harden_trim_xmlrpc_fault_xml(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- assembled from literals and one esc_html() string.

		exit;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_xmlrpc_server_class' ) ) {

	/**
	 * `wp_xmlrpc_server_class` callback, last resort: block the server class.
	 *
	 * Unreachable while the init guard is in place (xmlrpc.php:81 runs after
	 * `init`), and kept so that removing or short-circuiting the guard cannot
	 * silently re-enable the endpoint. `status_header()` is called explicitly
	 * because the XML-RPC die handler never sets one. These strings are
	 * translated: the text domain is loaded by then.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-xmlrpc/jpkcom-disable-xmlrpc.php the wp_xmlrpc_server_class closure
	 *
	 * @param string $class The XML-RPC server class name.
	 * @return never
	 */
	function jpkcom_harden_trim_xmlrpc_server_class( string $class ): never {
		status_header( 403 );

		wp_die(
			esc_html__( 'XML-RPC is disabled.', 'jpkcom-harden-trim' ),
			esc_html__( 'Error', 'jpkcom-harden-trim' ),
			array( 'response' => 403 )
		);
	}

}

// Comments.

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_close_rest_status' ) ) {

	/**
	 * Report comments and pings as closed in a REST post response.
	 *
	 * Removing post type support is not enough for `post`, `page` and
	 * `attachment`: `WP_REST_Posts_Controller::get_item_schema()` carries a
	 * hardcoded `$fixed_schemas` list for those three in which `comments` is
	 * always present (7.1.3 class-wp-rest-posts-controller.php:2552, :2586-2588);
	 * the `post_type_supports()` check applies only to other post types. The
	 * response therefore kept reporting the stored value, typically `open`.
	 *
	 * The fields are rewritten rather than removed, so the block editor keeps
	 * the response shape it expects. A REST write can still store `open`, but
	 * it is inert: `comments_open` is filtered false and this reports closed.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php jpkcom_disable_comments_close_rest_status()
	 *
	 * @param mixed $response Prepared response, a WP_REST_Response in practice.
	 * @return mixed The response with both status fields set to closed.
	 */
	function jpkcom_harden_trim_comments_close_rest_status( mixed $response ): mixed {
		if ( ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) ) {
			return $response;
		}

		foreach ( array( 'comment_status', 'ping_status' ) as $field ) {
			if ( array_key_exists( $field, $data ) ) {
				$data[ $field ] = 'closed';
			}
		}

		$response->set_data( $data );

		return $response;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_is_note_request' ) ) {

	/**
	 * Whether a REST request on the comment routes is a block-editor Notes request.
	 *
	 * Notes (core since 6.9) are comments of type `note`, created and read
	 * through `/wp/v2/comments`. A request counts as a note request when
	 *
	 * - it targets the collection route and its `type` parameter is exactly
	 *   `note` - the same strict comparison core's controller makes
	 *   (`'note' === $request['type']`), for GET and POST alike;
	 * - it targets the item route `/wp/v2/comments/<id>` and that comment exists
	 *   and is of type `note` (any method; core refuses to change a comment's
	 *   type on update, 7.1.3 class-wp-rest-comments-controller.php:925).
	 *
	 * The route is read from `get_route()` and matched the way core matches it
	 * (class-wp-rest-server.php:1172): case-insensitively, against the same
	 * `[\d]+` item pattern, and with `$` accepting a trailing newline as core's
	 * `@^…$@i` does. A trailing slash is tolerated as well. The ID is taken from
	 * the route because the URL parameters are not populated yet on
	 * `rest_pre_dispatch`: `dispatch()` applies the filter (:1079) before it
	 * matches the route (:1096), and a batch sub-request is passed with its URL
	 * parameters cleared (:1860-1866).
	 *
	 * The controller itself does not use the ID in the route: it reads
	 * `$request['id']` (class-wp-rest-comments-controller.php:435, :480, :873,
	 * :918, :1042, :1066), and `get_param()` prefers the JSON body, the form body
	 * and the query string to the URL parameters (class-wp-rest-request.php:362-384,
	 * :408-418). `/wp/v2/comments/<note>?id=<n>` would therefore load comment
	 * `<n>`. An item request with an `id` parameter is a note request only when
	 * that parameter is numeric and equal to the ID in the route. Read here,
	 * before routing, `get_param( 'id' )` returns exactly the value core will
	 * prefer later, since the URL parameters are still empty.
	 *
	 * Core's own permission checks still decide who may read or write notes.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php jpkcom_disable_comments_is_note_request()
	 *
	 * @param WP_REST_Request $request The request about to be dispatched.
	 * @return bool True for a note request, false for anything else.
	 */
	function jpkcom_harden_trim_comments_is_note_request( WP_REST_Request $request ): bool {
		$route = (string) $request->get_route();

		if ( 1 === preg_match( '#^/wp/v2/comments/?$#i', $route ) ) {
			return 'note' === $request->get_param( 'type' );
		}

		if ( 1 !== preg_match( '#^/wp/v2/comments/([\d]+)/?$#i', $route, $matches ) ) {
			return false;
		}

		$comment_id = (int) $matches[1];

		// get_comment() of an empty ID falls back to the global $comment.
		if ( $comment_id <= 0 ) {
			return false;
		}

		// The controller loads $request['id'], which a body or query `id` overrides.
		$param_id = $request->get_param( 'id' );

		if ( null !== $param_id && ( ! is_numeric( $param_id ) || (float) $param_id !== (float) $comment_id ) ) {
			return false;
		}

		$comment = get_comment( $comment_id );

		return $comment instanceof WP_Comment && 'note' === $comment->comment_type;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_is_note_query' ) ) {

	/**
	 * Whether a comment query asks for notes and nothing else.
	 *
	 * Mirrors how `WP_Comment_Query::get_comment_ids()` reads the requested
	 * types (7.1.3 class-wp-comment-query.php:776-789): `type` and `type__in`
	 * are merged, and an empty string means "all types". The query counts as a
	 * note query only when, after dropping those empty entries, exactly `note`
	 * remains. `all`, `comment`, an empty type, a mix such as
	 * `[ 'note', 'comment' ]` or a spelling core would not treat as a note
	 * (`NOTE`, `note,comment`) do not qualify.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php jpkcom_disable_comments_is_note_query()
	 *
	 * @param WP_Comment_Query $query The query about to run.
	 * @return bool True when only notes are requested.
	 */
	function jpkcom_harden_trim_comments_is_note_query( WP_Comment_Query $query ): bool {
		$types = array_merge(
			(array) ( $query->query_vars['type'] ?? '' ),
			(array) ( $query->query_vars['type__in'] ?? '' )
		);

		$types = array_values(
			array_unique(
				array_filter( $types, static fn ( mixed $type ): bool => '' !== $type )
			)
		);

		return array( 'note' ) === $types;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_attach_rest_status' ) ) {

	/**
	 * `rest_api_init` callback: apply the REST status rewrite to every post type.
	 *
	 * Registered on rest_api_init because the filter name is per post type and
	 * every post type is registered by then.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the rest_api_init closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_comments_attach_rest_status(): void {
		foreach ( get_post_types() as $post_type ) {
			add_filter( 'rest_prepare_' . $post_type, 'jpkcom_harden_trim_comments_close_rest_status', PHP_INT_MAX );
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_remove_support' ) ) {

	/**
	 * `wp_loaded` callback: remove comment and trackback support from every post type.
	 *
	 * Bound to `wp_loaded`, not `admin_init`: every post type is registered
	 * once `init` has completed (7.1.3 wp-settings.php:779 `init`, :801
	 * `wp_loaded`), while `admin_init` and `rest_api_init` fire later. On
	 * `admin_init` (the standalone plugin up to 1.0.8) the removal never
	 * reached REST requests, so `/wp/v2/posts` kept reporting
	 * `comment_status: open`.
	 *
	 * The two supports are checked independently: a post type may declare
	 * `trackbacks` without `comments`, and a nested check left those untouched.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the wp_loaded closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_comments_remove_support(): void {
		foreach ( get_post_types() as $post_type ) {
			if ( post_type_supports( $post_type, 'comments' ) ) {
				remove_post_type_support( $post_type, 'comments' );
			}

			if ( post_type_supports( $post_type, 'trackbacks' ) ) {
				remove_post_type_support( $post_type, 'trackbacks' );
			}
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_redirect_screen' ) ) {

	/**
	 * `admin_init` callback: redirect the comments screen to the dashboard.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the admin_init closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_comments_redirect_screen(): void {
		global $pagenow;

		if ( 'edit-comments.php' === $pagenow ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_zero_counts' ) ) {

	/**
	 * `wp_count_comments` callback: report zero comments everywhere.
	 *
	 * The dashboard's "At a Glance" widget builds its comment line from
	 * `wp_count_comments()` (filter at 7.1.3 comment.php:1529); with a non-zero
	 * count it linked to `edit-comments.php`, which is redirected away. A
	 * non-empty object also keeps core from running its own count query.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the wp_count_comments closure
	 *
	 * @param mixed $counts  Counts assembled so far, an empty array by default.
	 * @param mixed $post_id Post ID the counts were requested for.
	 * @return object Zeroed count object in the shape core expects.
	 */
	function jpkcom_harden_trim_comments_zero_counts( mixed $counts, mixed $post_id ): object {
		return (object) array(
			'approved'       => 0,
			'moderated'      => 0,
			'spam'           => 0,
			'trash'          => 0,
			'post-trashed'   => 0,
			'total_comments' => 0,
		);
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_short_circuit_query' ) ) {

	/**
	 * `comments_pre_query` callback: short-circuit every comment query except note-only ones.
	 *
	 * `comments_array` only covers the template path. The dashboard Activity
	 * widget queries comments straight through `WP_Comment_Query` (filter at
	 * 7.1.3 class-wp-comment-query.php:441), which is why the full moderation
	 * list still appeared there. `dashboard_recent_comments` has not been a
	 * widget since WordPress 3.8, so removing that meta box does nothing.
	 *
	 * Queries for block-editor Notes and nothing else pass through untouched
	 * (see jpkcom_harden_trim_comments_is_note_query()), so the Notes panel can
	 * list notes and core gets the real note count. Notes are not public: core
	 * leaves them out of every query that does not ask for them (:782-789), and
	 * out of `comment_count` (comment.php:3138).
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the comments_pre_query closure
	 *
	 * @param mixed            $comment_data Short-circuit value, null by default.
	 * @param WP_Comment_Query $query        The query instance.
	 * @return mixed `0` for counting queries, an empty array otherwise; the
	 *               incoming value for a note-only query.
	 */
	function jpkcom_harden_trim_comments_short_circuit_query( mixed $comment_data, WP_Comment_Query $query ): mixed {
		if ( jpkcom_harden_trim_comments_is_note_query( $query ) ) {
			return $comment_data;
		}

		return empty( $query->query_vars['count'] ) ? array() : 0;
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_block_feed' ) ) {

	/**
	 * `template_redirect` priority 0: refuse the comment feeds.
	 *
	 * Comment feeds are assembled by `WP_Query` directly through `$wpdb` (only
	 * the `comment_feed_*` filters apply), so they bypass `comments_array` and
	 * `comments_pre_query`: approved comments stayed readable at
	 * `/comments/feed/` and `<post>/feed/`. `/feed/` is unaffected, because
	 * `is_comment_feed()` is false there.
	 *
	 * Deliberately a `wp_die( ..., 404 )` and not
	 * jpkcom_harden_trim_force_404(), as in the standalone plugin: while
	 * `is_comment_feed()` is true core routes it to `_xml_wp_die_handler`
	 * (7.1.3 functions.php:3866-3878), so the answer is a 404 XML error
	 * document, not the theme's 404 template.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the template_redirect closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_comments_block_feed(): void {
		if ( is_comment_feed() ) {
			wp_die(
				esc_html__( 'Comments are disabled.', 'jpkcom-harden-trim' ),
				esc_html__( 'Not Found', 'jpkcom-harden-trim' ),
				array( 'response' => 404 )
			);
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_remove_menu' ) ) {

	/**
	 * `admin_menu` callback: remove the Comments entry from the admin menu.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the admin_menu closure
	 *
	 * @return void
	 */
	function jpkcom_harden_trim_comments_remove_menu(): void {
		remove_menu_page( 'edit-comments.php' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_remove_admin_bar_node' ) ) {

	/**
	 * `admin_bar_menu` priority 999: remove the Comments node from the admin bar.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the admin_bar_menu closure
	 *
	 * @param WP_Admin_Bar $wp_admin_bar The admin bar instance.
	 * @return void
	 */
	function jpkcom_harden_trim_comments_remove_admin_bar_node( WP_Admin_Bar $wp_admin_bar ): void {
		$wp_admin_bar->remove_node( 'comments' );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_comments_rest_gate' ) ) {

	/**
	 * `rest_pre_dispatch` callback: close the REST comment routes to everything except block-editor Notes.
	 *
	 * Up to Disable Comments 1.0.10 the `/wp/v2/comments` routes were removed on
	 * `rest_endpoints`. That also broke Notes, which core reads and writes
	 * through exactly those routes. The routes now stay registered, and this
	 * gate answers every request on them that is not a note request (see
	 * jpkcom_harden_trim_comments_is_note_request()) the way a missing route is
	 * answered: `rest_no_route`, core's own message (default text domain, so the
	 * translation is core's too) and status 404.
	 *
	 * A request is on the comment routes when its route is `/wp/v2/comments` or
	 * lies below `/wp/v2/comments/`, compared case-insensitively as core does;
	 * `?rest_route=` requests arrive with the same route. `/wp/v2/commentsfoo`
	 * and other namespaces are not touched.
	 *
	 * PHP_INT_MAX, and a non-note request is answered even when an earlier
	 * filter already produced a result - that includes core's OPTIONS handler
	 * at priority 10 (7.1.3 rest-api.php:837), which would otherwise describe
	 * the route. For a note request the incoming value is returned unchanged.
	 *
	 * The error is returned as a response, not as a `WP_Error`: in a batch
	 * request core passes a `rest_pre_dispatch` result straight on to
	 * `rest_post_dispatch` (class-wp-rest-server.php:1894), where
	 * `rest_send_allow_header()` calls `get_matched_route()` on it, which a
	 * `WP_Error` does not have. In a single request `dispatch()` returns the
	 * response as is - the same bytes as core's own missing-route answer.
	 *
	 * Side effect: the route index at `/wp-json/` lists the comment routes
	 * again; that is inherent to keeping them for Notes.
	 *
	 * @since 1.1.0
	 * @see   jpkcom-disable-comments/jpkcom-disable-comments.php the rest_pre_dispatch closure
	 *
	 * @param mixed $result  Response from an earlier filter, null by default.
	 * @param mixed $server  Server instance.
	 * @param mixed $request The request about to be dispatched.
	 * @return mixed The incoming value, or the 404 response.
	 */
	function jpkcom_harden_trim_comments_rest_gate( mixed $result, mixed $server, mixed $request ): mixed {
		if ( ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( 1 !== preg_match( '#^/wp/v2/comments(?:/|$)#i', (string) $request->get_route() ) ) {
			return $result;
		}

		if ( jpkcom_harden_trim_comments_is_note_request( $request ) ) {
			return $result;
		}

		return rest_convert_error_to_response(
			new WP_Error(
				'rest_no_route',
				// phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- core's own string and domain, so the answer matches core's missing-route answer in every locale.
				__( 'No route was found matching the URL and request method.' ),
				array( 'status' => 404 )
			)
		);
	}

}
