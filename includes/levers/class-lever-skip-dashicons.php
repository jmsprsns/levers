<?php
/**
 * Lever: skip front-end dashicons.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stops the Dashicons stylesheet loading on the front end for logged-out
 * visitors.
 *
 * Dashicons ships with WordPress for the admin and the toolbar. Some themes
 * and plugins drag it onto the front end too, where it's almost never used
 * by anyone who isn't logged in. Deregistering it for logged-out visitors
 * drops one CSS request from every public page load.
 */
class Levers_Lever_Skip_Dashicons extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'skip-dashicons';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Skip front-end dashicons', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Stops the Dashicons stylesheet loading on the front end for logged-out visitors. Skips a small CSS request on every page.', 'levers' );
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
		return 'zap';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Priority 100 so this runs after themes and plugins have had their
		// chance to enqueue Dashicons.
		add_action( 'wp_enqueue_scripts', array( $this, 'deregister_dashicons' ), 100 );
	}

	/**
	 * Drop Dashicons from the front-end stylesheet queue for guests.
	 *
	 * Logged-in users keep it - the admin toolbar needs it.
	 *
	 * @return void
	 */
	public function deregister_dashicons() {
		if ( is_user_logged_in() ) {
			return;
		}

		wp_deregister_style( 'dashicons' );
		wp_dequeue_style( 'dashicons' );
	}
}
