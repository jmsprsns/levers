<?php
/**
 * Lever: fix insecure content.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites insecure http:// resource URLs to https://.
 *
 * When a page is served over HTTPS, any resource still loaded over plain HTTP
 * (an image, script, iframe, stylesheet, and so on) is "mixed content" - the
 * browser blocks it or warns about it. This lever rewrites those resource URLs
 * to https:// in post content and in enqueued scripts and styles.
 *
 * It only runs on requests that are themselves served over HTTPS, since mixed
 * content cannot occur on a plain HTTP page.
 */
class Levers_Lever_Fix_Insecure_Content extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'fix-insecure-content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Fix insecure content', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Rewrites http:// resource URLs to https:// in post content and enqueued assets so secure pages stop showing mixed-content warnings.', 'levers' );
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
	public function is_available() {
		return ! self::is_local_environment();
	}

	/**
	 * {@inheritDoc}
	 */
	public function unavailable_reason() {
		return __( 'Disabled on local development sites.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'globe-lock';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Mixed content can only occur on a page served over HTTPS.
		if ( ! $this->is_secure_request() ) {
			return;
		}

		$content_hooks = array(
			'the_content',
			'the_excerpt',
			'widget_text',
			'widget_block_content',
			'post_thumbnail_html',
		);

		foreach ( $content_hooks as $hook ) {
			// Priority 99 so this runs after shortcodes and embeds have
			// produced their markup.
			add_filter( $hook, array( $this, 'fix_content' ), 99 );
		}

		// Enqueued scripts and stylesheets.
		add_filter( 'script_loader_src', array( $this, 'fix_asset_url' ), 999 );
		add_filter( 'style_loader_src', array( $this, 'fix_asset_url' ), 999 );
	}

	/**
	 * Rewrite http:// to https:// inside src and srcset attributes.
	 *
	 * Only resource attributes are touched - navigation links (<a href>) are
	 * left alone, since they do not cause mixed content.
	 *
	 * @param mixed $html Filtered HTML.
	 * @return mixed
	 */
	public function fix_content( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'http://' ) ) {
			return $html;
		}

		$fixed = preg_replace_callback(
			'#\b(srcset|src)(\s*=\s*)(["\'])(.*?)\3#is',
			array( $this, 'rewrite_attribute' ),
			$html
		);

		return ( null === $fixed ) ? $html : $fixed;
	}

	/**
	 * Replace http:// with https:// within a matched attribute value.
	 *
	 * @param array $matches Regex matches: 1 name, 2 "=", 3 quote, 4 value.
	 * @return string
	 */
	public function rewrite_attribute( $matches ) {
		$value = str_replace( 'http://', 'https://', $matches[4] );

		return $matches[1] . $matches[2] . $matches[3] . $value . $matches[3];
	}

	/**
	 * Rewrite an enqueued asset URL from http:// to https://.
	 *
	 * @param mixed $src Asset URL.
	 * @return mixed
	 */
	public function fix_asset_url( $src ) {
		if ( is_string( $src ) && 0 === strpos( $src, 'http://' ) ) {
			$src = 'https://' . substr( $src, 7 );
		}

		return $src;
	}

	/**
	 * Whether the current request is being served over HTTPS.
	 *
	 * Also accepts a reverse proxy reporting HTTPS upstream.
	 *
	 * @return bool
	 */
	private function is_secure_request() {
		if ( is_ssl() ) {
			return true;
		}

		return isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
			&& 'https' === strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
	}
}
