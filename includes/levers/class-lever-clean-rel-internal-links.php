<?php
/**
 * Lever: strip SEO-blocking rel tokens from links to your own site.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes SEO-hostile rel tokens from <a> tags that point to the same
 * site - nofollow, sponsored, ugc, noindex, noreferrer / referrer,
 * external, nocache, noarchive, nosnippet, notranslate.
 *
 * Why these specifically:
 *
 *   - nofollow / sponsored / ugc tell search engines not to count the
 *     link, which is fine on outbound links but throws away your own
 *     internal link equity. Page builders and SEO plugins routinely
 *     stamp them on every link.
 *
 *   - noreferrer (and the rarer "referrer") suppress the Referer header,
 *     which breaks attribution in your own analytics for clicks between
 *     your own pages.
 *
 *   - external is just wrong on an internal link; some plugins add it
 *     to every link in a list and the misclassification ripples into
 *     SEO tools.
 *
 *   - noindex / noarchive / nosnippet / notranslate / nocache aren't
 *     valid rel values at all - they're robots meta directives - but
 *     misconfigured plugins do stamp them onto rel, and there's no
 *     case for leaving them.
 *
 * What we keep: anything else, including noopener (important on
 * target="_blank"), me, prev, next, canonical, author, alternate.
 *
 * Outbound links are left strictly alone. The lever only ever touches
 * links whose host matches the site's host (or that are host-less,
 * i.e. root-relative, anchor or query-only).
 */
class Levers_Lever_Clean_Rel_Internal_Links extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'clean-rel-internal-links';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Clean rel on internal links', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Strips SEO-blocking rel tokens (nofollow, sponsored, ugc, noindex, noreferrer, external...) from internal links only.', 'levers' );
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
		return 'link-2';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		if ( is_admin() ) {
			return;
		}

		add_filter( 'the_content', array( $this, 'clean_links_in_html' ), 11 );
		add_filter( 'the_excerpt', array( $this, 'clean_links_in_html' ), 11 );
		add_filter( 'widget_text_content', array( $this, 'clean_links_in_html' ), 11 );
		add_filter( 'comment_text', array( $this, 'clean_links_in_html' ), 11 );
	}

	/**
	 * Tokens to strip whenever the host matches.
	 *
	 * @return array<int,string>
	 */
	private function blocked_tokens() {
		return array(
			'nofollow',
			'sponsored',
			'ugc',
			'noindex',
			'noreferrer',
			'referrer',
			'external',
			'nocache',
			'noarchive',
			'nosnippet',
			'notranslate',
		);
	}

	/* ---------------------------------------------------------------------
	 * HTML walker
	 * ------------------------------------------------------------------- */

	/**
	 * Filter callback: walk every <a ...> tag in the HTML.
	 *
	 * @param string $html Filterable HTML.
	 * @return string
	 */
	public function clean_links_in_html( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		if ( false === stripos( $html, '<a' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			'#<a\b[^>]*>#i',
			array( $this, 'process_anchor' ),
			$html
		);
	}

	/**
	 * Decide what to do with a single matched <a> opening tag.
	 *
	 * @param array $match preg_replace_callback match: [full].
	 * @return string
	 */
	private function process_anchor( $match ) {
		$tag = $match[0];

		$rel = $this->extract_attribute( $tag, 'rel' );

		if ( '' === $rel ) {
			return $tag;
		}

		$href = $this->extract_attribute( $tag, 'href' );

		if ( '' === $href ) {
			return $tag;
		}

		if ( ! $this->is_internal_href( $href ) ) {
			return $tag;
		}

		$cleaned = $this->clean_rel_value( $rel );

		if ( $cleaned === $rel ) {
			return $tag;
		}

		return $this->replace_rel( $tag, $cleaned );
	}

	/**
	 * Drop blocked tokens from a rel attribute value. Returns the
	 * remaining space-separated tokens (in their original order), or
	 * '' if nothing useful is left.
	 *
	 * @param string $rel Raw rel value, e.g. "noopener nofollow ugc".
	 * @return string
	 */
	private function clean_rel_value( $rel ) {
		$tokens = preg_split( '/\s+/', trim( $rel ) );

		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return '';
		}

		$blocked = array_flip( $this->blocked_tokens() );
		$kept    = array();
		$seen    = array();

		foreach ( $tokens as $token ) {
			if ( '' === $token ) {
				continue;
			}

			$lower = strtolower( $token );

			if ( isset( $blocked[ $lower ] ) ) {
				continue;
			}

			// De-duplicate while preserving order.
			if ( isset( $seen[ $lower ] ) ) {
				continue;
			}

			$seen[ $lower ] = true;
			$kept[]         = $token;
		}

		return implode( ' ', $kept );
	}

	/**
	 * Swap the rel attribute's value in a tag, or remove the attribute
	 * entirely when the new value is empty.
	 *
	 * @param string $tag      Original <a ...> tag.
	 * @param string $new_value New rel value.
	 * @return string
	 */
	private function replace_rel( $tag, $new_value ) {
		// Match `rel="..."`, `rel='...'`, or `rel=value` (bare).
		$pattern = '/(\s)rel\s*=\s*(["\'])(.*?)\2|(\s)rel\s*=\s*([^\s>]+)/i';

		if ( '' === $new_value ) {
			// Drop the whole attribute, keeping the leading whitespace
			// match so we don't collapse two attributes together.
			return (string) preg_replace( $pattern, '', $tag, 1 );
		}

		$replacement = '$1rel="' . $new_value . '"$4';

		return (string) preg_replace( $pattern, $replacement, $tag, 1 );
	}

	/* ---------------------------------------------------------------------
	 * Internal-link detection
	 * ------------------------------------------------------------------- */

	/**
	 * Whether an href points to the current site.
	 *
	 * Treats relative paths, fragments, and query-only links as internal.
	 * Treats javascript:, mailto:, tel:, sms: and other non-http schemes
	 * as external (we have nothing to gain by touching them).
	 *
	 * @param string $href href attribute value.
	 * @return bool
	 */
	private function is_internal_href( $href ) {
		$href = trim( html_entity_decode( $href ) );

		if ( '' === $href ) {
			return false;
		}

		// Fragment-only, query-only and root-relative are always internal.
		if ( '#' === $href[0] || '?' === $href[0] || '/' === $href[0] ) {
			return true;
		}

		// Non-http schemes (mailto:, tel:, javascript:, sms:, data:...).
		if ( preg_match( '#^[a-z][a-z0-9+\-.]*:#i', $href ) ) {
			$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );

			if ( 'http' !== $scheme && 'https' !== $scheme && '' !== $scheme ) {
				return false;
			}
		}

		$host = strtolower( (string) wp_parse_url( $href, PHP_URL_HOST ) );

		if ( '' === $host ) {
			// No host means a relative URL we haven't already early-returned;
			// treat as internal.
			return true;
		}

		$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

		return $host === $site_host;
	}

	/* ---------------------------------------------------------------------
	 * Attribute helpers (same idea as add-missing-image-dimensions)
	 * ------------------------------------------------------------------- */

	/**
	 * Extract a quoted or unquoted attribute value, or empty string.
	 *
	 * @param string $tag  Whole tag.
	 * @param string $name Attribute name.
	 * @return string
	 */
	private function extract_attribute( $tag, $name ) {
		$quoted = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*(["\'])(.*?)\1/i';

		if ( preg_match( $quoted, $tag, $m ) ) {
			return $m[2];
		}

		$bare = '/\s' . preg_quote( $name, '/' ) . '\s*=\s*([^\s>]+)/i';

		if ( preg_match( $bare, $tag, $m ) ) {
			return $m[1];
		}

		return '';
	}
}
