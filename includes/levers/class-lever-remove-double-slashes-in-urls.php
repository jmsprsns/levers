<?php
/**
 * Lever: collapse stray double slashes in URLs found in content.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cleans up duplicate slashes inside URL paths in href, src, action,
 * formaction and poster attributes.
 *
 * Ahrefs flags this as the "Double slash in URL" error; Google's
 * docs call it out as usually a website bug. The classic source is
 * something like trailingslashit( $site_url ) . '/about', which
 * produces /about preceded by an extra slash, giving the crawler
 * two paths for the same page.
 *
 * What we touch:
 *
 *   - Absolute http(s) URLs: //+ inside the PATH only is collapsed.
 *     Scheme (the legitimate ://), query string, and fragment are
 *     left alone.
 *
 *   - Root-relative URLs (/blog//post-name): same path collapse.
 *
 *   - Protocol-relative URLs (//cdn.example.com/asset): host kept,
 *     path collapsed. The leading // is the protocol marker and
 *     stays as is.
 *
 * What we leave alone:
 *
 *   - URLs in plain text or code samples - we only edit values of
 *     known URL attributes, so a <pre> block of example code stays
 *     verbatim.
 *
 *   - mailto:, tel:, sms:, javascript:, data: and anything else
 *     with a non-http scheme.
 *
 *   - srcset values (multi-URL with descriptors) - rare source of
 *     the error and risky to parse on the fly.
 */
class Levers_Lever_Remove_Double_Slashes_In_Urls extends Levers_Lever {

	/** Attributes we scan for URLs. */
	const URL_ATTRIBUTES = 'href|src|action|formaction|poster';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-double-slashes-in-urls';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove double slashes from URLs', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Collapses double slashes in URL paths inside links and images. Resolves the "Double slash in URL" issue some SEO tools flag.', 'levers' );
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
		return 'merge';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( is_admin() ) {
			return;
		}

		add_filter( 'the_content', array( $this, 'clean_urls_in_html' ), 11 );
		add_filter( 'the_excerpt', array( $this, 'clean_urls_in_html' ), 11 );
		add_filter( 'widget_text_content', array( $this, 'clean_urls_in_html' ), 11 );
		add_filter( 'comment_text', array( $this, 'clean_urls_in_html' ), 11 );
	}

	/* ---------------------------------------------------------------------
	 * HTML walker
	 * ------------------------------------------------------------------- */

	/**
	 * Filter callback: rewrite URL-bearing attribute values in $html.
	 *
	 * @param string $html Filterable HTML.
	 * @return string
	 */
	public function clean_urls_in_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// Fast-path: if there's no '//' anywhere, nothing to do.
		if ( false === strpos( $html, '//' ) ) {
			return $html;
		}

		$pattern = '#\b(' . self::URL_ATTRIBUTES . ')(\s*=\s*)(["\'])(.*?)\3#i';

		return (string) preg_replace_callback(
			$pattern,
			array( $this, 'rewrite_attribute' ),
			$html
		);
	}

	/**
	 * Replace one matched attribute's value with a cleaned version.
	 *
	 * @param array $m Regex match: [full, name, eq, quote, value].
	 * @return string
	 */
	private function rewrite_attribute( $m ) {
		$cleaned = $this->clean_url( $m[4] );

		return $m[1] . $m[2] . $m[3] . $cleaned . $m[3];
	}

	/* ---------------------------------------------------------------------
	 * URL cleanup
	 * ------------------------------------------------------------------- */

	/**
	 * Collapse repeated slashes in a single URL's path.
	 *
	 * Splits the URL into:
	 *   head  = scheme://host[:port]  (or //host for protocol-relative; '' for root-relative)
	 *   path  = up to ? or # boundary
	 *   rest  = ? query ... # fragment ...
	 *
	 * Only path gets the //+ -> / treatment.
	 *
	 * @param string $url Raw attribute value.
	 * @return string
	 */
	private function clean_url( $url ) {
		if ( '' === $url ) {
			return $url;
		}

		// Non-http(s) schemes: mailto:, tel:, sms:, javascript:, data:...
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $url ) && ! preg_match( '#^https?:#i', $url ) ) {
			return $url;
		}

		// Carve off the head (scheme + authority) so we don't touch the ://
		// or collapse the leading // of a protocol-relative URL.
		$head = '';
		$tail = $url;

		if ( preg_match( '~^(https?://[^/?#]+)(.*)$~i', $url, $m ) ) {
			$head = $m[1];
			$tail = $m[2];
		} elseif ( 0 === strpos( $url, '//' ) ) {
			if ( preg_match( '~^(//[^/?#]+)(.*)$~', $url, $m ) ) {
				$head = $m[1];
				$tail = $m[2];
			}
		}

		// Split tail at first ? or #.
		$boundary = strcspn( $tail, '?#' );
		$path     = substr( $tail, 0, $boundary );
		$rest     = (string) substr( $tail, $boundary );

		if ( '' === $path || false === strpos( $path, '//' ) ) {
			return $url;
		}

		$cleaned_path = (string) preg_replace( '#/{2,}#', '/', $path );

		return $head . $cleaned_path . $rest;
	}
}
