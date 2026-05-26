<?php
/**
 * Lever: dynamic copyright year.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the copyright year in `<footer>` fresh, automatically.
 *
 * Output-buffers the front-end response, finds every `<footer>...</footer>`
 * block, and rewrites stale `© 2025`, `&copy; 2025` or `Copyright 2025`
 * patterns to the current year. Year ranges (`© 2020-2025`) are handled
 * too - only the *end* year is bumped, so the original founding year is
 * preserved.
 *
 * The replacement is a no-op when the year already matches, so there's no
 * unnecessary string churn on already-current footers.
 */
class Levers_Lever_Dynamic_Copyright_Year extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'dynamic-copyright-year';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Dynamic copyright year', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Scans <footer> for stale "© YYYY" or "Copyright YYYY" and auto-bumps the year to the current one. Skipped if already up to date.', 'levers' );
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
		return 'copyright';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 1 );
	}

	/**
	 * Start an output buffer for the front-end page; the callback below
	 * inspects the full HTML at flush time.
	 *
	 * @return void
	 */
	public function start_buffer() {
		// Front-end HTML only - don't ob_start over feeds, ajax, REST,
		// cron, or anything that would care about the buffer wrapping it.
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() ) {
			return;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		ob_start( array( $this, 'rewrite_footer_year' ) );
	}

	/**
	 * Buffer callback: refresh stale years inside every `<footer>` block.
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	public function rewrite_footer_year( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}

		// Fast path: nothing copyright-shaped in this page at all.
		if ( false === stripos( $html, '<footer' ) ) {
			return $html;
		}

		$current_year = (int) wp_date( 'Y' );

		$result = preg_replace_callback(
			'#<footer\b[^>]*>(?:.*?)</footer>#is',
			function ( $matches ) use ( $current_year ) {
				return $this->refresh_years( $matches[0], $current_year );
			},
			$html
		);

		return ( null === $result ) ? $html : $result;
	}

	/**
	 * Update stale years inside one footer chunk.
	 *
	 * @param string $footer       Footer HTML.
	 * @param int    $current_year Year to bump to.
	 * @return string
	 */
	private function refresh_years( $footer, $current_year ) {
		// 1. Year ranges first: "© 2020 - 2025" / "Copyright 2020-2025".
		//    Only update the END year, so the founding year stays intact.
		$footer = preg_replace_callback(
			'/(©|&copy;|&#169;|Copyright)(\s*)(\d{4})(\s*[\-\x{2013}\x{2014}]\s*)(\d{4})/iu',
			function ( $m ) use ( $current_year ) {
				if ( (int) $m[5] === $current_year ) {
					return $m[0];
				}

				return $m[1] . $m[2] . $m[3] . $m[4] . $current_year;
			},
			$footer
		);

		// 2. Single year: "© 2025", "Copyright 2025" - but only when not
		//    immediately followed by a "- YYYY" range (the lookahead).
		$footer = preg_replace_callback(
			'/(©|&copy;|&#169;|Copyright)(\s*)(\d{4})(?!\s*[\-\x{2013}\x{2014}]\s*\d{4})/iu',
			function ( $m ) use ( $current_year ) {
				if ( (int) $m[3] === $current_year ) {
					return $m[0];
				}

				return $m[1] . $m[2] . $current_year;
			},
			$footer
		);

		return $footer;
	}
}
