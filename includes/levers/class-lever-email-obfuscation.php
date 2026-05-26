<?php
/**
 * Lever: obfuscate email addresses in rendered content.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites email addresses so naive harvesters can't scrape them.
 *
 * Uses WordPress's built-in `antispambot()`, which encodes each character
 * as a random mix of decimal / hex HTML entities (about half the time it
 * leaves a character alone). Browsers render the output exactly like the
 * original; a plain-text regex scrape of the page source sees almost
 * nothing it can parse as an email.
 *
 * Hooks the standard text-output filters - the same set the curly-quote
 * and em-dash levers do - so post titles, content, excerpts, widget text
 * and comment text are all covered.
 */
class Levers_Lever_Email_Obfuscation extends Levers_Lever {

	/** Email regex - intentionally simple, matches the common shape. */
	const EMAIL_REGEX = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'email-obfuscation';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Email obfuscation', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Rewrites email addresses in content as HTML entities so crawlers and harvesters can't scrape them. Still clickable for real users.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'at-sign';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		$hooks = array( 'the_title', 'the_content', 'the_excerpt', 'get_the_excerpt', 'widget_text', 'comment_text' );

		foreach ( $hooks as $hook ) {
			// Priority 50: late enough to run after wptexturize and the
			// other in-content rewrites, but before any super-late filters.
			add_filter( $hook, array( $this, 'obfuscate_emails' ), 50 );
		}
	}

	/**
	 * Replace every email-shaped substring with its antispambot() version.
	 *
	 * Works inside <a href="mailto:..."> too - the href value also gets
	 * matched and rewritten, and browsers happily decode HTML entities
	 * inside URLs at click time.
	 *
	 * @param mixed $text Text to scan.
	 * @return mixed
	 */
	public function obfuscate_emails( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		// Fast path: no @ in the whole blob means no emails to obfuscate.
		if ( false === strpos( $text, '@' ) ) {
			return $text;
		}

		$result = preg_replace_callback(
			self::EMAIL_REGEX,
			array( $this, 'obfuscate_match' ),
			$text
		);

		return ( null === $result ) ? $text : $result;
	}

	/**
	 * Run one matched email through antispambot().
	 *
	 * @param array $match Regex match.
	 * @return string
	 */
	public function obfuscate_match( $match ) {
		return antispambot( $match[0] );
	}
}
