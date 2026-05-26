<?php
/**
 * Lever: stop nav-menu # links from jumping to the top of the page.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites `href="#"` in nav menu items to `href="javascript:void(0);"`.
 *
 * Themes and site builders often use `#` as a placeholder href on dropdown
 * *parent* items - the link only exists to anchor the submenu, it isn't
 * meant to navigate anywhere. Clicking it scrolls the page to the top,
 * which feels broken. Swapping the href for `javascript:void(0);` keeps the
 * link clickable but does nothing on click, so the dropdown opens without
 * scrolling.
 *
 * Real anchor links (`href="#section"`) are left alone - only the
 * placeholder `href="#"` is rewritten.
 */
class Levers_Lever_Stop_Nav_Jumps extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'stop-nav-jumps';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Stop nav menu jumps', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Replaces empty # hrefs in nav menus with javascript:void(0); so clicking a dropdown parent doesn't yank the page back to the top.", 'levers' );
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
		return 'mouse-pointer-click';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Run very late so we win over any other walker filters.
		add_filter( 'walker_nav_menu_start_el', array( $this, 'fix_hash_href' ), 999 );
	}

	/**
	 * Replace href="#" (and href='#') with href="javascript:void(0);".
	 *
	 * Real anchor links (href="#some-id") aren't touched - only the bare
	 * placeholder is rewritten.
	 *
	 * @param mixed $item_html The walker's rendered <li>...</li> markup.
	 * @return mixed
	 */
	public function fix_hash_href( $item_html ) {
		if ( ! is_string( $item_html ) || '' === $item_html ) {
			return $item_html;
		}

		return str_replace(
			array( 'href="#"', "href='#'" ),
			'href="javascript:void(0);"',
			$item_html
		);
	}
}
