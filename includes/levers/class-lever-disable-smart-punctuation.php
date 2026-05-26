<?php
/**
 * Lever: disable smart punctuation (and clean up curly quotes).
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stops WordPress's smart-punctuation conversion and straightens any curly
 * quotes that are already in your content.
 *
 * Two layers, one lever:
 *
 *   1. wptexturize() is short-circuited via the `run_wptexturize` filter,
 *      so straight quotes stay straight and `--` / `---` stay as plain
 *      dashes - no matter which hook would have run wptexturize.
 *   2. Filtered text output (titles, content, excerpts, widgets, comments)
 *      also gets a curly-quote -> straight-quote pass at priority 99, so
 *      any curlies that slipped in from a paste, an old database row, or
 *      another plugin are cleaned up at render time too.
 */
class Levers_Lever_Disable_Smart_Punctuation extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-smart-punctuation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable smart punctuation', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Turns off WordPress auto-converting quotes and dashes to smart variants, and replaces any curly quotes already in your content.', 'levers' );
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
		return 'pilcrow';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// 1. Stop wptexturize() from running anywhere.
		add_filter( 'run_wptexturize', '__return_false' );

		// 2. Clean up curlies that are already in the text (typed in by
		//    the author, pasted from a word processor, or saved before
		//    this lever was switched on).
		$hooks = array( 'the_title', 'the_content', 'the_excerpt', 'get_the_excerpt', 'widget_text', 'comment_text' );

		foreach ( $hooks as $hook ) {
			add_filter( $hook, array( $this, 'straighten_curly_quotes' ), 99 );
		}
	}

	/**
	 * Replace every curly-quote variant (character or HTML entity) with
	 * its straight counterpart.
	 *
	 * @param mixed $text Filtered text.
	 * @return mixed
	 */
	public function straighten_curly_quotes( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		// Singles: U+2018..U+201B, U+2032 (prime), plus HTML entities.
		$singles = array(
			"\u{2018}", "\u{2019}", "\u{201A}", "\u{201B}", "\u{2032}",
			'&lsquo;', '&rsquo;', '&sbquo;', '&prime;',
			'&#8216;', '&#8217;', '&#8218;', '&#8242;',
			'&#x2018;', '&#x2019;', '&#x201A;', '&#x2032;',
		);

		// Doubles: U+201C..U+201F, U+2033 (double prime), plus entities.
		$doubles = array(
			"\u{201C}", "\u{201D}", "\u{201E}", "\u{201F}", "\u{2033}",
			'&ldquo;', '&rdquo;', '&bdquo;', '&Prime;',
			'&#8220;', '&#8221;', '&#8222;', '&#8243;',
			'&#x201C;', '&#x201D;', '&#x201E;', '&#x2033;',
		);

		$text = str_replace( $singles, "'", $text );
		$text = str_replace( $doubles, '"', $text );

		return $text;
	}
}
