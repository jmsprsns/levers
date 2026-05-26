<?php
/**
 * Lever: hide the WordPress admin footer.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes the "Thank you for creating with WordPress" line and the version
 * string from the dashboard's bottom strip.
 *
 * Belt-and-suspenders approach: filter the two strings out via core's own
 * `admin_footer_text` and `update_footer` filters AND hide `#wpfooter`
 * with a one-line inline style. The filters keep the markup empty for any
 * code that reads the text; the style collapses the strip itself so there's
 * no leftover empty bar.
 */
class Levers_Lever_Hide_Admin_Footer extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'hide-admin-footer';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Hide admin footer credit', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Removes the \"Thank you for creating with WordPress\" line and the version string from the dashboard's bottom strip.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'wordpress-cleanup';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'panel-bottom-close';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Empty the two strings WP injects into the footer. Priority 11
		// so we run after core's default callbacks at priority 10.
		add_filter( 'admin_footer_text', '__return_empty_string', 11 );
		add_filter( 'update_footer', '__return_empty_string', 11 );

		// Collapse the now-empty #wpfooter element itself.
		add_action( 'admin_head', array( $this, 'print_hide_css' ) );
	}

	/**
	 * Inline style to remove the (now empty) footer strip from the layout.
	 *
	 * @return void
	 */
	public function print_hide_css() {
		echo '<style id="levers-hide-admin-footer">#wpfooter{display:none !important;}</style>' . "\n";
	}
}
