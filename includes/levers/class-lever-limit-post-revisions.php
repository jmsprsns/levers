<?php
/**
 * Lever: limit and clean post revisions.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Caps the number of revisions WordPress keeps per post, and trims any
 * pre-existing backlog.
 *
 * Revisions are usually the single largest source of wp_posts bloat on
 * a long-running site: a few hundred published posts can quietly hide
 * tens of thousands of revision rows, each duplicating the post content.
 * Two mechanisms here, one toggle:
 *
 *   - Going forward: the `wp_revisions_to_keep` filter caps how many
 *     revisions WordPress will store for any newly-edited post (or
 *     respects a smaller cap set elsewhere).
 *   - Existing backlog: a daily cron sweep finds posts that already
 *     have more revisions than the cap and trims the excess - oldest
 *     first - via wp_delete_post_revision().
 */
class Levers_Lever_Limit_Post_Revisions extends Levers_Lever {

	/** Maximum revisions kept per post. */
	const KEEP_REVISIONS = 5;

	/** Cron hook for the daily backlog trim. */
	const CRON_HOOK = 'levers_trim_post_revisions';

	/** Max parent posts processed per cron run. */
	const TRIM_LIMIT = 50;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'limit-post-revisions';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Limit & clean post revisions', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Caps revisions at 5 per post going forward and trims existing extras. Usually the single biggest source of wp_posts bloat.', 'levers' );
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
		return 'history';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Cap revisions on new edits.
		add_filter( 'wp_revisions_to_keep', array( $this, 'cap_revisions' ), 10, 2 );

		// Trim the backlog daily.
		add_action( self::CRON_HOOK, array( $this, 'trim_existing_revisions' ) );

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
	 * Force the revision cap to our maximum, but respect a stricter
	 * value set elsewhere (constant, theme, other plugin).
	 *
	 * @param int|bool $num  Current cap (-1 / true = unlimited).
	 * @param WP_Post  $post Post being edited.
	 * @return int
	 */
	public function cap_revisions( $num, $post ) {
		$num = is_numeric( $num ) ? (int) $num : -1;

		if ( $num < 0 ) {
			return self::KEEP_REVISIONS;
		}

		return min( $num, self::KEEP_REVISIONS );
	}

	/**
	 * Find posts whose revision count exceeds the cap and delete the
	 * excess (oldest first).
	 *
	 * @return void
	 */
	public function trim_existing_revisions() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled cleanup.
		$over_quota = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_parent
				 FROM {$wpdb->posts}
				 WHERE post_type = 'revision'
				 GROUP BY post_parent
				 HAVING COUNT(*) > %d
				 LIMIT %d",
				self::KEEP_REVISIONS,
				self::TRIM_LIMIT
			)
		);

		if ( empty( $over_quota ) ) {
			return;
		}

		foreach ( $over_quota as $parent_id ) {
			$revisions = wp_get_post_revisions(
				(int) $parent_id,
				array(
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);

			// Keep the newest KEEP_REVISIONS, drop the rest.
			$excess = array_slice( $revisions, self::KEEP_REVISIONS );

			foreach ( $excess as $revision ) {
				wp_delete_post_revision( $revision->ID );
			}
		}
	}
}
