<?php
/**
 * Head cleanup features
 *
 * Shortlink, RSD, REST discovery links, feed links and the generator tag
 * Every removal is global: the head and the HTTP
 * headers are shared by all visitors, so the exempt roles do not apply.
 * Hook names and priorities were checked against wp-includes/default-filters.php
 * of WordPress 7.1.3; they are registered before plugins load, so removing
 * them on plugins_loaded works.
 *
 * @package JPKCom_Harden_Trim
 * @since   1.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( function: 'jpkcom_harden_trim_register_head_features' ) ) {

	/**
	 * Remove the head output of the active head features.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string, mixed> $settings Effective settings.
	 * @return void
	 */
	function jpkcom_harden_trim_register_head_features( array $settings ): void {

		if ( true === ( $settings['head_shortlink'] ?? false ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( true === ( $settings['head_rsd'] ?? false ) ) {
			remove_action( 'wp_head', 'rsd_link', 10 );
		}

		if ( true === ( $settings['head_rest_links'] ?? false ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}

		if ( true === ( $settings['head_feed_links'] ?? false ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( true === ( $settings['head_generator'] ?? false ) ) {
			remove_action( 'wp_head', 'wp_generator', 10 );
			// Feeds and the export file print the generator through the same filter.
			add_filter( 'the_generator', '__return_empty_string' );
		}
	}

}
