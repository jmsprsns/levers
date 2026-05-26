<?php
/**
 * Lever: fix scheduled post modified time.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Aligns a scheduled post's "modified" timestamp with its publish date.
 *
 * When WordPress auto-publishes a scheduled post, it stamps
 * `post_modified` with the moment cron happened to fire - a few seconds
 * after the actual publish time. The result is that themes, sitemaps and
 * HTTP cache headers can show "Last updated" slightly after the publish
 * date, which is confusing and arguably wrong (nothing was really
 * "modified", the post was simply published).
 *
 * This lever copies `post_date` over `post_modified` on the
 * `future_to_publish` transition, so the two dates stay in sync.
 */
class Levers_Lever_Fix_Scheduled_Modified_Time extends Levers_Lever {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'fix-scheduled-modified-time';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Fix scheduled post modified time', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'When a scheduled post auto-publishes, copies its publish date onto the modified date so "Last updated" matches the publish moment exactly.', 'levers' );
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
		return 'calendar-sync';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( 'future_to_publish', array( $this, 'sync_modified_to_publish' ) );
	}

	/**
	 * Copy post_date over post_modified for the post that just published.
	 *
	 * Uses a direct $wpdb->update to avoid the cascade of save_post-style
	 * hooks that wp_update_post would re-fire during the transition.
	 *
	 * @param WP_Post $post The post that transitioned from future to publish.
	 * @return void
	 */
	public function sync_modified_to_publish( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		// Already aligned - nothing to do.
		if ( $post->post_date === $post->post_modified
			&& $post->post_date_gmt === $post->post_modified_gmt
		) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional surgical update; cache cleared below.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => $post->post_date,
				'post_modified_gmt' => $post->post_date_gmt,
			),
			array( 'ID' => $post->ID )
		);

		clean_post_cache( $post->ID );
	}
}
