<?php
/**
 * Lever: hide WordPress updates from non-admin users.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stops editors, authors and other non-admin users from seeing update
 * notifications they can't act on anyway.
 *
 * Three places this matters:
 *
 *   - Update transients (`update_core`, `update_plugins`, `update_themes`)
 *     drive the counters in the admin menu and admin bar. Filtering them
 *     to return "no updates" for users without `update_core` blanks the
 *     counter without changing what admins see.
 *   - The `update_nag` / `maintenance_nag` actions are unhooked for
 *     non-admins, so the yellow "Please update" banner doesn't appear.
 *
 * Admins, wp-cron and WP-CLI all see updates exactly as before - the
 * filter only short-circuits for an authenticated request without the
 * `update_core` capability.
 */
class Levers_Lever_Hide_Updates_From_Non_Admins extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'hide-updates-from-non-admins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Hide updates from non-admins', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Hides update nags, counters and the Updates submenu from editors and authors. Admins still see and apply updates as normal.", 'levers' );
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
		return 'bell-off';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Short-circuit the update transients for users without
		// update_core. The same filter is used for the admin menu/bar
		// counters and the dashboard widget, so they all go quiet.
		add_filter( 'pre_site_transient_update_core', array( $this, 'maybe_empty_updates' ) );
		add_filter( 'pre_site_transient_update_plugins', array( $this, 'maybe_empty_updates' ) );
		add_filter( 'pre_site_transient_update_themes', array( $this, 'maybe_empty_updates' ) );

		// And remove the yellow "you should update" banners for the
		// same audience.
		add_action( 'admin_init', array( $this, 'maybe_remove_nags' ) );
	}

	/**
	 * Return a stub "no updates available" object for non-admins, or pass
	 * through whatever WordPress was about to use for everyone else.
	 *
	 * @param mixed $value Existing short-circuit value (false = let WP fetch).
	 * @return mixed
	 */
	public function maybe_empty_updates( $value ) {
		if ( $this->user_can_see_updates() ) {
			return $value;
		}

		return (object) array(
			'updates'         => array(),
			'last_checked'    => time(),
			'version_checked' => get_bloginfo( 'version' ),
			'translations'    => array(),
		);
	}

	/**
	 * Unhook the core update nags for non-admins.
	 *
	 * @return void
	 */
	public function maybe_remove_nags() {
		if ( $this->user_can_see_updates() ) {
			return;
		}

		remove_action( 'admin_notices', 'update_nag', 3 );
		remove_action( 'network_admin_notices', 'update_nag', 3 );
		remove_action( 'admin_notices', 'maintenance_nag' );
		remove_action( 'network_admin_notices', 'maintenance_nag' );
	}

	/**
	 * Whether the current request should see update info as usual.
	 *
	 * Cron and WP-CLI run without a user but absolutely must still see
	 * updates so background checks keep working.
	 *
	 * @return bool
	 */
	private function user_can_see_updates() {
		if ( wp_doing_cron() ) {
			return true;
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return true;
		}

		return current_user_can( 'update_core' );
	}
}
