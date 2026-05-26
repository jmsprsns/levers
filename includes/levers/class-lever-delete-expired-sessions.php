<?php
/**
 * Lever: delete expired _wp_session_* rows.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Daily sweep that purges expired WP-Session-Manager rows out of
 * wp_options.
 *
 * Many membership, e-commerce and form plugins (and WooCommerce's older
 * versions) store front-end sessions in wp_options using the WP Session
 * Manager convention:
 *
 *   `_wp_session_<id>`          - the session payload
 *   `_wp_session_expires_<id>`  - a Unix timestamp marking when it dies
 *
 * Nothing in core empties those when the timestamp passes; they just
 * sit in wp_options forever (and, on a busy WooCommerce site, that's
 * thousands of rows in a few weeks). This lever finds the expired
 * timestamps and removes both halves of the pair.
 */
class Levers_Lever_Delete_Expired_Sessions extends Levers_Lever {

	/** Cron hook for the daily sweep. */
	const CRON_HOOK = 'levers_purge_expired_sessions';

	/** Max sessions purged per run, so the cron stays bounded. */
	const PURGE_LIMIT = 500;

	/** Option-name prefix for the expiry marker. */
	const EXPIRES_PREFIX = '_wp_session_expires_';

	/** Option-name prefix for the session payload. */
	const VALUE_PREFIX = '_wp_session_';

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'delete-expired-sessions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Delete expired sessions', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Daily purge of expired _wp_session_* rows from wp_options. Common bloat on sites running WooCommerce or membership plugins.', 'levers' );
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
		return 'hourglass';
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
	 * Find expired session pairs and delete both halves of each.
	 *
	 * @return void
	 */
	public function purge_expired() {
		global $wpdb;

		$like = $wpdb->esc_like( self::EXPIRES_PREFIX ) . '%';
		$now  = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled cleanup.
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

		$prefix_length = strlen( self::EXPIRES_PREFIX );

		foreach ( $expired as $expires_option ) {
			$id = substr( (string) $expires_option, $prefix_length );

			if ( '' === $id ) {
				continue;
			}

			delete_option( self::VALUE_PREFIX . $id );
			delete_option( self::EXPIRES_PREFIX . $id );
		}
	}
}
