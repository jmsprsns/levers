<?php
/**
 * Lever: disable jQuery Migrate on the front end.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drops the jquery-migrate compatibility shim from the front-end jQuery
 * dependency chain.
 *
 * WordPress still bundles jquery-migrate to keep old plugins/themes that
 * use removed jQuery APIs working. Modern themes and plugins don't need
 * it; loading it is one extra request and a chunk of JS that does nothing
 * useful on a clean stack.
 *
 * Mechanism: we hook `wp_default_scripts` (which fires when WP registers
 * its bundled scripts) and edit jquery's `deps` array to remove the
 * 'jquery-migrate' handle. Anything that later enqueues jquery will then
 * not pull migrate in alongside it. The admin keeps it so editor /
 * customiser code that assumes its presence is unaffected.
 */
class Levers_Lever_Disable_Jquery_Migrate extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'disable-jquery-migrate';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Disable jQuery Migrate', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Removes the legacy jquery-migrate shim from the front end. Safe on modern themes; leave off if you run old plugins.', 'levers' );
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
		return 'package-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'wp_default_scripts', array( $this, 'drop_migrate_dependency' ) );
	}

	/**
	 * Strip 'jquery-migrate' out of jquery's deps array, on the front end.
	 *
	 * @param WP_Scripts $scripts Scripts registry being built.
	 * @return void
	 */
	public function drop_migrate_dependency( $scripts ) {
		if ( is_admin() ) {
			return;
		}

		if ( ! ( $scripts instanceof WP_Scripts ) || empty( $scripts->registered['jquery'] ) ) {
			return;
		}

		$jquery = $scripts->registered['jquery'];

		if ( ! empty( $jquery->deps ) && is_array( $jquery->deps ) ) {
			$jquery->deps = array_values( array_diff( $jquery->deps, array( 'jquery-migrate' ) ) );
		}
	}
}
