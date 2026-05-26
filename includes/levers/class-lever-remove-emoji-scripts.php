<?php
/**
 * Lever: remove WordPress's emoji scripts.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Disables WordPress's emoji-compatibility shim.
 *
 * WordPress ships `wp-emoji-release.min.js` (plus inline CSS, a TinyMCE
 * plugin, RSS / mail rewriters, and a DNS prefetch to s.w.org for Twemoji
 * images) to backfill emoji support on browsers that don't render the
 * native ones. Every browser still in support since ~2017 renders them
 * fine, so on a modern site the shim is dead weight on every page load.
 */
class Levers_Lever_Remove_Emoji_Scripts extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'remove-emoji-scripts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Remove emoji scripts', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Stops wp-emoji-release.min.js and its inline CSS loading, plus the DNS prefetch to s.w.org. Kills an external request modern browsers don't need.", 'levers' );
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
		return 'smile';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Front-end JS + CSS.
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );

		// Admin JS + CSS.
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );

		// Feeds, comments and outgoing mail.
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		// TinyMCE's emoji button.
		add_filter( 'tiny_mce_plugins', array( $this, 'drop_tinymce_emoji_plugin' ) );

		// The <link rel="dns-prefetch" href="//s.w.org"> in the head.
		add_filter( 'wp_resource_hints', array( $this, 'drop_emoji_dns_prefetch' ), 10, 2 );
	}

	/**
	 * Remove the wpemoji plugin from TinyMCE's plugin list.
	 *
	 * @param mixed $plugins Plugins registered with TinyMCE.
	 * @return array
	 */
	public function drop_tinymce_emoji_plugin( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return array();
		}

		return array_values( array_diff( $plugins, array( 'wpemoji' ) ) );
	}

	/**
	 * Drop the DNS prefetch to s.w.org that core adds for Twemoji images.
	 *
	 * @param array  $urls          Resource-hint URLs.
	 * @param string $relation_type Hint type (dns-prefetch, preconnect, etc.).
	 * @return array
	 */
	public function drop_emoji_dns_prefetch( $urls, $relation_type ) {
		if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
			return $urls;
		}

		foreach ( $urls as $key => $url ) {
			$href = is_array( $url ) ? ( isset( $url['href'] ) ? $url['href'] : '' ) : $url;

			if ( is_string( $href ) && false !== strpos( $href, 's.w.org' ) ) {
				unset( $urls[ $key ] );
			}
		}

		return array_values( $urls );
	}
}
