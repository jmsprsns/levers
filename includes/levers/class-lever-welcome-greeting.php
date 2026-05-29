<?php
/**
 * Lever: friendlier admin greeting.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the toolbar's "Howdy, {Name}" with "Welcome back, {Name}!".
 */
class Levers_Lever_Welcome_Greeting extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'welcome-greeting';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Change "Howdy" to "Welcome back"', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Swaps the "Howdy" greeting in the top admin toolbar for a warmer "Welcome back" - shown wherever the toolbar appears.', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'branding';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'hand';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_filter( 'gettext', array( $this, 'filter_greeting' ), 10, 3 );
	}

	/**
	 * Rewrite the core "Howdy, %s" string before it is used.
	 *
	 * Matching on the untranslated source string keeps this working
	 * regardless of the site's language.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Original (source) text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function filter_greeting( $translation, $text, $domain ) {
		if ( 'default' === $domain && 'Howdy, %s' === $text ) {
			return __( 'Welcome back, %s!', 'levers' );
		}

		return $translation;
	}
}
