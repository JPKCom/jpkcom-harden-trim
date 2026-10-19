<?php
/**
 * WP_Query stub with core's set_404() semantics (WordPress 7.1.3).
 *
 * class-wp-query.php: set_404() (:1843) saves is_feed, runs init_query_flags()
 * (:502, which does not reset is_embed), sets is_404 and restores is_feed;
 * it never touches the queried posts or the queried object. A stub that only
 * flips is_404 hides exactly the state a forced 404 leaves behind, so the
 * feature tests use this one.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

if ( ! class_exists( class: 'WP_Query' ) ) {
	class WP_Query {
		public mixed $queried_object    = null;
		public mixed $queried_object_id = null;
		/** @var array<int, mixed>|null */
		public ?array $posts            = null;
		public int $post_count          = 0;
		public int $current_post        = -1;
		public mixed $post              = null;
		public int $found_posts         = 0;
		public int $max_num_pages       = 0;
		public bool $is_single          = false;
		public bool $is_singular        = false;
		public bool $is_author          = false;
		public bool $is_archive         = false;
		public bool $is_feed            = false;
		public bool $is_comment_feed    = false;
		public bool $is_404             = false;
		public bool $is_embed           = false;

		/**
		 * Core's set_404(): flags reset except is_embed, is_feed kept, posts kept.
		 */
		public function set_404(): void {
			$is_feed               = $this->is_feed;
			$this->is_single       = false;
			$this->is_singular     = false;
			$this->is_author       = false;
			$this->is_archive      = false;
			$this->is_feed         = false;
			$this->is_comment_feed = false;
			$this->is_404          = true;
			$this->is_feed         = $is_feed;
		}

		public function is_embed(): bool {
			return $this->is_embed;
		}
	}
}

/**
 * A main query as core leaves it before template_redirect.
 *
 * @param array<string, bool> $flags Flags to set (is_embed, is_feed, is_author, ...).
 * @return WP_Query
 */
function jht_query_with_posts( array $flags = array() ): WP_Query {
	$q                    = new WP_Query();
	$post                 = new WP_Post( 7, 'post' );
	$q->posts             = array( $post );
	$q->post              = $post;
	$q->post_count        = 1;
	$q->found_posts       = 1;
	$q->max_num_pages     = 1;
	$q->queried_object    = $post;
	$q->queried_object_id = 7;
	foreach ( $flags as $flag => $value ) {
		$q->{$flag} = $value;
	}
	$GLOBALS['post']  = $post;
	$GLOBALS['posts'] = &$q->posts;
	return $q;
}
