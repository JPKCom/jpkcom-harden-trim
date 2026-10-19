<?php
/**
 * Feature registration
 *
 * Single entry point the main file calls on plugins_loaded: hands the
 * effective settings to the registrar of each feature group. A group whose
 * module is missing is skipped, so the plugin stays loadable. Also holds the
 * helpers more than one feature module calls; the main file loads this file
 * before every features-*.php.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_features' ) ) {

	/**
	 * Register the hooks of every feature group.
	 *
	 * Registrars only add hooks for features whose value is active; role
	 * decisions happen later, inside the callbacks, where the user is known.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_features( array $settings ): void {
		foreach ( array( 'performance', 'head', 'privacy', 'security' ) as $group ) {
			$registrar = 'jpkcom_harden_trim_register_' . $group . '_features';

			if ( function_exists( function: $registrar ) ) {
				$registrar( $settings );
			}
		}
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_is_front_end' ) ) {

	/**
	 * Whether the request renders the public site.
	 *
	 * Excludes the admin and REST requests: the block-editor settings REST
	 * route builds the editor canvas assets through
	 * _wp_get_iframed_editor_assets(), which fires wp_print_styles and
	 * wp_print_footer_scripts with is_admin() false.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True on the front end.
	 */
	function jpkcom_harden_trim_is_front_end(): bool {
		return ! is_admin() && ! ( defined( constant_name: 'REST_REQUEST' ) && REST_REQUEST );
	}

}

if ( ! function_exists( function: 'jpkcom_harden_trim_force_404' ) ) {

	/**
	 * Turn the main query into a plain 404 that the theme's 404 template renders.
	 *
	 * WP_Query::set_404() (7.1.3 class-wp-query.php:1843) resets the
	 * conditional flags but keeps `is_feed`, keeps `is_embed` (unknown to
	 * init_query_flags(), :502) and never touches the queried posts or the
	 * queried object. template-loader.php runs do_feed() while is_feed() is
	 * true and checks is_embed before is_404, and a theme without a 404
	 * template falls back to its index, whose loop would still list the
	 * posts. So feed, embed, posts and queried object are cleared as well,
	 * together with the globals WP::register_globals() derived from them.
	 *
	 * On a feed request WP::send_headers() (7.1.3 class-wp.php:471-476) has
	 * already set the feed Content-Type (application/rss+xml and the like)
	 * before template_redirect, so the HTML 404 page would go out under it.
	 * The Content-Type is then set back to what core sends for HTML pages
	 * (`html_type`; charset `blog_charset`, class-wp.php:468), unless the
	 * headers are already sent.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Query $query The main query, at template_redirect.
	 * @return void
	 */
	function jpkcom_harden_trim_force_404( WP_Query $query ): void {
		$was_feed = $query->is_feed || $query->is_comment_feed;

		$query->set_404();

		$query->is_feed           = false;
		$query->is_comment_feed   = false;
		$query->is_embed          = false;
		$query->posts             = array();
		$query->post_count        = 0;
		$query->current_post      = -1;
		$query->post              = null;
		$query->found_posts       = 0;
		$query->max_num_pages     = 0;
		$query->queried_object    = null;
		$query->queried_object_id = null;

		$GLOBALS['post'] = null;
		unset( $GLOBALS['authordata'] );

		if ( $was_feed && ! headers_sent() ) {
			$type    = get_option( 'html_type' );
			$charset = get_option( 'blog_charset' );

			header( 'Content-Type: ' . ( is_string( $type ) && '' !== $type ? $type : 'text/html' ) . '; charset=' . ( is_string( $charset ) && '' !== $charset ? $charset : 'UTF-8' ) );
		}
	}

}
