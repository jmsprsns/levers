<?php
/**
 * Lever: replace em-dashes with a regular dash.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Swaps em-dashes for a plain hyphen across front-end content.
 *
 * Em-dashes are a common tell of AI-generated writing, so replacing them
 * helps published content read as hand-written.
 */
class Levers_Lever_Replace_Em_Dashes extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'replace-em-dashes';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Replace em-dashes with a regular dash sitewide', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Swaps em-dashes for a regular hyphen in titles, content, excerpts and comments. A common AI-writing tell.', 'levers' );
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
		return 'type';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		$hooks = array( 'the_title', 'the_content', 'the_excerpt', 'get_the_excerpt', 'widget_text', 'comment_text' );

		foreach ( $hooks as $hook ) {
			// Priority 99 so this runs after wptexturize, which can itself
			// turn "---" into an em-dash.
			add_filter( $hook, array( $this, 'replace_em_dashes' ), 99 );
		}
	}

	/**
	 * Replace em-dashes (literal character and HTML entities) with a hyphen.
	 *
	 * @param mixed $text Filtered text.
	 * @return mixed
	 */
	public function replace_em_dashes( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		$em_dashes = array(
			"\u{2014}", // The literal em-dash character.
			'&mdash;',
			'&#8212;',
			'&#x2014;',
			'&#X2014;',
		);

		return str_replace( $em_dashes, '-', $text );
	}
}
