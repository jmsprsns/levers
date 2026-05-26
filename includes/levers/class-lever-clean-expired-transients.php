<?php
/**
 * Lever: clean expired transients.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily cron sweep that deletes expired transient rows out of wp_options.
 *
 * WordPress only deletes an expired transient when something asks for it
 * via `get_transient()` and finds it stale. On a site without a
 * persistent object cache (the common case) every plugin that sets
 * a transient and then forgets to fetch it again leaves behind two
 * dead options - the value and the timeout - until the end of time.
 * Over months that becomes a real chunk of wp_options bloat, which in
 * turn slows the autoload payload on every single page load.
 *
 * This lever finds rows where the timeout is in the past and removes
 * the matching pair via the proper API (`delete_transient` /
 * `delete_site_transient`), so any cache layer also gets invalidated.
 */
class Levers_Lever_Clean_Expired_Transients extends Levers_Lever {

	/** Cron hook for the daily sweep. */
	const CRON_HOOK = 'levers_purge_expired_transients';

	/** Hard cap on rows processed per sweep. */
	const PURGE_LIMIT = 500;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'clean-expired-transients';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Clean expired transients', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Daily sweep that purges expired transients from wp_options. They don't reliably self-delete without a persistent object cache.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'maintenance';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'database-zap';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( self::CRON_HOOK, array( $this, 'purge_expired' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	public function on_disable() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Find expired transients and delete them via the public API so any
	 * object-cache layer stays in sync.
	 *
	 * @return void
	 */
	public function purge_expired() {
		$this->purge_set( '_transient_timeout_', 'delete_transient' );
		$this->purge_set( '_site_transient_timeout_', 'delete_site_transient' );
	}

	/**
	 * Sweep one transient family (regular or site).
	 *
	 * @param string   $timeout_prefix Option-name prefix for the timeout pair.
	 * @param callable $deleter        delete_transient / delete_site_transient.
	 * @return void
	 */
	private function purge_set( $timeout_prefix, $deleter ) {
		global $wpdb;

		$now  = time();
		$like = $wpdb->esc_like( $timeout_prefix ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled cleanup over wp_options.
		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d
				 LIMIT %d",
				$like,
				$now,
				self::PURGE_LIMIT
			)
		);

		if ( empty( $expired ) ) {
			return;
		}

		$prefix_length = strlen( $timeout_prefix );

		foreach ( $expired as $option_name ) {
			$key = substr( (string) $option_name, $prefix_length );

			if ( '' === $key ) {
				continue;
			}

			call_user_func( $deleter, $key );
		}
	}
}
