<?php
/**
 * Lever: disable WordPress 7.0's admin view-transition animations.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns off the admin page-fade transitions introduced in WordPress 7.0.
 *
 * WordPress 7.0 enqueues a `wp-view-transitions-admin` stylesheet that
 * uses the CSS View Transitions API to fade between admin pages and
 * menu states. It's purely cosmetic and there's no built-in switch -
 * dequeuing + deregistering the handle removes the effect cleanly and
 * the dashboard goes back to instant page swaps.
 *
 * Helpful for users with motion sensitivity, slow machines, or just a
 * preference for "click -> instantly there" admin navigation.
 */
class Levers_Lever_Disable_Admin_Transitions extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-admin-transitions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable admin fade transitions', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Disables WordPress 7.0's admin page-fade transition. Pages load instantly again, no animation between admin screens.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'admin-tools';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'accessibility';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Priority 999 to run after core / themes have queued the handle.
		add_action( 'admin_enqueue_scripts', array( $this, 'kill_view_transitions' ), 999 );
	}

	/**
	 * Remove the wp-view-transitions-admin stylesheet from this request.
	 *
	 * @return void
	 */
	public function kill_view_transitions() {
		wp_dequeue_style( 'wp-view-transitions-admin' );
		wp_deregister_style( 'wp-view-transitions-admin' );
	}
}
