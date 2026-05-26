<?php
/**
 * Lever: noindex internal search-result pages.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells search engines not to index the site's own /?s= search pages.
 *
 * WordPress's built-in search results are indexable by default. They're
 * thin pages (one query, a list of excerpts) with little value to
 * outsiders, and they're a known SEO-spam vector: an attacker hits
 * /?s=cheap+pills repeatedly, gets the page crawled, and ends up with
 * spammy keyword queries ranking under your domain. Adding noindex to
 * the search archive shuts that off cleanly.
 *
 * `follow` is intentionally kept so search engines still crawl the
 * links inside the results page if WordPress shows any.
 */
class Levers_Lever_Noindex_Search_Results extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'noindex-search-results';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Noindex internal search results', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Adds noindex to /?s= search pages. WordPress indexes them by default; spammers abuse them to rank junk URLs under your domain.', 'levers' );
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
		return 'search-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'wp_robots', array( $this, 'noindex_search' ) );
	}

	/**
	 * Inject noindex/follow into the robots meta tag on search pages.
	 *
	 * @param array $robots Robots directives keyed by name.
	 * @return array
	 */
	public function noindex_search( $robots ) {
		if ( ! is_array( $robots ) ) {
			$robots = array();
		}

		if ( is_search() ) {
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}

		return $robots;
	}
}
