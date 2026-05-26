<?php
/**
 * Lever: hide the WordPress version.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes the obvious places WordPress prints its version number.
 *
 * Hiding the version isn't a security fix on its own (a determined
 * attacker can fingerprint WordPress a dozen other ways), but it does
 * remove the free signposts that automated vulnerability scanners look
 * for first. Three places get stripped:
 *
 *   - <meta name="generator" content="WordPress X.Y"> in <head>.
 *   - <generator> elements in RSS / Atom feeds.
 *   - The ?ver=X.Y query string on core (/wp-includes/, /wp-admin/)
 *     CSS and JS URLs. Theme and plugin assets keep their ?ver= so
 *     cache-busting still works for things outside core.
 */
class Levers_Lever_Hide_Wp_Version extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'hide-wp-version';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Hide WordPress version', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Removes the generator meta tag, the ?ver= strings on core assets, and the version from feeds.', 'levers' );
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
		return 'eye-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// <meta name="generator"> in <head>.
		remove_action( 'wp_head', 'wp_generator' );

		// <generator> in RSS / Atom / RDF feeds.
		add_filter( 'the_generator', '__return_empty_string' );

		// ?ver= on core asset URLs only - leaves plugin/theme assets alone
		// so their cache-busting keeps working.
		add_filter( 'style_loader_src', array( $this, 'strip_ver_from_core_asset' ), 999 );
		add_filter( 'script_loader_src', array( $this, 'strip_ver_from_core_asset' ), 999 );
	}

	/**
	 * Strip ?ver=X.Y from a core asset URL, leave non-core URLs untouched.
	 *
	 * @param mixed $src Asset URL.
	 * @return mixed
	 */
	public function strip_ver_from_core_asset( $src ) {
		if ( ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		if ( false === strpos( $src, '/wp-includes/' )
			&& false === strpos( $src, '/wp-admin/' )
		) {
			return $src;
		}

		return remove_query_arg( 'ver', $src );
	}
}
