<?php
/**
 * Lever: disable per-post feeds.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirects single-post feed URLs (the `/feed/` appended to each post's
 * permalink) back to the post itself.
 *
 * These per-post feeds are the comments-feed for one post. They're rarely
 * used by real visitors, regularly scraped by bots, and provide a second
 * place the content can leak from. Sending them straight to the post
 * removes the endpoint without breaking the site-wide /feed/.
 */
class Levers_Lever_Disable_Post_Feeds extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-post-feeds';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable per-post feeds', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Redirects each post's /feed/ URL back to the post itself. Removes a rarely-used endpoint that mostly serves scrapers.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'wordpress-cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'rss';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'template_redirect', array( $this, 'redirect_post_feed' ) );
	}

	/**
	 * Send a single-post feed request to the post's permalink.
	 *
	 * 302 (not 301) so toggling the lever back off takes effect immediately
	 * without browsers caching a permanent redirect.
	 *
	 * @return void
	 */
	public function redirect_post_feed() {
		if ( ! is_feed() || ! is_singular( 'post' ) ) {
			return;
		}

		$permalink = get_permalink();

		if ( ! $permalink ) {
			return;
		}

		wp_safe_redirect( $permalink, 302 );
		exit;
	}
}
