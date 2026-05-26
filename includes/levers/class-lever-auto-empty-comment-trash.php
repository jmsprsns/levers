<?php
/**
 * Lever: auto-empty old spam and trash comments.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permanently deletes spam and trashed comments older than N days.
 *
 * WordPress's spam and trash buckets are write-only by default - nothing
 * empties them. On a site that gets a steady drip of spam, `wp_comments`
 * silently grows forever, eventually slowing list-table queries and
 * comment counts. This lever runs once a day, finds comments in spam or
 * trash whose date_gmt is older than 30 days, and `wp_delete_comment()`s
 * them for good.
 *
 * Approved or pending comments are never touched.
 */
class Levers_Lever_Auto_Empty_Comment_Trash extends Levers_Lever {

	/** Cron hook for the daily purge. */
	const CRON_HOOK = 'levers_purge_spam_trash_comments';

	/** Age (in days) at which spam/trash comments become eligible for purge. */
	const KEEP_DAYS = 30;

	/** Maximum comments deleted per run, so the cron stays bounded. */
	const PURGE_LIMIT = 200;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'auto-empty-comment-trash';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Auto-empty spam & trash comments', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( "Auto-purges comments in spam or trash older than 30 days, so wp_comments doesn't bloat with junk you'll never review.", 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function category() {
		return 'spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function icon() {
		return 'trash-2';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		add_action( self::CRON_HOOK, array( $this, 'purge_old' ) );

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
	 * Permanently delete spam/trash comments older than the cutoff.
	 *
	 * @return void
	 */
	public function purge_old() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::KEEP_DAYS * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded scheduled cleanup.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				 WHERE comment_approved IN ( 'spam', 'trash' )
				   AND comment_date_gmt < %s
				 LIMIT %d",
				$cutoff,
				self::PURGE_LIMIT
			)
		);

		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			wp_delete_comment( (int) $id, true );
		}
	}
}
