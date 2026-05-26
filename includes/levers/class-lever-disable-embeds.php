<?php
/**
 * Lever: disable oEmbed / embeds.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns off the WordPress oEmbed / embed system.
 *
 * WordPress ships a small JS file (`wp-embed.min.js`) plus a REST endpoint
 * and a couple of `<link>` tags so that *other* sites can iframe-embed
 * your posts. Most sites don't need this and never use it; turning the
 * whole cluster off removes a script request, an extra REST route, and
 * two head tags.
 *
 * What this lever switches off:
 *   - The `<link rel="alternate" type="application/json+oembed">` head tags.
 *   - The /wp-json/oembed/1.0/* REST endpoint.
 *   - The wp-embed front-end script.
 *   - TinyMCE's `wpembed` plugin.
 */
class Levers_Lever_Disable_Embeds extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-embeds';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable oEmbed/embeds', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Removes wp-embed.min.js and the embed REST endpoint if you don't embed external posts.", 'levers' );
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
		return 'frame';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Strip the oEmbed discovery <link> from <head>.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

		// Strip the oEmbed host JS (used by very old WP versions; harmless
		// to remove on newer ones).
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );

		// Don't register the /wp-json/oembed/1.0/* REST routes.
		remove_action( 'rest_api_init', 'wp_oembed_register_route' );

		// Drop the wp-embed front-end script.
		add_action( 'wp_print_scripts', array( $this, 'dequeue_embed_script' ), 100 );

		// Remove TinyMCE's embed plugin.
		add_filter( 'tiny_mce_plugins', array( $this, 'drop_embed_tinymce_plugin' ) );
	}

	/**
	 * Dequeue wp-embed.min.js if WordPress enqueued it.
	 *
	 * @return void
	 */
	public function dequeue_embed_script() {
		wp_dequeue_script( 'wp-embed' );
	}

	/**
	 * Remove the wpembed plugin from TinyMCE's plugin list.
	 *
	 * @param mixed $plugins Plugins registered with TinyMCE.
	 * @return array
	 */
	public function drop_embed_tinymce_plugin( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return array();
		}

		return array_values( array_diff( $plugins, array( 'wpembed' ) ) );
	}
}
