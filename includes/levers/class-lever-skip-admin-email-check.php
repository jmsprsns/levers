<?php
/**
 * Lever: skip the WordPress admin email verification interstitial.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suppresses the "Administration email verification" interstitial.
 *
 * Every six months, WordPress slams an admin who has just signed in with
 * a full-screen prompt asking whether the admin email on file is still
 * correct. For solo or small-team sites that ship from the same address
 * forever it's pure friction. Filtering `admin_email_check_interval` to
 * zero is the WordPress-sanctioned way to turn it off.
 */
class Levers_Lever_Skip_Admin_Email_Check extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'skip-admin-email-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Skip admin email verification prompt', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Suppresses the periodic \"Is this still your email?\" interstitial that interrupts admins.", 'levers' );
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
		return 'mail-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Core treats an interval of 0 as "never check".
		add_filter( 'admin_email_check_interval', '__return_zero' );
	}
}
