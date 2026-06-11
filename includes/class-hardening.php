<?php
/**
 * BotCreds Agent Memory — Hardening Module
 *
 * Locks down the WordPress install so it behaves as an API-only backend.
 * Disabled by default. Enable via Agent Memory > Settings > Site Hardening, or by adding:
 *   define( 'BOTCREDS_MEMORY_HARDENED', true );
 * to wp-config.php, or by setting the option:
 *   wp option update botcreds_memory_hardened 1
 *
 * What this class does:
 *   1. Strips /wp/v2/users endpoints for unauthenticated requests
 *   2. Walls off HTML front-end — 403 for anon, passes /wp-json/ and /wp-login.php through
 *   3. Author archives and feeds → 403
 *   4. Disables XML-RPC
 *   5. Forces comments closed
 *   6. Security headers: nosniff, X-Frame-Options, Referrer-Policy, tight CSP
 *   7. Noindex/nofollow/noarchive meta tag
 *   8. On activation: sets blog_public=0, default_comment_status=closed
 *   9. On activation: deletes Hello World post and Sample Page
 *
 * @package BotCreds_Agent_Memory
 * @since   2.0.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Botcreds_Memory_Hardening
 */
class Botcreds_Memory_Hardening {

	/**
	 * Check whether hardened mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		// Constant takes precedence.
		if ( defined( 'BOTCREDS_MEMORY_HARDENED' ) ) {
			return (bool) BOTCREDS_MEMORY_HARDENED;
		}
		return (bool) get_option( 'botcreds_memory_hardened', 0 );
	}

	/**
	 * Register all hardening hooks.
	 */
	public static function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// 1. Strip user enumeration endpoints for unauthenticated requests.
		add_filter( 'rest_endpoints', [ __CLASS__, 'strip_user_endpoints' ], 99 );

		// 2. Front-end login wall — passes /wp-json/ and /wp-login.php through.
		add_action( 'template_redirect', [ __CLASS__, 'frontend_login_wall' ], 1 );

		// 3. Block author archives and feeds.
		add_action( 'template_redirect', [ __CLASS__, 'block_author_and_feeds' ], 2 );

		// 4. Disable XML-RPC.
		add_filter( 'xmlrpc_enabled', '__return_false' );

		// 5. Force comments closed.
		add_filter( 'pre_option_default_comment_status', '__return_zero' );

		// 6. Security headers.
		add_action( 'send_headers', [ __CLASS__, 'send_security_headers' ] );

		// 7. Noindex meta tag.
		add_action( 'wp_head', [ __CLASS__, 'noindex_meta' ] );
	}

	/**
	 * 1. Remove /wp/v2/users endpoints from the REST map for anon requests.
	 *
	 * @param array $endpoints Registered REST endpoints.
	 * @return array
	 */
	public static function strip_user_endpoints( array $endpoints ): array {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'] );
			unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		}
		return $endpoints;
	}

	/**
	 * 2. Wall off the HTML front-end from unauthenticated access.
	 *    /wp-json/ and /wp-login.php pass through so the REST API and login remain reachable.
	 */
	public static function frontend_login_wall(): void {
		if ( is_user_logged_in() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		if (
			0 === strpos( $uri, '/wp-json/' ) ||
			0 === strpos( $uri, '/wp-login.php' ) ||
			0 === strpos( $uri, '/wp-admin/' )
		) {
			return;
		}

		wp_die(
			esc_html__( 'Private site. Agent API access only.', 'botcreds-agent-memory' ),
			esc_html__( 'Forbidden', 'botcreds-agent-memory' ),
			[ 'response' => 403 ]
		);
	}

	/**
	 * 3. Block author archive pages and feeds — return 403.
	 */
	public static function block_author_and_feeds(): void {
		if ( is_author() || is_feed() ) {
			wp_die(
				esc_html__( 'Forbidden', 'botcreds-agent-memory' ),
				esc_html__( 'Forbidden', 'botcreds-agent-memory' ),
				[ 'response' => 403 ]
			);
		}
	}

	/**
	 * 6. Send security headers on every response.
	 */
	public static function send_security_headers(): void {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: DENY' );
		header( 'Referrer-Policy: no-referrer' );
		header( "Content-Security-Policy: default-src 'none'" );
		header( 'Permissions-Policy: interest-cohort=()' );
	}

	/**
	 * 7. Output a noindex/nofollow/noarchive robots meta tag.
	 */
	public static function noindex_meta(): void {
		echo '<meta name="robots" content="noindex,nofollow,noarchive" />' . "\n";
	}

	/**
	 * Run once on plugin activation:
	 *   - Set blog_public = 0 (discourage search engines)
	 *   - Set default_comment_status = closed
	 *   - Delete Hello World post and Sample Page
	 */
	public static function on_activation(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		// Discourage search engines.
		update_option( 'blog_public', 0 );

		// Close comments by default.
		update_option( 'default_comment_status', 'closed' );

		// Delete Hello World post (slug: hello-world).
		$hello = get_page_by_path( 'hello-world', OBJECT, 'post' );
		if ( $hello ) {
			wp_delete_post( $hello->ID, true );
		}

		// Delete Sample Page (slug: sample-page).
		$sample = get_page_by_path( 'sample-page', OBJECT, 'page' );
		if ( $sample ) {
			wp_delete_post( $sample->ID, true );
		}
	}
}
