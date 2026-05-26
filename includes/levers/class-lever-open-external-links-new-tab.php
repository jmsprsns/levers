<?php
/**
 * Lever: open external links in a new tab.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds `target="_blank"` and `rel="noopener"` to every link in filtered
 * content that points off-site.
 *
 * Design notes:
 *   - Idempotent. A link that already has a `target` attribute (any value,
 *     even empty) is left alone, so Yoast / Rank Math / RankMath SEO /
 *     anything else that runs the same trick won't end up duplicating or
 *     fighting each other.
 *   - `rel` is *merged*, not replaced. Existing `nofollow`, `sponsored`,
 *     `ugc`, etc. survive - we only add `noopener` if it isn't already
 *     in the list. This means an SEO plugin's `nofollow` work is
 *     preserved.
 *   - Only http(s) URLs are touched. Mailto, tel, javascript, hash and
 *     relative URLs are explicitly skipped (the first three because
 *     they aren't "external" in the new-tab sense, the rest because
 *     they're same-site).
 *   - Host comparison strips a leading `www.` from both sides, so
 *     example.com and www.example.com match.
 */
class Levers_Lever_Open_External_Links_New_Tab extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'open-external-links-new-tab';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Open external links in a new tab', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Opens external links in a new tab (with rel='noopener' for safety). Idempotent so other SEO plugins can pile on without fighting it.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'frontend';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'external-link';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		foreach ( array( 'the_content', 'the_excerpt', 'widget_text', 'comment_text' ) as $hook ) {
			add_filter( $hook, array( $this, 'process_content' ), 12 );
		}
	}

	/**
	 * Walk every <a ...> tag in the content and apply the rewrite.
	 *
	 * @param mixed $content HTML content.
	 * @return mixed
	 */
	public function process_content( $content ) {
		if ( ! is_string( $content ) || false === stripos( $content, '<a ' ) ) {
			return $content;
		}

		$result = preg_replace_callback(
			'/<a\s+([^>]+?)>/i',
			array( $this, 'rewrite_link' ),
			$content
		);

		return ( null === $result ) ? $content : $result;
	}

	/**
	 * Decide whether a single <a> tag should be rewritten, and do so.
	 *
	 * @param array $matches Regex matches; $matches[1] is the attribute soup.
	 * @return string
	 */
	public function rewrite_link( $matches ) {
		$attrs = $matches[1];

		// Already has a target= attribute? Leave the author's choice alone.
		if ( preg_match( '/\btarget\s*=\s*["\']/i', $attrs ) ) {
			return $matches[0];
		}

		// Need a real href to inspect.
		if ( ! preg_match( '/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $href_match ) ) {
			return $matches[0];
		}

		$href = trim( $href_match[2] );

		if ( '' === $href ) {
			return $matches[0];
		}

		// Skip non-http(s): mailto, tel, javascript, #anchor, relative paths.
		if ( ! preg_match( '#^https?://#i', $href ) ) {
			return $matches[0];
		}

		if ( ! $this->is_external_url( $href ) ) {
			return $matches[0];
		}

		$attrs = rtrim( $attrs ) . ' target="_blank"';
		$attrs = $this->merge_rel( $attrs, array( 'noopener' ) );

		return '<a ' . $attrs . '>';
	}

	/**
	 * Whether a URL points off-site.
	 *
	 * @param string $url Absolute http(s) URL.
	 * @return bool
	 */
	private function is_external_url( $url ) {
		$parts = wp_parse_url( $url );

		if ( empty( $parts['host'] ) ) {
			return false;
		}

		$home = wp_parse_url( home_url() );

		if ( empty( $home['host'] ) ) {
			return false;
		}

		$host = preg_replace( '/^www\./i', '', strtolower( $parts['host'] ) );
		$home = preg_replace( '/^www\./i', '', strtolower( $home['host'] ) );

		return $host !== $home;
	}

	/**
	 * Merge values into a tag's rel="..." attribute, preserving anything
	 * that's already there (nofollow, sponsored, ugc, ...).
	 *
	 * @param string   $attrs    Existing attribute soup of the <a> tag.
	 * @param string[] $additions Rel values to ensure are present.
	 * @return string
	 */
	private function merge_rel( $attrs, $additions ) {
		if ( preg_match( '/\brel\s*=\s*(["\'])(.*?)\1/i', $attrs, $match ) ) {
			$existing = preg_split( '/\s+/', trim( $match[2] ), -1, PREG_SPLIT_NO_EMPTY );
			$merged   = array_values( array_unique( array_merge( $existing, $additions ) ) );

			return preg_replace(
				'/\brel\s*=\s*(["\'])(.*?)\1/i',
				'rel="' . esc_attr( implode( ' ', $merged ) ) . '"',
				$attrs,
				1
			);
		}

		return rtrim( $attrs ) . ' rel="' . esc_attr( implode( ' ', $additions ) ) . '"';
	}
}
