<?php
/**
 * Lever: block WordPress user enumeration.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closes the two public ways an attacker can harvest WordPress usernames.
 *
 *   1. The classic `?author=N` trick - hitting that URL on a default
 *      install makes WordPress 301 to /author/{username}/, leaking the
 *      login slug for user N.
 *   2. The REST API users endpoints at /wp-json/wp/v2/users and
 *      /wp-json/wp/v2/users/{id}, which by default return the slug,
 *      display name and avatar of every user who has published a post.
 *
 * Pairs directly with the login-attempts limiter: with usernames blinded
 * here, the brute-forcer doesn't even know what to throw at the login.
 *
 * Logged-in users keep both paths working - the block editor needs the
 * REST users endpoint for author dropdowns and similar features.
 */
class Levers_Lever_Block_User_Enumeration extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'block-user-enumeration';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Block user enumeration', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Blocks ?author=N enumeration and locks /wp-json/wp/v2/users for logged-out visitors. Stops usernames being harvested.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'security';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'user-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// 1. Block ?author=N redirects before they reach the template.
		add_action( 'template_redirect', array( $this, 'block_author_query' ), 1 );

		// 2. Drop the REST users endpoints for unauthenticated requests.
		add_filter( 'rest_endpoints', array( $this, 'lock_rest_users' ) );
	}

	/**
	 * Send anonymous ?author=N requests to the home page instead of
	 * letting WordPress redirect to /author/{username}/.
	 *
	 * @return void
	 */
	public function block_author_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check on a public query var.
		if ( empty( $_GET['author'] ) ) {
			return;
		}

		// Don't interfere when the admin is loading the dashboard or for
		// any authenticated user (block editor uses author lookups).
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Remove the public-facing /wp/v2/users routes for guests.
	 *
	 * @param array $endpoints Registered REST routes.
	 * @return array
	 */
	public function lock_rest_users( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		$blocked = array(
			'/wp/v2/users',
			'/wp/v2/users/(?P<id>[\d]+)',
		);

		foreach ( $blocked as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}

		return $endpoints;
	}
}
