<?php
/**
 * Lever: prevent blog spam.
 *
 * @package Levers
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marks comments that contain a link as spam.
 *
 * Blog-comment spam almost always carries a link, either in the comment body
 * or in the commenter's "website" field. This lever flags such comments as
 * spam the moment they are posted, and also runs an hourly sweep that clears
 * link-bearing comments out of the pending queue (the backlog from before the
 * lever was switched on, plus anything inserted outside the normal flow).
 */
class Levers_Lever_Prevent_Blog_Spam extends Levers_Lever {

	/** Cron hook for the pending-comment sweep. */
	const CRON_HOOK = 'levers_sweep_comment_spam';

	/** Maximum comments processed per sweep. */
	const SWEEP_LIMIT = 200;

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'prevent-blog-spam';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title() {
		return __( 'Prevent links in blog comments', 'levers' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description() {
		return __( 'Marks comments containing a link (in the text or website field) as spam. Real-time on new comments, with an hourly pending-queue sweep.', 'levers' );
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
		return 'message-square-x';
	}

	/**
	 * {@inheritDoc}
	 */
	public function run() {
		// Real-time: flag link-bearing comments as they are posted.
		add_filter( 'pre_comment_approved', array( $this, 'flag_comment' ), 20, 2 );

		// Hourly sweep of the pending queue.
		add_action( self::CRON_HOOK, array( $this, 'sweep_pending' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Stop the hourly sweep when the lever is switched off.
	 *
	 * @return void
	 */
	public function on_disable() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/* ---------------------------------------------------------------------
	 * Real-time
	 * ------------------------------------------------------------------- */

	/**
	 * Flag a freshly posted comment as spam when it carries a link.
	 *
	 * @param int|string $approved    Approval status: 0, 1, 'spam' or 'trash'.
	 * @param array      $commentdata Comment data.
	 * @return int|string
	 */
	public function flag_comment( $approved, $commentdata ) {
		// Leave anything already rejected alone.
		if ( 'spam' === $approved || 'trash' === $approved ) {
			return $approved;
		}

		// Only act on real comments - never pingbacks or trackbacks.
		$type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
		if ( '' !== $type && 'comment' !== $type ) {
			return $approved;
		}

		// Never flag a comment from someone who can moderate comments.
		if ( ! empty( $commentdata['user_id'] ) && user_can( (int) $commentdata['user_id'], 'moderate_comments' ) ) {
			return $approved;
		}

		$url     = isset( $commentdata['comment_author_url'] ) ? (string) $commentdata['comment_author_url'] : '';
		$content = isset( $commentdata['comment_content'] ) ? (string) $commentdata['comment_content'] : '';

		if ( '' !== trim( $url ) || $this->has_link( $content ) ) {
			return 'spam';
		}

		return $approved;
	}

	/**
	 * Whether a string contains something that looks like a link.
	 *
	 * @param string $text Text to inspect.
	 * @return bool
	 */
	private function has_link( $text ) {
		return (bool) preg_match( '#(https?://|ftp://|www\.)#i', $text )
			|| (bool) preg_match( '#<a\s#i', $text );
	}

	/* ---------------------------------------------------------------------
	 * Hourly sweep
	 * ------------------------------------------------------------------- */

	/**
	 * Mark pending comments that carry a link as spam.
	 *
	 * @return void
	 */
	public function sweep_pending() {
		global $wpdb;

		$http = '%' . $wpdb->esc_like( 'http' ) . '%';
		$www  = '%' . $wpdb->esc_like( 'www.' ) . '%';
		$tag  = '%' . $wpdb->esc_like( '<a ' ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- scheduled spam sweep.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments}
				 WHERE comment_approved = '0'
				   AND comment_type IN ( '', 'comment' )
				   AND (
				       comment_author_url <> ''
				       OR comment_content LIKE %s
				       OR comment_content LIKE %s
				       OR comment_content LIKE %s
				   )
				 LIMIT %d",
				$http,
				$www,
				$tag,
				self::SWEEP_LIMIT
			)
		);

		foreach ( $ids as $id ) {
			wp_spam_comment( (int) $id );
		}
	}
}
