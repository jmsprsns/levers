<?php
/**
 * Lever: force font-display:swap on Google Fonts.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites enqueued Google Fonts stylesheet URLs so they carry
 * `display=swap`, keeping text visible during webfont load.
 *
 * By default a browser hides text styled with a not-yet-downloaded webfont
 * for up to ~3 seconds (the "flash of invisible text", FOIT). Google Fonts
 * honours a `display` query parameter that maps to the CSS `font-display`
 * descriptor; `swap` tells the browser to render the text immediately in a
 * fallback face and swap in the webfont once it arrives. That trades a
 * brief style flash for text that is never invisible - better for both
 * perceived performance and Core Web Vitals.
 *
 * Mechanism: we filter `style_loader_src`, the URL of every enqueued
 * stylesheet, and for the two Google Fonts endpoints
 * (`fonts.googleapis.com/css` and `/css2`) we add `display=swap`, or
 * overwrite an existing `display` value that isn't already `swap`. Themes
 * and plugins that enqueue their fonts through `wp_enqueue_style()` - the
 * overwhelmingly common case - are covered automatically, without the
 * site owner editing any code.
 */
class Levers_Lever_Swap_Google_Fonts_Display extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'swap-google-fonts-display';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Swap Google Fonts display', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Adds display=swap to Google Fonts stylesheets so text stays visible while the webfont loads. Avoids the flash of invisible text and helps Core Web Vitals.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'performance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'type';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'style_loader_src', array( $this, 'add_display_swap' ), 10, 2 );
	}

	/**
	 * Ensure a Google Fonts stylesheet URL carries display=swap.
	 *
	 * @param mixed  $src    Stylesheet URL.
	 * @param string $handle Style handle (unused, kept for the filter signature).
	 * @return mixed The URL, with display=swap added or corrected when it
	 *               points at Google Fonts; otherwise unchanged.
	 */
	public function add_display_swap( $src, $handle = '' ) {
		unset( $handle );

		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		$host = wp_parse_url( $src, PHP_URL_HOST );

		if ( 'fonts.googleapis.com' !== $host ) {
			return $src;
		}

		$path = (string) wp_parse_url( $src, PHP_URL_PATH );

		// Only the CSS endpoints take a display parameter.
		if ( '/css' !== $path && '/css2' !== $path ) {
			return $src;
		}

		// Already correct - leave it alone.
		if ( preg_match( '/([?&])display=swap(&|$)/', $src ) ) {
			return $src;
		}

		// Overwrite any other display value (auto, block, fallback, optional).
		if ( preg_match( '/([?&])display=[^&]*/', $src ) ) {
			return preg_replace( '/([?&])display=[^&]*/', '${1}display=swap', $src );
		}

		// No display parameter yet - append one.
		$separator = ( false === strpos( $src, '?' ) ) ? '?' : '&';

		return $src . $separator . 'display=swap';
	}
}
