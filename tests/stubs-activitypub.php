<?php
/**
 * Stubs mirroring the ActivityPub plugin's public API (wordpress.org slug
 * `activitypub`, read against 9.3.1).
 *
 * Mirrored, not simplified: the author-enumeration exemption delegates to
 * these functions, so a stub that classifies more loosely than the real
 * plugin would hide exactly the bypass the tests look for.
 *
 * - is_activitypub_request() (functions-request.php:70 → class-query.php:297):
 *   `?activitypub` in the query, or accept_prefers_activitypub() on the
 *   Accept header; filter `activitypub_is_activitypub_request`.
 * - accept_prefers_activitypub() (functions-request.php:118): q-aware, the
 *   highest-quality type wins (ties: first listed), `q=0` refuses a type,
 *   ld+json counts only with the AS2 profile.
 * - should_negotiate_content() (functions-request.php:79 → class-query.php:346):
 *   true for `?author=` requests; the test can force false.
 * - user_can_activitypub() (functions-user.php:90): numeric ID of an actor;
 *   the test lists the actor IDs in $GLOBALS['apstub_actors'].
 *
 * Included with `require` (not require_once) and $GLOBALS['apstub_parts']
 * naming the functions to define, so a suite can load the API in stages and
 * check that a missing function blocks the exemption.
 *
 * @package JPKCom_Harden_Trim
 * @since 1.0.0
 */

declare(strict_types=1);

namespace Activitypub;

$apstub_parts = $GLOBALS['apstub_parts'] ?? array( 'is_activitypub_request', 'should_negotiate_content', 'user_can_activitypub' );

if ( ! \defined( 'ACTIVITYPUB_PLUGIN_VERSION' ) ) {
	\define( 'ACTIVITYPUB_PLUGIN_VERSION', '9.3.1' );
}

if ( ! \defined( 'ACTIVITYPUB_PLUGIN_DIR' ) ) {
	\define( 'ACTIVITYPUB_PLUGIN_DIR', '/var/www/html/wp-content/plugins/activitypub/' );
}

if ( ! \function_exists( 'Activitypub\\accept_prefers_activitypub' ) ) {
	function accept_prefers_activitypub( $accept ) {
		$winner_quality = 0.0;
		$winner_is_ap   = false;

		foreach ( \explode( ',', (string) $accept ) as $part ) {
			$segments   = \explode( ';', $part );
			$media_type = \strtolower( \trim( (string) \array_shift( $segments ) ) );

			if ( '' === $media_type ) {
				continue;
			}

			$quality = 1.0;
			$profile = '';
			foreach ( $segments as $param ) {
				$param = \trim( $param );
				if ( 0 === \stripos( $param, 'q=' ) ) {
					$q_value = \trim( \substr( $param, 2 ) );
					if ( \is_numeric( $q_value ) ) {
						$quality = (float) $q_value;
					}
				} elseif ( 0 === \stripos( $param, 'profile=' ) ) {
					$profile = \strtolower( \trim( \substr( $param, 8 ), '"' ) );
				}
			}

			if ( $quality <= 0 ) {
				continue;
			}

			if ( $quality > $winner_quality ) {
				$winner_quality = $quality;
				$winner_is_ap   = 'application/activity+json' === $media_type
					|| ( 'application/ld+json' === $media_type && false !== \strpos( $profile, '://www.w3.org/ns/activitystreams' ) );
			}
		}

		return $winner_is_ap;
	}
}

if ( \in_array( 'is_activitypub_request', $apstub_parts, true ) && ! \function_exists( 'Activitypub\\is_activitypub_request' ) ) {
	function is_activitypub_request() {
		$is = false;

		if ( isset( $GLOBALS['wp_query']->query_vars['activitypub'] ) || isset( $_GET['activitypub'] ) ) {
			$is = true;
		} elseif ( isset( $_SERVER['HTTP_ACCEPT'] ) ) {
			$is = accept_prefers_activitypub( \wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
		}

		return \apply_filters( 'activitypub_is_activitypub_request', $is );
	}
}

if ( \in_array( 'should_negotiate_content', $apstub_parts, true ) && ! \function_exists( 'Activitypub\\should_negotiate_content' ) ) {
	function should_negotiate_content() {
		return \apply_filters( 'activitypub_should_negotiate_content', $GLOBALS['apstub_negotiate'] ?? true );
	}
}

if ( \in_array( 'user_can_activitypub', $apstub_parts, true ) && ! \function_exists( 'Activitypub\\user_can_activitypub' ) ) {
	function user_can_activitypub( $user_id ) {
		if ( ! \is_numeric( $user_id ) ) {
			return false;
		}

		$enabled = \in_array( (int) $user_id, $GLOBALS['apstub_actors'] ?? array(), true );

		return \apply_filters( 'activitypub_user_can_activitypub', $enabled, $user_id );
	}
}
