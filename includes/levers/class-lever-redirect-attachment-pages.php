<?php
/**
 * Lever: redirect attachment pages.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Redirects WordPress's standalone attachment pages so they stop existing
 * as crawlable, near-empty pages.
 *
 * For every uploaded file WordPress generates a public attachment page
 * (e.g. /my-post/some-image/). For most sites these pages are pure noise:
 * one image, no content, no value to visitors, and a constant nuisance to
 * search engines that index them as thin pages.
 *
 *   - Attachment has a post parent  -> 301 to that parent's permalink.
 *   - No parent                      -> 302 to the home page.
 *
 * 301 for the parent case because the relationship is permanent and we
 * want search engines to consolidate the URL. 302 for the home fallback
 * because we never want a parent-less attachment URL permanently mapped
 * to the home page.
 */
class Levers_Lever_Redirect_Attachment_Pages extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'redirect-attachment-pages';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Redirect attachment pages', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Redirects attachment pages to the parent post (301) if there is one, otherwise to the home page (302). Stops empty image pages being indexed.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'seo';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'paperclip';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'template_redirect', array( $this, 'redirect_attachment' ) );
	}

	/**
	 * Send attachment-page requests to the parent post, or home if none.
	 *
	 * @return void
	 */
	public function redirect_attachment() {
		if ( ! is_attachment() ) {
			return;
		}

		$attachment = get_queried_object();

		if ( ! $attachment instanceof WP_Post ) {
			return;
		}

		if ( $attachment->post_parent > 0 ) {
			$parent_url = get_permalink( $attachment->post_parent );

			if ( $parent_url ) {
				wp_safe_redirect( $parent_url, 301 );
				exit;
			}
		}

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}
}
