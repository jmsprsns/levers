<?php
/**
 * Lever: publish missed scheduled posts.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catches scheduled posts that missed their publish window and publishes
 * them retroactively.
 *
 * WP-Cron is "lazy": the event that publishes a scheduled post only fires
 * when a visitor (or admin) loads a page near the scheduled time. On a
 * quiet site that can be hours late, and sometimes the event is missed
 * entirely - the post sits stuck on the "future" status until a human
 * notices and hits Publish.
 *
 * This lever piggy-backs on the same page-load trigger WP-Cron uses, but
 * with a direct DB sweep that doesn't rely on the missed cron event ever
 * firing. A 15-minute transient throttles it so even high-traffic sites
 * only run the check four times an hour.
 */
class Levers_Lever_Publish_Missed_Posts extends Levers_Lever {

	/** How often to actually run the sweep, at most. */
	const THROTTLE_SECONDS = 900;   // 15 minutes.

	/** Throttle transient key. */
	const THROTTLE_KEY = 'levers_missed_schedule_check';

	/** Hard cap on posts published per sweep. */
	const SWEEP_LIMIT = 50;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'publish-missed-posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Publish missed scheduled posts', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Catches scheduled posts that missed their publish time (a known WP-Cron quirk on low-traffic sites) and publishes them automatically.', 'levers' );
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
		return 'alarm-clock-check';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Fire on every front-end and admin page load, throttled below.
		add_action( 'wp', array( $this, 'publish_missed' ) );
		add_action( 'admin_init', array( $this, 'publish_missed' ) );
	}

	/**
	 * Find scheduled posts whose date has passed and publish them.
	 *
	 * @return void
	 */
	public function publish_missed() {
		if ( get_transient( self::THROTTLE_KEY ) ) {
			return;
		}

		// Mark first so concurrent requests don't all do the sweep.
		set_transient( self::THROTTLE_KEY, 1, self::THROTTLE_SECONDS );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted sweep for stuck scheduled posts.
		$missed = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_status = 'future'
				   AND post_date_gmt <= %s
				 LIMIT %d",
				current_time( 'mysql', 1 ),
				self::SWEEP_LIMIT
			)
		);

		if ( empty( $missed ) ) {
			return;
		}

		foreach ( $missed as $post_id ) {
			wp_publish_post( (int) $post_id );
		}
	}
}
